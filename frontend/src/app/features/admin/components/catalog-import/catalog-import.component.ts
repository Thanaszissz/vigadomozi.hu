import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { AdminService, CatalogImportResult, CatalogImportType } from '../../../../services/admin.service';

@Component({
  selector: 'app-catalog-import',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './catalog-import.component.html',
  styleUrls: ['./catalog-import.component.css']
})
export class CatalogImportComponent {
  /** a route data.kind adja meg: 'movie' (filmek) vagy 'show' (előadások / programok) */
  readonly kind: CatalogImportType;
  jsonText = '';
  updateExisting = true;
  busy = false;
  error = '';
  fileName = '';
  result: CatalogImportResult | null = null;

  constructor(private adminService: AdminService, private router: Router, route: ActivatedRoute) {
    this.kind = route.snapshot.data['kind'] === 'show' ? 'show' : 'movie';
  }

  get isShow(): boolean {
    return this.kind === 'show';
  }

  get noun(): string {
    return this.isShow ? 'előadás / program' : 'film';
  }

  get placeholder(): string {
    return this.isShow
      ? '[ { "title": "Előadás címe", "category": "szinhaz", "ticket_price": 3500, "poster": "https://...", "showtimes": [ { "auditorium": "Nagyterem", "starts_at": ["2026-11-05 19:00"] } ] } ]'
      : '[ { "title": "Film címe", "poster": "https://...", "showtimes": [ { "auditorium": "Nagyterem", "starts_at": ["2026-11-05 18:00"] } ] } ]';
  }

  loadSample(): void {
    this.adminService.getCatalogImportSample(this.kind).subscribe({
      next: sample => {
        this.jsonText = JSON.stringify(sample, null, 2);
        this.error = '';
        this.result = null;
      },
      error: () => (this.error = 'A minta JSON betöltése nem sikerült.')
    });
  }

  downloadSample(): void {
    this.adminService.getCatalogImportSample(this.kind).subscribe({
      next: sample => {
        const blob = new Blob([JSON.stringify(sample, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = this.isShow ? 'eloadasok-es-idopontok-minta.json' : 'filmek-es-eloadasok-minta.json';
        link.click();
        URL.revokeObjectURL(url);
      },
      error: () => (this.error = 'A minta JSON letöltése nem sikerült.')
    });
  }

  onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) {
      return;
    }
    this.fileName = file.name;
    file.text().then(text => {
      this.jsonText = text;
      this.error = '';
      this.result = null;
    });
    input.value = '';
  }

  run(dryRun: boolean): void {
    this.error = '';
    this.result = null;

    let data: unknown;
    try {
      data = JSON.parse(this.jsonText);
    } catch (e) {
      this.error = 'A JSON nem érvényes: ' + (e instanceof Error ? e.message : String(e));
      return;
    }

    if (!dryRun && !confirm(`Biztosan feltöltöd a JSON tartalmát (${this.noun} és időpontok)?`)) {
      return;
    }

    this.busy = true;
    this.adminService.importCatalog(data, dryRun, this.updateExisting, this.kind).subscribe({
      next: result => {
        this.result = result;
        this.busy = false;
      },
      error: err => {
        this.error = err?.error?.message || 'A feltöltés nem sikerült (túl nagy lehet a kérés, vagy a szerver nem érhető el).';
        this.busy = false;
      }
    });
  }

  formatBytes(bytes?: number): string {
    return bytes ? (bytes / 1024).toFixed(0) + ' KB' : '';
  }

  goBack(): void {
    this.router.navigate([this.isShow ? '/admin/theater-shows' : '/admin/movies']);
  }
}
