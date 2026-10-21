import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService } from '../../../../services/admin.service';
import { INLINE_SECTION_MAX_SEATS, sectionBlocks, seatKey } from '../../../../core/util/seat';

interface Seat {
  row: number;
  column: number;
  blocked: boolean;
  sector?: string | null;
  price?: number | null;
  section?: string | null;
  rowLabel?: string;
}

interface Sector {
  name: string;
  price: number;
}

interface Section {
  name: string;
  /** a szint az előtte lévő szinttel egy sorban jelenik meg (csak ha a sorai 5 széknél rövidebbek) */
  inline?: boolean;
}

interface RowConfig {
  label: string;
  columns: number;
  section?: string | null;
  aisle?: boolean;
}

@Component({
  selector: 'app-auditoria-admin',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './auditoria-admin.component.html',
  styleUrls: ['./auditoria-admin.component.css']
})
export class AuditoriaAdminComponent implements OnInit {
  auditoria: any[] = [];
  selectedAuditorium: any = null;
  seatLayout: Seat[] = [];
  originalSeatLayout: Seat[] = [];
  sectors: Sector[] = [];
  sections: Section[] = [];
  selectedSectorName: string | null = null;
  rowConfigs: RowConfig[] = [];
  newSectorName = '';
  newSectorPrice = 2000;
  newSectionName = '';
  newRowLabel = '';
  newRowColumns = 10;
  newRowSection: string | null = null;
  dragKind: 'sector' | 'section' | null = null;
  dragIndex: number | null = null;
  dragOverIndex: number | null = null;
  loading = true;
  error = '';
  showEditModal = false;
  editingAuditorium: any = null;
  isNewAuditorium = false;

  constructor(
    private adminService: AdminService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.loadAuditoria();
  }

  loadAuditoria(): void {
    this.loading = true;
    this.adminService.getAuditoria().subscribe({
      next: (auditoria) => {
        this.auditoria = auditoria;
        this.loading = false;
        if (auditoria.length > 0) {
          this.selectAuditorium(auditoria[0]);
        }
      },
      error: (err) => {
        this.error = 'Hiba történt a termek betöltése során';
        this.loading = false;
        console.error('Error loading auditoria:', err);
      }
    });
  }

  selectAuditorium(auditorium: any): void {
    this.selectedAuditorium = auditorium;
    this.loadSeatLayout();
  }

  loadSeatLayout(): void {
    if (!this.selectedAuditorium) return;

    // Load seat layout from backend or generate default
    this.adminService.getAuditoriumSeats(this.selectedAuditorium.id).subscribe({
      next: (response) => {
        const seats = Array.isArray(response) ? response : (response?.seats ?? []);
        const sectors = Array.isArray(response?.sectors) ? response.sectors : [];
        const sections = Array.isArray(response?.sections) ? response.sections : [];
        this.seatLayout = this.normalizeSeatLabels(seats);
        this.originalSeatLayout = JSON.parse(JSON.stringify(this.seatLayout));
        this.sectors = sectors.length ? sectors : this.getDefaultSectors();
        this.sections = sections.length ? sections : this.getDefaultSections();
        this.rowConfigs = Array.isArray(response?.rows) && response.rows.length
          ? response.rows
          : this.getDefaultRowConfigs();
      },
      error: (err) => {
        // If no custom layout, generate default grid
        this.seatLayout = this.generateDefaultLayout();
        this.originalSeatLayout = JSON.parse(JSON.stringify(this.seatLayout));
        this.sectors = this.getDefaultSectors();
        this.sections = this.getDefaultSections();
        this.rowConfigs = this.getDefaultRowConfigs();
        console.log('No custom layout, using default', err);
      }
    });
  }

  generateDefaultLayout(): Seat[] {
    const seats: Seat[] = [];
    for (let row = 1; row <= this.selectedAuditorium.rows; row++) {
      for (let col = 1; col <= this.selectedAuditorium.columns; col++) {
        seats.push({
          row: row,
          column: col,
          blocked: false,
          sector: null,
          price: null,
          section: null,
          rowLabel: this.getRowLabel(row)
        });
      }
    }
    return seats;
  }

