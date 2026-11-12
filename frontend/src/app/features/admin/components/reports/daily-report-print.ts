import { escapeHtml } from '../../../../core/util/html';
import { paymentLabel } from '../ticket-print/ticket-print.component';

export interface DailyReportData {
  date: string;
  summary: any;
  sales: any[];
  issuedBy: string;
  logoUrl: string;
}

const WEEKDAYS = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];
const CHANNELS: Record<string, string> = { pos: 'Pénztár', admin: 'Admin', online: 'Online' };
const ft = (n: number | string) => `${Math.round(Number(n) || 0).toLocaleString('hu-HU')}\u00A0Ft`;
const num = (n: number | string) => Math.round(Number(n) || 0).toLocaleString('hu-HU');
const tz = { timeZone: 'Europe/Budapest' } as const;
const hm = (v: string) => new Date(v).toLocaleTimeString('hu-HU', { ...tz, hour: '2-digit', minute: '2-digit' });
const dayTime = (v: string) => new Date(v).toLocaleString('hu-HU', { ...tz, month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
const fullTime = (v: string) => new Date(v).toLocaleString('hu-HU', { ...tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });

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
  .kpis { display: grid; grid-template-columns: 1.4fr 1fr 1fr 1fr; gap: 10px; margin: 14px 0; }
  .kpi { border: 1px solid #e3d3ba; background: #fbf6ec; border-radius: 8px; padding: 10px 14px; }
  .kpi .l { font-size: 9.5px; letter-spacing: 1.2px; text-transform: uppercase; color: #8b6f47; }
  .kpi .v { font-size: 21px; font-weight: 800; margin-top: 2px; white-space: nowrap; }
  .kpi .s { font-size: 10px; color: #8b6f47; }
  .kpi.hl { background: #2c1810; border-color: #2c1810; color: #fff; }
  .kpi.hl .l, .kpi.hl .s { color: #d4a574; }
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
  tfoot td { font-weight: 800; border-top: 2px solid #2c1810; border-bottom: none; background: #f4ead7; }
  .bar { height: 7px; border-radius: 4px; background: #d4a574; min-width: 2px; }
  .muted { color: #8b6f47; }
  .empty { color: #8b6f47; font-style: italic; padding: 6px 0; }
  .tag { margin-left: 4px; padding: 0 4px; border-radius: 4px; background: #e8d5b7; font-size: 8.5px; font-weight: 700; white-space: nowrap; }
  footer { margin-top: 10px; padding: 10px 14px; background: #f4ead7; border: 1px solid #e3d3ba; border-radius: 8px; font-size: 10px; color: #5c3d2e; display: flex; justify-content: space-between; gap: 14px; page-break-inside: avoid; }
  footer b { color: #2c1810; }
  .no-print { position: fixed; top: 10px; right: 10px; }
  @media print { .no-print { display: none; } }
`;

/**
 * A napi riport nyomtatható / PDF-be menthető HTML-je (A4): összesítő, fizetési módok, értékesítés típusa és csatornája,
 * óránkénti forgalom, eladók, előadások / programok, egyéb szolgáltatások és az eladások listája.
 */
export function buildDailyReportHtml(d: DailyReportData): string {
  const e = escapeHtml;
  const s = d.summary;
  const [y, m, day] = d.date.split('-').map(Number);
  const dayLabel = `${y}. ${String(m).padStart(2, '0')}. ${String(day).padStart(2, '0')}. ${WEEKDAYS[new Date(y, m - 1, day).getDay()]}`;
  const total = Number(s.total_amount ?? 0);
  const pct = (a: number) => (total > 0 ? `${Math.round((Number(a) / total) * 100)}%` : '–');

  const methodRows = (s.by_payment_method ?? []).map((i: any) => `
    <tr><td>${e(i.method && i.method !== 'unknown' ? paymentLabel(i.method) : 'Ismeretlen')}</td>
      <td class="c">${num(i.count)}</td><td class="r">${e(ft(i.amount))}</td><td class="r muted">${pct(i.amount)}</td></tr>`).join('');

  const kindRows = (s.by_kind ?? []).map((i: any) => `
    <tr><td>${e(i.label)}</td><td class="c">${num(i.tickets)}</td><td class="r">${e(ft(i.amount))}</td></tr>`).join('');
  const channelRows = (s.by_channel ?? []).map((i: any) => `
    <tr><td>${e(CHANNELS[i.key] ?? i.key)}</td><td class="c">${num(i.tickets)}</td><td class="r">${e(ft(i.amount))}</td></tr>`).join('');
  const sellerRows = (s.by_seller ?? []).map((i: any) => `
    <tr><td>${e(i.label)}</td><td class="c">${num(i.tickets)}</td><td class="r">${e(ft(i.amount))}</td></tr>`).join('');

  const maxHour = Math.max(1, ...(s.hourly ?? []).map((h: any) => Number(h.amount)));
  const hourRows = (s.hourly ?? []).map((h: any) => `
    <tr><td>${String(h.hour).padStart(2, '0')}:00</td><td class="c">${num(h.count)}</td>
      <td><div class="bar" style="width:${Math.max(2, Math.round((Number(h.amount) / maxHour) * 100))}%"></div></td>
      <td class="r">${e(ft(h.amount))}</td></tr>`).join('');

  const programRows = (s.by_program ?? []).map((i: any) => `
    <tr><td>${i.starts_at ? e(dayTime(i.starts_at)) : '—'}</td>
      <td>${e(i.title)}${i.kind === 'theater' ? '<span class="tag">program</span>' : ''}</td>
      <td>${e(i.auditorium_name || '—')}</td><td class="c">${num(i.tickets)}</td>
      <td class="c">${i.online_tickets ? num(i.online_tickets) : '—'}</td><td class="r">${e(ft(i.amount))}</td></tr>`).join('');
  const programTotal = (s.by_program ?? []).reduce((sum: number, i: any) => sum + Number(i.amount), 0);

  const serviceRows = (s.by_service ?? []).map((i: any) => `
    <tr><td>${e(i.name)}</td><td class="c">${num(i.tickets)}</td><td class="r">${e(ft(i.amount))}</td></tr>`).join('');
  const serviceTotal = (s.by_service ?? []).reduce((sum: number, i: any) => sum + Number(i.amount), 0);

  const salePay = (sale: any) => {
    const parts: Array<{ method: string; amount: number }> = sale.payments ?? [];
    return parts.length > 1
      ? parts.map(p => `${paymentLabel(p.method)} ${ft(p.amount)}`).join(' + ')
      : (parts[0] ? paymentLabel(parts[0].method) : '—');
  };
  const saleRows = d.sales.map((x: any) => `<tr>
      <td>${e(hm(x.sold_at))}</td><td>${e(x.seller)}</td><td>${e(CHANNELS[x.channel] ?? x.channel)}</td>
      <td>${e(x.customer && x.customer !== 'Helyszíni vásárló' ? x.customer : '—')}</td>
      <td>${e(x.title)}${x.starts_at ? `<br><span class="muted">${e(dayTime(x.starts_at))}</span>` : ''}</td>
      <td class="c">${num(x.tickets)}</td><td>${e(salePay(x))}</td><td class="r">${e(ft(x.amount))}</td></tr>`).join('');

  const table = (cols: string, head: string, rows: string, foot = '') =>
    `<table><colgroup>${cols}</colgroup><thead><tr>${head}</tr></thead><tbody>${rows}</tbody>${foot ? `<tfoot>${foot}</tfoot>` : ''}</table>`;
  const w = (...p: number[]) => p.map(x => `<col style="width:${x}%">`).join('');

  return `<!DOCTYPE html>
<html lang="hu"><head><meta charset="utf-8"><title>Napi riport – ${e(d.date)}</title><style>${CSS}</style></head>
<body>
  <div class="band">
    <img src="${e(d.logoUrl)}" alt="Vigadó Online Jegyértékesítő">
    <div class="doc">
      <div class="kind">Vigadó Online Jegyértékesítő • Kisújszállás</div>
      <h1>Napi riport</h1>
      <div class="day">${e(dayLabel)}</div>
    </div>
  </div>

  <div class="kpis">
    <div class="kpi hl"><div class="l">Bevétel</div><div class="v">${e(ft(total))}</div><div class="s">minden eladás (jegy, szolgáltatás, online)</div></div>
    <div class="kpi"><div class="l">Jegyek</div><div class="v">${num(s.total_tickets)}</div></div>
    <div class="kpi"><div class="l">Szolgáltatás-nyugta</div><div class="v">${num(s.total_services)}</div></div>
    <div class="kpi"><div class="l">Eladások</div><div class="v">${num(s.total_sales)}</div></div>
  </div>

  <div class="cols">
    <section>
      <h2>Fizetési módok</h2>
      ${methodRows ? table(w(40, 16, 30, 14), '<th>Mód</th><th class="c">Db</th><th class="r">Összeg</th><th class="r">Arány</th>', methodRows,
        `<td>Összesen</td><td class="c">${num(s.total_receipts)}</td><td class="r">${e(ft(total))}</td><td></td>`) : '<div class="empty">Nincs adat.</div>'}
    </section>
    <section>
      <h2>Óránkénti forgalom</h2>
      ${hourRows ? table(w(18, 14, 36, 32), '<th>Óra</th><th class="c">Db</th><th></th><th class="r">Bevétel</th>', hourRows) : '<div class="empty">Nincs adat.</div>'}
    </section>
  </div>

  <div class="cols">
    <section>
      <h2>Értékesítés típusa</h2>
      ${table(w(52, 16, 32), '<th>Típus</th><th class="c">Db</th><th class="r">Bevétel</th>', kindRows)}
    </section>
    <section>
      <h2>Értékesítési csatorna</h2>
      ${table(w(52, 16, 32), '<th>Csatorna</th><th class="c">Db</th><th class="r">Bevétel</th>', channelRows)}
    </section>
  </div>

  <section>
    <h2>Előadások és programok</h2>
    ${programRows ? table(w(13, 36, 15, 8, 10, 18), '<th>Időpont</th><th>Cím</th><th>Terem</th><th class="c">Jegy</th><th class="c">Online</th><th class="r">Bevétel</th>', programRows,
      `<td colspan="3">Összesen</td><td class="c">${num(s.total_tickets)}</td><td></td><td class="r">${e(ft(programTotal))}</td>`) : '<div class="empty">Ezen a napon nem volt jegyeladás előadásra.</div>'}
  </section>

  ${serviceRows ? `<section>
    <h2>Egyéb szolgáltatások</h2>
    ${table(w(58, 16, 26), '<th>Szolgáltatás</th><th class="c">Nyugta</th><th class="r">Bevétel</th>', serviceRows,
      `<td>Összesen</td><td class="c">${num(s.total_services)}</td><td class="r">${e(ft(serviceTotal))}</td>`)}
  </section>` : ''}

  <section>
    <h2>Eladónként</h2>
    ${table(w(58, 16, 26), '<th>Eladó</th><th class="c">Db</th><th class="r">Bevétel</th>', sellerRows,
      `<td>Összesen</td><td class="c">${num(s.total_receipts)}</td><td class="r">${e(ft(total))}</td>`)}
  </section>

  ${saleRows ? `<section style="page-break-inside:auto">
    <h2>Eladások listája</h2>
    ${table(w(6, 11, 8, 13, 26, 5, 19, 12), '<th>Idő</th><th>Eladó</th><th>Csatorna</th><th>Vevő</th><th>Tétel</th><th class="c">Db</th><th>Fizetés</th><th class="r">Összeg</th>', saleRows)}
  </section>` : ''}

  <footer>
    <div><b>Kisújszállási Művelődési Központ és Könyvtár</b> – Vigadó Online Jegyértékesítő<br>5310 Kisújszállás, Szabadság tér 7. • +36-59-328-083 • info@vigadokisuj.hu</div>
    <div style="text-align:right">Nyomtatta: <b>${e(d.issuedBy || '—')}</b><br>${e(fullTime(new Date().toISOString()))}</div>
  </footer>
  <div class="no-print"><button onclick="window.print()">Nyomtatás / PDF</button></div>
</body></html>`;
}
