import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AdminService, PaymentMethod, Ticket } from '../../../../services/admin.service';
import { TicketPrintComponent } from '../ticket-print/ticket-print.component';

interface ServiceRow {
  id: number;
  name: string;
  price: number;
  quantity: number;
}

/** Egyéb szolgáltatások (Sárkányvár, Néprajzi gyűjtemények, élménykártya stb.) helyszíni értékesítése nyugtával. */
@Component({
  selector: 'app-pos-services',
  standalone: true,
  imports: [CommonModule, FormsModule, TicketPrintComponent],
  templateUrl: './pos-services.component.html',
  styleUrls: ['./pos-services.component.css']
})
export class PosServicesComponent implements OnInit {
  rows: ServiceRow[] = [];
  loading = false;
  error: string | null = null;

  paymentMethod: PaymentMethod | null = null;
  cashGiven: number | null = null;
  processing = false;

  soldTickets: Ticket[] = [];
  printOpen = false;

  todayTickets: Ticket[] = [];
  voidTarget: Ticket | null = null;
  voidReason = '';
  voidError: string | null = null;
  notice: string | null = null;

  constructor(private admin: AdminService) {}

  ngOnInit(): void {
    this.load();
    this.loadToday();
  }

  load(): void {
    this.loading = true;
    this.error = null;

    this.admin.posServices().subscribe({
      next: (data) => {
        const previous = new Map(this.rows.map(r => [r.id, r.quantity]));
        this.rows = data.services.map(s => ({ ...s, quantity: previous.get(s.id) ?? 0 }));
        this.loading = false;
      },
      error: () => {
        this.error = 'A szolgáltatások betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  loadToday(): void {
    this.admin.posServiceTickets().subscribe({
      next: (tickets) => (this.todayTickets = tickets),
      error: () => (this.todayTickets = [])
    });
  }

  change(row: ServiceRow, delta: number): void {
    row.quantity = Math.max(0, Math.min(20, row.quantity + delta));
    this.error = null;
    this.soldTickets = [];
    this.notice = null;
  }

  get selected(): ServiceRow[] {
    return this.rows.filter(r => r.quantity > 0);
  }

  get count(): number {
    return this.selected.reduce((sum, r) => sum + r.quantity, 0);
  }

  get total(): number {
    return this.selected.reduce((sum, r) => sum + r.quantity * r.price, 0);
  }

  get changeDue(): number | null {
    if (this.paymentMethod !== 'cash' || this.cashGiven === null || this.cashGiven === undefined) return null;
    return this.cashGiven - this.total;
  }

  get canConfirm(): boolean {
    if (this.count === 0 || this.processing || this.count > 50) return false;
    // 0 Ft-os eladásnál (pl. nyereményjegy) nincs fizetendő összeg, a fizetési mód nem kötelező
    if (this.total > 0 && !this.paymentMethod) return false;
    if (this.paymentMethod === 'cash' && this.cashGiven !== null && this.cashGiven !== undefined && this.cashGiven < this.total) return false;
    return true;
  }

  setPayment(method: PaymentMethod): void {
    this.paymentMethod = method;
    this.cashGiven = null;
    this.error = null;
  }

  confirm(): void {
    if (!this.canConfirm) return;

    this.processing = true;
    this.error = null;

    this.admin.posSellServices({
      items: this.selected.map(r => ({ service_id: r.id, quantity: r.quantity })),
      payment_method: this.paymentMethod ?? 'cash'
    }).subscribe({
      next: (response) => {
        this.processing = false;
        this.soldTickets = response.tickets;
        this.printOpen = true;
        this.rows.forEach(r => (r.quantity = 0));
        this.paymentMethod = null;
        this.cashGiven = null;
        this.loadToday();
      },
      error: (err) => {
        this.processing = false;
        const errors = err?.error?.errors;
        this.error = (errors && (Object.values(errors) as string[][])[0]?.[0]) || err?.error?.message || 'Az eladás sikertelen. Próbálja újra.';
        if (err?.status === 422) this.load();
      }
    });
  }

  newSale(): void {
    this.soldTickets = [];
    this.printOpen = false;
    this.notice = null;
  }

  closePrint(): void {
    this.printOpen = false;
  }

  // ---- Sztornó ----

  askVoid(ticket: Ticket): void {
    this.voidTarget = ticket;
    this.voidReason = '';
    this.voidError = null;
  }

  closeVoid(): void {
    if (!this.processing) this.voidTarget = null;
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
        this.notice = `↩️ A(z) ${ticket.ticket_number} nyugta sztornózva.`
          + (result.nav_correction_needed ? ' Figyelem: erre a napra már volt NAV-beküldés, ezért helyesbítést kell beküldeni.' : '');
        this.soldTickets = this.soldTickets.filter(t => t.id !== ticket.id);
        this.loadToday();
      },
      error: (err) => {
        this.processing = false;
        this.voidError = err?.error?.message || 'A sztornózás sikertelen. Próbálja újra.';
        if (err?.status === 409) this.loadToday();
      }
    });
  }
}
