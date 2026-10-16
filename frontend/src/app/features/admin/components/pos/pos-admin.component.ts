import { Component, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, Subscription, of } from 'rxjs';
import { catchError, debounceTime, distinctUntilChanged, switchMap } from 'rxjs/operators';
import { ApiService, Seat, Showtime, ShowtimeDetail } from '../../../../core/api/api.service';
import { AdminService, InvoiceBuyer, PaymentMethod, PosInvoice, Ticket, TicketCategory, categoryLabel } from '../../../../services/admin.service';
import { TicketPrintComponent } from '../ticket-print/ticket-print.component';
import { AuthService } from '../../../../services/auth.service';
import { PosServicesComponent } from './pos-services.component';

interface DayTab {
  key: string;
  label: string;
  showtimes: Showtime[];
}

type SaleMode = 'seats' | 'reservations' | 'void';

@Component({
  selector: 'app-pos-admin',
  standalone: true,
  imports: [CommonModule, FormsModule, TicketPrintComponent, PosServicesComponent],
  templateUrl: './pos-admin.component.html',
  styleUrls: ['./pos-admin.component.css']
})
export class PosAdminComponent implements OnInit, OnDestroy {
  // A kezdés után ennyi percig még lehet jegyet adni (a szerver is ezt érvényesíti)
  private readonly graceMinutes = 30;

  days: DayTab[] = [];
  selectedDayKey = '';
  loadingShowtimes = false;
  showtimesError: string | null = null;

  showtime: ShowtimeDetail | null = null;
  private requestedShowtimeId = 0;
  loadingShowtime = false;

  mode: SaleMode = 'seats';
  area: 'cinema' | 'services' = 'cinema';
  selectedSeats: Seat[] = [];

  // Székenkénti jegykategória (normál / Dolgozó jegy / Élménykártya) és a Dolgozó jegy beállított ára
  seatCategories: Record<string, TicketCategory> = {};
  staffPrice = 0;
  categoryLabel = categoryLabel;
  pendingReservations: any[] = [];
  selectedReservation: any | null = null;

  paymentMethod: PaymentMethod | null = null;
  cashGiven: number | null = null;
  customerName = '';

  processing = false;
  error: string | null = null;

  // Helyszíni foglalás (fizetés nélkül)
  reserveOpen = false;
  holdName = '';
  holdPhone = '';
  holdEmail = '';
  heldNotice: string | null = null;

  // Név szerinti vásárló-javaslatok a foglalási űrlapon
  customerSuggestions: Array<{ id: number; name: string; email: string | null; phone: string | null }> = [];
  private nameSearch$ = new Subject<string>();
  private nameSearchSub?: Subscription;

  soldTickets: Ticket[] = [];
  printOpen = false;

  // ÁFÁS számla (Számlázz.hu)
  invoicingEnabled = false;
  invoiceVatRate = '5';
  invoiceRequested = false;
  invoiceConfirmOpen = false;
  invoiceBuyer = { name: '', zip: '', city: '', address: '', tax_number: '', email: '' };
  pendingInvoice: { reservationId: number; buyer: InvoiceBuyer } | null = null;
  invoiceBusy = false;
  invoiceError: string | null = null;
  soldInvoice: PosInvoice | null = null;

  constructor(
    private api: ApiService,
    private admin: AdminService,
    private auth: AuthService,
    private router: Router
  ) {}

  /** Az előadások napi eladása (minden eladó jegyei) a vezetőknek és az adminisztrátoroknak érhető el. */
  get canSeeShowtimeDay(): boolean {
    return this.auth.can('daily_close.all');
  }

  ngOnInit(): void {
    this.loadShowtimes();
    this.admin.posServices().subscribe({
      next: (s) => (this.staffPrice = s.staff_price),
      error: () => (this.staffPrice = 0)
    });
    this.admin.posInvoicing().subscribe({
      next: (s) => {
        this.invoicingEnabled = s.enabled;
        this.invoiceVatRate = s.vat_rate;
      },
      error: () => (this.invoicingEnabled = false)
    });
    this.nameSearchSub = this.nameSearch$.pipe(
      debounceTime(250),
      distinctUntilChanged(),
      switchMap(q => q.length < 2 ? of([]) : this.admin.posSearchCustomers(q).pipe(catchError(() => of([]))))
    ).subscribe(list => (this.customerSuggestions = list));
  }

