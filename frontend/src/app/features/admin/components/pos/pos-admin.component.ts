import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { ApiService, Seat, Showtime, ShowtimeDetail } from '../../../../core/api/api.service';
import { AdminService, PaymentMethod, Ticket } from '../../../../services/admin.service';
import { TicketPrintComponent } from '../ticket-print/ticket-print.component';

interface DayTab {
  key: string;
  label: string;
  showtimes: Showtime[];
}

type SaleMode = 'seats' | 'reservations';

@Component({
  selector: 'app-pos-admin',
  standalone: true,
  imports: [CommonModule, FormsModule, TicketPrintComponent],
  templateUrl: './pos-admin.component.html',
  styleUrls: ['./pos-admin.component.css']
})
export class PosAdminComponent implements OnInit {
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
  selectedSeats: Seat[] = [];
  pendingReservations: any[] = [];
  selectedReservation: any | null = null;

  paymentMethod: PaymentMethod | null = null;
  cashGiven: number | null = null;
  customerName = '';

  processing = false;
  error: string | null = null;

  soldTickets: Ticket[] = [];
  printOpen = false;

  constructor(
    private api: ApiService,
    private admin: AdminService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.loadShowtimes();
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
        if (this.selectedReservation && !this.pendingReservations.some(r => r.id === this.selectedReservation.id)) {
          this.selectedReservation = null;
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

  isAisle(seat: Seat): boolean {
    return seat.type === 'aisle';
  }

  seatClass(seat: Seat): string {
    if (this.isSelected(seat)) return 'selected';
    if (this.reservationSeatKeys.has(seat.key)) return 'reserved-pick';
    return seat.status;
  }

  isSelected(seat: Seat): boolean {
    return this.selectedSeats.some(s => s.key === seat.key);
  }

  toggleSeat(seat: Seat): void {
    if (this.mode !== 'seats' || this.isAisle(seat) || !seat.bookable || seat.status !== 'available') return;

    this.selectedSeats = this.isSelected(seat)
      ? this.selectedSeats.filter(s => s.key !== seat.key)
      : [...this.selectedSeats, seat];
    this.error = null;
    this.soldTickets = [];
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
    this.selectedSeats = [];
    this.selectedReservation = null;
    this.resetPayment();
  }

  selectReservation(reservation: any): void {
    this.selectedReservation = this.selectedReservation?.id === reservation.id ? null : reservation;
    this.soldTickets = [];
    this.resetPayment();
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
    return this.selectedSeats.reduce((sum, seat) => sum + seat.price, 0);
  }

  get hasSelection(): boolean {
    return this.mode === 'seats' ? this.selectedSeats.length > 0 : !!this.selectedReservation;
  }

  get change(): number | null {
    if (this.paymentMethod !== 'cash' || this.cashGiven === null || this.cashGiven === undefined) return null;
    return this.cashGiven - this.total;
  }

  get canConfirm(): boolean {
    if (!this.hasSelection || !this.paymentMethod || this.processing) return false;
    if (this.paymentMethod === 'cash' && this.cashGiven !== null && this.cashGiven !== undefined && this.cashGiven < this.total) {
      return false;
    }
    return true;
  }

  setPayment(method: PaymentMethod): void {
    this.paymentMethod = method;
    this.cashGiven = null;
    this.error = null;
  }

  confirmSale(): void {
    if (!this.canConfirm || !this.showtime || !this.paymentMethod) return;

    this.processing = true;
    this.error = null;

    const request$ = this.mode === 'reservations' && this.selectedReservation
      ? this.admin.posPayReservation(this.selectedReservation.id, this.paymentMethod)
      : this.admin.posSell({
          showtime_id: this.showtime.id,
          seatKeys: this.selectedSeats.map(s => s.key),
          payment_method: this.paymentMethod,
          customer_name: this.customerName.trim() || undefined
        });

    request$.subscribe({
      next: (response) => {
        this.soldTickets = response.tickets;
        this.printOpen = true;
        this.processing = false;
        this.selectedSeats = [];
        this.selectedReservation = null;
        this.customerName = '';
        this.resetPayment();
        this.refresh();
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
    this.selectedSeats = [];
    this.selectedReservation = null;
    this.customerName = '';
    this.soldTickets = [];
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
