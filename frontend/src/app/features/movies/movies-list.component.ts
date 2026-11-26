import { Component, OnInit } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ApiService, Movie } from '../../core/api/api.service';
import { forkJoin, of } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { MovieBadgesComponent, showCategoryLabel } from '../../shared/movie-badges.component';
import { PosterPipe } from '../../shared/poster.pipe';

@Component({
  selector: 'app-movies-list',
  standalone: true,
  imports: [CommonModule, RouterLink, FormsModule, MovieBadgesComponent, PosterPipe],
  templateUrl: './movies-list.component.html',
  styleUrls: ['./movies-list.component.css']
})
export class MoviesListComponent implements OnInit {
  movies: Movie[] = [];
  searchTerm: string = '';
  loading = false;
  error: string | null = null;

  constructor(private apiService: ApiService) {}

  ngOnInit() {
    this.loadMovies();
  }

  loadMovies() {
    this.loading = true;
    this.error = null;

    // A színházi előadások hibája nem akadályozhatja a filmek megjelenítését
    forkJoin({
      movies: this.apiService.getMovies(),
      shows: this.apiService.getTheaterShows().pipe(catchError(() => of([] as Movie[]))),
    }).subscribe({
      next: ({ movies, shows }) => {
        this.movies = [
          ...movies.map(m => ({ ...m, is_theater: false })),
          ...shows.map(s => ({ ...s, is_theater: true })),
        ];
        this.loading = false;
      },
      error: (err) => {
        this.error = 'A műsor betöltése sikertelen. Próbálja később.';
        console.error(err);
        this.loading = false;
      },
    });
  }

  detailLink(item: Movie): string {
    return (item.is_theater ? '/programs/' : '/movies/') + item.id;
  }

  kindLabel(item: Movie): string {
    return showCategoryLabel(item.category) || 'Előadás';
  }

  get filteredMovies(): Movie[] {
    if (!this.searchTerm.trim()) {
      return this.movies;
    }
    
    const search = this.searchTerm.toLowerCase();
    return this.movies.filter(movie => 
      movie.title.toLowerCase().includes(search) ||
      movie.description?.toLowerCase().includes(search)
    );
  }
}
