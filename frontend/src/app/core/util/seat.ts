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
