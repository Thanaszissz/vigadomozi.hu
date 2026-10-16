import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { DateInputComponent } from '../../../../shared/date-time-inputs.component';
import { Router } from '@angular/router';
import { AdminService, Ticket, TicketPage, categoryLabel } from '../../../../services/admin.service';
import { TicketPrintComponent, paymentLabel } from '../ticket-print/ticket-print.component';

@Component({
  selector: 'app-tickets-admin',
  standalone: true,
  imports: [CommonModule, FormsModule, DateInputComponent, TicketPrintComponent],
  templateUrl: './tickets-admin.component.html',
  styleUrls: ['./tickets-admin.component.css']
})
export class TicketsAdminComponent implements OnInit {
  years: number[] = [];
  year = new Date().getFullYear();
  paymentMethod = '';
  date = '';
  query = '';
  page = 1;
  readonly pageSize = 50;

  result: TicketPage | null = null;
  loading = false;
  error: string | null = null;
  exporting = false;

  printTickets: Ticket[] = [];

  paymentLabel = paymentLabel;
  categoryLabel = categoryLabel;

  /** Az összesítő kártyák a nem normál kategóriákról (Dolgozó jegy, Élménykártya, Egyéb szolgáltatás). */
  get categoryCards(): Array<{ category: string; tickets: number; amount: number }> {
    return (this.result?.summary.by_category ?? []).filter(c => c.category !== 'standard');
  }

  constructor(private admin: AdminService, private router: Router) {}

  ngOnInit(): void {
    this.admin.getTicketYears().subscribe({
      next: (years) => {
        this.years = years;
        if (years.length && !years.includes(this.year)) {
          this.year = years[0];
        }
        this.load();
      },
      error: () => this.load()
    });
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
  }

  load(page = 1): void {
    this.page = page;
    this.loading = true;
    this.error = null;

    this.admin.getTickets({
      year: this.year,
      page,
      payment_method: this.paymentMethod,
      date: this.date,
      q: this.query.trim()
    }).subscribe({
      next: (result) => {
        this.result = result;
        this.loading = false;
      },
      error: () => {
        this.error = 'A jegyek betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  changeYear(): void {
    this.date = '';
    this.load();
  }

  exportCsv(): void {
    this.exporting = true;
    this.admin.exportTickets({
      year: this.year,
      payment_method: this.paymentMethod,
      date: this.date,
      q: this.query.trim()
    }).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `jegyek_${this.year}${this.date ? '_' + this.date : ''}${this.paymentMethod ? '_' + this.paymentMethod : ''}.csv`;
        link.click();
        URL.revokeObjectURL(url);
        this.exporting = false;
      },
      error: () => {
        this.error = 'Az exportálás sikertelen.';
        this.exporting = false;
      }
    });
  }

  get rangeFrom(): number {
    const p = this.result?.tickets;
    return p && p.total > 0 ? (p.current_page - 1) * this.pageSize + 1 : 0;
  }

  get rangeTo(): number {
    const p = this.result?.tickets;
    return p ? Math.min(p.current_page * this.pageSize, p.total) : 0;
  }

  /** A lapozó oldalszámai: az első, az utolsó és az aktuális oldal környéke; a kimaradó részt '…' jelzi. */
  get pageButtons(): Array<number | null> {
    const last = this.result?.tickets.last_page ?? 1;
    const current = this.result?.tickets.current_page ?? 1;
    const wanted = new Set([1, last, current - 1, current, current + 1].filter(n => n >= 1 && n <= last));
    const sorted = [...wanted].sort((a, b) => a - b);

    const out: Array<number | null> = [];
    sorted.forEach((n, i) => {
      if (i > 0 && n - sorted[i - 1] > 1) out.push(null);
      out.push(n);
    });
    return out;
  }

  print(ticket: Ticket): void {
    this.printTickets = [ticket];
  }

  closePrint(): void {
    this.printTickets = [];
  }

  methodSummary(method: string): { tickets: number; amount: number } {
    const row = this.result?.summary.by_method.find(m => m.payment_method === method);
    return { tickets: row?.tickets ?? 0, amount: row?.amount ?? 0 };
  }
}
