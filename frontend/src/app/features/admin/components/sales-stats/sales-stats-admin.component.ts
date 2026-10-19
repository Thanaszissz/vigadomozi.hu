import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AdminService } from '../../../../services/admin.service';
import { DateInputComponent } from '../../../../shared/date-time-inputs.component';
import { escapeHtml } from '../../../../core/util/html';

type Period = 'day' | 'week' | 'month' | 'year' | 'custom';
type Bucket = 'hour' | 'day' | 'week' | 'month' | 'year';

interface StatRow {
  key: string;
  label: string;
  tickets: number;
  amount: number;
}

const PAYMENT_LABELS: Record<string, string> = {
  cash: 'Készpénz',
  card: 'Bankkártya',
  experience_card: 'Élménykártya',
  simplepay: 'Online (SimplePay)',
  stripe: 'Online (Stripe)',
  unknown: 'Ismeretlen'
};

const MONTHS = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];

/** Értékesítési statisztika a Riportok oldalon: napi / heti / havi / éves időszak (léptethető) vagy egyéni intervallum. */
@Component({
  selector: 'app-sales-stats-admin',
  standalone: true,
  imports: [CommonModule, FormsModule, DateInputComponent],
  templateUrl: './sales-stats-admin.component.html',
  styleUrls: ['./sales-stats-admin.component.css']
})
export class SalesStatsAdminComponent implements OnInit {
  readonly periods: Array<{ value: Period; label: string }> = [
    { value: 'day', label: 'Napi' },
    { value: 'week', label: 'Heti' },
    { value: 'month', label: 'Havi' },
    { value: 'year', label: 'Éves' },
    { value: 'custom', label: 'Egyéni' }
  ];
  readonly buckets: Array<{ value: Bucket; label: string }> = [
    { value: 'day', label: 'Napok' },
    { value: 'week', label: 'Hetek' },
    { value: 'month', label: 'Hónapok' },
    { value: 'year', label: 'Évek' }
  ];

  period: Period = 'month';
  // Az időszak egy tetszőleges napja (a hét / hónap / év ebből adódik), yyyy-MM-dd
  anchor = this.fmt(new Date());
  customFrom = '';
  customTo = '';
  customBucket: Bucket = 'day';

  loading = false;
  error = '';
  data: any = null;

  constructor(private admin: AdminService) {}

  ngOnInit(): void {
    const start = new Date();
    start.setDate(start.getDate() - 29);
    this.customFrom = this.fmt(start);
    this.customTo = this.fmt(new Date());
    this.load();
  }

  setPeriod(period: Period): void {
    if (this.period === period) {
      return;
    }
    this.period = period;
    this.load();
  }

  /** Előző / következő nap, hét, hónap, év. */
  shift(delta: number): void {
    const d = this.parse(this.anchor);
    switch (this.period) {
      case 'day': d.setDate(d.getDate() + delta); break;
      case 'week': d.setDate(d.getDate() + 7 * delta); break;
      case 'month': d.setMonth(d.getMonth() + delta, 1); break;
      case 'year': d.setFullYear(d.getFullYear() + delta, 0, 1); break;
    }
    this.anchor = this.fmt(d);
    this.load();
  }

  goToToday(): void {
    this.anchor = this.fmt(new Date());
    this.load();
  }

