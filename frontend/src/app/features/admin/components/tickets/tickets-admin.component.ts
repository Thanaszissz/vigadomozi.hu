import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService, Ticket, TicketPage } from '../../../../services/admin.service';
import { TicketPrintComponent, paymentLabel } from '../ticket-print/ticket-print.component';

@Component({
  selector: 'app-tickets-admin',
  standalone: true,
  imports: [CommonModule, FormsModule, TicketPrintComponent],
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

  result: TicketPage | null = null;
  loading = false;
  error: string | null = null;
  exporting = false;

  printTickets: Ticket[] = [];

  paymentLabel = paymentLabel;

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
    this.admin.exportTickets(this.year).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `jegyek_${this.year}.csv`;
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
