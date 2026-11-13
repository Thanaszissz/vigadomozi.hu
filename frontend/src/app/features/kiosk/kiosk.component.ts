import { Component, HostListener, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { ApiService, Movie, Showtime } from '../../core/api/api.service';
import { MovieBadgesComponent, showCategoryLabel } from '../../shared/movie-badges.component';

interface KioskDay {
  label: string;
  showtimes: Showtime[];
}

interface KioskProgram {
  key: string;
  movie: Movie;
  theater: boolean;
  next: Date;
  showtimes: Showtime[];
}

const DETAIL_IDLE_MS = 60_000;
const REFRESH_MS = 5 * 60_000;

/** Helyszíni jegyfoglaló (kiosk): nagy plakátok az előadásokról, érintéssel választható időpont, majd a székválasztás és a fizetés. */
@Component({
  selector: 'app-kiosk',
  standalone: true,
  imports: [CommonModule, MovieBadgesComponent],
  template: `
    <div class="kiosk">
      <header class="intro">
        <h1>Üdvözöljük a Vigadóban!</h1>
        <p>Érintse meg a kiválasztott előadás plakátját a jegyfoglaláshoz</p>
      </header>

      <div *ngIf="loading" class="state">Műsor betöltése...</div>
      <div *ngIf="error" class="state error">
        {{ error }}
        <button type="button" class="btn" (click)="load()">Újrapróbálom</button>
      </div>
      <div *ngIf="!loading && !error && programs.length === 0" class="state">
        Jelenleg nincs foglalható előadás.
      </div>

      <div class="grid" *ngIf="programs.length > 0">
        <button type="button" class="poster-card" *ngFor="let p of programs" (click)="open(p)">
          <div class="poster">
            <img *ngIf="p.movie.poster_path" [src]="p.movie.poster_path" [alt]="p.movie.title" loading="lazy">
            <div *ngIf="!p.movie.poster_path" class="no-poster">{{ p.movie.title }}</div>
          </div>
          <div class="card-info">
            <span class="card-title">{{ p.movie.title }}</span>
            <span class="card-next">Következő: {{ shortWhen(p.next) }}</span>
          </div>
        </button>
      </div>

      <div class="overlay" *ngIf="selected" (click)="close()">
        <div class="detail" (click)="$event.stopPropagation()">
          <button type="button" class="close" (click)="close()" aria-label="Bezárás">✕</button>

          <div class="detail-poster">
            <img *ngIf="selected.movie.poster_path" [src]="selected.movie.poster_path" [alt]="selected.movie.title">
          </div>

          <div class="detail-body">
            <h2>{{ selected.movie.title }}</h2>
            <div class="meta">
              <app-movie-badges [badges]="selected.movie.badges" [kind]="selected.theater ? 'show' : 'movie'"></app-movie-badges>
              <span *ngIf="selected.theater && categoryLabel(selected.movie)">{{ categoryLabel(selected.movie) }}</span>
              <span *ngIf="selected.movie.duration_min">{{ selected.movie.duration_min }} perc</span>
              <span *ngIf="ratingLabel(selected.movie)">{{ ratingLabel(selected.movie) }}</span>
            </div>
            <p class="desc" *ngIf="selected.movie.description">{{ selected.movie.description }}</p>

            <h3>Válasszon időpontot</h3>
            <div class="day" *ngFor="let day of days(selected)">
              <div class="day-label">{{ day.label }}</div>
              <div class="times">
                <button type="button" class="time" *ngFor="let st of day.showtimes" (click)="book(st)">
                  <b>{{ time(st) }}</b>
                  <small *ngIf="st.auditorium?.name">{{ st.auditorium.name }}</small>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    :host {
      display: block;
      min-height: 100vh;
      background: #3d2817;
      color: #e8d5b7;
      user-select: none;
      -webkit-user-select: none;
      -webkit-tap-highlight-color: transparent;
    }

    .kiosk { padding: 28px 32px 40px; }

    .intro { text-align: center; margin-bottom: 26px; }
    .intro h1 { margin: 0 0 6px; font-size: 40px; color: #f0d9b5; }
    .intro p { margin: 0; font-size: 22px; color: #cdb596; }

    .state {
      max-width: 700px;
      margin: 40px auto;
      padding: 26px;
      text-align: center;
      font-size: 24px;
      background: #5c3d2e;
      border-radius: 14px;
    }
    .state.error { color: #fecaca; }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 26px;
    }

    .poster-card {
      display: flex;
      flex-direction: column;
      padding: 0;
      overflow: hidden;
      text-align: left;
      color: inherit;
      font: inherit;
      background: #5c3d2e;
      border: 2px solid #8b6f47;
      border-radius: 14px;
      cursor: pointer;
      transition: transform 0.15s ease, border-color 0.15s ease;
    }

    .poster-card:hover { transform: none; opacity: 1; }
    .poster-card:active { transform: scale(0.97); border-color: #f0c27a; }

    .poster { aspect-ratio: 2 / 3; background: #2f1e12; }
    .poster img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .no-poster {
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      font-size: 26px;
      font-weight: 700;
      text-align: center;
    }

    .card-info { display: flex; flex-direction: column; gap: 4px; padding: 14px 16px 16px; }
    .card-title { font-size: 24px; font-weight: 800; color: #f0d9b5; line-height: 1.2; }
    .card-next { font-size: 18px; color: #cdb596; }

    .overlay {
      position: fixed;
      inset: 0;
      z-index: 3000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: rgba(20, 12, 6, 0.85);
    }

    .detail {
      position: relative;
      display: flex;
      gap: 32px;
      width: 100%;
      max-width: 1250px;
      max-height: 100%;
      overflow: auto;
      padding: 32px;
      background: #4a3425;
      border: 2px solid #d4a574;
      border-radius: 18px;
    }

    .close {
      position: absolute;
      top: 14px;
      right: 14px;
      width: 64px;
      height: 64px;
      padding: 0;
      font-size: 30px;
      color: #fff;
      background: #8b3a2e;
      border-radius: 50%;
    }

    .detail-poster { flex: 0 0 36%; max-width: 420px; }
    .detail-poster img { width: 100%; border-radius: 12px; display: block; }

    .detail-body { flex: 1; min-width: 0; padding-right: 60px; }
    .detail-body h2 { margin: 0 0 12px; font-size: 40px; color: #f0d9b5; line-height: 1.15; }

    .meta { display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: center; margin-bottom: 14px; font-size: 20px; color: #cdb596; }

    .desc {
      margin: 0 0 18px;
      font-size: 20px;
      line-height: 1.5;
      display: -webkit-box;
      -webkit-line-clamp: 5;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .detail-body h3 { margin: 10px 0 12px; font-size: 26px; color: #f0d9b5; }

    .day { margin-bottom: 16px; }
    .day-label { margin-bottom: 8px; font-size: 22px; font-weight: 700; text-transform: capitalize; color: #cdb596; }
    .times { display: flex; flex-wrap: wrap; gap: 12px; }

    .time {
      display: flex;
      flex-direction: column;
      align-items: center;
      min-width: 130px;
      padding: 14px 22px;
      color: #1b3a1b;
      background: #4ade80;
      border-radius: 12px;
      font: inherit;
      cursor: pointer;
    }
    .time:hover { transform: none; opacity: 1; }
    .time:active { background: #22c55e; }
    .time b { font-size: 32px; line-height: 1.1; }
    .time small { font-size: 15px; font-weight: 600; }

    .btn { margin-left: 14px; padding: 12px 22px; font-size: 20px; background: #d4a574; color: #2c1810; border-radius: 10px; }

    @media (max-width: 900px) {
      .kiosk { padding: 18px 14px 30px; }
      .intro h1 { font-size: 28px; }
      .intro p { font-size: 18px; }
      .grid { grid-template-columns: repeat(2, 1fr); gap: 14px; }
      .detail { flex-direction: column; padding: 20px; gap: 18px; }
      .detail-poster { flex: none; max-width: 260px; margin: 0 auto; }
      .detail-body { padding-right: 0; }
      .detail-body h2 { font-size: 30px; }
    }
  `]
})
export class KioskComponent implements OnInit, OnDestroy {
  programs: KioskProgram[] = [];
  selected: KioskProgram | null = null;
  loading = false;
  error: string | null = null;

