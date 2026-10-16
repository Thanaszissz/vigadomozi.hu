import { Component, OnDestroy, OnInit } from "@angular/core";
import { RouterLink } from "@angular/router";
import { CommonModule } from "@angular/common";
import { FormsModule } from "@angular/forms";
import { ApiService } from "../../core/api/api.service";
import { MovieBadgesComponent } from "../../shared/movie-badges.component";
import { youtubeId } from "../../core/util/html";
import { DomSanitizer, SafeResourceUrl } from "@angular/platform-browser";

interface ShowtimeWithMovie {
  id: number;
  starts_at: string;
  movie: {
    id: number;
    title: string;
    description: string;
    film_info?: string | null;
    badges?: string[] | null;
    poster_path: string;
  };
}

interface WeekDay {
  date: Date;
  label: string;
  sub: string;
  showtimes: ShowtimeWithMovie[];
  isSelected?: boolean;
  isAll?: boolean;
}

@Component({
  selector: "app-home",
  standalone: true,
  imports: [CommonModule, RouterLink, FormsModule, MovieBadgesComponent],
  templateUrl: "./home.component.html",
  styleUrls: ["./home.component.css"]
})
export class HomeComponent implements OnInit, OnDestroy {
  posters: Array<{ id: number; title: string; image: string; startsAt?: string }> = [];

  slideGroups: Array<Array<{ id: number; title: string; image: string; startsAt?: string }>> = [];
  featuredMovies: Array<{ showtime: ShowtimeWithMovie; movieId: number }> = [];
  weekDays: WeekDay[] = [];
  allShowtimes: ShowtimeWithMovie[] = [];
  selectedDayShowtimes: ShowtimeWithMovie[] = [];
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
    private sanitizer: DomSanitizer
  ) {}

  ngOnInit(): void {
    this.timer = window.setInterval(() => this.nextSlide(), 4000);
    this.generateWeekDays();
    this.loadUpcomingShowtimes();
  }

  ngOnDestroy(): void {
    if (this.timer) {
      window.clearInterval(this.timer);
    }
  }

  generateWeekDays(): void {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const dayNames = ["Vasárnap", "Hétfő", "Kedd", "Szerda", "Csütörtök", "Péntek", "Szombat"];
    const monthNames = ["Január", "Február", "Március", "Április", "Május", "Június", "Július", "Augusztus", "Szeptember", "Október", "November", "December"];

    this.weekDays = [];
    for (let i = 0; i < 6; i++) {
      const date = new Date(today);
      date.setDate(date.getDate() + i);
      
      const dayName = dayNames[date.getDay()];
      const monthName = monthNames[date.getMonth()];
      const label = `${monthName} ${date.getDate()}`;
      
      this.weekDays.push({
        date: date,
        label: label,
        sub: dayName,
        showtimes: []
      });
    }

    this.weekDays.push({
      date: today,
      label: 'Teljes műsorlista megtekintése',
      sub: 'Összes',
      showtimes: [],
      isAll: true
    });

    if (this.weekDays.length > 0) {
      this.weekDays[0].isSelected = true;
      this.selectedDayShowtimes = this.weekDays[0].showtimes;
    }
  }

  loadUpcomingShowtimes(): void {
    this.apiService.getShowtimes().subscribe({
      next: (showtimes: ShowtimeWithMovie[]) => {
        this.allShowtimes = showtimes;

        this.weekDays.forEach(day => {
          if (day.isAll) {
            return;
          }
          day.showtimes = this.allShowtimes.filter(st => {
            const stDate = new Date(st.starts_at);
            stDate.setHours(0, 0, 0, 0);
            return stDate.getTime() === day.date.getTime();
          });
        });

        const now = new Date();
        const upcomingShowtimes = showtimes
          .filter(st => new Date(st.starts_at) > now)
          .sort((a: ShowtimeWithMovie, b: ShowtimeWithMovie) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime())
          .slice(0, 2);

        this.featuredMovies = upcomingShowtimes.map(st => ({
          showtime: st,
          movieId: st.movie.id
        }));

        // Slider: legközelebbi előadások egyedi filmjei időrendben
        const upcomingSorted = showtimes
          .filter(st => new Date(st.starts_at) > now)
          .sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime());

        const seen = new Set<number>();
        const sliderItems: Array<{ id: number; title: string; image: string; startsAt?: string }> = [];
        for (const st of upcomingSorted) {
          if (seen.has(st.movie.id)) continue;
          if (!st.movie.poster_path) continue;
          seen.add(st.movie.id);
          sliderItems.push({
            id: st.movie.id,
            title: st.movie.title,
            image: st.movie.poster_path,
            startsAt: st.starts_at
          });
          if (sliderItems.length >= 16) break;
        }
        this.posters = sliderItems;
        this.slideGroups = this.chunk(this.posters, 4);
        this.activeSlide = 0;

        // Set showtimes for the first selected day
        const selectedDay = this.weekDays.find(d => d.isSelected);
        if (selectedDay && !selectedDay.isAll) {
          this.selectedDayShowtimes = selectedDay.showtimes;
        }
      },
      error: (err: any) => {
        console.error("Showtimes loading error:", err);
      }
    });
  }

  selectDay(day: WeekDay): void {
    if (day.isAll) {
      return; // Az "Összes" kártyára kattintva ne történjen semmi, mert az route-ol
    }
    this.weekDays.forEach(d => d.isSelected = false);
    day.isSelected = true;
    this.selectedDayShowtimes = day.showtimes;
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

  private getYouTubeEmbedUrl(url: string): SafeResourceUrl {
    // Csak szabályos YouTube-azonosító kerülhet az iframe címébe
    const videoId = youtubeId(url) ?? '';
    const embedUrl = `https://www.youtube.com/embed/${videoId}?autoplay=1`;
    return this.sanitizer.bypassSecurityTrustResourceUrl(embedUrl);
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

  formatShowtime(iso: string): string {
    const d = new Date(iso);
    const dayNames = ["V", "H", "K", "Sze", "Cs", "P", "Szo"];
    const pad = (n: number) => n.toString().padStart(2, "0");
    return `${pad(d.getMonth() + 1)}.${pad(d.getDate())}. (${dayNames[d.getDay()]}) ${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  private chunk<T>(items: T[], size: number): T[][] {
    const groups: T[][] = [];
    for (let i = 0; i < items.length; i += size) {
      groups.push(items.slice(i, i + size));
    }
    return groups;
  }
}
