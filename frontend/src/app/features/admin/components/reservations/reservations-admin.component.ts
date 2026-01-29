import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { AdminService } from '../../../../services/admin.service';

@Component({
  selector: 'app-reservations-admin',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './reservations-admin.component.html',
  styleUrls: ['./reservations-admin.component.css']
})
export class ReservationsAdminComponent implements OnInit {
  showtimeGroups: any[] = [];
  loading = true;
  error = '';
  expandedShowtimeId: number | null = null;
  cashConfirmOpen = false;
  pendingCashReservation: any | null = null;
  cashPaymentError: string | null = null;

  constructor(
    private adminService: AdminService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.loadReservations();
  }

  loadReservations(): void {
    this.loading = true;
    this.adminService.getGroupedReservations().subscribe({
      next: (groups) => {
        this.showtimeGroups = groups;
        this.loading = false;
      },
      error: (err) => {
        this.error = 'Hiba történt a foglalások betöltése során';
        this.loading = false;
        console.error('Error loading reservations:', err);
      }
    });
  }

  toggleShowtime(group: any): void {
    const showtimeId = group?.id ?? null;
    if (!showtimeId) {
      return;
    }

    this.expandedShowtimeId = this.expandedShowtimeId === showtimeId ? null : showtimeId;
  }

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

  printReceipt(reservation: any): void {
    if (!reservation || reservation.status !== 'PAID') {
      return;
    }

    const logoUrl = `${window.location.origin}/assets/vigado_logo_beige.png`;
    const createdAt = reservation.created_at ? new Date(reservation.created_at).toLocaleString('hu-HU') : '';
    const showtime = reservation.showtime;
    const showtimeDate = showtime?.starts_at ? new Date(showtime.starts_at).toLocaleString('hu-HU') : '';
    const seats = (reservation.items || []).map((item: any) => `${item.row_label}-${item.seat_number}`).join(', ');
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
          <img class="logo" src="${logoUrl}" alt="Vigadó Mozi" />
          <div>
            <h1>Befizetési igazolás</h1>
            <div class="value">Foglalás #${reservation.id}</div>
          </div>
        </div>

        <div class="section">
          <div class="label">Ügyfél</div>
          <div class="value">${reservation.customer_name || 'N/A'} • ${reservation.customer_email || 'N/A'} • ${reservation.customer_phone || 'N/A'}</div>
        </div>

        <div class="section">
          <div class="label">Előadás</div>
          <div class="value">${showtime?.movie?.title || 'N/A'}</div>
          <div class="value">${showtimeDate} • ${showtime?.auditorium?.name || 'N/A'}</div>
        </div>

        <div class="section">
          <div class="label">Ülések</div>
          <div class="value">${seats || 'N/A'}</div>
        </div>

        <div class="section">
          <div class="label">Fizetés</div>
          <div class="value">${reservation.payment_provider || '—'} • ${createdAt}</div>
        </div>

        <div class="divider"></div>

        <div class="total">Összesen: ${totalAmount} Ft</div>

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