  private lastActivity = Date.now();
  private lastLoad = Date.now();
  private timer?: ReturnType<typeof setInterval>;

  constructor(private api: ApiService, private router: Router) {}

  ngOnInit(): void {
    this.load();
    this.timer = setInterval(() => this.tick(), 5000);
  }

  ngOnDestroy(): void {
    clearInterval(this.timer);
  }

  @HostListener('document:pointerdown')
  @HostListener('document:keydown')
  touched(): void {
    this.lastActivity = Date.now();
  }

  /** Tétlenség esetén a részletek bezáródnak, és a műsor időnként frissül. */
  private tick(): void {
    const idle = Date.now() - this.lastActivity;
    if (this.selected && idle > DETAIL_IDLE_MS) {
      this.selected = null;
    }
    if (!this.selected && Date.now() - this.lastLoad > REFRESH_MS) {
      this.load(true);
    }
  }

  load(silent = false): void {
    if (!silent) {
      this.loading = true;
    }
    this.error = null;

    this.api.getShowtimes().subscribe({
      next: (showtimes) => {
        this.programs = this.group(showtimes);
        this.lastLoad = Date.now();
        this.loading = false;
      },
      error: (err) => {
        console.error(err);
        if (!silent) {
          this.error = 'A műsor betöltése nem sikerült.';
        }
        this.loading = false;
      }
    });
  }

