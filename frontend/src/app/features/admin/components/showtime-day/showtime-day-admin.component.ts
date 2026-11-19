import { Component, OnInit } from '@angular/core';
import { CommonModule, formatDate } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { DateInputComponent } from '../../../../shared/date-time-inputs.component';
import { Router } from '@angular/router';
import { AdminService, ShowtimeDay, ShowtimePeriod, Ticket, categoryLabel } from '../../../../services/admin.service';
import { paymentLabel, ticketPaymentLabel } from '../ticket-print/ticket-print.component';

const MONTHS = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
const WEEKDAYS = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];

/** Egy időszak (nap / hét / hónap / év) előadásainak teljes jegyeladása (eladás időpontjától és eladójától függetlenül), listában és CSV exporttal. */
@Component({
  selector: 'app-showtime-day-admin',
  standalone: true,
  imports: [CommonModule, FormsModule, DateInputComponent],
  templateUrl: './showtime-day-admin.component.html',
  styleUrls: ['./showtime-day-admin.component.css']
})
export class ShowtimeDayAdminComponent implements OnInit {
  date = this.keyOf(new Date());
  period: ShowtimePeriod = 'day';
  readonly periods: Array<{ key: ShowtimePeriod; label: string }> = [
    { key: 'day', label: 'Nap' },
    { key: 'week', label: 'Hét' },
    { key: 'month', label: 'Hónap' },
    { key: 'year', label: 'Év' },
  ];
  data: ShowtimeDay | null = null;
  loading = false;
  error: string | null = null;
  exporting = false;
  showFilter = 'all';
  showDetails = false;

  paymentLabel = paymentLabel;
  ticketPaymentLabel = ticketPaymentLabel;
  categoryLabel = categoryLabel;

  constructor(private admin: AdminService, private router: Router) {}

  ngOnInit(): void {
    this.load();
  }

  goBack(): void {
    this.router.navigate(['/admin/pos']);
  }

