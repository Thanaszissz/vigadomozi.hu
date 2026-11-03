import { escapeHtml } from '../../../../core/util/html';
import { paymentLabel } from '../ticket-print/ticket-print.component';

export interface DailyReportData {
  date: string;
  summary: any;
  reservations: any[];
  issuedBy: string;
  logoUrl: string;
}

const WEEKDAYS = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];
const ft = (n: number | string) => `${Math.round(Number(n) || 0).toLocaleString('hu-HU')}\u00A0Ft`;
const num = (n: number | string) => Math.round(Number(n) || 0).toLocaleString('hu-HU');
const tz = { timeZone: 'Europe/Budapest' } as const;
const hm = (v: string) => new Date(v).toLocaleTimeString('hu-HU', { ...tz, hour: '2-digit', minute: '2-digit' });
const dayTime = (v: string) => new Date(v).toLocaleString('hu-HU', { ...tz, month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });

const CSS = `
  @page { size: A4; margin: 12mm; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; }
  body { font-family: 'Segoe UI', Arial, Helvetica, sans-serif; color: #2c1810; font-size: 11.5px; line-height: 1.4; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .band { background: #2c1810; color: #f3e3c8; padding: 16px 22px; display: flex; align-items: center; justify-content: space-between; gap: 20px; border-bottom: 4px solid #d4a574; border-radius: 8px 8px 0 0; }
  .band img { height: 46px; width: auto; display: block; }
  .band .doc { text-align: right; }
  .band .kind { font-size: 10px; letter-spacing: 2px; text-transform: uppercase; color: #d4a574; }
  .band h1 { margin: 2px 0 0; font-size: 22px; color: #fff; }
  .band .day { margin-top: 2px; font-size: 13px; color: #f0d9b3; font-weight: 600; text-transform: capitalize; }
  .kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 14px 0; }
  .kpi { border: 1px solid #e3d3ba; background: #fbf6ec; border-radius: 8px; padding: 10px 14px; }
  .kpi .l { font-size: 9.5px; letter-spacing: 1.2px; text-transform: uppercase; color: #8b6f47; }
  .kpi .v { font-size: 22px; font-weight: 800; margin-top: 2px; white-space: nowrap; }
  .kpi.hl { background: #2c1810; border-color: #2c1810; color: #fff; }
  .kpi.hl .l { color: #d4a574; }
  .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 4px; }
  section { margin-bottom: 14px; page-break-inside: avoid; }
  h2 { margin: 0 0 6px; padding-bottom: 4px; font-size: 10px; letter-spacing: 1.4px; text-transform: uppercase; color: #8b6f47; border-bottom: 2px solid #2c1810; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  th { text-align: left; font-size: 9px; letter-spacing: 0.8px; text-transform: uppercase; color: #8b6f47; padding: 4px 6px; border-bottom: 1px solid #c9b48f; }
  td { padding: 4px 6px; border-bottom: 1px solid #eadfcb; vertical-align: top; overflow-wrap: anywhere; }
  tr { page-break-inside: avoid; }
  tbody tr:nth-child(even) td { background: #fcf9f2; }
  td.r, th.r { text-align: right; white-space: nowrap; }
  td.c, th.c { text-align: center; }
  td.num { font-family: 'Courier New', monospace; font-weight: 700; }
  tfoot td { font-weight: 800; border-top: 2px solid #2c1810; border-bottom: none; background: #f4ead7; }
  .bar { height: 7px; border-radius: 4px; background: #d4a574; min-width: 2px; }
  .muted { color: #8b6f47; }
  .empty { color: #8b6f47; font-style: italic; padding: 6px 0; }
  footer { margin-top: 10px; padding: 10px 14px; background: #f4ead7; border: 1px solid #e3d3ba; border-radius: 8px; font-size: 10px; color: #5c3d2e; display: flex; justify-content: space-between; gap: 14px; page-break-inside: avoid; }
  footer b { color: #2c1810; }
  .no-print { position: fixed; top: 10px; right: 10px; }
  @media print { .no-print { display: none; } }
`;

