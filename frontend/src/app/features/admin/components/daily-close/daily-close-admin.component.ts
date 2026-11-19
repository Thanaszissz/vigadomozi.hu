import { Component, OnInit } from '@angular/core';
import { CommonModule, formatDate } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { DateInputComponent } from '../../../../shared/date-time-inputs.component';
import { Router } from '@angular/router';
import { AdminService, DailyClose, SalesLogEntry, Ticket, categoryLabel } from '../../../../services/admin.service';
import { AuthService } from '../../../../services/auth.service';
import { paymentLabel, ticketPaymentLabel } from '../ticket-print/ticket-print.component';

type Tab = 'close' | 'finance' | 'log';

/** A pénzügyi kimutatás egy sora: jegytípusonkénti bevétel és darabszám. */
interface FinanceRow { label: string; rank: number; tickets: number; amount: number }

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
  .summary { margin: 10px 0 22px; border: 2px solid #111; page-break-inside: avoid; }
  .summary h3 { margin: 0; padding: 6px 12px; font-size: 14px; text-transform: uppercase; letter-spacing: .5px; background: #eee; border-bottom: 2px solid #111; }
  .summary table { margin: 0; }
  .summary td { border-bottom: 1px solid #ddd; padding: 6px 12px; font-size: 16px; }
  .summary tr.total td { border-top: 2px solid #111; border-bottom: none; font-size: 18px; font-weight: 700; background: #f3f3f3; }
  tr.count td { border-bottom: 3px solid #111; }
  .grand { margin-top: 26px; border: 4px double #000; page-break-inside: avoid; }
  .grand h3 { margin: 0; padding: 8px 12px; font-size: 16px; letter-spacing: 1px; text-align: center; text-transform: uppercase; background: #000; color: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .grand table { margin: 0; }
  .grand td { border-bottom: 1px solid #bbb; padding: 5px 12px; font-size: 14px; }
  .grand tr.total td { border-top: 3px solid #000; border-bottom: none; padding: 10px 12px; font-size: 20px; font-weight: 700; background: #e4e4e4; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .sign { display: flex; gap: 40px; margin-top: 50px; page-break-inside: avoid; }
  .sign div { flex: 1; border-top: 1px solid #111; text-align: center; padding-top: 3px; }
  .newpage { page-break-before: always; }
  .finance table th, .finance table td { padding: 6px 10px; font-size: 13px; }
  .finance h1 { font-size: 22px; }
  .finance-note { margin-top: 8px; color: #555; font-size: 10px; }
`;

@Component({
  selector: 'app-daily-close-admin',
  standalone: true,
  imports: [CommonModule, FormsModule, DateInputComponent],
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
  ticketPaymentLabel = ticketPaymentLabel;

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

  get visibleTotals(): { tickets: number; amount: number; cash: number; card: number; szep: number; voucher: number; other: number; experience: number } {
    const all = this.visibleSellers.flatMap(s => s.tickets);
    return { tickets: all.length, ...this.sellerSums(all) };
  }

  sellerSums(tickets: Ticket[]): { cash: number; card: number; szep: number; voucher: number; other: number; experience: number; amount: number } {
    const sums = { cash: 0, card: 0, szep: 0, voucher: 0, other: 0, experience: 0, amount: 0 };
    for (const t of tickets) {
      sums.amount += t.price_amount;
      // Részfizetésnél a jegy összege a módonkénti részek szerint oszlik meg
      const parts = t.payment_split?.length ? t.payment_split : [{ method: t.payment_method, amount: t.price_amount }];
      for (const p of parts) {
        if (p.method === 'cash') sums.cash += p.amount;
        else if (p.method === 'card') sums.card += p.amount;
        else if (p.method === 'szep_card') sums.szep += p.amount;
        else if (p.method === 'voucher') sums.voucher += p.amount;
        else if (p.method === 'experience_card') sums.experience += p.amount;
        else sums.other += p.amount;
      }
    }
    return sums;
  }

  /** Jegytípusonkénti darabszám és összeg (mint a pénzügyi kimutatásban): az összefoglalók „Ebből” sorai, a sorok együtt kiadják az összes jegyet. */
  categorySums(tickets: Ticket[]): FinanceRow[] {
    return this.typeRows(tickets);
  }

  get visibleCategorySums(): FinanceRow[] {
    return this.typeRows(this.visibleSellers.flatMap(s => s.tickets));
  }

  get dateLabel(): string {
    return this.date.replace(/-/g, '.') + '.';
  }

  isService(t: Ticket): boolean {
    return t.category === 'service';
  }

  /** A jegy típusa (a rank a sorrendet adja: mozi, színház / koncert, szolgáltatás, kedvezményes, ingyenes). */
  private ticketType(t: Ticket): { label: string; rank: number } {
    if (Number(t.price_amount) === 0) return { label: 'Ingyenes jegy', rank: 5 };
    if (t.category === 'service') return { label: t.movie_title, rank: 2 };
    if (t.category === 'experience_card') return { label: 'Élménykártyás jegy', rank: 3 };
    if (t.category === 'staff') return { label: 'Ingyenes jegy', rank: 4 };
    if (t.is_theater) return { label: t.movie_title, rank: 1 };
    return { label: t.section ? `Mozijegy – ${t.section}` : 'Mozijegy', rank: 0 };
  }

  /** Jegytípusonkénti bevétel és kiadott jegyek száma a megadott jegyekből. */
  private typeRows(tickets: Ticket[]): FinanceRow[] {
    const rows = new Map<string, FinanceRow>();
    for (const t of tickets) {
      const { label, rank } = this.ticketType(t);
      const row = rows.get(label) ?? { label, rank, tickets: 0, amount: 0 };
      row.tickets += 1;
      row.amount += Number(t.price_amount);
      rows.set(label, row);
    }
    return [...rows.values()].sort((a, b) => a.rank - b.rank || a.label.localeCompare(b.label, 'hu'));
  }

  get financeRows(): FinanceRow[] {
    return this.typeRows(this.visibleSellers.flatMap(s => s.tickets));
  }

  /** A jegyárak bruttó értékek; az ÁFA-kulcs (%) a nettó bevétel és az ÁFA számolásához. */
  readonly vatRate = 27;

  /** Bruttó összegből nettó és ÁFA: a nettó kerekített, az ÁFA a különbség (a kettő összege mindig a bruttó). */
  vatSplit(gross: number): { net: number; vat: number } {
    const net = Math.round(gross / (1 + this.vatRate / 100));
    return { net, vat: gross - net };
  }

  /** A pénzügyi kimutatás összesen sora: a nettó és az ÁFA a sorok értékeinek összege, így a tábla pontosan összeadódik. */
  get financeTotals(): { tickets: number; amount: number; net: number; vat: number } {
    const rows = this.financeRows;
    const parts = rows.map(r => this.vatSplit(r.amount));
    return {
      tickets: rows.reduce((sum, r) => sum + r.tickets, 0),
      amount: rows.reduce((sum, r) => sum + r.amount, 0),
      net: parts.reduce((sum, p) => sum + p.net, 0),
      vat: parts.reduce((sum, p) => sum + p.vat, 0),
    };
  }

  categoryTag(t: Ticket): string {
    return t.category && t.category !== 'standard' && t.category !== 'service' ? categoryLabel(t.category) : '';
  }

  channelLabel(channel: string): string {
    return ({ pos: 'Pénztár', admin: 'Admin', online: 'Online' } as Record<string, string>)[channel] ?? channel;
  }

  // ---- Összesítő megjelenítés ----

  /** Forint, ezres tagolással (szóközzel), sortörés nélkül. */
  ft(n: number): string {
    return `${Math.round(n).toLocaleString('hu-HU')}\u00A0Ft`;
  }

  /** A mindösszesen hány százaléka az adott összeg. */
  pct(amount: number): string {
    const total = this.visibleTotals.amount;
    return total > 0 ? `${Math.round((amount / total) * 100)}%` : '–';
  }

  /** Fizetési módonkénti sorok: a készpénz és a bankkártya mindig látszik, a többi csak ha volt forgalom. */
  get methodRows(): Array<{ key: string; icon: string; label: string; amount: number }> {
    const t = this.visibleTotals;
    return [
      { key: 'cash', icon: '💶', label: 'Készpénz', amount: t.cash, always: true },
      { key: 'card', icon: '💳', label: 'Bankkártya', amount: t.card, always: true },
      { key: 'szep', icon: '🏖️', label: 'SZÉP kártya', amount: t.szep, always: false },
      { key: 'voucher', icon: '🎟️', label: 'Utalvány', amount: t.voucher, always: false },
      { key: 'experience', icon: '🎁', label: 'Élménykártya (érték)', amount: t.experience, always: false },
      { key: 'other', icon: '🌐', label: 'Online (bankkártya)', amount: t.other, always: false }
    ].filter(r => r.always || r.amount > 0);
  }

  /** A jegytípusok bontása (képernyős panel és CSV): a sorok együtt kiadják az összes jegyet és a mindösszesen összeget. */
  get categoryRows(): FinanceRow[] {
    return this.typeRows(this.visibleSellers.flatMap(s => s.tickets));
  }

  // ---- CSV export ----

  /**
   * A látható napi zárás CSV-ben (Excel-barát: pontosvesszős, UTF-8 BOM-mal): fejléc, összesítők (fizetési mód, kategória, eladó),
   * majd minden jegy külön sorban, fizetési módonkénti oszlopokkal (részfizetésnél a jegy összege szétosztva).
   */
  exportCsv(): void {
    if (!this.close) return;

    const sellers = this.visibleSellers;
    const totals = this.visibleTotals;
    const current = this.auth.getCurrentUser();
    const day = this.date.replace(/-/g, '.') + '.';
    const filterNote = this.sellerFilter === 'all' ? 'Minden eladó' : (sellers[0]?.user_name ?? '');
    const dt = (v: string) => formatDate(v, 'yyyy-MM-dd HH:mm', 'en-US');

    const lines: Array<Array<string | number>> = [];
    const blank = () => lines.push([]);

    lines.push(['Napi zárás – Vigadó Online Jegyértékesítő']);
    lines.push(['Nap', day]);
    lines.push(['Eladó', filterNote]);
    lines.push(['Készítette', current?.name ?? '']);
    lines.push(['Készült', formatDate(new Date(), 'yyyy-MM-dd HH:mm', 'en-US')]);
    blank();

    lines.push(['FIZETÉSI MÓDOK']);
    lines.push(['Fizetési mód', 'Összeg (Ft)']);
    for (const m of this.methodRows) lines.push([m.label, m.amount]);
    lines.push(['Mindösszesen', totals.amount]);
    blank();

    lines.push(['JEGYTÍPUSOK']);
    lines.push(['Jegytípus', 'Darab', 'Összeg (Ft)']);
    for (const c of this.categoryRows) lines.push([c.label, c.tickets, c.amount]);
    lines.push(['Összesen', totals.tickets, totals.amount]);
    blank();

    lines.push(['PÉNZÜGYI KIMUTATÁS', day]);
    lines.push(['Jegytípus', 'Jegyek (db)', 'Nettó (Ft)', `ÁFA ${this.vatRate}% (Ft)`, 'Bruttó (Ft)']);
    for (const r of this.financeRows) {
      const { net, vat } = this.vatSplit(r.amount);
      lines.push([r.label, r.tickets, net, vat, r.amount]);
    }
    const fin = this.financeTotals;
    lines.push(['Összesen', fin.tickets, fin.net, fin.vat, fin.amount]);
    blank();

    lines.push(['ELADÓNKÉNT']);
    lines.push(['Eladó', 'Jegyek / nyugták (db)', 'Készpénz (Ft)', 'Bankkártya (Ft)', 'SZÉP kártya (Ft)', 'Utalvány (Ft)', 'Élménykártya (Ft)', 'Online (Ft)', 'Összesen (Ft)']);
    for (const s of sellers) {
      const x = this.sellerSums(s.tickets);
      lines.push([s.user_name, s.tickets.length, x.cash, x.card, x.szep, x.voucher, x.experience, x.other, x.amount]);
    }
    blank();

    lines.push(['TÉTELEK']);
    lines.push(['Eladó', 'Jegyszám', 'Eladva', 'Film / szolgáltatás', 'Kategória', 'Előadás kezdete', 'Szint', 'Sor', 'Szék', 'Fizetési mód',
      'Készpénz (Ft)', 'Bankkártya (Ft)', 'SZÉP kártya (Ft)', 'Utalvány (Ft)', 'Élménykártya (Ft)', 'Online (Ft)', 'Ár (Ft)']);
    for (const s of sellers) {
      for (const t of s.tickets) {
        const x = this.sellerSums([t]);
        const service = this.isService(t);
        lines.push([
          s.user_name, t.ticket_number, dt(t.sold_at), t.movie_title, categoryLabel(t.category),
          service ? '' : dt(t.starts_at), service ? '' : (t.section ?? ''), service ? '' : t.row_label, service ? '' : (t.seat_number ?? ''),
          ticketPaymentLabel(t), x.cash, x.card, x.szep, x.voucher, x.experience, x.other, t.price_amount
        ]);
      }
    }

    const cell = (v: string | number): string => {
      if (typeof v === 'number') return String(Math.round(v));
      // Táblázatkezelő-képlet beszúrás ellen: a =, +, -, @ kezdetű szöveg elé aposztróf kerül
      const text = /^[=+\-@\t\r]/.test(v) ? `'${v}` : v;
      return /[";\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
    };
    const csv = '\uFEFF' + lines.map(row => row.map(cell).join(';')).join('\r\n') + '\r\n';

    const who = this.sellerFilter === 'all' ? '' : '_' + (sellers[0]?.user_name ?? 'elado').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').toLowerCase();
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    link.download = `napi_zaras_${this.date}${who}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);

    this.admin.logClientEvent(
      'report.daily_close_export',
      `Napi zárás exportálása (CSV): ${this.date}${this.sellerFilter === 'all' ? '' : ' (egy eladó)'}`,
      { date: this.date, tickets: String(totals.tickets), amount: String(totals.amount) }
    );
  }

  // ---- Nyomtatás ----

  print(): void {
    if (!this.close) return;

    this.admin.logClientEvent(
      'report.daily_close_print',
      `Napi zárás nyomtatása: ${this.date}${this.sellerFilter === 'all' ? '' : ' (egy eladó)'}`,
      { date: this.date, seller_filter: this.sellerFilter, tickets: this.visibleTotals.tickets, amount: this.visibleTotals.amount }
    );

    this.printHtml(this.buildPrintHtml());
  }

  /** Csak a hivatalnak szánt pénzügyi kimutatás nyomtatása. */
  printFinance(): void {
    if (!this.close) return;

    this.admin.logClientEvent(
      'report.daily_close_print',
      `Pénzügyi kimutatás nyomtatása: ${this.date}${this.sellerFilter === 'all' ? '' : ' (egy eladó)'}`,
      { date: this.date, seller_filter: this.sellerFilter, tickets: this.visibleTotals.tickets, amount: this.visibleTotals.amount }
    );

    this.printHtml(`<!doctype html><html><head><meta charset="utf-8"><title>Pénzügyi kimutatás ${this.date}</title><style>${PRINT_CSS}</style></head><body>${this.financeHtml(false)}</body></html>`);
  }

  private printHtml(html: string): void {
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(frame);

    const doc = frame.contentDocument!;
    doc.open();
    doc.write(html);
    doc.close();

    setTimeout(() => {
      frame.contentWindow?.focus();
      frame.contentWindow?.print();
      setTimeout(() => frame.remove(), 1000);
    }, 150);
  }

  /** A „Pénzügyi kimutatás” lap: jegytípus, bevétel, jegyek (db), a nap dátumával; a napi zárás végén külön oldalon. */
  private financeHtml(newPage: boolean): string {
    const esc = (v: unknown) => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!));
    const ft = (n: number) => `${Math.round(n).toLocaleString('hu-HU')} Ft`;
    const totals = this.financeTotals;
    const sellerNote = this.sellerFilter === 'all' ? '' : ` • Eladó: ${this.visibleSellers[0]?.user_name ?? ''}`;
    const rows = this.financeRows.map(r => {
      const { net, vat } = this.vatSplit(r.amount);
      return `<tr><td>${esc(r.label)}</td><td class="r">${r.tickets} db</td><td class="r">${esc(ft(net))}</td><td class="r">${esc(ft(vat))}</td><td class="r"><b>${esc(ft(r.amount))}</b></td></tr>`;
    }).join('');

    return `<div class="${newPage ? 'finance newpage' : 'finance'}">
      <h1>Pénzügyi kimutatás</h1>
      <div class="sub">${esc(this.date.replace(/-/g, '.'))}.${esc(sellerNote)}</div>
      <table>
        <thead><tr><th>Jegytípus</th><th class="r">Jegyek (db)</th><th class="r">Nettó</th><th class="r">ÁFA ${this.vatRate}%</th><th class="r">Bruttó</th></tr></thead>
        <tbody>
          ${rows || '<tr><td colspan="5">Ezen a napon nem volt jegyeladás.</td></tr>'}
          <tr class="sub"><td>Összesen</td><td class="r">${totals.tickets} db</td><td class="r">${esc(ft(totals.net))}</td><td class="r">${esc(ft(totals.vat))}</td><td class="r">${esc(ft(totals.amount))}</td></tr>
        </tbody>
      </table>
      <div class="finance-note">A jegyárak bruttó értékek, a nettó összeg ${this.vatRate}% ÁFA-kulccsal számolt, jegytípusonként kerekített érték.</div>
      <div class="sign"><div>Pénztáros</div><div>Átvette</div></div>
    </div>`;
  }

  private buildPrintHtml(): string {
    const esc = (v: unknown) => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!));
    const ft = (n: number) => `${Math.round(n).toLocaleString('hu-HU')} Ft`;

    const current = this.auth.getCurrentUser();
    const sellers = this.visibleSellers;
    const totals = this.visibleTotals;
    const filterNote = this.sellerFilter === 'all' ? 'Minden eladó' : `Eladó: ${sellers[0]?.user_name ?? ''}`;

    // Eladónkénti és teljes összefoglaló azonos felépítésű: fizetési módok, kategóriák, mindösszesen
    const summaryRows = (
      x: { tickets: number; cash: number; card: number; szep: number; voucher: number; experience: number; other: number; amount: number },
      cats: Array<{ label: string; tickets: number; amount: number }>
    ) => `
      <tr class="count"><td>Eladott jegyek / nyugták száma</td><td class="r"><b>${x.tickets} db</b></td></tr>
      <tr><td>Készpénz</td><td class="r">${esc(ft(x.cash))}</td></tr>
      <tr><td>Bankkártya</td><td class="r">${esc(ft(x.card))}</td></tr>
      ${x.szep ? `<tr><td>SZÉP kártya</td><td class="r">${esc(ft(x.szep))}</td></tr>` : ''}
      ${x.voucher ? `<tr><td>Utalvány</td><td class="r">${esc(ft(x.voucher))}</td></tr>` : ''}
      ${x.experience ? `<tr><td>Élménykártya (érték)</td><td class="r">${esc(ft(x.experience))}</td></tr>` : ''}
      ${x.other ? `<tr><td>Egyéb (online)</td><td class="r">${esc(ft(x.other))}</td></tr>` : ''}
      ${cats.map(c => `<tr><td>Ebből: ${esc(c.label)}</td><td class="r">${c.tickets} db${c.amount ? ' • ' + esc(ft(c.amount)) : ''}</td></tr>`).join('')}
      <tr class="total"><td>Mindösszesen</td><td class="r">${esc(ft(x.amount))}</td></tr>`;

    const sections = sellers.map(seller => {
      const sums = this.sellerSums(seller.tickets);

      // Jegyenkénti lista nincs a nyomtatásban: csak az eladónkénti összefoglaló
      return `
        <div class="summary">
          <h3>${esc(seller.user_name)} – összefoglaló</h3>
          <table>${summaryRows({ tickets: seller.tickets.length, ...sums }, this.categorySums(seller.tickets))}</table>
        </div>`;
    }).join('');

    return `<!doctype html><html><head><meta charset="utf-8"><title>Napi zárás ${esc(this.date)}</title><style>${PRINT_CSS}</style></head><body>
      <h1>Napi zárás – Vigadó Online Jegyértékesítő</h1>
      <div class="sub">${esc(this.date.replace(/-/g, '.'))}. • ${esc(filterNote)}</div>
      <div class="meta">
        <span>Nyomtatta: ${esc(current?.name ?? '-')}</span>
        <span>Nyomtatva: ${esc(formatDate(new Date(), 'yyyy.MM.dd. HH:mm', 'en-US'))}</span>
      </div>
      ${sections || '<p>Ezen a napon nem volt jegyeladás.</p>'}
      <div class="grand">
        <h3>Napi elszámolás</h3>
        <table>${summaryRows(totals, this.visibleCategorySums)}</table>
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