  load(): void {
    if (!this.date) return;

    this.loading = true;
    this.error = null;
    this.showFilter = 'all';

    this.admin.getShowtimeDay(this.date, this.period).subscribe({
      next: (data) => {
        this.data = data;
        this.loading = false;
      },
      error: () => {
        this.error = 'Az előadások eladásának betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  setPeriod(period: ShowtimePeriod): void {
    if (this.period === period) return;
    this.period = period;
    this.load();
  }

  /** Léptetés az aktuális időszak szerint: egy nap / hét / hónap / év előre vagy vissza. */
  shift(delta: number): void {
    const [y, m, d] = this.date.split('-').map(Number);
    let next: Date;
    if (this.period === 'day') {
      next = new Date(y, m - 1, d + delta);
    } else if (this.period === 'week') {
      next = new Date(y, m - 1, d + delta * 7);
    } else {
      const months = this.period === 'month' ? delta : delta * 12;
      const lastDay = new Date(y, m - 1 + months + 1, 0).getDate();
      next = new Date(y, m - 1 + months, Math.min(d, lastDay));
    }
    this.date = this.keyOf(next);
    this.load();
  }

  /** A kiválasztott időszak felirata (a szerver által számolt határokból). */
  get rangeLabel(): string {
    const data = this.data;
    if (!data) return '';
    const dot = (key: string) => key.replace(/-/g, '.') + '.';
    const [y, m] = data.from.split('-').map(Number);
    switch (data.period) {
      case 'year': return `${y}.`;
      case 'month': return `${y}. ${MONTHS[m - 1]}`;
      case 'week': return `${dot(data.from)} – ${dot(data.to)}`;
      default: return dot(data.from);
    }
  }

  /** Az összesítő bontás sorának felirata: nap (hét / hónap) vagy hónap (év). */
  breakdownLabel(key: string): string {
    const [y, m, d] = key.split('-').map(Number);
    if (d === undefined) return `${y}. ${MONTHS[m - 1]}`;
    return `${String(m).padStart(2, '0')}.${String(d).padStart(2, '0')}. (${WEEKDAYS[new Date(y, m - 1, d).getDay()]})`;
  }

  /** Egy előadás kezdése: egy napnál hosszabb időszaknál a dátummal együtt. */
  startLabel(iso: string): string {
    return formatDate(iso, this.data?.period === 'day' ? 'HH:mm' : 'MM.dd. HH:mm', 'en-US');
  }

  /** Az időszak előadásainak száma (hosszabb időszaknál a bontás soraiból). */
  get showCount(): number {
    if (!this.data) return 0;
    return this.data.detail ? this.data.shows.length : this.data.breakdown.reduce((sum, b) => sum + b.shows, 0);
  }

  get periodTitle(): string {
    return ({ day: 'napi', week: 'heti', month: 'havi', year: 'éves' } as Record<ShowtimePeriod, string>)[this.data?.period ?? this.period];
  }

  /** Az előadás azonosítója a szűrőhöz és a csoportosításhoz. */
  showKey(s: { showtime_id: number | null; starts_at: string; movie_title: string }): string {
    return `${s.showtime_id ?? 0}|${s.starts_at}|${s.movie_title}`;
  }

  get visibleShows(): ShowtimeDay['shows'] {
    if (!this.data) return [];
    return this.showFilter === 'all' ? this.data.shows : this.data.shows.filter(s => this.showKey(s) === this.showFilter);
  }

  ticketsOf(show: ShowtimeDay['shows'][number]): Ticket[] {
    const key = this.showKey(show);
    return (this.data?.tickets ?? []).filter(t => this.showKey(t) === key);
  }

  exportCsv(type: 'summary' | 'tickets'): void {
    this.exporting = true;
    this.admin.exportShowtimeDay(this.date, type, this.period).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `elo_adasok_${type === 'summary' ? 'osszesito_' : ''}${this.date}${this.period === 'day' ? '' : '_' + this.period}.csv`;
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

  /** Az áttekintő sorra kattintva az adott előadás jegyei jelennek meg (újra kattintva visszaáll a lista). */
  pick(show: ShowtimeDay['shows'][number]): void {
    const key = this.showKey(show);
    this.showFilter = this.showFilter === key ? 'all' : key;
  }

  get detailsVisible(): boolean {
    return this.showDetails || this.showFilter !== 'all';
  }

  /** PDF / nyomtatás: a böngésző nyomtatási párbeszédablaka, ahol a „Mentés PDF-ként” is választható. */
  print(): void {
    if (!this.data) return;

    this.admin.logClientEvent(
      'report.showtime_day_print',
      `Előadások eladásának nyomtatása (PDF): ${this.date} (${this.period})`,
      { date: this.date, period: this.period, tickets: this.data.totals.tickets, amount: this.data.totals.amount }
    );

    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(frame);

    const doc = frame.contentDocument!;
    doc.open();
    doc.write(this.buildPrintHtml(this.data));
    doc.close();

    setTimeout(() => {
      frame.contentWindow?.focus();
      frame.contentWindow?.print();
      setTimeout(() => frame.remove(), 1000);
    }, 150);
  }

  private buildPrintHtml(data: ShowtimeDay): string {
    const esc = (v: unknown) => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!));
    const ft = (n: number) => `${Math.round(n).toLocaleString('hu-HU')} Ft`;
    const time = (iso: string) => this.startLabel(iso);
    const t = data.totals;
    const vat = `${data.vat_rate}%`;

    const showRows = data.shows.map(s => `<tr>
      <td>${esc(time(s.starts_at))}</td><td><b>${esc(s.movie_title)}</b></td><td>${esc(s.auditorium_name || '—')}</td>
      <td class="r">${s.tickets} db</td><td class="r">${ft(s.net)}</td><td class="r">${ft(s.vat)}</td><td class="r"><b>${ft(s.amount)}</b></td></tr>`).join('');

    const breakdownTitle = data.period === 'year' ? 'Havi bontás' : 'Napi bontás';
    const breakdownRows = data.breakdown.map(b => `<tr>
      <td><b>${esc(this.breakdownLabel(b.key))}</b></td><td class="r">${b.shows} előadás</td>
      <td class="r">${b.tickets} db</td><td class="r">${ft(b.net)}</td><td class="r">${ft(b.vat)}</td><td class="r"><b>${ft(b.amount)}</b></td></tr>`).join('');
    const breakdownHtml = data.breakdown.length ? `
      <h2>${breakdownTitle}</h2>
      <table>
        <thead><tr><th>${data.period === 'year' ? 'Hónap' : 'Nap'}</th><th class="r">Előadások</th><th class="r">Jegy</th><th class="r">Nettó</th><th class="r">ÁFA ${vat}</th><th class="r">Bruttó</th></tr></thead>
        <tbody>${breakdownRows}
          <tr class="total"><td>Összesen</td><td class="r">${data.breakdown.reduce((a, b) => a + b.shows, 0)} előadás</td><td class="r">${t.tickets} db</td><td class="r">${ft(t.net)}</td><td class="r">${ft(t.vat)}</td><td class="r">${ft(t.amount)}</td></tr>
        </tbody>
      </table>` : '';
    const showsHtml = data.detail ? `
      <h2>Előadásonként</h2>
      <table>
        <thead><tr><th>Kezdés</th><th>Film</th><th>Terem</th><th class="r">Jegy</th><th class="r">Nettó</th><th class="r">ÁFA ${vat}</th><th class="r">Bruttó</th></tr></thead>
        <tbody>${showRows}
          <tr class="total"><td colspan="3">Összesen</td><td class="r">${t.tickets} db</td><td class="r">${ft(t.net)}</td><td class="r">${ft(t.vat)}</td><td class="r">${ft(t.amount)}</td></tr>
        </tbody>
      </table>` : '';

    const pay: Array<[string, string]> = [
      ['Készpénz', ft(t.cash)],
      ['Bankkártya', ft(t.card)],
      ...(t.szep_card > 0 ? [['SZÉP kártya', ft(t.szep_card)] as [string, string]] : []),
      ...(t.voucher > 0 ? [['Utalvány', ft(t.voucher)] as [string, string]] : []),
      ...(t.online > 0 ? [['Online', ft(t.online)] as [string, string]] : []),
      ...(t.experience_tickets > 0 ? [['Élménykártya', `${t.experience_tickets} db • ${ft(t.experience_card)}`] as [string, string]] : []),
      ...(t.staff > 0 ? [['Ingyenes jegy', `${t.staff} db`] as [string, string]] : []),
    ];
    const payRows = pay.map(([label, value]) => `<tr><td>${esc(label)}</td><td class="r">${esc(value)}</td></tr>`).join('');

    const css = `
      @page { size: A4 landscape; margin: 12mm; }
      html, body { margin: 0; padding: 0; background: #fff; }
      body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 12px; }
      h1 { font-size: 22px; margin: 0 0 2px; }
      .sub { color: #444; margin-bottom: 14px; }
      h2 { font-size: 14px; margin: 18px 0 6px; padding-bottom: 2px; border-bottom: 2px solid #111; }
      .kpi { display: flex; gap: 12px; margin-bottom: 6px; }
      .kpi div { flex: 1; border: 1px solid #111; padding: 8px 12px; }
      .kpi div.gross { flex: 1.4; border-width: 3px; }
      .kpi span { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: #444; }
      .kpi b { display: block; font-size: 22px; margin-top: 2px; }
      .kpi .gross b { font-size: 28px; }
      table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
      th, td { border-bottom: 1px solid #ccc; padding: 5px 8px; text-align: left; }
      th { background: #eee; font-size: 10px; text-transform: uppercase; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      td.r, th.r { text-align: right; }
      tr.total td { font-weight: 700; border-top: 2px solid #111; background: #f3f3f3; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      tr { page-break-inside: avoid; }
      table.pay { width: 50%; }
      .note { margin-top: 14px; color: #555; font-size: 10px; }
    `;

    return `<!doctype html><html><head><meta charset="utf-8"><title>Előadások eladott jegyei ${esc(this.rangeLabel)}</title><style>${css}</style></head><body>
      <h1>Előadások eladott jegyei – ${esc(this.periodTitle)} összesítő</h1>
      <div class="sub">${esc(this.rangeLabel)} • ${t.tickets} eladott jegy • ${data.shows.length || data.breakdown.reduce((a, b) => a + b.shows, 0)} előadás</div>

      <div class="kpi">
        <div class="gross"><span>Bruttó bevétel</span><b>${ft(t.amount)}</b></div>
        <div><span>Nettó bevétel</span><b>${ft(t.net)}</b></div>
        <div><span>ÁFA (${vat})</span><b>${ft(t.vat)}</b></div>
      </div>
      ${breakdownHtml}
      ${showsHtml}
      <h2>Fizetési mód szerint</h2>
      <table class="pay"><tbody>${payRows}</tbody></table>

      <div class="note">A bruttó bevétel az előadásokra eddig eladott összes jegy értéke (a sztornózott jegyek nélkül). A nettó összeg az ${vat}-os ÁFA-kulccsal számolt, előadásonként kerekített érték.
      Nyomtatva: ${esc(formatDate(new Date(), 'yyyy.MM.dd. HH:mm', 'en-US'))}</div>
    </body></html>`;
  }

  private keyOf(d: Date): string {
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }
}
