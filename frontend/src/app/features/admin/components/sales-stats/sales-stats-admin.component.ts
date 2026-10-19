import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService } from '../../../../services/admin.service';
import { DateInputComponent } from '../../../../shared/date-time-inputs.component';

type Group = 'day' | 'week' | 'month' | 'year';

interface StatRow {
  key: string;
  label: string;
  tickets: number;
  amount: number;
}

const PAYMENT_LABELS: Record<string, string> = {
  cash: '💵 Készpénz',
  card: '💳 Bankkártya',
  experience_card: '🎁 Élménykártya',
  simplepay: '🌐 Online (SimplePay)',
  stripe: '🌐 Online (Stripe)',
  unknown: 'Ismeretlen'
};

@Component({
  selector: 'app-sales-stats-admin',
  standalone: true,
  imports: [CommonModule, FormsModule, DateInputComponent],
  templateUrl: './sales-stats-admin.component.html',
  styleUrls: ['./sales-stats-admin.component.css']
})
export class SalesStatsAdminComponent implements OnInit {
  readonly groups: Array<{ value: Group; label: string }> = [
    { value: 'day', label: 'Napi' },
    { value: 'week', label: 'Heti' },
    { value: 'month', label: 'Havi' },
    { value: 'year', label: 'Éves' }
  ];

  from = '';
  to = '';
  group: Group = 'day';

  loading = false;
  error = '';
  data: any = null;

  constructor(private admin: AdminService, private router: Router) {}

  ngOnInit(): void {
    this.preset('30d');
  }

  goBack(): void {
    this.router.navigate(['/admin/reports']);
  }

  /** Gyors intervallum-választók (helyi naptár szerint). */
  preset(kind: 'today' | 'week' | 'month' | 'year' | '30d'): void {
    const now = new Date();
    const start = new Date(now);

    switch (kind) {
      case 'week': {
        const monday = (now.getDay() + 6) % 7;
        start.setDate(now.getDate() - monday);
        this.group = 'day';
        break;
      }
      case 'month':
        start.setDate(1);
        this.group = 'day';
        break;
      case 'year':
        start.setMonth(0, 1);
        this.group = 'month';
        break;
      case '30d':
        start.setDate(now.getDate() - 29);
        this.group = 'day';
        break;
      default:
        this.group = 'day';
    }

    this.from = this.fmt(start);
    this.to = this.fmt(now);
    this.load();
  }

  load(): void {
    if (!this.from || !this.to) {
      return;
    }
    if (this.from > this.to) {
      this.error = 'A kezdő dátum nem lehet későbbi a záró dátumnál.';
      return;
    }

    this.loading = true;
    this.error = '';

    this.admin.getSalesStats(this.from, this.to, this.group).subscribe({
      next: (data) => {
        this.data = data;
        this.loading = false;
      },
      error: (err) => {
        const errors = err?.error?.errors;
        this.error = (errors && (Object.values(errors) as string[][])[0]?.[0]) || 'A statisztika betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  paymentLabel(row: StatRow): string {
    return PAYMENT_LABELS[row.key] ?? row.key;
  }

  /** Egy sor aránya a lista legnagyobb értékéhez képest (oszlopdiagramhoz). */
  barWidth(value: number, rows: StatRow[], field: 'tickets' | 'amount' = 'tickets'): number {
    const max = Math.max(...rows.map(r => r[field]), 0);
    return max > 0 && value > 0 ? Math.max((value / max) * 100, 2) : 0;
  }

  share(value: number, total: number): number {
    return total > 0 ? Math.round((value / total) * 1000) / 10 : 0;
  }

  get ticketTotal(): number {
    return this.data?.summary?.tickets ?? 0;
  }

  get paymentTotal(): number {
    return (this.data?.by_payment ?? []).reduce((sum: number, r: StatRow) => sum + r.tickets, 0);
  }

  private fmt(d: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }
}