  private group(showtimes: Showtime[]): KioskProgram[] {
    const now = Date.now();
    const map = new Map<string, KioskProgram>();

    const upcoming = showtimes
      .filter(st => !st.is_external && (!st.status || st.status === 'active') && !!st.movie && new Date(st.starts_at).getTime() > now)
      .sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime());

    for (const st of upcoming) {
      const key = `${st.is_theater ? 't' : 'm'}-${st.movie.id}`;
      let program = map.get(key);
      if (!program) {
        program = { key, movie: st.movie, theater: !!st.is_theater, next: new Date(st.starts_at), showtimes: [] };
        map.set(key, program);
      }
      program.showtimes.push(st);
    }

    return [...map.values()];
  }

  open(program: KioskProgram): void {
    this.selected = program;
  }

  close(): void {
    this.selected = null;
  }

  book(showtime: Showtime): void {
    this.router.navigate(['/showtimes', showtime.id, 'select-seats']);
  }

  days(program: KioskProgram): KioskDay[] {
    const days: KioskDay[] = [];
    for (const st of program.showtimes) {
      const label = this.dayLabel(new Date(st.starts_at));
      const last = days[days.length - 1];
      if (last && last.label === label) {
        last.showtimes.push(st);
      } else {
        days.push({ label, showtimes: [st] });
      }
    }
    return days;
  }

  time(showtime: Showtime): string {
    return new Date(showtime.starts_at).toLocaleTimeString('hu-HU', { hour: '2-digit', minute: '2-digit' });
  }

  shortWhen(date: Date): string {
    return `${this.dayLabel(date)} ${date.toLocaleTimeString('hu-HU', { hour: '2-digit', minute: '2-digit' })}`;
  }

  categoryLabel(movie: Movie): string {
    return showCategoryLabel(movie.category);
  }

  ratingLabel(movie: Movie): string {
    if (!movie.rating) return '';
    return movie.rating === 'general' ? 'Korhatár nélkül' : `Korhatár: ${movie.rating}+`;
  }

  private dayLabel(date: Date): string {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const day = new Date(date);
    day.setHours(0, 0, 0, 0);
    const diff = Math.round((day.getTime() - today.getTime()) / 86400000);

    if (diff === 0) return 'Ma';
    if (diff === 1) return 'Holnap';
    return date.toLocaleDateString('hu-HU', { month: 'long', day: 'numeric', weekday: 'long' });
  }
}
