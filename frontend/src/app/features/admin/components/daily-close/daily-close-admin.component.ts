import { Component, OnInit } from '@angular/core';
import { CommonModule, formatDate } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService, DailyClose, SalesLogEntry, Ticket } from '../../../../services/admin.service';
import { AuthService } from '../../../../services/auth.service';
import { paymentLabel } from '../ticket-print/ticket-print.component';

type Tab = 'close' | 'log';

const PRINT_CSS = `
  @page { size: A4; margin: 12mm; }
  html, body { margin: 0; padding: 0; background: #fff; }
  body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 11px; }
  h1 { font-size: 20px; margin: 0 0 2px; }
  .sub { color: #444; margin-bottom: 10px; }
  .meta { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 11px; }
  h2 { font-size: 14px; margin: 16px 0 4px; padding-bottom: 2px; border-bottom: 2px solid #111; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  th, td { border-bottom: 1px solid #ccc; padding: 3px 5px; text-align: left; }
  th { background: #eee; font-size: 10px; text-transform: uppercase; }
  td.r, th.r { text-align: right; }
  td.num { font-family: 'Courier New', monospace; font-weight: 700; }
  tr.sub td { font-weight: 700; border-top: 1px solid #111; background: #f7f7f7; }
  tr { page-break-inside: avoid; }
  .grand { margin-top: 16px; border: 2px solid #111; padding: 8px 10px; page-break-inside: avoid; }
  .grand table { margin: 0; }
  .grand td { border: none; font-size: 12px; }
  .sign { display: flex; gap: 40px; margin-top: 50px; page-break-inside: avoid; }
  .sign div { flex: 1; border-top: 1px solid #111; text-align: center; padding-top: 3px; }
`;

@Component({
  selector: 'app-daily-close-admin',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './daily-close-admin.component.html',
  styleUrls: ['./daily-close-admin.component.css']
})
export class DailyCloseAdminComponent implements OnInit {
  date = this.todayKey();
  tab: Tab = 'close';
  sellerFilter = 'all';

  close: DailyClose | null = null;
  logs: SalesLogEntry[] = [];
  loading = false;
  error: string | null = null;

  paymentLabel = paymentLabel;

  constructor(
    private admin: AdminService,
    private auth: AuthService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.load();
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
  }

  load(): void {
    if (!this.date) return;

    this.loading = true;
    this.error = null;
    this.sellerFilter = 'all';

    this.admin.getDailyClose(this.date).subscribe({
      next: (close) => {
        this.close = close;
        this.loading = false;
      },
      error: () => {
        this.error = 'A napi zárás betöltése sikertelen.';
        this.loading = false;
      }
    });

    this.admin.getSalesLog(this.date).subscribe({
      next: (result) => { this.logs = result.logs; },
      error: () => { this.logs = []; }
    });
  }

  shiftDay(delta: number): void {
    const [y, m, d] = this.date.split('-').map(Number);
    this.date = this.keyOf(new Date(y, m - 1, d + delta));
    this.load();
  }

  // A kiválasztott eladó(k) szekciói; az összesítés mindig a látható sorokból számolódik
  get visibleSellers(): DailyClose['sellers'] {
    if (!this.close) return [];
    return this.sellerFilter === 'all'
      ? this.close.sellers
      : this.close.sellers.filter(s => String(s.user_id ?? 0) === this.sellerFilter);
  }

  get visibleTotals(): { tickets: number; amount: number; cash: number; card: number; other: number } {
    const all = this.visibleSellers.flatMap(s => s.tickets);
    return { tickets: all.length, ...this.sellerSums(all) };
  }

  sellerSums(tickets: Ticket[]): { cash: number; card: number; other: number; amount: number } {
    const sums = { cash: 0, card: 0, other: 0, amount: 0 };
    for (const t of tickets) {
      sums.amount += t.price_amount;
      if (t.payment_method === 'cash') sums.cash += t.price_amount;
      else if (t.payment_method === 'card') sums.card += t.price_amount;
      else sums.other += t.price_amount;
    }
    return sums;
  }

  channelLabel(channel: string): string {
    return ({ pos: 'Pénztár', admin: 'Admin', online: 'Online' } as Record<string, string>)[channel] ?? channel;
  }

  // ---- Nyomtatás ----