  ngOnDestroy(): void {
    this.nameSearchSub?.unsubscribe();
  }

  onHoldNameChange(value: string): void {
    this.nameSearch$.next((value ?? '').trim());
  }

  pickCustomer(c: { name: string; email: string | null; phone: string | null }, event: Event): void {
    event.preventDefault();
    this.holdName = c.name;
    this.holdPhone = c.phone ?? '';
    this.holdEmail = c.email ?? '';
    this.customerSuggestions = [];
    this.nameSearch$.next('');
  }

  closeSuggestions(): void {
    this.customerSuggestions = [];
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
  }

  goTo(path: string): void {
    this.router.navigate([path]);
  }

  // ---- Előadás kiválasztása ----

  loadShowtimes(): void {
    this.loadingShowtimes = true;
    this.showtimesError = null;

    this.api.getShowtimes().subscribe({
      next: (showtimes) => {
        const limit = Date.now() - this.graceMinutes * 60000;
        const open = showtimes
          .filter(s => s.status === 'active' && new Date(s.starts_at).getTime() >= limit)
          .sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime());

        const byDay = new Map<string, Showtime[]>();
        for (const s of open) {
          const key = this.dayKey(new Date(s.starts_at));
          byDay.set(key, [...(byDay.get(key) ?? []), s]);
        }

        this.days = Array.from(byDay.entries()).map(([key, list]) => ({
          key,
          label: this.dayLabel(key),
          showtimes: list
        }));

        if (!this.days.some(d => d.key === this.selectedDayKey)) {
          this.selectedDayKey = this.days[0]?.key ?? '';
        }
        this.loadingShowtimes = false;
      },
      error: () => {
        this.showtimesError = 'Az előadások betöltése sikertelen.';
        this.loadingShowtimes = false;
      }
    });
  }

  get selectedDay(): DayTab | undefined {
    return this.days.find(d => d.key === this.selectedDayKey);
  }

  selectDay(key: string): void {
    this.selectedDayKey = key;
  }

  selectShowtime(showtime: Showtime): void {
    this.resetSale();
    this.mode = 'seats';
    // A korábbi előadás termét és székeit azonnal eltávolítjuk, hogy ne maradjon félrevezető adat
    this.showtime = null;
    this.pendingReservations = [];
    this.soldList = [];
    this.requestedShowtimeId = showtime.id;
    this.loadShowtime(showtime.id);
  }

  loadShowtime(id: number): void {
    this.loadingShowtime = true;
    this.error = null;
    this.requestedShowtimeId = id;

    this.api.getShowtimeById(id).subscribe({
      next: (detail) => {
        // Lassabb, közben lecserélt előadás válaszát eldobjuk
        if (this.requestedShowtimeId !== id) return;

        this.showtime = detail;
        // A frissítés után már nem szabad székek kikerülnek a kijelölésből
        this.selectedSeats = this.selectedSeats.filter(sel => this.findSeat(sel.key)?.status === 'available');
        this.loadingShowtime = false;
        this.loadPendingReservations();
        this.loadSoldTickets();
      },
      error: () => {
        if (this.requestedShowtimeId !== id) return;
        this.error = 'Az előadás betöltése sikertelen.';
        this.loadingShowtime = false;
      }
    });
  }

  refresh(): void {
    if (this.showtime) {
      this.loadShowtime(this.showtime.id);
    }
  }

  private loadPendingReservations(): void {
    if (!this.showtime) return;

    const showtimeId = this.showtime.id;

    this.admin.getShowtimeReservations(showtimeId).subscribe({
      next: (reservations) => {
        if (this.showtime?.id !== showtimeId) return;
        this.pendingReservations = reservations.filter(r => r.status === 'PENDING');
        if (this.selectedReservation) {
          this.selectedReservation = this.pendingReservations.find(r => r.id === this.selectedReservation.id) ?? null;
          if (!this.selectedReservation) this.editing = false;
        }
      },
      error: () => { this.pendingReservations = []; }
    });
  }

  // ---- Székválasztó ----

  getRows(): string[] {
    return this.showtime ? Object.keys(this.showtime.seatMap.seats) : [];
  }

  getSectionGroups(): Array<{ name: string; rows: string[] }> {
    if (!this.showtime) return [];

    const groups = new Map<string, string[]>();
    for (const row of this.getRows()) {
      const seats = Object.values(this.showtime.seatMap.seats[row] || {});
      const section = seats.find(s => !!s.section)?.section || 'Nézőtér';
      groups.set(section, [...(groups.get(section) ?? []), row]);
    }
    return Array.from(groups.entries()).map(([name, rows]) => ({ name, rows }));
  }

  getSeatsInRow(row: string): Seat[] {
    return this.showtime ? Object.values(this.showtime.seatMap.seats[row] || {}) : [];
  }

  hasAisleBefore(row: string, index: number): boolean {
    const aisleAfter = this.showtime?.seatMap.layout?.aisles?.[row];
    return aisleAfter !== undefined && index === aisleAfter;
  }

  isAisle(seat: Seat): boolean {
    return seat.type === 'aisle';
  }

  seatClass(seat: Seat): string {
    if (this.editing) {
      return this.editSeatKeys.includes(seat.key) ? 'selected' : seat.status;
    }
    if (this.isSelected(seat)) return 'selected';
    if (this.reservationSeatKeys.has(seat.key)) return 'reserved-pick';
    return seat.status;
  }

  isSelected(seat: Seat): boolean {
    return this.selectedSeats.some(s => s.key === seat.key);
  }

  toggleSeat(seat: Seat): void {
    if (this.editing) {
      if (this.isAisle(seat) || !seat.bookable) return;
      if (this.editSeatKeys.includes(seat.key)) {
        this.editSeatKeys = this.editSeatKeys.filter(k => k !== seat.key);
      } else if (seat.status === 'available') {
        this.editSeatKeys = [...this.editSeatKeys, seat.key];
      }
      this.error = null;
      return;
    }
    if (this.mode !== 'seats' || this.isAisle(seat) || !seat.bookable || seat.status !== 'available') return;

    this.selectedSeats = this.isSelected(seat)
      ? this.selectedSeats.filter(s => s.key !== seat.key)
      : [...this.selectedSeats, seat];
    // Új kiválasztáskor mindig normál jegyről indul
    delete this.seatCategories[seat.key];
    this.error = null;
    this.soldTickets = [];
  }

  categoryOf(seat: Seat): TicketCategory {
    return this.seatCategories[seat.key] ?? 'standard';
  }

  setSeatCategory(seat: Seat, category: TicketCategory): void {
    if (category === 'standard') {
      delete this.seatCategories[seat.key];
    } else {
      this.seatCategories[seat.key] = category;
    }
    this.error = null;
  }

  /** A szék jegyára a kategória szerint: Dolgozó jegy = a beállított ár, egyébként (Élménykártyánál is) a terem ára. */
  effectivePrice(seat: Seat): number {
    return this.categoryOf(seat) === 'staff' ? this.staffPrice : seat.price;
  }

  private seatCategoriesPayload(): Record<string, TicketCategory> | undefined {
    const picked: Record<string, TicketCategory> = {};
    for (const seat of this.selectedSeats) {
      const category = this.categoryOf(seat);
      if (category !== 'standard') picked[seat.key] = category;
    }
    return Object.keys(picked).length ? picked : undefined;
  }

  private findSeat(key: string): Seat | undefined {
    for (const row of this.getRows()) {
      const seat = this.showtime?.seatMap.seats[row]?.[key];
      if (seat) return seat;
    }
    return undefined;
  }

  // ---- Foglalások ----

  setMode(mode: SaleMode): void {
    if (this.mode === mode) return;
    this.mode = mode;
    this.cancelEdit();
    this.selectedSeats = [];
    this.selectedReservation = null;
    this.resetPayment();
  }

  selectReservation(reservation: any): void {
    this.selectedReservation = this.selectedReservation?.id === reservation.id ? null : reservation;
    this.soldTickets = [];
    this.cancelEdit();
    this.resetPayment();
  }

  // ---- Sztornó ----

  soldList: Array<Ticket & { voidable: boolean }> = [];
  voidTarget: (Ticket & { voidable: boolean }) | null = null;
  voidReason = '';
  voidError: string | null = null;

  private loadSoldTickets(): void {
    if (!this.showtime) return;

    const showtimeId = this.showtime.id;
    this.admin.posShowtimeTickets(showtimeId).subscribe({
      next: (tickets) => {
        if (this.showtime?.id === showtimeId) this.soldList = tickets;
      },
      error: () => { this.soldList = []; }
    });
  }

  askVoid(ticket: Ticket & { voidable: boolean }): void {
    this.voidTarget = ticket;
    this.voidReason = '';
    this.voidError = null;
    this.heldNotice = null;
    this.error = null;
  }

  closeVoid(): void {
    if (this.processing) return;
    this.voidTarget = null;
  }

  confirmVoid(): void {
    const ticket = this.voidTarget;
    if (!ticket || this.processing) return;

    this.processing = true;
    this.voidError = null;

    this.admin.posVoidTicket(ticket.id, this.voidReason.trim() || undefined).subscribe({
      next: (result) => {
        this.processing = false;
        this.voidTarget = null;
        this.heldNotice = `↩️ A(z) ${ticket.ticket_number} jegy sztornózva, a(z) ${ticket.seat_key} szék újra szabad.`
          + (result.nav_correction_needed ? ' Figyelem: erre a napra már volt NAV-beküldés, ezért helyesbítést kell beküldeni.' : '');
        this.refresh();
      },
      error: (err) => {
        this.processing = false;
        this.voidError = err?.error?.message || 'A sztornózás sikertelen. Próbálja újra.';
        if (err?.status === 409) this.refresh();
      }
    });
  }

  // ---- Foglalás módosítása / törlése ----

  editing = false;
  editName = '';
  editPhone = '';
  editEmail = '';
  editSeatKeys: string[] = [];
  deleteConfirmOpen = false;
  private readonly walkInEmail = 'helyszini@vigadojegy.hu';

  startEdit(): void {
    const r = this.selectedReservation;
    if (!r) return;
    this.editing = true;
    this.editName = r.customer_name || '';
    this.editPhone = r.customer_phone || '';
    this.editEmail = r.customer_email && r.customer_email !== this.walkInEmail ? r.customer_email : '';
    this.editSeatKeys = (r.items ?? []).map((i: any) => i.seat_key);
    this.error = null;
    this.resetPayment();
  }

  cancelEdit(): void {
    this.editing = false;
    this.editSeatKeys = [];
    this.error = null;
  }

  get canSaveEdit(): boolean {
    const emailOk = !this.editEmail.trim() || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.editEmail.trim());
    return this.editing && !!this.editName.trim() && !!this.editPhone.trim() && emailOk && this.editSeatKeys.length > 0 && !this.processing;
  }

  get editTotal(): number {
    return this.editSeatKeys.reduce((sum, key) => sum + (this.findSeat(key)?.price ?? 0), 0);
  }

  saveEdit(): void {
    const r = this.selectedReservation;
    if (!r || !this.canSaveEdit) return;

    this.processing = true;
    this.error = null;

    this.admin.posUpdateReservation(r.id, {
      customer_name: this.editName.trim(),
      customer_phone: this.editPhone.trim(),
      customer_email: this.editEmail.trim() || undefined,
      seatKeys: this.editSeatKeys
    }).subscribe({
      next: (response) => {
        this.processing = false;
        this.heldNotice = `✏️ A(z) #${response.reservation.id} foglalás módosítva.`;
        this.editing = false;
        this.editSeatKeys = [];
        this.selectedReservation = response.reservation;
        this.refresh();
      },
      error: (err) => {
        this.processing = false;
        const errors = err?.error?.errors;
        this.error = (errors && Object.values(errors as Record<string, string[]>)[0]?.[0]) || err?.error?.message || 'A foglalás módosítása sikertelen.';
        if (err?.status === 409 || err?.status === 422) {
          this.refresh();
        }
      }
    });
  }

  askDelete(): void {
    if (!this.selectedReservation) return;
    this.error = null;
    this.deleteConfirmOpen = true;
  }

  closeDelete(): void {
    if (this.processing) return;
    this.deleteConfirmOpen = false;
  }

  confirmDelete(): void {
    const r = this.selectedReservation;
    if (!r || this.processing) return;

    this.processing = true;
    this.admin.posDeleteReservation(r.id).subscribe({
      next: () => {
        this.processing = false;
        this.deleteConfirmOpen = false;
        this.heldNotice = `🗑️ A(z) #${r.id} foglalás törölve, a székek újra szabadok.`;
        this.selectedReservation = null;
        this.editing = false;
        this.resetPayment();
        this.refresh();
      },
      error: (err) => {
        this.processing = false;
        this.deleteConfirmOpen = false;
        this.error = err?.error?.message || 'A foglalás törlése sikertelen.';
        this.refresh();
      }
    });
  }

  get reservationSeatKeys(): Set<string> {
    return new Set<string>(
      this.selectedReservation ? (this.selectedReservation.items ?? []).map((i: any) => i.seat_key) : []
    );
  }

  // ---- Fizetés ----

  get total(): number {
    if (this.mode === 'reservations') {
      return this.selectedReservation ? Number(this.selectedReservation.total_amount) : 0;
    }
    return this.selectedSeats.reduce((sum, seat) => sum + this.effectivePrice(seat), 0);
  }

  /** A fizetendő összeg: az élménykártyás jegyek értéke nem készpénzzel/kártyával fizetendő (a kártya fedezi). */
  get payable(): number {
    if (this.mode === 'reservations') {
      return (this.selectedReservation?.items ?? [])
        .filter((i: any) => i.category !== 'experience_card')
        .reduce((sum: number, i: any) => sum + Number(i.price_amount), 0);
    }
    return this.selectedSeats
      .filter(seat => this.categoryOf(seat) !== 'experience_card')
      .reduce((sum, seat) => sum + this.effectivePrice(seat), 0);
  }

  get cardCoveredAmount(): number {
    return this.total - this.payable;
  }

  get hasSelection(): boolean {
    return this.mode === 'seats' ? this.selectedSeats.length > 0 : !!this.selectedReservation;
  }

  get change(): number | null {
    if (this.paymentMethod !== 'cash' || this.cashGiven === null || this.cashGiven === undefined) return null;
    return this.cashGiven - this.payable;
  }

  get canConfirm(): boolean {
    if (!this.hasSelection || this.processing) return false;
    // Ha nincs fizetendő összeg (csak Dolgozó jegy / Élménykártya), a fizetési mód nem kötelező
    if (this.payable > 0 && !this.paymentMethod) return false;
    if (this.paymentMethod === 'cash' && this.cashGiven !== null && this.cashGiven !== undefined && this.cashGiven < this.payable) {
      return false;
    }
    if (this.invoiceActive && !this.invoiceFormValid) return false;
    return true;
  }

  /** Számla csak fizetendő összeg esetén kérhető. */
  get invoiceActive(): boolean {
    return this.invoicingEnabled && this.invoiceRequested && this.payable > 0;
  }

  setPayment(method: PaymentMethod): void {
    this.paymentMethod = method;
    this.cashGiven = null;
    this.error = null;
  }

  // ---- ÁFÁS számla ----

  toggleInvoice(): void {
    this.invoiceRequested = !this.invoiceRequested;
    if (this.invoiceRequested && !this.invoiceBuyer.name) {
      const r = this.mode === 'reservations' ? this.selectedReservation : null;
      this.invoiceBuyer.name = (r?.customer_name || this.customerName || '').trim();
      const email = (r?.customer_email || '').trim();
      this.invoiceBuyer.email = email && !email.endsWith('@vigadojegy.hu') ? email : '';
    }
  }

  get invoiceFormValid(): boolean {
    const b = this.invoiceBuyer;
    const taxOk = !b.tax_number.trim() || /^\d{8}-\d-\d{2}$/.test(b.tax_number.trim());
    const emailOk = !b.email.trim() || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(b.email.trim());
    return !!b.name.trim() && b.zip.trim().length >= 3 && !!b.city.trim() && !!b.address.trim() && taxOk && emailOk;
  }

  get invoiceLineAmounts(): number[] {
    // A 0 Ft-os (Dolgozó jegy) és az élménykártyás jegyek nem kerülnek a számlára
    const amounts = this.mode === 'reservations'
      ? (this.selectedReservation?.items ?? [])
          .filter((i: any) => i.category !== 'experience_card')
          .map((i: any) => Math.round(Number(i.price_amount)))
      : this.selectedSeats.filter(s => this.categoryOf(s) !== 'experience_card').map(s => Math.round(this.effectivePrice(s)));
    return amounts.filter((a: number) => a > 0);
  }

  /** A szerverrel azonos számítás: tételenként kerekített nettó és ÁFA. */
  get invoiceTotals(): { net: number; vat: number; gross: number } {
    const rate = /^\d+$/.test(this.invoiceVatRate) ? Number(this.invoiceVatRate) : 0;
    let net = 0, gross = 0;
    for (const g of this.invoiceLineAmounts) {
      net += rate > 0 ? Math.round(g / (1 + rate / 100)) : g;
      gross += g;
    }
    return { net, vat: gross - net, gross };
  }

  get invoiceVatLabel(): string {
    return ({ AAM: 'AAM (alanyi adómentes)', TAM: 'TAM (tárgyi adómentes)' } as Record<string, string>)[this.invoiceVatRate] ?? `${this.invoiceVatRate}%`;
  }

  confirmSale(): void {
    if (!this.canConfirm) return;
    if (this.invoiceActive) {
      this.invoiceConfirmOpen = true;
      return;
    }
    this.executeSale();
  }

  closeInvoiceConfirm(): void {
    this.invoiceConfirmOpen = false;
  }

  confirmInvoiceAndSell(): void {
    this.invoiceConfirmOpen = false;
    this.executeSale();
  }

  private buyerPayload(): InvoiceBuyer {
    const b = this.invoiceBuyer;
    return {
      name: b.name.trim(),
      zip: b.zip.trim(),
      city: b.city.trim(),
      address: b.address.trim(),
      tax_number: b.tax_number.trim() || null,
      email: b.email.trim() || null
    };
  }

  private issueInvoice(reservationId: number, buyer: InvoiceBuyer): void {
    this.pendingInvoice = { reservationId, buyer };
    this.invoiceBusy = true;
    this.invoiceError = null;

    this.admin.posIssueInvoice(reservationId, buyer).subscribe({
      next: (invoice) => {
        this.invoiceBusy = false;
        this.soldInvoice = invoice;
        this.pendingInvoice = null;
      },
      error: (err) => {
        this.invoiceBusy = false;
        const errors = err?.error?.errors;
        this.invoiceError = (errors && (Object.values(errors) as string[][])[0]?.[0]) || err?.error?.message || 'A számla kiállítása sikertelen.';
      }
    });
  }

  retryInvoice(): void {
    if (this.pendingInvoice && !this.invoiceBusy) {
      this.issueInvoice(this.pendingInvoice.reservationId, this.pendingInvoice.buyer);
    }
  }

  openInvoicePdf(): void {
    if (!this.soldInvoice) return;
    this.admin.posInvoicePdf(this.soldInvoice.id).subscribe({
      next: (blob) => window.open(URL.createObjectURL(blob), '_blank'),
      error: () => (this.invoiceError = 'A számla PDF letöltése sikertelen.')
    });
  }

  private executeSale(): void {
    if (!this.canConfirm || !this.showtime) return;

    // 0 Ft-os eladásnál nincs fizetési mód választás: a nyugta készpénzesként kerül rögzítésre
    const method: PaymentMethod = this.paymentMethod ?? 'cash';
    this.processing = true;
    this.error = null;
    const buyer = this.invoiceActive ? this.buyerPayload() : null;

    const request$ = this.mode === 'reservations' && this.selectedReservation
      ? this.admin.posPayReservation(this.selectedReservation.id, method)
      : this.admin.posSell({
          showtime_id: this.showtime.id,
          seatKeys: this.selectedSeats.map(s => s.key),
          payment_method: method,
          customer_name: this.customerName.trim() || undefined,
          seat_categories: this.seatCategoriesPayload()
        });

    request$.subscribe({
      next: (response) => {
        this.soldTickets = response.tickets;
        this.printOpen = true;
        this.processing = false;
        this.selectedSeats = [];
        this.selectedReservation = null;
        this.customerName = '';
        this.invoiceRequested = false;
        this.invoiceBuyer = { name: '', zip: '', city: '', address: '', tax_number: '', email: '' };
        this.soldInvoice = null;
        this.invoiceError = null;
        this.pendingInvoice = null;
        this.resetPayment();
        this.refresh();
        if (buyer) {
          this.issueInvoice(response.reservation.id, buyer);
        }
      },
      error: (err) => {
        this.processing = false;
        this.error = err?.error?.message || 'A jegyeladás sikertelen. Próbálja újra.';
        if (err?.status === 409 || err?.status === 422) {
          this.selectedSeats = [];
          this.refresh();
        }
      }
    });
  }

  closePrint(): void {
    this.printOpen = false;
  }

  // ---- Foglalás fizetés nélkül ----

  openReserve(): void {
    this.reserveOpen = true;
    this.heldNotice = null;
    this.error = null;
  }

  closeReserve(): void {
    this.reserveOpen = false;
    this.error = null;
  }

  get canHold(): boolean {
    const emailOk = !this.holdEmail.trim() || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.holdEmail.trim());
    return this.mode === 'seats' && this.selectedSeats.length > 0 && !!this.holdName.trim() && !!this.holdPhone.trim() && emailOk && !this.processing;
  }

  confirmHold(): void {
    if (!this.canHold || !this.showtime) return;

    this.processing = true;
    this.error = null;

    this.admin.posHold({
      showtime_id: this.showtime.id,
      seatKeys: this.selectedSeats.map(s => s.key),
      customer_name: this.holdName.trim(),
      customer_phone: this.holdPhone.trim(),
      customer_email: this.holdEmail.trim() || undefined,
      seat_categories: this.seatCategoriesPayload()
    }).subscribe({
      next: (response) => {
        const seats = this.selectedSeats.map(s => s.key).join(', ');
        this.heldNotice = `📌 Foglalás rögzítve (#${response.reservation.id}): ${this.holdName.trim()}, ${seats}.`
          + (this.holdEmail.trim() ? ' A visszaigazoló e-mailt elküldtük.' : '');
        this.processing = false;
        this.selectedSeats = [];
        this.seatCategories = {};
        this.reserveOpen = false;
        this.holdName = '';
        this.holdPhone = '';
        this.holdEmail = '';
        this.resetPayment();
        this.refresh();
      },
      error: (err) => {
        this.processing = false;
        const errors = err?.error?.errors;
        this.error = (errors && Object.values(errors as Record<string, string[]>)[0]?.[0]) || err?.error?.message || 'A foglalás rögzítése sikertelen. Próbálja újra.';
        if (err?.status === 409 || err?.status === 422) {
          this.selectedSeats = this.selectedSeats.filter(sel => this.findSeat(sel.key)?.status === 'available');
          this.refresh();
        }
      }
    });
  }

  newSale(): void {
    this.soldTickets = [];
    this.printOpen = false;
    this.resetSale();
    this.refresh();
  }

  private resetPayment(): void {
    this.paymentMethod = null;
    this.cashGiven = null;
    this.error = null;
  }

  private resetSale(): void {
    this.cancelEdit();
    this.deleteConfirmOpen = false;
    this.selectedSeats = [];
    this.selectedReservation = null;
    this.customerName = '';
    this.reserveOpen = false;
    this.heldNotice = null;
    this.soldTickets = [];
    this.invoiceRequested = false;
    this.invoiceConfirmOpen = false;
    this.invoiceBuyer = { name: '', zip: '', city: '', address: '', tax_number: '', email: '' };
    this.soldInvoice = null;
    this.invoiceError = null;
    this.pendingInvoice = null;
    this.resetPayment();
  }

  // ---- Megjelenítés ----

  private dayKey(d: Date): string {
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }

  private dayLabel(key: string): string {
    const names = ['Vasárnap', 'Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat'];
    const [y, m, d] = key.split('-').map(Number);
    const date = new Date(y, m - 1, d);
    const today = this.dayKey(new Date());
    const dayName = key === today ? 'Ma' : names[date.getDay()];
    return `${dayName} ${String(m).padStart(2, '0')}.${String(d).padStart(2, '0')}.`;
  }
}
