import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService, ShowtimeDay, Ticket, categoryLabel } from '../../../../services/admin.service';
import { paymentLabel } from '../ticket-print/ticket-print.component';

/** Egy nap előadásainak teljes jegyeladása (eladás időpontjától és eladójától függetlenül), listában és CSV exporttal. */
@Component({
  selector: 'app-showtime-day-admin',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './showtime-day-admin.component.html',
  styleUrls: ['./showtime-day-admin.component.css']
})
export class ShowtimeDayAdminComponent implements OnInit {
  date = this.keyOf(new Date());
  data: ShowtimeDay | null = null;
  loading = false;
  error: string | null = null;
  exporting = false;
  showFilter = 'all';
  showDetails = false;

  paymentLabel = paymentLabel;
  categoryLabel = categoryLabel;

  constructor(private admin: AdminService, private router: Router) {}

  ngOnInit(): void {
    this.load();
  }

  goBack(): void {
    this.router.navigate(['/admin/pos']);
  }

  load(): void {
    if (!this.date) return;

    this.loading = true;
    this.error = null;
    this.showFilter = 'all';

    this.admin.getShowtimeDay(this.date).subscribe({
      next: (data) => {
        this.data = data;
        this.loading = false;
      },
      error: () => {
        this.error = 'Az előadások eladásának betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  shiftDay(delta: number): void {
    const [y, m, d] = this.date.split('-').map(Number);
    this.date = this.keyOf(new Date(y, m - 1, d + delta));
    this.load();
  }

  /** Az előadás azonosítója a szűrőhöz és a csoportosításhoz. */
  showKey(s: { showtime_id: number | null; starts_at: string; movie_title: string }): string {
    return `${s.showtime_id ?? 0}|${s.starts_at}|${s.movie_title}`;
  }

  get visibleShows(): ShowtimeDay['shows'] {
    if (!this.data) return [];
    return this.showFilter === 'all' ? this.data.shows : this.data.shows.filter(s => this.showKey(s) === this.showFilter);
  }

  ticketsOf(show: ShowtimeDay['shows'][number]): Ticket[] {
    const key = this.showKey(show);
    return (this.data?.tickets ?? []).filter(t => this.showKey(t) === key);
  }

  exportCsv(type: 'summary' | 'tickets'): void {
    this.exporting = true;
    this.admin.exportShowtimeDay(this.date, type).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `elo_adasok_${type === 'summary' ? 'osszesito_' : ''}${this.date}.csv`;
        link.click();
        URL.revokeObjectURL(url);
        this.exporting = false;
      },
      error: () => {
        this.error = 'Az exportálás sikertelen.';
        this.exporting = false;
      }
    });
  }

  /** Az áttekintő sorra kattintva az adott előadás jegyei jelennek meg (újra kattintva visszaáll a lista). */
  pick(show: ShowtimeDay['shows'][number]): void {
    const key = this.showKey(show);
    this.showFilter = this.showFilter === key ? 'all' : key;
  }

  get detailsVisible(): boolean {
    return this.showDetails || this.showFilter !== 'all';
  }

  private keyOf(d: Date): string {
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }
}
