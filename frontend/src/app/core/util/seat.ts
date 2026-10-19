/**
 * A szék kulcsa szint nélküli sornál "A-05", szintes sornál "Emelet|1-05" (szint|sorjel-székszám),
 * a nézőtér térképén a sor kulcsa "Emelet|1" / "A".
 */
export function rowKey(section: string | null | undefined, label: string): string {
  return section ? `${section}|${label}` : label;
}

export function seatKey(section: string | null | undefined, label: string, number: number): string {
  return `${rowKey(section, label)}-${String(number).padStart(2, '0')}`;
}

/** A sor kulcsából a megjelenítendő sorjel (a szint nélkül). */
export function rowLabelOf(rowKeyValue: string): string {
  const i = rowKeyValue.indexOf('|');
  return i >= 0 ? rowKeyValue.slice(i + 1) : rowKeyValue;
}

/** A szék kulcsából a megjelenítendő székszám (pl. "05"). */
export function seatNumberOf(key: string): string {
  const i = key.lastIndexOf('-');
  return i >= 0 ? key.slice(i + 1) : key;
}

/** Emberi olvasatú megjelölés: "Emelet 1-5" / "E-5". */
export function seatLabel(key: string | null | undefined): string {
  if (!key || key === '-') return key ?? '';
  const i = key.lastIndexOf('-');
  if (i < 0) return key;
  const rowPart = key.slice(0, i);
  const number = parseInt(key.slice(i + 1), 10);
  const sep = rowPart.indexOf('|');
  const row = sep >= 0 ? rowPart.slice(sep + 1) : rowPart;
  return (sep >= 0 ? rowPart.slice(0, sep) + ' ' : '') + row + '-' + (Number.isNaN(number) ? key.slice(i + 1) : number);
}

/** A jegyen / foglalási tételen szereplő hely: "Emelet · 1. sor 5. szék". */
export function placeLabel(item: { section?: string | null; row_label?: string | null; seat_number?: number | null }): string {
  return (item.section ? item.section + ' · ' : '') + `${item.row_label ?? ''}. sor ${item.seat_number ?? '—'}. szék`;
}

/** Ennél kevesebb székes sorokból álló (keskeny) szintek kerülhetnek egymás mellé, egy sorba a megjelenítésben. */
export const INLINE_SECTION_MAX_SEATS = 5;

/**
 * A szinteket megjelenítési blokkokba rendezi: az "inline" jelölésű szint az előző szinttel egy sorba kerül
 * (pl. Bal páholy + Jobb páholy), de csak ha mindkettő keskeny, azaz a leghosszabb sora 5 széknél rövidebb.
 * Egyébként minden szint külön blokk.
 */
export function sectionBlocks<T extends { name: string }>(
  groups: T[],
  inline: Record<string, boolean> | null | undefined,
  widestRow: (group: T) => number
): T[][] {
  const blocks: T[][] = [];
  for (const group of groups) {
    const last = blocks[blocks.length - 1]?.slice(-1)[0];
    const canJoin = !!last && !!inline?.[group.name]
      && widestRow(group) < INLINE_SECTION_MAX_SEATS && widestRow(last) < INLINE_SECTION_MAX_SEATS;
    if (canJoin) {
      blocks[blocks.length - 1].push(group);
    } else {
      blocks.push([group]);
    }
  }
  return blocks;
}

interface GapSeat {
  key: string;
  type: string;
  bookable: boolean;
  status: string;
}

/**
 * Azok a szabad székek, amelyek a soron belül egyedül maradnának üresen: mindkét oldalukon foglalt / nem foglalható /
 * kijelölt szék, a folyosó vagy a sor széle van. A kijelölt székek (selected) foglaltnak számítanak.
 * A sorokat a folyosó-székek és a sor közepén lévő folyosók (aisles: sorkulcs => a folyosó utáni első szék indexe) szakaszokra bontják.
 */
export function isolatedFreeSeats(
  rows: Record<string, Record<string, GapSeat>> | null | undefined,
  aisles: Record<string, number> | null | undefined,
  selected: ReadonlySet<string>
): string[] {
  const result: string[] = [];

  for (const [rowKeyValue, rowSeats] of Object.entries(rows ?? {})) {
    const seats = Object.values(rowSeats ?? {});
    const aisleBefore = aisles?.[rowKeyValue];
    let segment: GapSeat[] = [];

    const flush = () => {
      const occupied = segment.map(s => s.status !== 'available' || !s.bookable || selected.has(s.key));
      segment.forEach((seat, i) => {
        if (!occupied[i] && (i === 0 || occupied[i - 1]) && (i === segment.length - 1 || occupied[i + 1])) {
          result.push(seat.key);
        }
      });
      segment = [];
    };

    seats.forEach((seat, index) => {
      if (aisleBefore !== undefined && index === aisleBefore) flush();
      if (seat.type === 'aisle') {
        flush();
      } else {
        segment.push(seat);
      }
    });
    flush();
  }

  return result;
}

/** A kijelölés által újonnan egyedül hagyott üres székek (a már előtte is magányos székekre nem figyelmeztet). */
export function newlyIsolatedSeats(
  rows: Record<string, Record<string, GapSeat>> | null | undefined,
  aisles: Record<string, number> | null | undefined,
  selectedKeys: string[]
): string[] {
  if (selectedKeys.length === 0) return [];

  const before = new Set(isolatedFreeSeats(rows, aisles, new Set()));
  return isolatedFreeSeats(rows, aisles, new Set(selectedKeys)).filter(key => !before.has(key));
}
