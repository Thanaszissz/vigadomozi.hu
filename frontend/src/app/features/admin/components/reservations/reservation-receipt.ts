import { escapeHtml } from '../../../../core/util/html';
import { categoryLabel } from '../../../../services/admin.service';
import { paymentLabel } from '../ticket-print/ticket-print.component';
import { placeLabel } from '../../../../core/util/seat';

export interface ReceiptData {
  reservation: any;
  showtime: any;
  tickets: any[];
  issuedBy: string;
  logoUrl: string;
}

const ft = (n: number | string) => `${Math.round(Number(n) || 0).toLocaleString('hu-HU')}\u00A0Ft`;
const when = (v: string | null | undefined, opts: Intl.DateTimeFormatOptions) =>
  v ? new Date(v).toLocaleString('hu-HU', { timeZone: 'Europe/Budapest', ...opts }) : '';

const CSS = `
  @page { size: A4; margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; }
  body { font-family: 'Segoe UI', Arial, Helvetica, sans-serif; color: #2c1810; font-size: 13px; line-height: 1.45; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .page { width: 210mm; min-height: 297mm; display: flex; flex-direction: column; }
  .band { background: #2c1810; color: #f3e3c8; padding: 22px 32px; display: flex; align-items: center; justify-content: space-between; gap: 24px; border-bottom: 4px solid #d4a574; }
  .band img { height: 54px; width: auto; display: block; }
  .band .doc { text-align: right; }
  .band .doc .kind { font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: #d4a574; }
  .band .doc h1 { margin: 2px 0 0; font-size: 24px; letter-spacing: 0.5px; color: #fff; }
  .band .doc .no { margin-top: 3px; font-size: 12px; color: #d9c3a0; }
  .body { padding: 26px 32px 10px; flex: 1; }
  .paid { display: inline-block; margin-bottom: 16px; padding: 4px 14px; border: 2px solid #2e7d32; color: #2e7d32; border-radius: 6px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; font-size: 12px; }
  .show { border-left: 5px solid #d4a574; padding: 4px 0 4px 16px; margin-bottom: 22px; }
  .show .label { font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: #8b6f47; }
  .show h2 { margin: 2px 0 4px; font-size: 22px; color: #2c1810; }
  .show .when { font-size: 15px; font-weight: 600; }
  .show .where { color: #5c3d2e; }
  .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 22px; }
  .card { border: 1px solid #e3d3ba; border-radius: 8px; padding: 12px 14px; background: #fbf6ec; }
  .card h3 { margin: 0 0 8px; font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: #8b6f47; }
  .card dl { margin: 0; display: grid; grid-template-columns: 92px 1fr; gap: 4px 10px; }
  .card dt { color: #8b6f47; }
  .card dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
  h3.sec { margin: 0 0 8px; font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: #8b6f47; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
  th { text-align: left; font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: #8b6f47; padding: 6px 8px; border-bottom: 2px solid #2c1810; }
  td { padding: 7px 8px; border-bottom: 1px solid #eadfcb; vertical-align: top; }
  tr { page-break-inside: avoid; }
  td.r, th.r { text-align: right; white-space: nowrap; }
  td.num { font-family: 'Courier New', monospace; font-weight: 700; }
  .tag { margin-left: 6px; padding: 1px 6px; border-radius: 6px; background: #e8d5b7; font-size: 10px; font-weight: 700; }
  .bottom { display: grid; grid-template-columns: 1fr 250px; gap: 18px; align-items: start; page-break-inside: avoid; }
  .methods table { margin: 0; }
  .methods td { padding: 5px 8px; }
  .total { background: #2c1810; color: #fff; border-radius: 10px; padding: 14px 18px; text-align: right; }
  .total .label { font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: #d4a574; }
  .total .amount { font-size: 28px; font-weight: 800; margin-top: 2px; white-space: nowrap; }
  .total .count { font-size: 12px; color: #d9c3a0; }
  .sign { margin-top: 34px; display: flex; justify-content: space-between; align-items: flex-end; gap: 30px; color: #5c3d2e; font-size: 12px; page-break-inside: avoid; }
  .sign .line { flex: 1; border-top: 1px solid #8b6f47; padding-top: 4px; text-align: center; }
  footer { margin-top: 18px; padding: 14px 32px 18px; background: #f4ead7; border-top: 1px solid #e3d3ba; font-size: 11px; color: #5c3d2e; display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
  footer b { color: #2c1810; }
  .no-print { position: fixed; top: 10px; right: 10px; }
  @media print { .no-print { display: none; } }
`;

