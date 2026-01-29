import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService } from '../../../../services/admin.service';

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
}

interface RowConfig {
  label: string;
  columns: number;
  section?: string | null;
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

    this.adminService.updateAuditoriumSeats(this.selectedAuditorium.id, this.seatLayout, this.sectors, this.sections, this.rowConfigs).subscribe({
      next: () => {
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
    const name = this.newSectionName.trim();
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

  addRow(): void {
    const label = this.newRowLabel.trim().toUpperCase();
    if (!label) {
      return;
    }
    if (this.rowConfigs.some(r => r.label === label)) {
      alert('Ez a sor jel már létezik.');
      return;
    }
    this.rowConfigs.push({
      label,
      columns: Number(this.newRowColumns) || 1,
      section: this.newRowSection
    });
    this.newRowLabel = '';
    this.newRowColumns = 10;
    this.newRowSection = null;
    this.rebuildSeatsFromRows();
  }

  removeRow(row: RowConfig): void {
    if (!confirm(`Biztosan törlöd a "${row.label}" sort?`)) {
      return;
    }
    this.rowConfigs = this.rowConfigs.filter(r => r.label !== row.label);
    this.rebuildSeatsFromRows();
  }

  updateRowConfig(): void {
    this.rebuildSeatsFromRows();
  }

  rebuildSeatsFromRows(): void {
    const seatMap = new Map<string, Seat>();
    for (const seat of this.seatLayout) {
      seatMap.set(`${seat.rowLabel}-${seat.column}`, seat);
    }

    const newSeats: Seat[] = [];
    let rowIndex = 1;
    for (const row of this.rowConfigs) {
      for (let col = 1; col <= row.columns; col++) {
        const key = `${row.label}-${col}`;
        const existing = seatMap.get(key);
        newSeats.push({
          row: rowIndex,
          column: col,
          blocked: existing?.blocked ?? false,
          sector: existing?.sector ?? null,
          price: existing?.price ?? null,
          section: row.section ?? existing?.section ?? null,
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

  getSeatsForRow(row: RowConfig): Seat[] {
    return this.seatLayout.filter(seat => seat.rowLabel === row.label);
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
    for (const seat of this.seatLayout) {
      if (seat.section === section.name) {
        seat.section = null;
      }
    }
    for (const row of this.rowConfigs) {
      if (row.section === section.name) {
        row.section = null;
      }
    }
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
    this.editingAuditorium = {
      name: '',
      rows: 8,
      columns: 10,
      total_seats: 80
    };
    this.isNewAuditorium = true;
    this.showEditModal = true;
  }

  closeModal(): void {
    this.showEditModal = false;
    this.editingAuditorium = null;
  }

  saveAuditorium(): void {
    if (!this.editingAuditorium) return;

    const auditoriumData = {
      name: this.editingAuditorium.name,
      rows: parseInt(this.editingAuditorium.rows),
      columns: parseInt(this.editingAuditorium.columns),
      total_seats: parseInt(this.editingAuditorium.rows) * parseInt(this.editingAuditorium.columns)
    };

    if (this.isNewAuditorium) {
      this.adminService.createAuditorium(auditoriumData).subscribe({
        next: (newAuditorium) => {
          this.auditoria.push(newAuditorium);
          this.closeModal();
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
    if (!confirm(`Biztosan törlöd a "${auditorium.name}" termet?`)) {
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

  updateTotalSeats(): void {
    if (this.editingAuditorium) {
      this.editingAuditorium.total_seats = parseInt(this.editingAuditorium.rows) * parseInt(this.editingAuditorium.columns);
    }
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
  }
}