  onAnchorChange(value: string): void {
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
      this.anchor = value;
      this.load();
    }
  }

  /** A kiválasztott időszak határai. */
  get range(): { from: string; to: string } {
    if (this.period === 'custom') {
      return { from: this.customFrom, to: this.customTo };
    }

    const a = this.parse(this.anchor);
    switch (this.period) {
      case 'week': {
        const from = new Date(a);
        from.setDate(a.getDate() - ((a.getDay() + 6) % 7));
        const to = new Date(from);
        to.setDate(from.getDate() + 6);
        return { from: this.fmt(from), to: this.fmt(to) };
      }
      case 'month':
        return { from: this.fmt(new Date(a.getFullYear(), a.getMonth(), 1)), to: this.fmt(new Date(a.getFullYear(), a.getMonth() + 1, 0)) };
      case 'year':
        return { from: this.fmt(new Date(a.getFullYear(), 0, 1)), to: this.fmt(new Date(a.getFullYear(), 11, 31)) };
      default:
        return { from: this.anchor, to: this.anchor };
    }
  }

  get bucket(): Bucket {
    switch (this.period) {
      case 'day': return 'hour';
      case 'year': return 'month';
      case 'custom': return this.customBucket;
      default: return 'day';
    }
  }

  get periodLabel(): string {
    const { from, to } = this.range;
    const f = this.parse(from);
    switch (this.period) {
      case 'day': return this.dotted(from);
      case 'week': return `${this.dotted(from)} – ${this.dotted(to).slice(5)}`;
      case 'month': return `${f.getFullYear()}. ${MONTHS[f.getMonth()]}`;
      case 'year': return `${f.getFullYear()}`;
      default: return `${this.dotted(from)} – ${this.dotted(to)}`;
    }
  }

  load(): void {
    const { from, to } = this.range;
    if (!from || !to) {
      return;
    }
    if (from > to) {
      this.error = 'A kezdő dátum nem lehet későbbi a záró dátumnál.';
      return;
    }

    this.loading = true;
    this.error = '';

    this.admin.getSalesStats(from, to, this.bucket).subscribe({
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

  paymentName(row: StatRow): string {
    return PAYMENT_LABELS[row.key] ?? row.key;
  }

  paymentIcon(row: StatRow): string {
    return ({ cash: '💵', card: '💳', experience_card: '🎁', simplepay: '🌐', stripe: '🌐' } as Record<string, string>)[row.key] ?? '•';
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

  get hasSales(): boolean {
    return !!this.data && (this.data.summary.tickets > 0 || this.data.summary.services > 0);
  }

  // ---- Export ----

  private exportName(ext: string): string {
    return `ertekesitesi-statisztika_${this.data.from}_${this.data.to}.${ext}`;
  }

  /** CSV (Excelben megnyitható: UTF-8 BOM, pontosvesszős elválasztás). */
  exportCsv(): void {
    if (!this.data) return;

    const cell = (v: string | number): string => {
      const s = String(v ?? '');
      // Képlet-injektálás ellen: a képletnek látszó szöveget aposztróffal kezdjük
      const safe = /^[=+\-@\t\r]/.test(s) && Number.isNaN(Number(s)) ? `'${s}` : s;
      return /[;"\n\r]/.test(safe) ? `"${safe.replace(/"/g, '""')}"` : safe;
    };
    const line = (...cols: Array<string | number>) => cols.map(cell).join(';');
    const section = (title: string, head: string[], rows: Array<Array<string | number>>) =>
      rows.length ? ['', line(title), line(...head), ...rows.map(r => line(...r))] : [];

    const d = this.data;
    const lines = [
      line('Értékesítési statisztika'),
      line('Időszak', `${d.from} – ${d.to}`),
      line('Eladott jegyek', d.summary.tickets),
      line('Bevétel (Ft)', d.summary.revenue),
      line('Egyéb szolgáltatás nyugták', d.summary.services),
      ...section('Időszakonként', ['Időszak', 'Jegyek', 'Bevétel (Ft)'], d.series.map((r: StatRow) => [r.label, r.tickets, r.amount])),
      ...section('Fizetési mód', ['Mód', 'Darab', 'Bevétel (Ft)'], d.by_payment.map((r: StatRow) => [this.paymentName(r), r.tickets, r.amount])),
      ...section('Jegykategória', ['Kategória', 'Darab', 'Bevétel (Ft)'], d.by_category.map((r: StatRow) => [r.label, r.tickets, r.amount])),
      ...section('Miből mennyi fogyott', ['Előadás / film', 'Darab', 'Bevétel (Ft)'], d.by_program.map((r: StatRow) => [r.label, r.tickets, r.amount])),
      ...section('Irányítószámok szerint', ['Irányítószám', 'Darab', 'Bevétel (Ft)'], d.by_zip.map((r: StatRow) => [r.label, r.tickets, r.amount])),
      ...section('Egyéb szolgáltatások', ['Szolgáltatás', 'Darab', 'Bevétel (Ft)'], d.services.map((r: StatRow) => [r.label, r.tickets, r.amount]))
    ];

    const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = this.exportName('csv');
    a.click();
    URL.revokeObjectURL(url);
  }

  /** Nyomtatható / PDF-be menthető összefoglaló (a böngésző nyomtatási párbeszédablakán keresztül). */
  exportPdf(): void {
    if (!this.data) return;

    const win = window.open('', '_blank');
    if (!win) return;

    const d = this.data;
    const ft = (n: number) => `${Math.round(n).toLocaleString('hu-HU')} Ft`;
    const table = (title: string, head: string, rows: StatRow[], labelOf: (r: StatRow) => string = r => r.label) =>
      rows.length
        ? `<h2>${escapeHtml(title)}</h2><table><thead><tr><th>${escapeHtml(head)}</th><th class="n">Darab</th><th class="n">Bevétel</th></tr></thead><tbody>${
            rows.map(r => `<tr><td>${escapeHtml(labelOf(r))}</td><td class="n">${r.tickets}</td><td class="n">${ft(r.amount)}</td></tr>`).join('')
          }</tbody></table>`
        : '';

    win.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Értékesítési statisztika ${escapeHtml(d.from)} – ${escapeHtml(d.to)}</title>
      <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #222; }
        h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 15px; margin: 22px 0 6px; }
        .sub { color: #666; margin-bottom: 14px; }
        .cards { display: flex; gap: 12px; margin-bottom: 8px; }
        .card { border: 1px solid #ccc; border-radius: 6px; padding: 10px 16px; } .card b { display: block; font-size: 18px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border-bottom: 1px solid #ddd; padding: 5px 8px; text-align: left; } th { background: #f1e6d6; }
        .n { text-align: right; }
        @media print { body { margin: 10mm; } }
      </style></head><body>
      <h1>Értékesítési statisztika</h1>
      <div class="sub">${escapeHtml(d.from)} – ${escapeHtml(d.to)}</div>
      <div class="cards">
        <div class="card"><b>${d.summary.tickets}</b>Eladott jegy</div>
        <div class="card"><b>${ft(d.summary.revenue)}</b>Bevétel</div>
        ${d.summary.services ? `<div class="card"><b>${d.summary.services}</b>Egyéb szolgáltatás</div>` : ''}
      </div>
      ${table('Időszakonként', 'Időszak', d.series)}
      ${table('Fizetési mód', 'Mód', d.by_payment, r => this.paymentName(r))}
      ${table('Jegykategória', 'Kategória', d.by_category)}
      ${table('Miből mennyi fogyott', 'Előadás / film', d.by_program)}
      ${table('Irányítószámok szerint', 'Irányítószám', d.by_zip)}
      ${table('Egyéb szolgáltatások', 'Szolgáltatás', d.services)}
      <script>window.onload = function () { window.print(); };<\/script>
      </body></html>`);
    win.document.close();
  }

  // ---- Segédek ----

  private parse(value: string): Date {
    const [y, m, d] = value.split('-').map(Number);
    return new Date(y, (m || 1) - 1, d || 1);
  }

  private dotted(value: string): string {
    return value.replace(/-/g, '.') + '.';
  }

  private fmt(d: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }
}
