import { Component, OnDestroy, OnInit } from "@angular/core";
import { RouterLink } from "@angular/router";
import { CommonModule } from "@angular/common";
import { ApiService } from "../../core/api/api.service";
import { DomSanitizer, SafeResourceUrl } from "@angular/platform-browser";

interface ShowtimeWithMovie {
  id: number;
  starts_at: string;
  movie: {
    id: number;
    title: string;
    description: string;
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
  imports: [CommonModule, RouterLink],
  templateUrl: "./home.component.html",
  styleUrls: ["./home.component.css"]
})
export class HomeComponent implements OnInit, OnDestroy {
  posters = [
    { id: 2, title: "Inception", image: "https://image.tmdb.org/t/p/w500/9gk7adHYeDvHkCSEqAvQNLV5Uge.jpg" },
    { id: 3, title: "The Dark Knight", image: "https://image.tmdb.org/t/p/w500/qJ2tW6WMUDux911r6m7haRef0WH.jpg" },
    { id: 1, title: "Interstellar", image: "https://image.tmdb.org/t/p/w500/gEU2QniE6E77NI6lCU6MxlNBvIx.jpg" },
    { id: 2, title: "The Matrix", image: "https://image.tmdb.org/t/p/w500/f89U3ADr1oiB1s9GkdPOEpXUk5H.jpg" },
    { id: 3, title: "Dune", image: "https://image.tmdb.org/t/p/w500/d5NXSklXo0qyIYkgV94XAgMIckC.jpg" },
    { id: 1, title: "Avatar", image: "https://image.tmdb.org/t/p/w500/kyeqWdyUXW608qlYkRqosgbbJyK.jpg" },
    { id: 2, title: "Oppenheimer", image: "https://image.tmdb.org/t/p/w500/8Gxv8gSFCU0XGDykEGv7zR1n2ua.jpg" },
    { id: 3, title: "Barbie", image: "https://image.tmdb.org/t/p/w500/iuFNMS8U5cb6xfzi51Dbkovj7vM.jpg" },
    { id: 1, title: "John Wick", image: "https://image.tmdb.org/t/p/w500/5vUux2vNUTqwCzb7tVcH18XnsF.jpg" },
    { id: 2, title: "Spider-Man", image: "https://image.tmdb.org/t/p/w500/1g0dhYtq4irTY1GPXvft6k4YLjm.jpg" },
    { id: 3, title: "Joker", image: "https://image.tmdb.org/t/p/w500/udDclJoHjfjb8Ekgsd4FDteOkCU.jpg" }
  ];

  slideGroups = this.chunk(this.posters, 4);
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

  constructor(private apiService: ApiService, private sanitizer: DomSanitizer) {}

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
    let videoId = "";

    if (url.includes("youtube.com/watch")) {
      const params = new URL(url).searchParams;
      videoId = params.get("v") || "";
    } else if (url.includes("youtu.be/")) {
      videoId = url.split("youtu.be/")[1].split("?")[0];
    } else if (url.includes("youtube.com/embed/")) {
      videoId = url.split("youtube.com/embed/")[1].split("?")[0];
    }

    const embedUrl = `https://www.youtube.com/embed/${videoId}?autoplay=1`;
    return this.sanitizer.bypassSecurityTrustResourceUrl(embedUrl);
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

  private chunk<T>(items: T[], size: number): T[][] {
    const groups: T[][] = [];
    for (let i = 0; i < items.length; i += size) {
      groups.push(items.slice(i, i + size));
    }
    return groups;
  }
}