/** A befizetési igazolás nyomtatható HTML-je (A4): fejléc, előadás, foglaló és fizetés adatai, jegyek, fizetési módonkénti bontás, végösszeg. */
export function buildReceiptHtml(d: ReceiptData): string {
  const e = escapeHtml;
  const r = d.reservation;
  const s = d.showtime;
  const items: any[] = d.tickets.length ? d.tickets : (r.items ?? []);
  const hasTickets = d.tickets.length > 0;

  const email = r.customer_email && !String(r.customer_email).endsWith('@vigadojegy.hu') ? r.customer_email : '';
  const showWhen = when(s?.starts_at, { year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'long', hour: '2-digit', minute: '2-digit' });

  // Módonkénti bontás: a jegyek (részfizetésnél a részek) összege; jegyek nélkül a foglalás teljes összege egy módon
  const byMethod = new Map<string, number>();
  if (hasTickets) {
    for (const t of d.tickets) {
      const parts = t.payment_split?.length ? t.payment_split : [{ method: t.payment_method, amount: t.price_amount }];
      for (const p of parts) byMethod.set(p.method, (byMethod.get(p.method) ?? 0) + Number(p.amount));
    }
  } else {
    byMethod.set(r.payment_provider ?? '', Number(r.total_amount ?? 0));
  }
  const methodRows = [...byMethod.entries()]
    .map(([m, amount]) => `<tr><td>${e(m ? paymentLabel(m) : '—')}</td><td class="r">${e(ft(amount))}</td></tr>`)
    .join('');
  const methodText = byMethod.size > 1 ? 'Vegyes fizetés' : (r.payment_provider ? paymentLabel(r.payment_provider) : '—');

  const rows = items.map((i, idx) => {
    const place = placeLabel(i);
    const cat = i.category && i.category !== 'standard' ? `<span class="tag">${e(categoryLabel(i.category))}</span>` : '';
    return `<tr>
      <td>${idx + 1}.</td>
      ${hasTickets ? `<td class="num">${e(i.ticket_number)}</td>` : ''}
      <td>${e(place)}${cat}</td>
      <td class="r">${e(ft(i.price_amount))}</td>
    </tr>`;
  }).join('');

  const total = Number(r.total_amount ?? items.reduce((sum, i) => sum + Number(i.price_amount), 0));

  return `<!DOCTYPE html>
<html lang="hu"><head><meta charset="utf-8"><title>Befizetési igazolás – #${e(r.id)}</title><style>${CSS}</style></head>
<body><div class="page">
  <div class="band">
    <img src="${e(d.logoUrl)}" alt="Vigadó Online Jegyértékesítő">
    <div class="doc">
      <div class="kind">Vigadó Online Jegyértékesítő • Kisújszállás</div>
      <h1>Befizetési igazolás</h1>
      <div class="no">Foglalás #${e(r.id)} • Kiállítva: ${e(when(new Date().toISOString(), { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }))}</div>
    </div>
  </div>

  <div class="body">
    <div class="paid">✓ Kifizetve</div>

    <div class="show">
      <div class="label">Előadás</div>
      <h2>${e(s?.movie?.title || 'N/A')}</h2>
      <div class="when">${e(showWhen)}</div>
      <div class="where">${e(s?.auditorium?.name || '')}</div>
    </div>

    <div class="cols">
      <div class="card">
        <h3>Foglaló</h3>
        <dl>
          <dt>Név</dt><dd>${e(r.customer_name || '—')}</dd>
          <dt>Telefon</dt><dd>${e(r.customer_phone || '—')}</dd>
          <dt>E-mail</dt><dd>${e(email || '—')}</dd>
        </dl>
      </div>
      <div class="card">
        <h3>Fizetés</h3>
        <dl>
          <dt>Mód</dt><dd>${e(methodText)}</dd>
          <dt>Foglalva</dt><dd>${e(when(r.created_at, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }) || '—')}</dd>
          <dt>Kezelte</dt><dd>${e(r.handled_by_admin?.name || d.issuedBy || '—')}</dd>
        </dl>
      </div>
    </div>

    <h3 class="sec">Jegyek</h3>
    <table>
      <thead><tr><th style="width:34px">#</th>${hasTickets ? '<th style="width:120px">Jegyszám</th>' : ''}<th>Hely</th><th class="r">Ár</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="4">—</td></tr>'}</tbody>
    </table>

    <div class="bottom">
      <div class="methods">
        <h3 class="sec">Fizetési módok</h3>
        <table><tbody>${methodRows}</tbody></table>
      </div>
      <div class="total">
        <div class="label">Fizetett összeg</div>
        <div class="amount">${e(ft(total))}</div>
        <div class="count">${items.length} jegy</div>
      </div>
    </div>

    <div class="sign">
      <div>Kiállította: <b>${e(d.issuedBy || '—')}</b></div>
      <div class="line">Pecsét / aláírás</div>
    </div>
  </div>

  <footer>
    <div><b>Kisújszállási Művelődési Központ és Könyvtár</b> – Vigadó Online Jegyértékesítő<br>5310 Kisújszállás, Szabadság tér 7.</div>
    <div>+36-59-328-083<br>info@vigadokisuj.hu</div>
  </footer>
</div>
<div class="no-print"><button onclick="window.print()">Nyomtatás / PDF</button></div>
</body></html>`;
}
