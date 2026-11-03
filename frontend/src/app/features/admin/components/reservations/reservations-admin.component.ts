import { Component, OnInit } from '@angular/core';
import { CommonModule, formatDate } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService } from '../../../../services/admin.service';
import { escapeHtml } from '../../../../core/util/html';
import { paymentLabel, printTickets } from '../ticket-print/ticket-print.component';

type StatusFilter = 'all' | 'PENDING' | 'PAID' | 'CLOSED';

interface ShowtimeView {
  id: number;
  raw: any;
  startsAt: string;
  title: string;
  auditorium: string;
  reservations: any[];
  pending: number;
  paid: number;
  seats: number;
  paidAmount: number;
}

interface DayView {
  key: string;
  label: string;
  showtimes: ShowtimeView[];
  reservations: number;
}

const WEEKDAYS = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];
const STATUS_ORDER: Record<string, number> = { PENDING: 0, PAID: 1 };

@Component({
  selector: 'app-reservations-admin',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './reservations-admin.component.html',
  styleUrls: ['./reservations-admin.component.css']
})
export class ReservationsAdminComponent implements OnInit {
  private groups: any[] = [];
  days: DayView[] = [];
  stats = { reservations: 0, pending: 0, paid: 0, paidAmount: 0, seats: 0 };

  loading = true;
  error = '';
  notice = '';

  query = '';
  statusFilter: StatusFilter = 'all';
  expanded = new Set<number>();
  printing = new Set<number>();

  cashConfirmOpen = false;
  pendingCashReservation: any | null = null;
  cashPaymentError: string | null = null;
  deleteConfirmOpen = false;
  pendingDeleteReservation: any | null = null;
  deleteError: string | null = null;
  deleting = false;

  paymentLabel = paymentLabel;

  constructor(
    private adminService: AdminService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.loadReservations();
  }

  loadReservations(): void {
    this.loading = true;
    this.error = '';
    this.adminService.getGroupedReservations().subscribe({
      next: (groups) => {
        this.groups = groups;
        this.loading = false;
        this.applyFilters();
      },
      error: (err) => {
        this.error = 'Hiba történt a foglalások betöltése során';
        this.loading = false;
        console.error('Error loading reservations:', err);
      }
    });
  }

  // ---- Szűrés, keresés, csoportosítás ----

  get filtersActive(): boolean {
    return this.query.trim() !== '' || this.statusFilter !== 'all';
  }

  clearFilters(): void {
    this.query = '';
    this.statusFilter = 'all';
    this.applyFilters();
  }

  /** Ékezet- és kisbetű-független összehasonlításhoz. */
  private norm(value: unknown): string {
    return String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  }

  private groupText(g: any): string {
    const d = g.starts_at ? new Date(g.starts_at) : null;
    const dates = d
      ? `${formatDate(d, 'yyyy.MM.dd. HH:mm', 'en-US')} ${formatDate(d, 'yyyy-MM-dd', 'en-US')} ${formatDate(d, 'MM.dd.', 'en-US')} ${WEEKDAYS[d.getDay()]}`
      : '';
    return this.norm(`${g.movie?.title ?? ''} ${g.auditorium?.name ?? ''} ${dates}`);
  }

  private reservationText(r: any): string {
    const seats = (r.items ?? []).map((i: any) => `${i.section ?? ''} ${i.row_label}/${i.seat_number}`).join(' ');
    return this.norm(
      `#${r.id} ${r.customer_name ?? ''} ${r.customer_email ?? ''} ${r.customer_phone ?? ''} ${this.sellerName(r)} ` +
      `${this.statusLabel(r.status)} ${r.payment_provider ? paymentLabel(r.payment_provider) : ''} ${seats}`
    );
  }

  /** Minden beírt szónak szerepelnie kell az előadás vagy a foglalás adataiban (előadás, név, telefon, e-mail, eladó, azonosító...). */
  private matches(tokens: string[], groupText: string, r: any): boolean {
    if (!tokens.length) return true;
    const text = this.reservationText(r);
    const phone = String(r.customer_phone ?? '').replace(/\D/g, '');
    return tokens.every(t => {
      if (groupText.includes(t) || text.includes(t)) return true;
      const digits = t.replace(/\D/g, '');
      return digits.length >= 3 && phone.includes(digits);
    });
  }

