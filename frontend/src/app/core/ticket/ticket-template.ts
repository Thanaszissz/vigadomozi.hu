// A jegy közös kinézete (210 mm x 100 mm): a nyomtatás, a PDF-letöltés és az előnézet ugyanezt használja.
// A jegy 840 x 400 px-es vásznon van megrajzolva, a méretezést a burkoló végzi.

export interface TicketView {
  ticket_number: string;
  movie_title: string;
  starts_at: string;
  auditorium_name: string | null;
  section?: string | null;
  row_label: string;
  seat_number: number | null;
  price_amount: number;
  category?: string | null;
  sold_at?: string;
}

export const TICKET_W = 840;
export const TICKET_H = 400;
// 210 mm kb. 793,7 px, ennyivel kell skálázni a vásznat, hogy pontosan 210 mm széles legyen
export const TICKET_PRINT_SCALE = 793.7 / TICKET_W;

export const TICKET_CSS = `
  .tk-box { position: relative; overflow: hidden; background: #fff; }
  .tk { position: absolute; left: 0; top: 0; width: ${TICKET_W}px; height: ${TICKET_H}px; transform-origin: 0 0;
    font-family: Georgia, 'Times New Roman', serif; color: #111; }
  .tk-bg { position: absolute; left: 0; top: 0; width: 100%; height: 100%; }
  .tk-abs { position: absolute; box-sizing: border-box; text-align: center; }

  .tk-main { left: 34px; top: 136px; width: 566px; }
  .tk-title { font-weight: 700; line-height: 1.1; display: flex; align-items: center; justify-content: center; height: 62px; }
  .tk-when { font-size: 23px; margin-top: 2px; }
  .tk-facts { top: 232px; display: flex; width: 236px; }
  .tk-facts.left { left: 38px; }
  .tk-facts.right { left: 366px; }
  .tk-fact { flex: 1; }
  .tk-fact span { display: block; font-family: Arial, Helvetica, sans-serif; font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: #555; }
  .tk-fact b { display: block; font-size: 24px; line-height: 1.15; }

  .tk-stub { left: 626px; top: 190px; width: 204px; }
  .tk-stub-title { font-weight: 700; font-size: 14px; line-height: 1.15; max-height: 33px; overflow: hidden; }
  .tk-stub-line { font-size: 12px; margin-top: 3px; line-height: 1.2; }
  .tk-stub-line b { font-weight: 700; }
  .tk-stub-number { font-family: 'Courier New', monospace; font-weight: 700; font-size: 15px; letter-spacing: 1px; margin-top: 6px; }
  .tk-stub-price { font-size: 15px; font-weight: 700; margin-top: 2px; }
`;

const esc = (v: unknown) =>
  String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!));

const pad = (n: number) => String(n).padStart(2, '0');

function formatWhen(iso: string): string {
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  return `${d.getFullYear()}.${pad(d.getMonth() + 1)}.${pad(d.getDate())}. ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function formatMoney(n: number): string {
  return `${Math.round(n).toLocaleString('hu-HU').replace(/\u00a0/g, ' ')} Ft`;
}

function titleSize(title: string): number {
  const len = title.length;
  if (len <= 20) return 36;
  if (len <= 30) return 30;
  if (len <= 44) return 25;
  return 21;
}

export function ticketBaseUrl(): string {
  return new URL('assets/ticket-base.png', document.baseURI).href;
}

/** Egy jegy HTML-je; a scale a 840 px-es vászon kicsinyítése (1 = eredeti méret). */
export function ticketMarkup(t: TicketView, scale = 1): string {
  const service = t.category === 'service';
  const tag = t.category === 'staff' ? 'Dolgozó jegy' : t.category === 'experience_card' ? 'Élménykártya' : '';
  // A szolgáltatás-nyugtának nincs előadása: az eladás időpontja szerepel rajta
  const when = formatWhen(service ? (t.sold_at ?? t.starts_at) : t.starts_at);
  const whenLine = tag ? `${when} • ${tag}` : when;
  const seat = t.seat_number ?? '—';
  // szintes székeknél a terem helyett a szint neve szerepel a jegyen
  const hall = t.section || t.auditorium_name || '—';
  const hallLabel = t.section ? 'Szint' : 'Terem';

  const facts = service
    ? `<div class="tk-abs tk-facts left">
        <div class="tk-fact"><span>Nyugtaszám</span><b style="font-size:19px;">${esc(t.ticket_number)}</b></div>
      </div>
      <div class="tk-abs tk-facts right">
        <div class="tk-fact"><span>Kategória</span><b style="font-size:19px;">Szolgáltatás</b></div>
        <div class="tk-fact"><span>Ár</span><b>${esc(formatMoney(t.price_amount))}</b></div>
      </div>`
    : `<div class="tk-abs tk-facts left">
        <div class="tk-fact"><span>${hallLabel}</span><b>${esc(hall)}</b></div>
        <div class="tk-fact"><span>Sor</span><b>${esc(t.row_label)}</b></div>
      </div>
      <div class="tk-abs tk-facts right">
        <div class="tk-fact"><span>Szék</span><b>${esc(seat)}</b></div>
        <div class="tk-fact"><span>Ár</span><b>${esc(formatMoney(t.price_amount))}</b></div>
      </div>`;

  const stubSeat = service
    ? ''
    : `<div class="tk-stub-line">${esc(hall)} • <b>${esc(t.row_label)}. sor ${esc(seat)}. szék</b></div>`;

  return `<div class="tk-box" style="width:${TICKET_W * scale}px;height:${TICKET_H * scale}px;">
    <div class="tk" style="transform:scale(${scale});">
      <img class="tk-bg" src="${esc(ticketBaseUrl())}" alt="">
      <div class="tk-abs tk-main">
        <div class="tk-title" style="font-size:${titleSize(t.movie_title)}px;">${esc(t.movie_title)}</div>
        <div class="tk-when">${esc(whenLine)}</div>
      </div>
      ${facts}
      <div class="tk-abs tk-stub">
        <div class="tk-stub-title">${esc(t.movie_title)}</div>
        <div class="tk-stub-line">${esc(when)}</div>
        ${stubSeat}
        ${tag ? `<div class="tk-stub-line"><b>${esc(tag)}</b></div>` : ''}
        <div class="tk-stub-number">${esc(t.ticket_number)}</div>
        <div class="tk-stub-price">${esc(formatMoney(t.price_amount))}</div>
      </div>
    </div>
  </div>`;
}

/** Megvárja, hogy a jegyek háttérképe betöltődjön (nyomtatás és képpé alakítás előtt). */
export function waitForImages(root: ParentNode): Promise<void> {
  const imgs = Array.from(root.querySelectorAll('img'));
  return Promise.all(
    imgs.map(img => (img.complete ? Promise.resolve() : new Promise<void>(res => { img.onload = img.onerror = () => res(); })))
  ).then(() => undefined);
}