  getDefaultRowConfigs(): RowConfig[] {
    const rows: RowConfig[] = [];
    for (let row = 1; row <= this.selectedAuditorium.rows; row++) {
      rows.push({
        label: this.getRowLabel(row),
        columns: this.selectedAuditorium.columns,
        section: null
      });
    }
    return rows;
  }

  getDefaultSectors(): Sector[] {
    return [{ name: 'Normál', price: 2000 }];
  }

  getDefaultSections(): Section[] {
    return [
      { name: 'Földszint' },
      { name: 'Emelet' }
    ];
  }

  getRowLabel(rowIndex: number): string {
    return String.fromCharCode(64 + rowIndex);
  }

  normalizeSeatLabels(seats: Seat[]): Seat[] {
    return seats.map(seat => ({
      ...seat,
      rowLabel: seat.rowLabel || this.getRowLabel(seat.row)
    }));
  }

  getSeatLayout(auditorium: any): Seat[] {
    return this.seatLayout;
  }

  toggleSeat(seat: Seat): void {
    let assigned = false;

    if (this.selectedSectorName) {
      const sector = this.sectors.find(s => s.name === this.selectedSectorName);
      seat.sector = sector?.name ?? null;
      seat.price = sector?.price ?? null;
      seat.blocked = false;
      assigned = true;
    }

    if (!assigned) {
      seat.blocked = !seat.blocked;
    }
  }

  saveSeatLayout(): void {
    if (!this.selectedAuditorium) return;

    // a szék kulcsa a szintet is tartalmazza, így két szinten is lehet "1. sor 1. szék"
    const seats = this.seatLayout.map(seat => ({
      ...seat,
      key: seatKey(seat.section, seat.rowLabel || this.getRowLabel(seat.row), seat.column)
    }));

    this.adminService.updateAuditoriumSeats(this.selectedAuditorium.id, seats as Seat[], this.sectors, this.sections, this.rowConfigs).subscribe({
      next: (response) => {
        if (response?.auditorium) {
          Object.assign(this.selectedAuditorium, {
            rows: response.auditorium.rows,
            columns: response.auditorium.columns,
            total_seats: response.auditorium.total_seats
          });
        }
        alert('Nézőtér elrendezés sikeresen mentve!');
        this.originalSeatLayout = JSON.parse(JSON.stringify(this.seatLayout));
      },
      error: (err) => {
        this.error = 'Hiba történt a mentés során';
        console.error('Error saving seat layout:', err);
      }
    });
  }

  resetSeatLayout(): void {
    if (!confirm('Biztosan visszaállítod az eredeti elrendezést?')) {
      return;
    }
    this.seatLayout = JSON.parse(JSON.stringify(this.originalSeatLayout));
  }

  selectSector(sector: Sector): void {
    this.selectedSectorName = sector.name;
  }

  clearSectorSelection(): void {
    this.selectedSectorName = null;
  }

  selectSection(section: Section): void {
    this.newRowSection = section.name;
  }

  clearSectionSelection(): void {
    this.newRowSection = null;
  }

  addSector(): void {
    const name = this.newSectorName.trim();
    if (!name) {
      return;
    }
    if (this.sectors.some(s => s.name === name)) {
      alert('Ez a szektor név már létezik.');
      return;
    }
    this.sectors.push({ name, price: Number(this.newSectorPrice) || 0 });
    this.newSectorName = '';
    this.newSectorPrice = 2000;
  }

  addSection(): void {
    // a "|" a szék kulcsában elválasztó jel, ezért nem szerepelhet a szint nevében
    const name = this.newSectionName.replace(/\|/g, '').trim();
    if (!name) {
      return;
    }
    if (this.sections.some(s => s.name === name)) {
      alert('Ez a szint név már létezik.');
      return;
    }
    this.sections.push({ name });
    this.newSectionName = '';
  }

  /** Szektor / szint áthelyezése a listában (nyilak vagy húzás). A sorrend a mentéssel együtt tárolódik. */
  moveItem(kind: 'sector' | 'section', from: number, to: number): void {
    const list: unknown[] = kind === 'sector' ? this.sectors : this.sections;
    if (from === to || from < 0 || to < 0 || from >= list.length || to >= list.length) {
      return;
    }
    const [item] = list.splice(from, 1);
    list.splice(to, 0, item);
  }