  applyFilters(): void {
    const tokens = this.norm(this.query).split(/\s+/).filter(Boolean);
    const status = this.statusFilter;
    const statusOk = (r: any) =>
      status === 'all' || (status === 'CLOSED' ? r.status === 'CANCELLED' || r.status === 'EXPIRED' : r.status === status);

    const views: ShowtimeView[] = [];
    for (const g of this.groups) {
      const gText = this.groupText(g);
      const rows = (g.reservations ?? [])
        .filter(statusOk)
        .filter((r: any) => this.matches(tokens, gText, r))
        .sort((a: any, b: any) => (STATUS_ORDER[a.status] ?? 2) - (STATUS_ORDER[b.status] ?? 2) || b.id - a.id);
      if (!rows.length) continue;

      const paidRows = rows.filter((r: any) => r.status === 'PAID');
      views.push({
        id: g.id,
        raw: g,
        startsAt: g.starts_at,
        title: g.movie?.title || 'Ismeretlen előadás',
        auditorium: g.auditorium?.name || '',
        reservations: rows,
        pending: rows.filter((r: any) => r.status === 'PENDING').length,
        paid: paidRows.length,
        seats: rows.filter((r: any) => r.status === 'PENDING' || r.status === 'PAID').reduce((s: number, r: any) => s + (r.items?.length ?? 0), 0),
        paidAmount: paidRows.reduce((s: number, r: any) => s + Number(r.total_amount ?? 0), 0)
      });
    }
    views.sort((a, b) => new Date(a.startsAt).getTime() - new Date(b.startsAt).getTime());

    const todayKey = formatDate(new Date(), 'yyyy-MM-dd', 'en-US');
    const byDay = new Map<string, DayView>();
    for (const v of views) {
      const d = new Date(v.startsAt);
      const key = formatDate(d, 'yyyy-MM-dd', 'en-US');
      if (!byDay.has(key)) {
        byDay.set(key, {
          key,
          label: `${formatDate(d, 'yyyy. MM. dd.', 'en-US')} ${WEEKDAYS[d.getDay()]}${key === todayKey ? ' (ma)' : ''}`,
          showtimes: [],
          reservations: 0
        });
      }
      const day = byDay.get(key)!;
      day.showtimes.push(v);
      day.reservations += v.reservations.length;
    }
    this.days = [...byDay.values()];

    this.stats = views.reduce(
      (s, v) => ({
        reservations: s.reservations + v.reservations.length,
        pending: s.pending + v.pending,
        paid: s.paid + v.paid,
        paidAmount: s.paidAmount + v.paidAmount,
        seats: s.seats + v.seats
      }),
      { reservations: 0, pending: 0, paid: 0, paidAmount: 0, seats: 0 }
    );

    // keresésnél a találatot tartalmazó előadások kinyílnak
    if (tokens.length) {
      views.forEach(v => this.expanded.add(v.id));
    }
  }

  // ---- Megjelenítés ----

  toggleShowtime(view: ShowtimeView): void {
    if (this.expanded.has(view.id)) this.expanded.delete(view.id);
    else this.expanded.add(view.id);
  }

  expandAll(open: boolean): void {
    this.expanded.clear();
    if (open) this.days.forEach(d => d.showtimes.forEach(v => this.expanded.add(v.id)));
  }

  statusLabel(status: string): string {
    return ({ PAID: 'Fizetve', PENDING: 'Függő', CANCELLED: 'Törölve', EXPIRED: 'Lejárt' } as Record<string, string>)[status] ?? status;
  }

  sellerName(r: any): string {
    if (r.handled_by_admin?.name) return r.handled_by_admin.name;
    return r.payment_provider === 'stripe' || r.payment_provider === 'simplepay' ? 'Online' : '';
  }

