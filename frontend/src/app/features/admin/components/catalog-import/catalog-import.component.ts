import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService, CatalogImportResult } from '../../../../services/admin.service';

@Component({
  selector: 'app-catalog-import',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './catalog-import.component.html',
  styleUrls: ['./catalog-import.component.css']
})
export class CatalogImportComponent {
  jsonText = '';
  updateExisting = true;
  busy = false;
  error = '';
  fileName = '';
  result: CatalogImportResult | null = null;

  constructor(private adminService: AdminService, private router: Router) {}

  loadSample(): void {
    this.adminService.getCatalogImportSample().subscribe({
      next: sample => {
        this.jsonText = JSON.stringify(sample, null, 2);
        this.error = '';
        this.result = null;
      },
      error: () => (this.error = 'A minta JSON betöltése nem sikerült.')
    });
  }

  downloadSample(): void {
    this.adminService.getCatalogImportSample().subscribe({
      next: sample => {
        const blob = new Blob([JSON.stringify(sample, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'filmek-es-eloadasok-minta.json';
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

    if (!dryRun && !confirm('Biztosan feltöltöd a filmeket és az előadásokat?')) {
      return;
    }

    this.busy = true;
    this.adminService.importCatalog(data, dryRun, this.updateExisting).subscribe({
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
    this.router.navigate(['/admin/movies']);
  }
}
