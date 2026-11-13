/** Az előadásokhoz (filmekhez, programokhoz), amelyekhez nincs feltöltött plakát, a cím alapján készülő márkázott alapplakát. */
export interface PosterSource {
  title?: string | null;
  poster_path?: string | null;
  is_theater?: boolean;
}

const esc = (v: string) => v.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!));

/** A cím sorokra tördelése (szóhatáron), legfeljebb maxLines sorba. */
function wrap(title: string, maxChars: number, maxLines: number): string[] {
  const lines: string[] = [];
  let line = '';
  for (const word of title.trim().split(/\s+/)) {
    const next = line ? `${line} ${word}` : word;
    if (next.length > maxChars && line) {
      lines.push(line);
      line = word;
    } else {
      line = next;
    }
  }
  if (line) lines.push(line);

  if (lines.length > maxLines) {
    const kept = lines.slice(0, maxLines);
    kept[maxLines - 1] = kept[maxLines - 1].replace(/[\s.,;:!?-]*$/, '') + '…';
    return kept;
  }
  return lines;
}

/** A plakát képe: a feltöltött plakát, vagy ha nincs, a cím alapján generált alapplakát (SVG adat-URL). */
export function posterSrc(item: PosterSource | null | undefined): string {
  if (item?.poster_path) {
    return item.poster_path;
  }

  const title = (item?.title ?? '').trim() || 'Vigadó';
  const size = title.length <= 14 ? 54 : title.length <= 28 ? 44 : title.length <= 48 ? 36 : 30;
  const maxChars = Math.max(8, Math.floor(330 / (size * 0.56)));
  const lines = wrap(title, maxChars, 6);
  const lineHeight = size * 1.2;
  const startY = 300 - ((lines.length - 1) * lineHeight) / 2 + size * 0.3;
  const label = item?.is_theater ? 'ELŐADÁS' : 'MOZI';

  const text = lines
    .map((l, i) => `<text x="200" y="${(startY + i * lineHeight).toFixed(1)}" text-anchor="middle" font-size="${size}" font-weight="700" fill="#f0d9b5">${esc(l)}</text>`)
    .join('');

  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 600" font-family="Georgia, 'Times New Roman', serif">`
    + `<defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#5c3d2e"/><stop offset="1" stop-color="#2c1810"/></linearGradient></defs>`
    + `<rect width="400" height="600" fill="url(#g)"/>`
    + `<rect x="16" y="16" width="368" height="568" rx="10" fill="none" stroke="#d4a574" stroke-width="3"/>`
    + `<rect x="26" y="26" width="348" height="548" rx="6" fill="none" stroke="#d4a574" stroke-width="1" opacity="0.6"/>`
    + `<text x="200" y="92" text-anchor="middle" font-size="30" font-weight="700" letter-spacing="6" fill="#d4a574">VIGADÓ</text>`
    + `<text x="200" y="118" text-anchor="middle" font-size="13" letter-spacing="4" fill="#cdb596">KISÚJSZÁLLÁS</text>`
    + `<line x1="130" y1="140" x2="270" y2="140" stroke="#d4a574" stroke-width="1.5"/>`
    + text
    + `<line x1="130" y1="470" x2="270" y2="470" stroke="#d4a574" stroke-width="1.5"/>`
    + `<text x="200" y="510" text-anchor="middle" font-size="16" letter-spacing="5" fill="#d4a574">${label}</text>`
    + `</svg>`;

  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}