  /** A foglalt székek szintenként csoportosítva, pl. „Földszinti emelvény: 1/6, 1/7”. */
  seatLines(r: any): Array<{ section: string; seats: string }> {
    const items = [...(r.items ?? [])].sort((a: any, b: any) =>
      String(a.section ?? '').localeCompare(String(b.section ?? ''), 'hu') ||
      String(a.row_label).localeCompare(String(b.row_label), 'hu', { numeric: true }) ||
      Number(a.seat_number) - Number(b.seat_number));
    const bySection = new Map<string, string[]>();
    for (const i of items) {
      const key = i.section ?? '';
      bySection.set(key, [...(bySection.get(key) ?? []), `${i.row_label}/${i.seat_number}`]);
    }
    return [...bySection.entries()].map(([section, seats]) => ({ section, seats: seats.join(', ') }));
  }

  ft(n: number | string): string {
    return `${Math.round(Number(n) || 0).toLocaleString('hu-HU')}\u00A0Ft`;
  }

  // ---- Fizetés, törlés ----

  startCardPayment(reservation: any): void {
    if (!reservation || reservation.status !== 'PENDING') {
      return;
    }

    this.adminService.startReservationPayment(reservation.id, 'card').subscribe({
      next: (result) => {
        if (result?.checkout_url) {
          window.open(result.checkout_url, '_blank');
        }
      },
      error: (err) => {
        console.error('Error starting card payment:', err);
      }
    });
  }

  markPaidCash(reservation: any): void {
    if (!reservation || reservation.status !== 'PENDING') {
      return;
    }
    this.pendingCashReservation = reservation;
    this.cashPaymentError = null;
    this.cashConfirmOpen = true;
  }

  confirmCashPayment(): void {
    if (!this.pendingCashReservation) {
      this.cashConfirmOpen = false;
      return;
    }

    const reservation = this.pendingCashReservation;

    this.adminService.startReservationPayment(reservation.id, 'cash').subscribe({
      next: (result) => {
        if (result?.reservation) {
          reservation.status = result.reservation.status;
          reservation.payment_provider = result.reservation.payment_provider;
          reservation.payment_ref = result.reservation.payment_ref;
        } else {
          reservation.status = 'PAID';
          reservation.payment_provider = 'cash';
        }
        this.closeCashConfirm();
        this.applyFilters();
      },
      error: (err) => {
        const message = err?.error?.message || 'Készpénzes fizetés sikertelen.';
        this.cashPaymentError = message;
        console.error('Error marking cash payment:', err);
      }
    });
  }

  closeCashConfirm(): void {
    this.cashConfirmOpen = false;
    this.pendingCashReservation = null;
    this.cashPaymentError = null;
  }

  askDelete(reservation: any): void {
    if (!reservation || reservation.status !== 'PENDING') {
      return;
    }
    this.pendingDeleteReservation = reservation;
    this.deleteError = null;
    this.deleteConfirmOpen = true;
  }

  confirmDelete(): void {
    const reservation = this.pendingDeleteReservation;
    if (!reservation || this.deleting) {
      return;
    }

    this.deleting = true;
    this.adminService.deleteReservation(reservation.id).subscribe({
      next: (result) => {
        reservation.status = result?.status ?? 'CANCELLED';
        this.deleting = false;
        this.closeDeleteConfirm();
        this.applyFilters();
      },
      error: (err) => {
        this.deleteError = err?.error?.message || 'A foglalás törlése sikertelen.';
        this.deleting = false;
        console.error('Error deleting reservation:', err);
      }
    });
  }

  closeDeleteConfirm(): void {
    if (this.deleting) {
      return;
    }
    this.deleteConfirmOpen = false;
    this.pendingDeleteReservation = null;
    this.deleteError = null;
  }

  // ---- Nyomtatás ----

