import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { ApiService, Movie, Showtime } from '../../core/api/api.service';
import { MovieBadgesComponent, showCategoryLabel } from '../../shared/movie-badges.component';
import { SeoService } from '../../core/seo/seo.service';
import { AnalyticsService } from '../../core/analytics/analytics.service';
import { youtubeId } from '../../core/util/html';

/** Előadás / program (színház, koncert, tánc, kiállítás stb.) oldala az időpontokkal és a jegyfoglalás indításával. */
@Component({
  selector: 'app-program-detail',
  standalone: true,
  imports: [CommonModule, RouterLink, MovieBadgesComponent],
  templateUrl: './program-detail.component.html',
  styleUrls: ['../movies/movie-detail.component.css', './program-detail.component.css']
})
export class ProgramDetailComponent implements OnInit {
  show: Movie | null = null;
  showtimes: Showtime[] = [];
  loading = false;
  error: string | null = null;
  trailerEmbedUrl: SafeResourceUrl | null = null;
  private showId = 0;
  private viewTracked = 0;

  constructor(
    private apiService: ApiService,
    private route: ActivatedRoute,
    private sanitizer: DomSanitizer,
    private seo: SeoService,
    private analytics: AnalyticsService
  ) {}

  /** view_item: az előadás legközelebbi időpontjával (egy megnyitásra egyszer). */
  private trackView(): void {
    if (this.viewTracked === this.showId) return;
    const next = this.showtimes
      .filter(st => new Date(st.starts_at).getTime() > Date.now())
      .sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime())[0];
    if (next) {
      this.viewTracked = this.showId;
      this.analytics.viewItem(next);
    }
  }

  ngOnInit(): void {
    this.route.params.subscribe(params => {
      this.showId = parseInt(params['id'], 10);
      this.load();
    });
  }

  categoryLabel(key: string | null | undefined): string {
    return showCategoryLabel(key);
  }

  private load(): void {
    this.loading = true;
    this.error = null;

    this.apiService.getTheaterShowById(this.showId).subscribe({
      next: (data) => {
        this.show = data;
        this.trailerEmbedUrl = data.trailer_youtube_url
          ? this.sanitizer.bypassSecurityTrustResourceUrl(`https://www.youtube.com/embed/${youtubeId(data.trailer_youtube_url) ?? ''}`)
          : null;
        this.seo.update(`/programs/${data.id}`, {
          title: data.title,
          description: [data.show_info, data.description].filter(Boolean).join(' – ').slice(0, 300),
          image: data.poster_path || undefined
        });
        this.loading = false;
      },
      error: () => {
        this.error = 'Az előadás betöltése sikertelen.';
        this.loading = false;
      }
    });

    this.apiService.getShowtimes({ theater_show_id: this.showId }).subscribe({
      next: (data) => {
        this.showtimes = data;
        this.trackView();
      },
      error: () => (this.showtimes = [])
    });
  }
}