/** A napi riport nyomtatható / PDF-be menthető HTML-je (A4): fejléc, összesítő, fizetési módok, óránkénti forgalom, előadások, adminisztrátorok és a foglalások listája. */
export function buildDailyReportHtml(d: DailyReportData): string {
  const e = escapeHtml;
  const s = d.summary;
  const [y, m, day] = d.date.split('-').map(Number);
  const dateObj = new Date(y, m - 1, day);
  const dayLabel = `${y}. ${String(m).padStart(2, '0')}. ${String(day).padStart(2, '0')}. ${WEEKDAYS[dateObj.getDay()]}`;
  const total = Number(s.total_amount ?? 0);

  const methodRows = (s.by_payment_method ?? []).map((i: any) => `
    <tr><td>${e(i.method && i.method !== 'unknown' ? paymentLabel(i.method) : 'Ismeretlen')}</td>
      <td class="c">${num(i.count)}</td><td class="r">${e(ft(i.amount))}</td>
      <td class="r muted">${total > 0 ? Math.round((Number(i.amount) / total) * 100) : 0}%</td></tr>`).join('');

  const maxHour = Math.max(1, ...(s.hourly ?? []).map((h: any) => Number(h.amount)));
  const hourRows = (s.hourly ?? []).map((h: any) => `
    <tr><td>${String(h.hour).padStart(2, '0')}:00</td><td class="c">${num(h.count)}</td>
      <td><div class="bar" style="width:${Math.max(2, Math.round((Number(h.amount) / maxHour) * 100))}%"></div></td>
      <td class="r">${e(ft(h.amount))}</td></tr>`).join('');

  const showRows = [...(s.by_showtime ?? [])]
    .sort((a: any, b: any) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime())
    .map((i: any) => `
    <tr><td>${e(dayTime(i.starts_at))}</td><td>${e(i.movie_title)}</td><td>${e(i.auditorium_name)}</td>
      <td class="c">${num(i.reservations_count)}</td><td class="c">${num(i.tickets_count)}</td><td class="r">${e(ft(i.total_amount))}</td></tr>`).join('');

  const adminRows = (s.by_admin ?? []).map((i: any) => `
    <tr><td>${e(i.admin_name)}</td><td class="muted">${e(i.admin_email && i.admin_email !== 'N/A' ? i.admin_email : '')}</td>
      <td class="c">${num(i.reservations_count)}</td><td class="c">${num(i.tickets_count)}</td><td class="r">${e(ft(i.total_amount))}</td></tr>`).join('');

  const listRows = [...d.reservations].map((r: any) => {
    const seats = (r.items ?? []).map((i: any) => `${i.row_label}/${i.seat_number}`).join(', ');
    const st = r.showtime;
    return `<tr>
      <td class="num">#${e(r.id)}</td><td>${e(hm(r.created_at))}</td><td>${e(r.customer_name || '—')}</td>
      <td>${e(st?.movie?.title || st?.theater_show?.title || '—')}${st?.starts_at ? `<br><span class="muted">${e(dayTime(st.starts_at))}</span>` : ''}</td>
      <td>${e(seats)}</td><td>${e(r.payment_provider ? paymentLabel(r.payment_provider) : '—')}</td>
      <td>${e(r.handled_by_admin?.name || '—')}</td><td class="r">${e(ft(r.total_amount))}</td></tr>`;
  }).join('');

  return `<!DOCTYPE html>
<html lang="hu"><head><meta charset="utf-8"><title>Napi riport – ${e(d.date)}</title><style>${CSS}</style></head>
<body>
  <div class="band">
    <img src="${e(d.logoUrl)}" alt="Vigadó Jegy">
    <div class="doc">
      <div class="kind">Vigadó Jegy • Kisújszállás</div>
      <h1>Napi riport</h1>
      <div class="day">${e(dayLabel)}</div>
    </div>
  </div>

  <div class="kpis">
    <div class="kpi"><div class="l">Foglalások</div><div class="v">${num(s.total_reservations)}</div></div>
    <div class="kpi"><div class="l">Jegyek</div><div class="v">${num(s.total_tickets)}</div></div>
    <div class="kpi hl"><div class="l">Bevétel</div><div class="v">${e(ft(total))}</div></div>
  </div>

  <div class="cols">
    <section>
      <h2>Fizetési módok</h2>
      ${methodRows ? `<table>
        <colgroup><col style="width:42%"><col style="width:16%"><col style="width:28%"><col style="width:14%"></colgroup>
        <thead><tr><th>Mód</th><th class="c">Foglalás</th><th class="r">Összeg</th><th class="r">Arány</th></tr></thead>
        <tbody>${methodRows}</tbody>
        <tfoot><tr><td>Összesen</td><td class="c">${num(s.total_reservations)}</td><td class="r">${e(ft(total))}</td><td></td></tr></tfoot>
      </table>` : '<div class="empty">Nincs adat.</div>'}
    </section>
    <section>
      <h2>Óránkénti forgalom</h2>
      ${hourRows ? `<table>
        <colgroup><col style="width:18%"><col style="width:16%"><col style="width:34%"><col style="width:32%"></colgroup>
        <thead><tr><th>Óra</th><th class="c">Foglalás</th><th></th><th class="r">Bevétel</th></tr></thead>
        <tbody>${hourRows}</tbody>
      </table>` : '<div class="empty">Nincs adat.</div>'}
    </section>
  </div>

  <section>
    <h2>Előadások szerinti bevétel</h2>
    ${showRows ? `<table>
      <colgroup><col style="width:14%"><col style="width:36%"><col style="width:16%"><col style="width:10%"><col style="width:9%"><col style="width:15%"></colgroup>
      <thead><tr><th>Időpont</th><th>Film / előadás</th><th>Terem</th><th class="c">Foglalás</th><th class="c">Jegy</th><th class="r">Bevétel</th></tr></thead>
      <tbody>${showRows}</tbody>
      <tfoot><tr><td colspan="3">Összesen</td><td class="c">${num(s.total_reservations)}</td><td class="c">${num(s.total_tickets)}</td><td class="r">${e(ft(total))}</td></tr></tfoot>
    </table>` : '<div class="empty">Ezen a napon nem volt kifizetett foglalás.</div>'}
  </section>

  ${adminRows ? `<section>
    <h2>Adminisztrátoronként</h2>
    <table>
      <colgroup><col style="width:28%"><col style="width:30%"><col style="width:12%"><col style="width:10%"><col style="width:20%"></colgroup>
      <thead><tr><th>Név</th><th>E-mail</th><th class="c">Foglalás</th><th class="c">Jegy</th><th class="r">Bevétel</th></tr></thead>
      <tbody>${adminRows}</tbody>
      <tfoot><tr><td colspan="2">Összesen</td><td class="c">${num(s.total_reservations)}</td><td class="c">${num(s.total_tickets)}</td><td class="r">${e(ft(total))}</td></tr></tfoot>
    </table>
  </section>` : ''}

  ${listRows ? `<section style="page-break-inside:auto">
    <h2>Kifizetett foglalások</h2>
    <table>
      <colgroup><col style="width:7%"><col style="width:7%"><col style="width:15%"><col style="width:25%"><col style="width:13%"><col style="width:11%"><col style="width:10%"><col style="width:12%"></colgroup>
      <thead><tr><th>#</th><th>Idő</th><th>Foglaló</th><th>Előadás</th><th>Székek</th><th>Fizetés</th><th>Kezelte</th><th class="r">Összeg</th></tr></thead>
      <tbody>${listRows}</tbody>
    </table>
  </section>` : ''}

  <footer>
    <div><b>Kisújszállási Művelődési Központ és Könyvtár</b> – Vigadó Jegy<br>5310 Kisújszállás, Szabadság tér 7. • +36-59-328-083 • info@vigadojegy.hu</div>
    <div style="text-align:right">Nyomtatta: <b>${e(d.issuedBy || '—')}</b><br>${e(new Date().toLocaleString('hu-HU', { ...tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }))}</div>
  </footer>
  <div class="no-print"><button onclick="window.print()">Nyomtatás / PDF</button></div>
</body></html>`;
}
