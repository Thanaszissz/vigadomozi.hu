import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AdminService, ExtraService } from '../../../../services/admin.service';

interface Row extends ExtraService {
  savedName: string;
  savedPrice: number;
  busy: boolean;
  error: string | null;
}

@Component({
  selector: 'app-extra-services-panel',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './extra-services-panel.component.html',
  styleUrls: ['./simplepay-panel.component.css', './extra-services-panel.component.css']
})
export class ExtraServicesPanelComponent implements OnInit {
  rows: Row[] = [];
  loading = false;
  error: string | null = null;
  notice: string | null = null;

  newName = '';
  newPrice: number | null = null;
  adding = false;
  addError: string | null = null;

  constructor(private admin: AdminService) {}

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading = true;
    this.error = null;

    this.admin.getExtraServices().subscribe({
      next: (services) => {
        this.rows = services.map(s => this.toRow(s));
        this.loading = false;
      },
      error: () => {
        this.error = 'Az egyéb szolgáltatások betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  private toRow(s: ExtraService): Row {
    return { ...s, savedName: s.name, savedPrice: s.price, busy: false, error: null };
  }

  isDirty(row: Row): boolean {
    return row.name.trim() !== row.savedName || Number(row.price) !== row.savedPrice;
  }

  private show(notice: string): void {
    this.notice = notice;
    window.setTimeout(() => (this.notice = null), 3500);
  }

  private errorOf(err: any, fallback: string): string {
    const errors = err?.error?.errors;
    return (errors && (Object.values(errors) as string[][])[0]?.[0]) || err?.error?.message || fallback;
  }

  save(row: Row): void {
    if (!this.isDirty(row) || row.busy) return;

    row.busy = true;
    row.error = null;

    this.admin.updateExtraService(row.id, { name: row.name.trim(), price: Number(row.price) }).subscribe({
      next: (saved) => {
        Object.assign(row, this.toRow(saved));
        this.show(`„${saved.name}” ára elmentve: ${saved.price.toLocaleString('hu-HU')} Ft.`);
      },
      error: (err) => {
        row.busy = false;
        row.error = this.errorOf(err, 'A mentés sikertelen.');
      }
    });
  }

  remove(row: Row): void {
    if (row.busy || !confirm(`Biztosan törlöd a(z) „${row.savedName}” szolgáltatást?`)) return;

    row.busy = true;
    this.admin.deleteExtraService(row.id).subscribe({
      next: () => {
        this.rows = this.rows.filter(r => r.id !== row.id);
        this.show('A szolgáltatás törölve.');
      },
      error: (err) => {
        row.busy = false;
        row.error = this.errorOf(err, 'A törlés sikertelen.');
      }
    });
  }

  get canAdd(): boolean {
    return !!this.newName.trim() && this.newPrice !== null && this.newPrice >= 0 && !this.adding;
  }

  add(): void {
    if (!this.canAdd) return;

    this.adding = true;
    this.addError = null;

    this.admin.createExtraService({ name: this.newName.trim(), price: Number(this.newPrice) }).subscribe({
      next: (created) => {
        this.rows.push(this.toRow(created));
        this.newName = '';
        this.newPrice = null;
        this.adding = false;
        this.show(`„${created.name}” felvéve.`);
      },
      error: (err) => {
        this.adding = false;
        this.addError = this.errorOf(err, 'A felvétel sikertelen.');
      }
    });
  }
}
