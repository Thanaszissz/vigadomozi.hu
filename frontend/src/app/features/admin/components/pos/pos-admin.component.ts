import { Component, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Observable, Subject, Subscription, of } from 'rxjs';
import { catchError, debounceTime, distinctUntilChanged, switchMap } from 'rxjs/operators';
import { ApiService, Seat, Showtime, ShowtimeDetail } from '../../../../core/api/api.service';
import { AdminService, InvoiceBuyer, PaymentMethod, PaymentPart, PosInvoice, SPLIT_METHODS, SplitMethod, Ticket, TicketCategory, categoryLabel } from '../../../../services/admin.service';
import { TicketPrintComponent, preloadTicketBackground, printTickets, ticketPaymentLabel } from '../ticket-print/ticket-print.component';
import { AuthService } from '../../../../services/auth.service';
import { PosServicesComponent } from './pos-services.component';
import { rowLabelOf, seatNumberOf, seatLabel, sectionBlocks, newlyIsolatedSeats } from '../../../../core/util/seat';

type SaleMode = 'seats' | 'reservations' | 'void';

/** A kosár egy előadásra kiválasztott székei (a helyszíni eladás több előadásra is szólhat egy tranzakcióban). */
interface CartSeat { key: string; price: number; category: TicketCategory }
interface CartLine { showtimeId: number; title: string; startsAt: string; auditorium: string; seats: CartSeat[] }
/** Egyéb szolgáltatás a kosárban (az árat a szerver az adatbázisból veszi, itt csak megjelenítésre szolgál). */
interface ServiceCartLine { serviceId: number; name: string; price: number; quantity: number }

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

  showtimes: Showtime[] = [];
  loadingShowtimes = false;
  showtimesError: string | null = null;

  showtime: ShowtimeDetail | null = null;
  private requestedShowtimeId = 0;
  loadingShowtime = false;

  mode: SaleMode = 'seats';
  area: 'cinema' | 'services' = 'cinema';
  selectedSeats: Seat[] = [];
  cart: CartLine[] = [];
  serviceCart: ServiceCartLine[] = [];
  readonly rowLabelOf = rowLabelOf;
  readonly seatNumberOf = seatNumberOf;
  readonly seatLabel = seatLabel;

  private gapCacheKey = '';
  private gapMapRef: unknown = null;
  private gapCache: string[] = [];

  /** Új eladás / foglalás esetén a kijelölés miatt egyedül maradó üres székek (figyelmeztetés, nem tiltás). */
  get isolatedSeatKeys(): string[] {
    const seatMap = this.showtime?.seatMap;
    const keys = this.mode === 'seats' ? [...this.selectedSeats.map(s => s.key), ...this.cartKeysFor(this.showtime?.id)] : [];
    const cacheKey = keys.join(',');
    if (cacheKey !== this.gapCacheKey || seatMap !== this.gapMapRef) {
      this.gapCacheKey = cacheKey;
      this.gapMapRef = seatMap;
      this.gapCache = seatMap ? newlyIsolatedSeats(seatMap.seats, seatMap.layout?.aisles, keys) : [];
    }
    return this.gapCache;
  }

  get isolatedSeatLabels(): string {
    return this.isolatedSeatKeys.map(key => seatLabel(key)).join(', ');
  }

  // Székenkénti jegykategória (normál / Dolgozó jegy / Élménykártya) és a Dolgozó jegy beállított ára
  seatCategories: Record<string, TicketCategory> = {};
  staffPrice = 0;
  categoryLabel = categoryLabel;
  pendingReservations: any[] = [];
  selectedReservation: any | null = null;
  payCount: number | null = null;
  paymentMethod: PaymentMethod | null = null;
  cashGiven: number | null = null;
  // Részfizetés: a fizetendő összeg több fizetési mód között osztva
  splitMode = false;
  splitMethods = SPLIT_METHODS;
  ticketPaymentLabel = ticketPaymentLabel;
  splitAmounts: Partial<Record<SplitMethod, number | null>> = {};
  customerName = '';

  processing = false;
  error: string | null = null;

  // Helyszíni foglalás (fizetés nélkül)
  reserveOpen = false;
  holdName = '';
  holdPhone = '';
  holdEmail = '';
  holdZip = '';  heldNotice: string | null = null;

  // Név szerinti vásárló-javaslatok a foglalási űrlapon
  customerSuggestions: Array<{ id: number; name: string; email: string | null; phone: string | null; zip: string | null }> = [];
  private nameSearch$ = new Subject<string>();
  private nameSearchSub?: Subscription;

  soldTickets: Ticket[] = [];
  printOpen = false;
  // eladás után a jegyek azonnal, előnézet nélkül a nyomtatóra mennek (a beállítás a böngészőben megmarad)
  autoPrint = localStorage.getItem('pos.autoPrint') !== '0';
  // előnyomott jegyalap: a jegyalap (háttér) nem nyomtatódik, csak a szöveg (a beállítás a böngészőben megmarad)
  preprintedStock = localStorage.getItem('pos.preprintedStock') === '1';

  // ÁFÁS számla (Számlázz.hu)
  invoicingEnabled = false;
  invoiceVatRate = '5';
  invoiceRequested = false;
  invoiceConfirmOpen = false;
  invoiceBuyer = { name: '', zip: '', city: '', address: '', tax_number: '', email: '' };
  pendingInvoice: { reservationIds: number[]; buyer: InvoiceBuyer } | null = null;
  invoiceBusy = false;
  invoiceError: string | null = null;
  soldInvoices: PosInvoice[] = [];

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
    preloadTicketBackground();
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

  pickCustomer(c: { name: string; email: string | null; phone: string | null; zip: string | null }, event: Event): void {
    event.preventDefault();
    this.holdName = c.name;
    this.holdPhone = c.phone ?? '';
    this.holdEmail = c.email ?? '';
    this.holdZip = c.zip ?? '';
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
          .filter(s => s.status === 'active' && !s.is_external && new Date(s.starts_at).getTime() >= limit)
          .sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime());

        this.showtimes = open;
        this.loadingShowtimes = false;
      },
      error: () => {
        this.showtimesError = 'Az előadások betöltése sikertelen.';
        this.loadingShowtimes = false;
      }
    });
  }

  /** Az előadás napja, pl. „2026. 11. 03. Kedd (ma)” */
  showtimeDayLabel(s: Showtime): string {
    return this.dayLabel(this.dayKey(new Date(s.starts_at)));
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

  private blocksSource: unknown = null;
  private blocksCache: Array<Array<{ name: string; rows: string[] }>> = [];

  /** A szintek megjelenítési blokkjai: az "egy sorba" jelölésű kis szintek egymás mellé kerülnek. */
  getSectionBlocks(): Array<Array<{ name: string; rows: string[] }>> {
    const source = this.showtime?.seatMap ?? null;
    if (source !== this.blocksSource) {
      this.blocksSource = source;
      this.blocksCache = source
        ? sectionBlocks(this.getSectionGroups(), source.layout?.inlineSections,
            g => Math.max(0, ...g.rows.map(row => this.getSeatsInRow(row).length)))
        : [];
    }
    return this.blocksCache;
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
    if (this.mode === 'seats' && this.isInCart(seat)) return 'selected';
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
    if (this.mode !== 'seats' || this.isAisle(seat) || !seat.bookable) return;
    // A kosárba tett szék újabb kattintásra kikerül a kosárból
    if (this.showtime && this.isInCart(seat)) {
      this.removeCartSeat(this.showtime.id, seat.key);
      return;
    }
    if (seat.status !== 'available') return;

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

  // ---- Kosár (több előadás egy tranzakcióban) ----

  private cartKeysFor(showtimeId: number | undefined): string[] {
    return this.cart.filter(l => l.showtimeId === showtimeId).flatMap(l => l.seats.map(s => s.key));
  }

  isInCart(seat: Seat): boolean {
    return !!this.showtime && this.cartKeysFor(this.showtime.id).includes(seat.key);
  }

  get cartSeatCount(): number {
    return this.cart.reduce((sum, l) => sum + l.seats.length, 0);
  }

  cartCountFor(showtimeId: number): number {
    return this.cartKeysFor(showtimeId).length;
  }

  /** Hány különböző előadásra szól a mostani eladás (a kosár és az éppen kijelölt székek együtt). */
  get saleProgramCount(): number {
    return this.saleItems().length;
  }

  cartLineTotal(line: CartLine): number {
    return line.seats.reduce((sum, s) => sum + s.price, 0);
  }

  private get cartTotal(): number {
    return this.cart.reduce((sum, l) => sum + this.cartLineTotal(l), 0);
  }

  private get cartPayable(): number {
    return this.cart.reduce((sum, l) => sum + l.seats.filter(s => s.category !== 'experience_card').reduce((a, s) => a + s.price, 0), 0);
  }

  // ---- Egyéb szolgáltatások a kosárban ----

  get serviceCartCount(): number {
    return this.serviceCart.reduce((sum, l) => sum + l.quantity, 0);
  }

  get serviceCartTotal(): number {
    return this.serviceCart.reduce((sum, l) => sum + l.quantity * l.price, 0);
  }

  get cartHasItems(): boolean {
    return this.cart.length > 0 || this.serviceCart.length > 0;
  }

  /** A kosár tételeinek száma (jegyek + szolgáltatás-nyugták) és összértéke, a fejléchez és a szolgáltatás-nézethez. */
  get cartItemCount(): number {
    return this.cartSeatCount + this.serviceCartCount;
  }

  get cartAmount(): number {
    return this.cartTotal + this.serviceCartTotal;
  }

  addServicesToCart(rows: Array<{ id: number; name: string; price: number; quantity: number }>): void {
    for (const row of rows) {
      const existing = this.serviceCart.find(l => l.serviceId === row.id);
      if (existing) {
        existing.quantity = Math.min(20, existing.quantity + row.quantity);
        existing.price = row.price;
      } else {
        this.serviceCart = [...this.serviceCart, { serviceId: row.id, name: row.name, price: row.price, quantity: Math.min(20, row.quantity) }];
      }
    }
    this.error = null;
    this.soldTickets = [];
  }

  removeServiceLine(line: ServiceCartLine): void {
    this.serviceCart = this.serviceCart.filter(l => l !== line);
    this.error = null;
  }

  openCartPayment(): void {
    this.area = 'cinema';
    this.mode = 'seats';
  }

  /** A kiválasztott székek kosárba tétele: utána másik előadás is választható, és minden egyben fizethető. */
  addToCart(): void {
    if (!this.showtime || this.mode !== 'seats' || this.selectedSeats.length === 0) return;

    const showtimeId = this.showtime.id;
    const seats: CartSeat[] = this.selectedSeats.map(s => ({ key: s.key, price: this.effectivePrice(s), category: this.categoryOf(s) }));
    const existing = this.cart.find(l => l.showtimeId === showtimeId);
    if (existing) {
      existing.seats = [...existing.seats, ...seats];
    } else {
      this.cart = [...this.cart, {
        showtimeId,
        title: this.showtime.movie.title,
        startsAt: this.showtime.starts_at,
        auditorium: this.showtime.auditorium.name,
        seats
      }];
    }
    this.selectedSeats = [];
    this.seatCategories = {};
    this.error = null;
    this.soldTickets = [];
  }

  removeCartSeat(showtimeId: number, seatKey: string): void {
    this.cart = this.cart
      .map(l => l.showtimeId === showtimeId ? { ...l, seats: l.seats.filter(s => s.key !== seatKey) } : l)
      .filter(l => l.seats.length > 0);
    this.error = null;
  }

  removeCartLine(line: CartLine): void {
    this.cart = this.cart.filter(l => l !== line);
    this.error = null;
  }

  clearCart(): void {
    this.cart = [];
    this.serviceCart = [];
    this.error = null;
  }

  /** A kosár tételei a szerver számára; az éppen kijelölt székek a saját előadásukhoz kerülnek. */
  private saleItems(): Array<{ showtime_id: number; seatKeys: string[]; seat_categories?: Record<string, TicketCategory> }> {
    const items = this.cart.map(l => {
      const picked: Record<string, TicketCategory> = {};
      for (const s of l.seats) {
        if (s.category !== 'standard') picked[s.key] = s.category;
      }
      return { showtime_id: l.showtimeId, seatKeys: l.seats.map(s => s.key), seat_categories: Object.keys(picked).length ? picked : undefined };
    });

    if (this.showtime && this.selectedSeats.length > 0) {
      const current = this.showtime.id;
      const categories = this.seatCategoriesPayload();
      const existing = items.find(i => i.showtime_id === current);
      if (existing) {
        existing.seatKeys = [...existing.seatKeys, ...this.selectedSeats.map(s => s.key)];
        existing.seat_categories = categories || existing.seat_categories ? { ...existing.seat_categories, ...categories } : undefined;
      } else {
        items.push({ showtime_id: current, seatKeys: this.selectedSeats.map(s => s.key), seat_categories: categories });
      }
    }

    return items;
  }

  // ---- Foglalások ----

  setMode(mode: SaleMode): void {
    if (this.mode === mode) return;
    this.mode = mode;
    this.cancelEdit();
    this.selectedSeats = [];
    this.selectedReservation = null;
    this.payCount = null;
    this.resetPayment();
  }

  selectReservation(reservation: any): void {
    this.selectedReservation = this.selectedReservation?.id === reservation.id ? null : reservation;
    this.payCount = null;
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
        this.heldNotice = `↩️ A(z) ${ticket.ticket_number} jegy sztornózva, a(z) ${seatLabel(ticket.seat_key)} szék újra szabad.`
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

  get reservationItems(): any[] {
    return this.selectedReservation?.items ?? [];
  }

  /** Hány jegyet fizet most a vásárló (alapból a foglalás összes jegye). */
  get payCountValue(): number {
    const n = this.reservationItems.length;
    return Math.min(Math.max(this.payCount ?? n, 1), Math.max(n, 1));
  }

  setPayCount(value: number | null): void {
    const n = Math.floor(Number(value));
    this.payCount = Number.isFinite(n) ? n : null;
    this.error = null;
  }

  adjustPayCount(delta: number): void {
    this.setPayCount(this.payCountValue + delta);
  }

  /** A most kifizetendő tételek: a foglalás első N széke (a többi foglalt marad). */
  get payItems(): any[] {
    return this.reservationItems.slice(0, this.payCountValue);
  }

  get isPartialPayment(): boolean {
    return this.mode === 'reservations' && this.payCountValue < this.reservationItems.length;
  }

  isPayItem(item: any): boolean {
    return this.payItems.includes(item);
  }

  // ---- Fizetés ----

  get total(): number {
    if (this.mode === 'reservations') {
      return this.payItems.reduce((sum: number, i: any) => sum + Number(i.price_amount), 0);
    }
    return this.selectedSeats.reduce((sum, seat) => sum + this.effectivePrice(seat), 0) + this.cartTotal + this.serviceCartTotal;
  }

  /** A fizetendő összeg: az élménykártyás jegyek értéke nem készpénzzel/kártyával fizetendő (a kártya fedezi). */
  get payable(): number {
    if (this.mode === 'reservations') {
      return this.payItems
        .filter((i: any) => i.category !== 'experience_card')
        .reduce((sum: number, i: any) => sum + Number(i.price_amount), 0);
    }
    return this.selectedSeats
      .filter(seat => this.categoryOf(seat) !== 'experience_card')
      .reduce((sum, seat) => sum + this.effectivePrice(seat), 0) + this.cartPayable + this.serviceCartTotal;
  }

  get cardCoveredAmount(): number {
    return this.total - this.payable;
  }

  get hasSelection(): boolean {
    return this.mode === 'seats' ? this.selectedSeats.length > 0 || this.cartHasItems : !!this.selectedReservation;
  }

  get change(): number | null {
    if (this.paymentMethod !== 'cash' || this.cashGiven === null || this.cashGiven === undefined) return null;
    return this.cashGiven - this.payable;
  }

  get canConfirm(): boolean {
    if (!this.hasSelection || this.processing) return false;
    // A kosaras (több tételes) eladás szerveroldali korlátja
    if (this.mode === 'seats' && this.cartHasItems && this.cartItemCount + this.selectedSeats.length > 50) return false;
    // Ha nincs fizetendő összeg (csak Dolgozó jegy / Élménykártya), a fizetési mód nem kötelező
    if (this.payable > 0 && this.splitMode) {
      if (this.splitRemaining !== 0) return false;
    } else if (this.payable > 0 && !this.paymentMethod) {
      return false;
    }
    if (this.paymentMethod === 'cash' && this.cashGiven !== null && this.cashGiven !== undefined && this.cashGiven < this.payable) {
      return false;
    }
    if (this.invoiceActive && !this.invoiceFormValid) return false;
    return true;
  }

  /** Számla csak fizetendő összeg esetén kérhető. */
  get invoiceActive(): boolean {
    // A számla csak a jegyekre készül (a szolgáltatás-nyugták nem kerülnek rá)
    return this.invoicingEnabled && this.invoiceRequested && this.invoiceLineAmounts.length > 0;
  }

  setPayment(method: PaymentMethod): void {
    this.paymentMethod = method;
    this.splitMode = false;
    this.cashGiven = null;
    this.error = null;
  }

  // ---- Részfizetés (több fizetési mód) ----

  toggleSplit(): void {
    this.splitMode = !this.splitMode;
    this.paymentMethod = null;
    this.cashGiven = null;
    this.splitAmounts = {};
    this.error = null;
  }

  splitValue(key: SplitMethod): number {
    const value = Math.round(Number(this.splitAmounts[key]));
    return Number.isFinite(value) && value > 0 ? value : 0;
  }

  get splitEntered(): number {
    return this.splitMethods.reduce((sum, m) => sum + this.splitValue(m.key), 0);
  }

  /** A még nem fedezett összeg (negatív, ha többet adtak meg a fizetendőnél). */
  get splitRemaining(): number {
    return this.payable - this.splitEntered;
  }

  get splitParts(): PaymentPart[] {
    return this.splitMethods
      .map(m => ({ method: m.key, amount: this.splitValue(m.key) }))
      .filter(p => p.amount > 0);
  }

  /** A sor összegét úgy tölti ki, hogy a többi mellett pont a fizetendő összeg jöjjön ki. */
  fillRemaining(key: SplitMethod): void {
    const others = this.splitEntered - this.splitValue(key);
    this.splitAmounts[key] = Math.max(0, this.payable - others);
    this.error = null;
  }

  splitLabel(key: string): string {
    return this.splitMethods.find(m => m.key === key)?.label ?? key;
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

  /** Az adószámot 12345678-1-42 alakra hozza (11 számjegy kötőjel nélkül, szóközökkel is elfogadott). */
  private normalizeTaxNumber(value: string): string {
    const raw = (value || '').trim();
    const digits = raw.replace(/\D/g, '');
    return digits.length === 11 ? `${digits.slice(0, 8)}-${digits[8]}-${digits.slice(9)}` : raw;
  }

  onTaxNumberChange(value: string): void {
    this.invoiceBuyer.tax_number = this.normalizeTaxNumber(value);
  }

  get invoiceFormValid(): boolean {
    return this.invoiceFormProblems.length === 0;
  }

  /** A számlázási űrlap hiányosságai, hogy a felhasználó lássa, miért nem aktív a gomb. */
  get invoiceFormProblems(): string[] {
    const b = this.invoiceBuyer;
    const problems: string[] = [];
    if (!b.name.trim()) problems.push('Vevő neve');
    if (b.zip.trim().length < 3) problems.push('Irányítószám');
    if (!b.city.trim()) problems.push('Település');
    if (!b.address.trim()) problems.push('Cím');
    if (b.tax_number.trim() && !/^\d{8}-\d-\d{2}$/.test(this.normalizeTaxNumber(b.tax_number))) {
      problems.push('Adószám (formátum: 12345678-1-42)');
    }
    if (b.email.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(b.email.trim())) problems.push('E-mail');
    return problems;
  }

  get invoiceLineAmounts(): number[] {
    // A 0 Ft-os (Dolgozó jegy) és az élménykártyás jegyek nem kerülnek a számlára
    const amounts = this.mode === 'reservations'
      ? this.payItems
          .filter((i: any) => i.category !== 'experience_card')
          .map((i: any) => Math.round(Number(i.price_amount)))
      : [...this.selectedSeats.map(s => Math.round(this.effectivePrice(s)) * (this.categoryOf(s) === 'experience_card' ? 0 : 1)),
         ...this.cart.flatMap(l => l.seats.map(s => s.category === 'experience_card' ? 0 : Math.round(s.price)))];
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
      tax_number: this.normalizeTaxNumber(b.tax_number) || null,
      email: b.email.trim() || null
    };
  }

  /** A számlák foglalásonként (előadásonként) készülnek, egymás után; hiba esetén a hátralévők újrapróbálhatók. */
  private issueInvoices(reservationIds: number[], buyer: InvoiceBuyer): void {
    const [current, ...rest] = reservationIds;
    if (current === undefined) {
      this.pendingInvoice = null;
      return;
    }

    this.pendingInvoice = { reservationIds, buyer };
    this.invoiceBusy = true;
    this.invoiceError = null;

    this.admin.posIssueInvoice(current, buyer).subscribe({
      next: (invoice) => {
        this.soldInvoices = [...this.soldInvoices, invoice];
        if (rest.length > 0) {
          this.issueInvoices(rest, buyer);
        } else {
          this.invoiceBusy = false;
          this.pendingInvoice = null;
        }
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
      this.issueInvoices(this.pendingInvoice.reservationIds, this.pendingInvoice.buyer);
    }
  }

  openInvoicePdf(invoice: PosInvoice): void {
    if (!invoice.has_pdf) return;
    this.admin.posInvoicePdf(invoice.id).subscribe({
      next: (blob) => window.open(URL.createObjectURL(blob), '_blank'),
      error: () => (this.invoiceError = 'A számla PDF letöltése sikertelen.')
    });
  }

  private executeSale(): void {
    const multi = this.mode === 'seats' && this.cartHasItems;
    if (!this.canConfirm || (!multi && !this.showtime)) return;

    // 0 Ft-os eladásnál nincs fizetési mód választás: a nyugta készpénzesként kerül rögzítésre
    const method: PaymentMethod = this.paymentMethod ?? 'cash';
    this.processing = true;
    this.error = null;
    const buyer = this.invoiceActive ? this.buyerPayload() : null;
    const partial = this.isPartialPayment;
    const remaining = this.reservationItems.length - this.payCountValue;
    const reservationId = this.selectedReservation?.id;
    const payments = this.splitMode && this.payable > 0 ? this.splitParts : undefined;
    const paymentPart = payments ? { payments } : { payment_method: method };
    let request$: Observable<{ reservation: any | null; reservations?: any[]; tickets: Ticket[] }>;
    if (this.mode === 'reservations' && this.selectedReservation) {
      request$ = this.admin.posPayReservation(this.selectedReservation.id, payments ? null : method, partial ? this.payItems.map((i: any) => i.seat_key) : undefined, payments);
    } else if (multi) {
      const items = this.saleItems();
      request$ = this.admin.posSellMulti({
        items: items.length > 0 ? items : undefined,
        services: this.serviceCart.length > 0 ? this.serviceCart.map(l => ({ service_id: l.serviceId, quantity: l.quantity })) : undefined,
        ...paymentPart,
        customer_name: this.customerName.trim() || undefined
      });
    } else {
      request$ = this.admin.posSell({
        showtime_id: this.showtime!.id,
        seatKeys: this.selectedSeats.map(s => s.key),
        ...paymentPart,
        customer_name: this.customerName.trim() || undefined,
        seat_categories: this.seatCategoriesPayload()
      });
    }

    request$.subscribe({
      next: (response) => {
        this.soldTickets = response.tickets;
        if (this.autoPrint) {
          this.printSold();
        } else {
          this.printOpen = true;
        }
        this.processing = false;
        this.selectedSeats = [];
        this.seatCategories = {};
        this.cart = [];
        this.serviceCart = [];
        this.selectedReservation = null;
        this.payCount = null;
        this.customerName = '';
        this.invoiceRequested = false;
        this.invoiceBuyer = { name: '', zip: '', city: '', address: '', tax_number: '', email: '' };
        this.soldInvoices = [];
        this.invoiceError = null;
        this.pendingInvoice = null;
        this.resetPayment();
        this.heldNotice = partial ? `📌 A(z) #${reservationId} foglalásban még ${remaining} szék foglalt marad (kifizetetlen).` : null;
        this.refresh();
        if (buyer) {
          const ids = (response.reservations?.length ? response.reservations : [response.reservation]).filter(Boolean).map((r: any) => r.id);
          this.issueInvoices(ids, buyer);
        }
      },
      error: (err) => {
        this.processing = false;
        const errors = err?.error?.errors;
        this.error = (multi && errors && (Object.values(errors) as string[][])[0]?.[0]) || err?.error?.message || 'A jegyeladás sikertelen. Próbálja újra.';
        if (err?.status === 409 || err?.status === 422) {
          // Kosárnál a kosár megmarad (a hibás tétel kivehető), a kijelölésből csak a már nem szabad székek esnek ki
          if (!multi) this.selectedSeats = [];
          this.refresh();
        }
      }
    });
  }

  closePrint(): void {
    this.printOpen = false;
  }

  /** Az eladott jegyek közvetlen nyomtatása (előnézet nélkül). */
  printSold(): void {
    void printTickets(this.soldTickets, numbers =>
      this.admin.logClientEvent('ticket.print', `Jegynyomtatás: ${numbers.join(', ')}`, { tickets: numbers }),
      !this.preprintedStock
    );
  }

  saveAutoPrint(): void {
    localStorage.setItem('pos.autoPrint', this.autoPrint ? '1' : '0');
  }

  savePreprintedStock(): void {
    localStorage.setItem('pos.preprintedStock', this.preprintedStock ? '1' : '0');
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
      customer_zip: this.holdZip.trim() || undefined,
      seat_categories: this.seatCategoriesPayload()
    }).subscribe({
      next: (response) => {
        const seats = this.selectedSeats.map(s => seatLabel(s.key)).join(', ');
        this.heldNotice = `📌 Foglalás rögzítve (#${response.reservation.id}): ${this.holdName.trim()}, ${seats}.`
          + (this.holdEmail.trim() ? ' A visszaigazoló e-mailt elküldtük.' : '');
        this.processing = false;
        this.selectedSeats = [];
        this.seatCategories = {};
        this.reserveOpen = false;
        this.holdName = '';
        this.holdPhone = '';
        this.holdEmail = '';
        this.holdZip = '';
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
    this.splitMode = false;
    this.splitAmounts = {};
    this.error = null;
  }

  private resetSale(): void {
    this.cancelEdit();
    this.deleteConfirmOpen = false;
    this.selectedSeats = [];
    this.selectedReservation = null;
    this.payCount = null;
    this.customerName = '';
    this.reserveOpen = false;
    this.heldNotice = null;
    this.soldTickets = [];
    this.invoiceRequested = false;
    this.invoiceConfirmOpen = false;
    this.invoiceBuyer = { name: '', zip: '', city: '', address: '', tax_number: '', email: '' };
    this.soldInvoices = [];
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
    const dayName = names[date.getDay()];
    return `${y}. ${String(m).padStart(2, '0')}. ${String(d).padStart(2, '0')}. ${dayName}${key === today ? ' (ma)' : ''}`;
  }
}
