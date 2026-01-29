import { Component, OnInit, OnDestroy } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { ApiService, Movie, Showtime } from '../../core/api/api.service';

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

  // Slider properties
  posters = [
    { id: 2, title: "Inception", image: "https://image.tmdb.org/t/p/w500/9gk7adHYeDvHkCSEqAvQNLV5Uge.jpg" },
    { id: 3, title: "The Dark Knight", image: "https://image.tmdb.org/t/p/w500/qJ2tW6WMUDux911r6m7haRef0WH.jpg" },
    { id: 1, title: "Interstellar", image: "https://image.tmdb.org/t/p/w500/gEU2QniE6E77NI6lCU6MxlNBvIx.jpg" },
    { id: 2, title: "The Matrix", image: "https://image.tmdb.org/t/p/w500/f89U3ADr1oiB1s9GkdPOEpXUk5H.jpg" },
    { id: 3, title: "Dune", image: "https://image.tmdb.org/t/p/w500/d5NXSklXo0qyIYkgV94XAgMIckC.jpg" },
    { id: 1, title: "Avatar", image: "https://image.tmdb.org/t/p/w500/kyeqWdyUXW608qlYkRqosgbbJyK.jpg" },
    { id: 2, title: "Oppenheimer", image: "https://image.tmdb.org/t/p/w500/8Gxv8gSFCU0XGDykEGv7zR1n2ua.jpg" },
    { id: 3, title: "Barbie", image: "https://image.tmdb.org/t/p/w500/iuFNMS8U5cb6xfzi51Dbkovj7vM.jpg" }
  ];

  slideGroups = this.chunk(this.posters, 5);
  activeSlide = 0;
  private timer?: number;

  trailerUrl: SafeResourceUrl | null = null;
  trailerTitle = "";
  trailerPoster = "";
  trailerRating = "";
  trailerMovieId: number | null = null;
  isTrailerOpen = false;

  constructor(
    private apiService: ApiService,
    private route: ActivatedRoute,
    private sanitizer: DomSanitizer
  ) {}

  ngOnInit() {
    this.timer = window.setInterval(() => this.nextSlide(), 4000);
    this.route.params.subscribe(params => {
      this.movieId = parseInt(params['id'], 10);
      this.loadMovie();
      this.loadShowtimes();
    });
  }

  ngOnDestroy(): void {
    if (this.timer) {
      window.clearInterval(this.timer);
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
    this.activeSlide = (this.activeSlide + 1) % this.slideGroups.length;
  }

  prevSlide(): void {
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
      },
      error: (err) => {
        console.error('Showtimes betöltése sikertelen:', err);
      },
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
