import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { NavigationEnd, Router, RouterLink } from '@angular/router';
import { ApiService, Movie } from '../core/api/api.service';
import { PosterPipe } from './poster.pipe';

@Component({
  selector: 'app-site-header',
  standalone: true,
  imports: [CommonModule, RouterLink, FormsModule, PosterPipe],
  templateUrl: './site-header.component.html',
  styleUrls: ['./site-header.component.css']
})
export class SiteHeaderComponent implements OnInit {
  allMovies: Movie[] = [];
  private movies: Movie[] = [];
  private shows: Movie[] = [];
  searchQuery = '';
  searchResults: Movie[] = [];
  showSuggestions = false;
  highlightedIndex = -1;
  currentUrl = '';

  constructor(private apiService: ApiService, private router: Router) {
    this.currentUrl = this.router.url;
    this.router.events.subscribe(event => {
      if (event instanceof NavigationEnd) {
        this.currentUrl = event.urlAfterRedirects;
      }
    });
  }

  ngOnInit(): void {
    // a filmek és a színházi előadások egy közös listában kereshetők
    this.apiService.getMovies().subscribe({
      next: (movies) => { this.movies = movies.map(m => ({ ...m, is_theater: false })); this.mergeCatalog(); },
      error: () => { this.movies = []; this.mergeCatalog(); }
    });
    this.apiService.getTheaterShows().subscribe({
      next: (shows) => { this.shows = shows.map(s => ({ ...s, is_theater: true })); this.mergeCatalog(); },
      error: () => { this.shows = []; this.mergeCatalog(); }
    });
  }

  private mergeCatalog(): void {
    this.allMovies = [...this.movies, ...this.shows];
  }

  /** A találat oldala: film vagy színházi előadás. */
  linkFor(item: Movie): string {
    return (item.is_theater ? '/programs/' : '/movies/') + item.id;
  }

  get activeSection(): 'home' | 'schedule' | 'tickets' | 'prices' | 'media' | '' {
    const path = this.currentUrl.split(/[?#]/)[0];
    if (path === '/' || path === '') return 'home';
    if (path.startsWith('/schedule')) return 'schedule';
    if (path.startsWith('/prices')) return 'prices';
    if (path.startsWith('/mediaajanlat')) return 'media';
    if (/^\/(movies|showtimes|checkout|hold-summary|success)/.test(path)) return 'tickets';
    return '';
  }

  onSearchInput(): void {
    const q = this.searchQuery.trim().toLowerCase();
    if (!q) {
      this.searchResults = this.allMovies.slice(0, 6);
    } else {
      this.searchResults = this.allMovies
        .filter(m => m.title.toLowerCase().includes(q) || (m.performer ?? '').toLowerCase().includes(q))
        .slice(0, 8);
    }
    this.showSuggestions = true;
    this.highlightedIndex = -1;
  }

  onSearchBlur(): void {
    setTimeout(() => { this.showSuggestions = false; }, 150);
  }

  onSearchKeydown(event: KeyboardEvent): void {
    if (!this.showSuggestions || this.searchResults.length === 0) {
      if (event.key === 'Enter') this.submitSearch();
      return;
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      this.highlightedIndex = (this.highlightedIndex + 1) % this.searchResults.length;
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      this.highlightedIndex = (this.highlightedIndex - 1 + this.searchResults.length) % this.searchResults.length;
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const target = this.highlightedIndex >= 0
        ? this.searchResults[this.highlightedIndex]
        : this.searchResults[0];
      this.selectSuggestion(target);
    } else if (event.key === 'Escape') {
      this.showSuggestions = false;
    }
  }

  selectSuggestion(movie: Movie): void {
    this.showSuggestions = false;
    this.searchQuery = '';
    this.router.navigate([this.linkFor(movie)]);
  }

  submitSearch(): void {
    if (this.searchResults.length > 0) {
      this.selectSuggestion(this.searchResults[0]);
    }
  }
}
