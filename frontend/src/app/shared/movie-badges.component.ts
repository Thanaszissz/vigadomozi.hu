import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

/** A filmekhez választható jelvények; a kulcsok a backend App\Support\MovieBadges osztályával egyeznek. */
export const MOVIE_BADGES: Array<{ key: string; label: string }> = [
  { key: 'premier', label: 'Premier' },
  { key: 'elopremier', label: 'Előpremier' },
  { key: 'magyar', label: 'Magyar film' },
  { key: 'szinkronizalt', label: 'Szinkronizált' },
  { key: 'feliratos', label: 'Feliratos' },
  { key: '3d', label: '3D' }
];

export function movieBadgeLabel(key: string): string {
  return MOVIE_BADGES.find(b => b.key === key)?.label ?? key;
}

/** A film jelvényei kis színes címkékként. */
@Component({
  selector: 'app-movie-badges',
  standalone: true,
  imports: [CommonModule],
  template: `
    <span class="badges" *ngIf="items.length">
      <span class="badge" *ngFor="let key of items" [ngClass]="'badge-' + key">{{ label(key) }}</span>
    </span>
  `,
  styles: [`
    .badges { display: inline-flex; flex-wrap: wrap; gap: 6px; }

    .badge {
      display: inline-block;
      padding: 2px 9px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.4px;
      text-transform: uppercase;
      background: #5c3d2e;
      color: #fdf7ed;
      line-height: 1.5;
    }

    .badge-premier { background: #c62828; color: #fff; box-shadow: 0 0 0 1px #ffcdd2 inset; }
    .badge-elopremier { background: #e67e22; color: #fff; }
    .badge-magyar { background: #2e7d32; color: #fff; }
    .badge-szinkronizalt { background: #1565c0; color: #fff; }
    .badge-feliratos { background: #6a1b9a; color: #fff; }
    .badge-3d { background: #00838f; color: #fff; }
  `]
})
export class MovieBadgesComponent {
  @Input() badges: string[] | null | undefined;

  get items(): string[] {
    return Array.isArray(this.badges) ? this.badges : [];
  }

  label(key: string): string {
    return movieBadgeLabel(key);
  }
}
