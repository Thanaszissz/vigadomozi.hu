import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { NavigationEnd, Router, RouterLink } from '@angular/router';
import { ApiService, Movie } from '../core/api/api.service';

@Component({
  selector: 'app-site-header',
  standalone: true,
  imports: [CommonModule, RouterLink, FormsModule],
  templateUrl: './site-header.component.html',
  styleUrls: ['./site-header.component.css']
})
export class SiteHeaderComponent implements OnInit {
  allMovies: Movie[] = [];
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
    this.apiService.getMovies().subscribe({
      next: (movies) => { this.allMovies = movies; },
      error: () => { this.allMovies = []; }
    });
  }

  get activeSection(): 'home' | 'schedule' | 'tickets' | 'prices' | '' {
    const path = this.currentUrl.split(/[?#]/)[0];
    if (path === '/' || path === '') return 'home';
    if (path.startsWith('/schedule')) return 'schedule';
    if (path.startsWith('/prices')) return 'prices';
    if (/^\/(movies|showtimes|checkout|hold-summary|success)/.test(path)) return 'tickets';
    return '';
  }

  onSearchInput(): void {
    const q = this.searchQuery.trim().toLowerCase();
    if (!q) {
      this.searchResults = this.allMovies.slice(0, 6);
    } else {
      this.searchResults = this.allMovies
        .filter(m => m.title.toLowerCase().includes(q))
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
    this.router.navigate(['/movies', movie.id]);
  }

  submitSearch(): void {
    if (this.searchResults.length > 0) {
      this.selectSuggestion(this.searchResults[0]);
    }
  }
}