  print(): void {
    if (!this.close) return;

    this.admin.logClientEvent(
      'report.daily_close_print',
      `Napi zárás nyomtatása: ${this.date}${this.sellerFilter === 'all' ? '' : ' (egy eladó)'}`,
      { date: this.date, seller_filter: this.sellerFilter, tickets: this.visibleTotals.tickets, amount: this.visibleTotals.amount }
    );

    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(frame);

    const doc = frame.contentDocument!;
    doc.open();
    doc.write(this.buildPrintHtml());
    doc.close();

    setTimeout(() => {
      frame.contentWindow?.focus();
      frame.contentWindow?.print();
      setTimeout(() => frame.remove(), 1000);
    }, 150);
  }

  private buildPrintHtml(): string {
    const esc = (v: unknown) => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!));
    const ft = (n: number) => `${Math.round(n).toLocaleString('hu-HU')} Ft`;
    const dt = (v: string) => formatDate(v, 'yyyy.MM.dd. HH:mm', 'en-US');
    const tm = (v: string) => formatDate(v, 'HH:mm', 'en-US');

    const current = this.auth.getCurrentUser();
    const sellers = this.visibleSellers;
    const totals = this.visibleTotals;
    const filterNote = this.sellerFilter === 'all' ? 'Minden eladó' : `Eladó: ${sellers[0]?.user_name ?? ''}`;

    const sections = sellers.map(seller => {
      const sums = this.sellerSums(seller.tickets);
      const rows = seller.tickets.map(t => `
        <tr>
          <td class="num">${esc(t.ticket_number)}</td>
          <td>${esc(tm(t.sold_at))}</td>
          <td>${esc(t.movie_title)}</td>
          <td>${esc(dt(t.starts_at))}</td>
          <td>${esc(t.row_label)} / ${esc(t.seat_number ?? '-')}</td>
          <td>${esc(paymentLabel(t.payment_method))}</td>
          <td class="r">${esc(ft(t.price_amount))}</td>
        </tr>`).join('');

      return `
        <div>
          <h2>${esc(seller.user_name)}</h2>
          <table>
            <thead><tr>
              <th>Jegyszám</th><th>Idő</th><th>Film</th><th>Előadás</th><th>Sor / szék</th><th>Fizetés</th><th class="r">Ár</th>
            </tr></thead>
            <tbody>
              ${rows}
              <tr class="sub">
                <td colspan="4">Összesen: ${seller.tickets.length} jegy</td>
                <td colspan="2">Készpénz: ${esc(ft(sums.cash))} • Kártya: ${esc(ft(sums.card))}${sums.other ? ' • Egyéb: ' + esc(ft(sums.other)) : ''}</td>
                <td class="r">${esc(ft(sums.amount))}</td>
              </tr>
            </tbody>
          </table>
        </div>`;
    }).join('');

    return `<!doctype html><html><head><meta charset="utf-8"><title>Napi zárás ${esc(this.date)}</title><style>${PRINT_CSS}</style></head><body>
      <h1>Napi zárás – Vigadó Mozi</h1>
      <div class="sub">${esc(this.date.replace(/-/g, '.'))}. • ${esc(filterNote)}</div>
      <div class="meta">
        <span>Nyomtatta: ${esc(current?.name ?? '-')}</span>
        <span>Nyomtatva: ${esc(formatDate(new Date(), 'yyyy.MM.dd. HH:mm', 'en-US'))}</span>
      </div>
      ${sections || '<p>Ezen a napon nem volt jegyeladás.</p>'}
      <div class="grand">
        <table>
          <tr><td>Eladott jegyek száma</td><td class="r"><b>${totals.tickets} db</b></td></tr>
          <tr><td>Készpénz</td><td class="r">${esc(ft(totals.cash))}</td></tr>
          <tr><td>Bankkártya</td><td class="r">${esc(ft(totals.card))}</td></tr>
          ${totals.other ? `<tr><td>Egyéb (online)</td><td class="r">${esc(ft(totals.other))}</td></tr>` : ''}
          <tr><td><b>Mindösszesen</b></td><td class="r"><b>${esc(ft(totals.amount))}</b></td></tr>
        </table>
      </div>
      <div class="sign"><div>Pénztáros</div><div>Átvette</div></div>
    </body></html>`;
  }

  private todayKey(): string {
    return this.keyOf(new Date());
  }

  private keyOf(d: Date): string {
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }
}
