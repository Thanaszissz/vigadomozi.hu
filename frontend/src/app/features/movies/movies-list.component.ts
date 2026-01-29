import { Component, OnInit } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ApiService, Movie } from '../../core/api/api.service';

@Component({
  selector: 'app-movies-list',
  standalone: true,
  imports: [CommonModule, RouterLink, FormsModule],
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

    this.apiService.getMovies().subscribe({
      next: (data) => {
        this.movies = data;
        this.loading = false;
      },
      error: (err) => {
        this.error = 'Filmek betöltése sikertelen. Próbálja később.';
        console.error(err);
        this.loading = false;
      },
    });
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
