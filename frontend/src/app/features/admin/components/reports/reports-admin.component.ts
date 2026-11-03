import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { DateInputComponent } from '../../../../shared/date-time-inputs.component';
import { SalesStatsAdminComponent } from '../sales-stats/sales-stats-admin.component';
import { Router } from '@angular/router';
import { AdminService } from '../../../../services/admin.service';
import { AuthService } from '../../../../services/auth.service';
import { paymentLabel } from '../ticket-print/ticket-print.component';
import { buildDailyReportHtml } from './daily-report-print';

@Component({
  selector: 'app-reports-admin',
  standalone: true,
  imports: [CommonModule, FormsModule, DateInputComponent, SalesStatsAdminComponent],
  templateUrl: './reports-admin.component.html',
  styleUrls: ['./reports-admin.component.css']
})
export class ReportsAdminComponent implements OnInit {
  selectedDate = new Date().toISOString().split('T')[0];
  activeTab: 'daily' | 'stats' = 'daily';
  loading = false;
  error = '';

  summary: any = null;
  sales: any[] = [];

  paymentLabel = paymentLabel;

  constructor(
    private adminService: AdminService,
    private auth: AuthService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.loadReport();
  }

  loadReport(): void {
    this.loading = true;
    this.error = '';

    this.adminService.getDailyReport(this.selectedDate).subscribe({
      next: (data) => {
        this.summary = data.summary;
        this.sales = data.sales ?? [];
        this.loading = false;
      },
      error: (err) => {
        this.error = 'Hiba történt a riport betöltése során';
        this.loading = false;
        console.error('Error loading report:', err);
      }
    });
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
  }

  // ---- Megjelenítés ----

  /** Forint, ezres tagolással (szóközzel), sortörés nélkül. */
  ft(n: number | string): string {
    return `${Math.round(Number(n) || 0).toLocaleString('hu-HU')}\u00A0Ft`;
  }

  num(n: number | string): string {
    return Math.round(Number(n) || 0).toLocaleString('hu-HU');
  }

  pct(amount: number): string {
    const total = Number(this.summary?.total_amount) || 0;
    return total > 0 ? `${Math.round((amount / total) * 100)}%` : '–';
  }

  channelLabel(channel: string): string {
    return ({ pos: 'Pénztár', admin: 'Admin', online: 'Online' } as Record<string, string>)[channel] ?? channel;
  }

  channelIcon(channel: string): string {
    return ({ pos: '🏪', admin: '🧑‍💼', online: '🌐' } as Record<string, string>)[channel] ?? '•';
  }

  kindIcon(kind: string): string {
    return ({ movie: '🎬', theater: '🎭', service: '🛍️' } as Record<string, string>)[kind] ?? '•';
  }

  methodIcon(method: string): string {
    return ({ cash: '💶', card: '💳', szep_card: '🏖️', voucher: '🎟️', experience_card: '🎁', mixed: '➗', stripe: '🌐', simplepay: '🌐' } as Record<string, string>)[method] ?? '•';
  }

  /** Egy eladás fizetése: egy módnál a neve, több módnál módonként az összeg. */
  salePayment(sale: any): string {
    const parts: Array<{ method: string; amount: number }> = sale.payments ?? [];
    if (parts.length <= 1) return parts.length ? paymentLabel(parts[0].method) : '—';
    return parts.map(p => `${paymentLabel(p.method)} ${this.ft(p.amount)}`).join(' + ');
  }

  get programTotal(): number {
    return (this.summary?.by_program ?? []).reduce((s: number, p: any) => s + Number(p.amount), 0);
  }

  get serviceTotal(): number {
    return (this.summary?.by_service ?? []).reduce((s: number, p: any) => s + Number(p.amount), 0);
  }

  hourWidth(amount: number): string {
    const max = Math.max(1, ...(this.summary?.hourly ?? []).map((h: any) => Number(h.amount)));
    return `${Math.max(2, Math.round((amount / max) * 100))}%`;
  }

  /** A napi riport nyomtatása / PDF-be mentése rejtett keretből (nem blokkolja a felugró ablak tiltás). */
  exportPDF(): void {
    if (!this.summary) return;

    const html = buildDailyReportHtml({
      date: this.selectedDate,
      summary: this.summary,
      sales: this.sales,
      issuedBy: this.auth.getCurrentUser()?.name ?? '',
      logoUrl: `${window.location.origin}/assets/vigado_logo_beige.png`
    });

    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(frame);
    const doc = frame.contentDocument!;
    doc.open();
    doc.write(html);
    doc.close();

    // a logó betöltése után nyomtatunk (legfeljebb 2 mp-et várunk)
    let fired = false;
    const done = () => {
      if (fired) return;
      fired = true;
      frame.contentWindow?.focus();
      frame.contentWindow?.print();
      setTimeout(() => frame.remove(), 1000);
    };
    const logo = doc.querySelector('img');
    if (logo && !logo.complete) {
      logo.addEventListener('load', done);
      logo.addEventListener('error', done);
      setTimeout(done, 2000);
    } else {
      setTimeout(done, 100);
    }
  }
}