  /** A foglalás kiállított jegyeinek közvetlen nyomtatása. */
  printReservationTickets(reservation: any): void {
    if (!reservation || reservation.status !== 'PAID' || this.printing.has(reservation.id)) {
      return;
    }

    this.notice = '';
    this.printing.add(reservation.id);
    this.adminService.getReservationTickets(reservation.id).subscribe({
      next: async (tickets) => {
        if (!tickets.length) {
          this.notice = `A(z) #${reservation.id} foglaláshoz nincs nyomtatható jegy (sztornózva?).`;
          this.printing.delete(reservation.id);
          return;
        }
        await printTickets(tickets, numbers =>
          this.adminService.logClientEvent('ticket.print', `Jegynyomtatás: ${numbers.join(', ')}`, { tickets: numbers })
        );
        this.printing.delete(reservation.id);
      },
      error: () => {
        this.notice = 'A jegyek betöltése sikertelen.';
        this.printing.delete(reservation.id);
      }
    });
  }

  printReceipt(reservation: any, showtime: any): void {
    if (!reservation || reservation.status !== 'PAID') {
      return;
    }

    const logoUrl = `${window.location.origin}/assets/vigado_logo_beige.png`;
    const createdAt = reservation.created_at ? new Date(reservation.created_at).toLocaleString('hu-HU') : '';
    const showtimeDate = showtime?.starts_at ? new Date(showtime.starts_at).toLocaleString('hu-HU') : '';
    const seats = (reservation.items || []).map((item: any) => `${item.section ? item.section + ' ' : ''}${item.row_label}-${item.seat_number}`).join(', ');
    const totalAmount = reservation.total_amount ?? 0;

    const receiptHtml = `
      <!DOCTYPE html>
      <html lang="hu">
      <head>
        <meta charset="utf-8" />
        <title>Befizetési igazolás</title>
        <style>
          body { font-family: Arial, sans-serif; padding: 24px; color: #2c1810; }
          .header { display: flex; align-items: center; gap: 16px; margin-bottom: 20px; }
          .logo { max-width: 160px; }
          h1 { font-size: 20px; margin: 0; }
          .section { margin-bottom: 16px; }
          .label { color: #5c3d2e; font-weight: 600; margin-bottom: 4px; }
          .value { font-size: 14px; }
          .total { font-size: 18px; font-weight: 700; margin-top: 12px; }
          .divider { height: 1px; background: #d4a574; margin: 16px 0; }
          @media print { .no-print { display: none; } }
        </style>
      </head>
      <body>
        <div class="header">
          <img class="logo" src="${logoUrl}" alt="Vigadó Jegy" />
          <div>
            <h1>Befizetési igazolás</h1>
            <div class="value">Foglalás #${reservation.id}</div>
          </div>
        </div>

        <div class="section">
          <div class="label">Ügyfél</div>
          <div class="value">${escapeHtml(reservation.customer_name || 'N/A')} • ${escapeHtml(reservation.customer_email || 'N/A')} • ${escapeHtml(reservation.customer_phone || 'N/A')}</div>
        </div>

        <div class="section">
          <div class="label">Előadás</div>
          <div class="value">${escapeHtml(showtime?.movie?.title || 'N/A')}</div>
          <div class="value">${escapeHtml(showtimeDate)} • ${escapeHtml(showtime?.auditorium?.name || 'N/A')}</div>
        </div>

        <div class="section">
          <div class="label">Ülések</div>
          <div class="value">${escapeHtml(seats || 'N/A')}</div>
        </div>

        <div class="section">
          <div class="label">Fizetés</div>
          <div class="value">${escapeHtml(reservation.payment_provider ? paymentLabel(reservation.payment_provider) : '—')} • ${escapeHtml(createdAt)}</div>
        </div>

        <div class="divider"></div>

        <div class="total">Összesen: ${escapeHtml(totalAmount)} Ft</div>

        <div class="no-print" style="margin-top: 20px;">
          <button onclick="window.print()">Nyomtatás / PDF</button>
        </div>
      </body>
      </html>
    `;

    const printWindow = window.open('', '_blank', 'width=800,height=700');
    if (!printWindow) {
      return;
    }

    printWindow.document.open();
    printWindow.document.write(receiptHtml);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
  }
}