  onDragStart(kind: 'sector' | 'section', index: number, event: DragEvent): void {
    this.dragKind = kind;
    this.dragIndex = index;
    event.dataTransfer?.setData('text/plain', String(index));
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
    }
  }

  onDragOver(kind: 'sector' | 'section', index: number, event: DragEvent): void {
    if (this.dragKind !== kind) {
      return;
    }
    event.preventDefault();
    this.dragOverIndex = index;
  }

  onDrop(kind: 'sector' | 'section', index: number, event: DragEvent): void {
    event.preventDefault();
    if (this.dragKind === kind && this.dragIndex !== null) {
      this.moveItem(kind, this.dragIndex, index);
    }
    this.onDragEnd();
  }

  onDragEnd(): void {
    this.dragKind = null;
    this.dragIndex = null;
    this.dragOverIndex = null;
  }

  private sameSection(a?: string | null, b?: string | null): boolean {
    return (a || null) === (b || null);
  }

  private rowExists(label: string, section?: string | null): boolean {
    return this.rowConfigs.some(r => r.label === label && this.sameSection(r.section, section));
  }

  /** Az adott szint első még nem használt sorjele: szintnél 1, 2, 3…, szint nélkül A, B, C… */
  nextRowLabel(section?: string | null): string {
    let index = 1;
    const labelOf = (i: number) => (section ? String(i) : this.getRowLabel(i));
    while (this.rowExists(labelOf(index), section)) {
      index++;
    }
    return labelOf(index);
  }

  addRow(): void {
    // a "-" és "|" a szék kulcsában elválasztó jel
    const typed = this.newRowLabel.replace(/[-|]/g, '').trim().toUpperCase();
    const label = typed || this.nextRowLabel(this.newRowSection);
    if (this.rowExists(label, this.newRowSection)) {
      alert('Ez a sor jel ezen a szinten már létezik.');
      return;
    }
    this.rowConfigs.push({
      label,
      columns: Number(this.newRowColumns) || 1,
      section: this.newRowSection,
      aisle: false
    });
    this.newRowLabel = '';
    this.newRowColumns = 10;
    this.rebuildSeatsFromRows();
  }

  removeRow(row: RowConfig): void {
    if (!confirm(`Biztosan törlöd a "${row.section ? row.section + ' ' : ''}${row.label}" sort?`)) {
      return;
    }
    this.rowConfigs = this.rowConfigs.filter(r => r !== row);
    this.rebuildSeatsFromRows();
  }

  /** Sorjel átírása: a sor székbeállításai (szektor, tiltás) megmaradnak, a jel a szinten belül egyedi marad. */
  renameRow(row: RowConfig, event: Event): void {
    const input = event.target as HTMLInputElement;
    const next = input.value.replace(/[-|]/g, '').trim().toUpperCase();
    if (!next || next === row.label) {
      input.value = row.label;
      return;
    }
    if (this.rowExists(next, row.section)) {
      alert('Ez a sor jel ezen a szinten már létezik.');
      input.value = row.label;
      return;
    }
    for (const seat of this.seatLayout) {
      if (seat.rowLabel === row.label && this.sameSection(seat.section, row.section)) {
        seat.rowLabel = next;
      }
    }
    row.label = next;
    input.value = next;
  }

  updateRowConfig(): void {
    // szintváltásnál a sorjelnek az új szinten is egyedinek kell lennie
    const seen = new Set<string>();
    for (const row of this.rowConfigs) {
      let key = `${row.section || ''}|${row.label}`;
      if (seen.has(key)) {
        row.label = this.nextRowLabel(row.section);
        key = `${row.section || ''}|${row.label}`;
      }
      seen.add(key);
    }
    this.rebuildSeatsFromRows();
  }

  rebuildSeatsFromRows(): void {
    const valid = new Set(this.rowConfigs.map(r => `${r.section || ''}|${r.label}`));
    const exact = new Map<string, Seat>();
    // csak a már nem létező (szintet váltott) sorok székei vihetők át az új helyükre
    const orphans = new Map<string, Seat>();
    for (const seat of this.seatLayout) {
      exact.set(`${seat.section || ''}|${seat.rowLabel}-${seat.column}`, seat);
      if (!valid.has(`${seat.section || ''}|${seat.rowLabel}`) && !orphans.has(`${seat.rowLabel}-${seat.column}`)) {
        orphans.set(`${seat.rowLabel}-${seat.column}`, seat);
      }
    }

    const newSeats: Seat[] = [];
    let rowIndex = 1;
    for (const row of this.rowConfigs) {
      for (let col = 1; col <= row.columns; col++) {
        const existing = exact.get(`${row.section || ''}|${row.label}-${col}`) ?? orphans.get(`${row.label}-${col}`);
        newSeats.push({
          row: rowIndex,
          column: col,
          blocked: existing?.blocked ?? false,
          sector: existing?.sector ?? null,
          price: existing?.price ?? null,
          section: row.section || null,
          rowLabel: row.label
        });
      }
      rowIndex++;
    }
    this.seatLayout = newSeats;
  }

  getSectionGroups(): Array<{ name: string; rows: RowConfig[]; color: string }>{
    const groups: Array<{ name: string; rows: RowConfig[]; color: string }> = [];
    for (const section of this.sections) {
      const rows = this.rowConfigs.filter(r => r.section === section.name);
      if (rows.length) {
        groups.push({ name: section.name, rows, color: this.getSectionColor(section.name) });
      }
    }

    const unassigned = this.rowConfigs.filter(r => !r.section);
    if (unassigned.length) {
      groups.push({ name: 'Nincs szint', rows: unassigned, color: '#3d2817' });
    }
    const floorIndex = groups.findIndex(g => g.name === 'Földszint' || g.name === 'Földszinti emelvény');
    if (floorIndex > 0) {
      const [floorGroup] = groups.splice(floorIndex, 1);
      groups.unshift(floorGroup);
    }
    return groups;
  }

  /** A szint leghosszabb sora (székek száma). */
  sectionWidestRow(name: string): number {
    return Math.max(0, ...this.rowConfigs.filter(r => r.section === name).map(r => Number(r.columns) || 0));
  }

  canInline(section: Section): boolean {
    const widest = this.sectionWidestRow(section.name);
    return widest > 0 && widest < INLINE_SECTION_MAX_SEATS;
  }

  /** A szintek megjelenítési blokkjai: az "egy sorba" jelölésű keskeny szintek egymás mellé kerülnek (mint a nézőtéren). */
  getSectionBlocks(): Array<Array<{ name: string; rows: RowConfig[]; color: string }>> {
    const inline: Record<string, boolean> = {};
    for (const s of this.sections) {
      inline[s.name] = !!s.inline;
    }
    return sectionBlocks(this.getSectionGroups(), inline, g => Math.max(0, ...g.rows.map(r => Number(r.columns) || 0)));
  }

  getSeatsForRow(row: RowConfig): Seat[] {
    return this.seatLayout.filter(seat => seat.rowLabel === row.label && this.sameSection(seat.section, row.section));
  }

  /** Folyosó a sor közepén (soronként kapcsolható). */
  rowHasAisle(row: RowConfig): boolean {
    return !!row.aisle && row.columns >= 2;
  }

  hasAisleBefore(row: RowConfig, index: number): boolean {
    return this.rowHasAisle(row) && index === Math.ceil(row.columns / 2);
  }

  rowGridColumns(row: RowConfig): string {
    if (!this.rowHasAisle(row)) {
      return `repeat(${row.columns}, 1fr)`;
    }
    const left = Math.ceil(row.columns / 2);
    return `repeat(${left}, 1fr) 28px repeat(${row.columns - left}, 1fr)`;
  }

  removeSector(sector: Sector): void {
    if (!confirm(`Biztosan törlöd a "${sector.name}" szektort?`)) {
      return;
    }
    this.sectors = this.sectors.filter(s => s.name !== sector.name);
    if (this.selectedSectorName === sector.name) {
      this.selectedSectorName = null;
    }
    for (const seat of this.seatLayout) {
      if (seat.sector === sector.name) {
        seat.sector = null;
        seat.price = null;
      }
    }
  }

  removeSection(section: Section): void {
    if (!confirm(`Biztosan törlöd a "${section.name}" szintet?`)) {
      return;
    }
    this.sections = this.sections.filter(s => s.name !== section.name);
    for (const row of this.rowConfigs) {
      if (row.section === section.name) {
        row.section = null;
      }
    }
    // a szint nélkülivé vált sorok jelei ütközhetnek, ezeket új jelet kapnak
    this.updateRowConfig();
  }

  updateSectorPrice(sector: Sector): void {
    const price = Number(sector.price) || 0;
    sector.price = price;
    for (const seat of this.seatLayout) {
      if (seat.sector === sector.name) {
        seat.price = price;
      }
    }
  }

  /** A szektor nélküli szék az első (alapértelmezett) szektor árát kapja, mint a szerveren is. */
  getSeatSector(seat: Seat): Sector | null {
    return this.sectors.find(s => s.name === seat.sector) ?? this.sectors[0] ?? null;
  }

  getSeatPrice(seat: Seat): number | null {
    return seat.price ?? this.getSeatSector(seat)?.price ?? null;
  }

  getSectorColor(sectorName?: string | null): string {
    const palette = ['#4CAF50', '#2f5aa6', '#c0392b', '#8e44ad', '#e67e22', '#16a085'];
    const index = this.sectors.findIndex(s => s.name === sectorName);
    return palette[(index < 0 ? 0 : index) % palette.length];
  }

  getSeatTitle(seat: Seat): string {
    const place = (seat.section ? seat.section + ' ' : '') + `${seat.rowLabel}-${seat.column}`;
    if (seat.blocked) {
      return `${place} • tiltott`;
    }
    const sector = this.getSeatSector(seat);
    const price = this.getSeatPrice(seat);
    return place + (sector ? ` • ${sector.name}` : '') + (price !== null ? ` • ${price} Ft` : '');
  }

  getSectionColor(sectionName?: string | null): string {
    if (!sectionName) {
      return 'transparent';
    }
    const palette = ['#5b3f8c', '#1f6f78', '#8c4f1f', '#2f5aa6', '#6b8e23', '#8b3d5e'];
    const index = this.sections.findIndex(s => s.name === sectionName);
    return palette[index % palette.length] || '#3d2817';
  }

  editAuditorium(auditorium: any): void {
    this.editingAuditorium = { ...auditorium };
    this.isNewAuditorium = false;
    this.showEditModal = true;
  }

  createNewAuditorium(): void {
    this.editingAuditorium = { name: '' };
    this.isNewAuditorium = true;
    this.showEditModal = true;
  }

  closeModal(): void {
    this.showEditModal = false;
    this.editingAuditorium = null;
  }

  saveAuditorium(): void {
    if (!this.editingAuditorium) return;

    const auditoriumData = { name: this.editingAuditorium.name };

    if (this.isNewAuditorium) {
      this.adminService.createAuditorium(auditoriumData).subscribe({
        next: (newAuditorium) => {
          this.auditoria.push(newAuditorium);
          this.closeModal();
          this.selectAuditorium(newAuditorium);
        },
        error: (err) => {
          this.error = 'Hiba történt a terem létrehozása során';
          console.error('Error creating auditorium:', err);
        }
      });
    } else {
      this.adminService.updateAuditorium(this.editingAuditorium.id, auditoriumData).subscribe({
        next: (updatedAuditorium) => {
          const index = this.auditoria.findIndex(a => a.id === updatedAuditorium.id);
          if (index !== -1) {
            this.auditoria[index] = updatedAuditorium;
            if (this.selectedAuditorium.id === updatedAuditorium.id) {
              this.selectedAuditorium = updatedAuditorium;
            }
          }
          this.closeModal();
        },
        error: (err) => {
          this.error = 'Hiba történt a mentés során';
          console.error('Error updating auditorium:', err);
        }
      });
    }
  }

  deleteAuditorium(auditorium: any): void {
    if (!confirm(`Biztosan törlöd a "${auditorium.name}" termet?\n\nFIGYELEM: a terem törlésével a hozzá tartozó összes előadás-időpont és az azokra szóló foglalások, jegyek is törlődnek!`)) {
      return;
    }

    this.adminService.deleteAuditorium(auditorium.id).subscribe({
      next: () => {
        this.auditoria = this.auditoria.filter(a => a.id !== auditorium.id);
        if (this.selectedAuditorium?.id === auditorium.id) {
          this.selectedAuditorium = this.auditoria.length > 0 ? this.auditoria[0] : null;
          if (this.selectedAuditorium) {
            this.loadSeatLayout();
          }
        }
      },
      error: (err) => {
        this.error = 'Hiba történt a törlés során';
        console.error('Error deleting auditorium:', err);
      }
    });
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
  }
}
