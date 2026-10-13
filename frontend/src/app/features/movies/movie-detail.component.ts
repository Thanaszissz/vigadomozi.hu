import { Component, OnInit, OnDestroy, HostListener } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { ApiService, Movie, Showtime } from '../../core/api/api.service';
import { SeoService } from '../../core/seo/seo.service';

@Component({
  selector: 'app-movie-detail',
  standalone: true,
  imports: [CommonModule, RouterLink],
  templateUrl: './movie-detail.component.html',
  styleUrls: ['./movie-detail.component.css']
})
export class MovieDetailComponent implements OnInit, OnDestroy {
  movie: Movie | null = null;
  showtimes: Showtime[] = [];
  loading = false;
  error: string | null = null;
  movieId: number = 0;

  // További ajánló: a még várható előadásokkal rendelkező filmek (dinamikusan töltve)
  posters: Array<{ id: number; title: string; image: string }> = [];
  slideGroups: Array<Array<{ id: number; title: string; image: string }>> = [];
  activeSlide = 0;
  private timer?: number;

  // Egyszer számolt URL: ha a sablon minden változásdetektáláskor új objektumot kapna, az iframe újratöltődne
  trailerEmbedUrl: SafeResourceUrl | null = null;
  trailerUrl: SafeResourceUrl | null = null;
  trailerTitle = "";
  trailerPoster = "";
  trailerRating = "";
  trailerMovieId: number | null = null;
  isTrailerOpen = false;

  constructor(
    private apiService: ApiService,
    private route: ActivatedRoute,
    private sanitizer: DomSanitizer,
    private seo: SeoService
  ) {}

  ngOnInit() {
    this.timer = window.setInterval(() => this.nextSlide(), 4000);
    this.route.params.subscribe(params => {
      this.movieId = parseInt(params['id'], 10);
      this.loadMovie();
      this.loadShowtimes();
      this.loadRecommendations();
    });
  }

  ngOnDestroy(): void {
    if (this.timer) {
      window.clearInterval(this.timer);
    }
  }

  /** Egy lapon annyi plakát jelenik meg, ahány oszlopot a képernyő szélessége megenged (a CSS-sel egyező töréspontok). */
  private slideSize(): number {
    const w = window.innerWidth;
    return w <= 480 ? 2 : w <= 768 ? 3 : w <= 1024 ? 4 : 5;
  }

  @HostListener('window:resize')
  regroupSlides(): void {
    const groups = this.chunk(this.posters, this.slideSize());
    if (groups.length !== this.slideGroups.length || groups[0]?.length !== this.slideGroups[0]?.length) {
      this.slideGroups = groups;
      this.activeSlide = Math.min(this.activeSlide, Math.max(groups.length - 1, 0));
    }
  }

  private chunk(arr: any[], size: number) {
    const chunks = [];
    for (let i = 0; i < arr.length; i += size) {
      chunks.push(arr.slice(i, i + size));
    }
    return chunks;
  }

  nextSlide(): void {
    if (!this.slideGroups.length) return;
    this.activeSlide = (this.activeSlide + 1) % this.slideGroups.length;
  }

  prevSlide(): void {
    if (!this.slideGroups.length) return;
    this.activeSlide = (this.activeSlide - 1 + this.slideGroups.length) % this.slideGroups.length;
  }

  goToSlide(index: number): void {
    this.activeSlide = index;
  }

  openTrailer(poster: { id: number; title: string }, event: MouseEvent): void {
    event.preventDefault();
    event.stopPropagation();

    this.apiService.getMovieById(poster.id).subscribe({
      next: (movie) => {
        if (!movie.trailer_youtube_url) {
          return;
        }
        this.trailerTitle = movie.title;
        this.trailerPoster = movie.poster_path || "";
        this.trailerRating = movie.rating || "";
        this.trailerMovieId = movie.id;
        this.trailerUrl = this.getYouTubeEmbedUrl(movie.trailer_youtube_url);
        this.isTrailerOpen = true;
      },
      error: () => {
        // ignore
      }
    });
  }

  closeTrailer(): void {
    this.isTrailerOpen = false;
    this.trailerUrl = null;
    this.trailerPoster = "";
    this.trailerRating = "";
    this.trailerMovieId = null;
  }

  loadMovie() {
    this.loading = true;
    this.error = null;

    this.apiService.getMovieById(this.movieId).subscribe({
      next: (data) => {
        this.movie = data;
        this.seo.setMovie(data, this.showtimes);
        this.trailerEmbedUrl = data.trailer_youtube_url
          ? this.getYouTubeEmbedUrl(data.trailer_youtube_url)
          : null;
        this.loading = false;
      },
      error: (err) => {
        this.error = 'Film betöltése sikertelen.';
        console.error(err);
        this.loading = false;
      },
    });
  }

  loadShowtimes() {
    this.apiService.getShowtimes({ movie_id: this.movieId }).subscribe({
      next: (data) => {
        this.showtimes = data;
        if (this.movie && this.movie.id === this.movieId) {
          this.seo.setMovie(this.movie, data);
        }
      },
      error: (err) => {
        console.error('Showtimes betöltése sikertelen:', err);
      },
    });
  }

  /** A még várható előadások egyedi filmjei időrendben, az éppen megnyitott film nélkül. */
  loadRecommendations() {
    this.apiService.getShowtimes().subscribe({
      next: (showtimes) => {
        const now = Date.now();
        const seen = new Set<number>([this.movieId]);
        const items: Array<{ id: number; title: string; image: string }> = [];

        const upcoming = showtimes
          .filter(st => new Date(st.starts_at).getTime() > now)
          .sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime());

        for (const st of upcoming) {
          const movie = st.movie;
          if (!movie || seen.has(movie.id) || !movie.poster_path) continue;
          seen.add(movie.id);
          items.push({ id: movie.id, title: movie.title, image: movie.poster_path });
          if (items.length >= 15) break;
        }

        this.posters = items;
        this.regroupSlides();
        this.activeSlide = 0;
      },
      error: () => {
        this.posters = [];
        this.slideGroups = [];
      }
    });
  }

  getYouTubeEmbedUrl(url: string): SafeResourceUrl {
    let videoId = '';
    
    if (url.includes('youtube.com/watch')) {
      const params = new URL(url).searchParams;
      videoId = params.get('v') || '';
    } else if (url.includes('youtu.be/')) {
      videoId = url.split('youtu.be/')[1].split('?')[0];
    } else if (url.includes('youtube.com/embed/')) {
      videoId = url.split('youtube.com/embed/')[1].split('?')[0];
    }
    
    const embedUrl = `https://www.youtube.com/embed/${videoId}?autoplay=1`;
    return this.sanitizer.bypassSecurityTrustResourceUrl(embedUrl);
  }
}
