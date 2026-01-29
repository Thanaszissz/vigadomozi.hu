import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterLink } from '@angular/router';
import { AdminService, Movie } from '../../../services/admin.service';

@Component({
  selector: 'app-movies-admin',
  standalone: true,
  imports: [CommonModule, RouterLink],
  template: `
    <div class="admin-container">
      <div class="header">
        <h1>🎥 Filmek kezelése</h1>
        <button (click)="goBack()" class="btn-back">← Vissza</button>
      </div>

      <div class="movies-list" *ngIf="!loading">
        <div class="movie-card" *ngFor="let movie of movies">
          <div class="movie-poster" [style.background-image]="movie.poster_url ? 'url(' + movie.poster_url + ')' : 'none'">
            <span *ngIf="!movie.poster_url" class="no-poster">🎬</span>
          </div>
          <div class="movie-info">
            <h3>{{ movie.title }}</h3>
            <p class="description">{{ movie.description }}</p>
            <div class="movie-meta">
              <span>⏱️ {{ movie.duration_minutes }} perc</span>
              <span *ngIf="movie.rating">⭐ {{ movie.rating }}</span>
              <span *ngIf="movie.genre">🎭 {{ movie.genre }}</span>
            </div>
            <div class="actions">
              <button class="btn-edit" (click)="editMovie(movie)">✏️ Szerkesztés</button>
              <button class="btn-delete" (click)="deleteMovie(movie)">🗑️ Törlés</button>
            </div>
          </div>
        </div>

        <div *ngIf="movies.length === 0" class="empty-state">
          <p>📭 Még nincsenek filmek az adatbázisban.</p>
        </div>
      </div>

      <div *ngIf="loading" class="loading">
        <p>⏳ Betöltés...</p>
      </div>

      <div *ngIf="error" class="error-message">
        <p>❌ {{ error }}</p>
      </div>
    </div>
  `,
  styles: [`
    .admin-container {
      padding: 20px;
      max-width: 1200px;
      margin: 0 auto;
    }

    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
    }

    h1 {
      margin: 0;
      color: #333;
    }

    .btn-back {
      padding: 10px 20px;
      background: #667eea;
      color: white;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.3s;
    }

    .btn-back:hover {
      background: #5568d3;
      transform: translateX(-3px);
    }

    .movies-list {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
      gap: 20px;
    }

    .movie-card {
      background: white;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      transition: transform 0.3s;
    }

    .movie-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    .movie-poster {
      height: 200px;
      background-size: cover;
      background-position: center;
      background-color: #f0f0f0;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .no-poster {
      font-size: 64px;
      opacity: 0.3;
    }

    .movie-info {
      padding: 15px;
    }

    .movie-info h3 {
      margin: 0 0 10px;
      color: #333;
    }

    .description {
      color: #666;
      font-size: 14px;
      margin: 0 0 15px;
      line-height: 1.4;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .movie-meta {
      display: flex;
      gap: 15px;
      flex-wrap: wrap;
      margin-bottom: 15px;
      font-size: 13px;
      color: #666;
    }

    .actions {
      display: flex;
      gap: 10px;
    }

    .btn-edit, .btn-delete {
      flex: 1;
      padding: 8px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-weight: 600;
      font-size: 13px;
      transition: all 0.3s;
    }

    .btn-edit {
      background: #4CAF50;
      color: white;
    }

    .btn-edit:hover {
      background: #45a049;
    }

    .btn-delete {
      background: #f44336;
      color: white;
    }

    .btn-delete:hover {
      background: #da190b;
    }

    .loading, .empty-state {
      text-align: center;
      padding: 60px 20px;
      color: #666;
      font-size: 18px;
    }

    .error-message {
      background: #ffebee;
      color: #c62828;
      padding: 15px;
      border-radius: 6px;
      margin-top: 20px;
    }
  `]
})
export class MoviesAdminComponent implements OnInit {
  movies: Movie[] = [];
  loading = true;
  error: string | null = null;

  constructor(
    private adminService: AdminService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.loadMovies();
  }

  loadMovies(): void {
    this.loading = true;
    this.adminService.getMovies().subscribe({
      next: (movies) => {
        this.movies = movies;
        this.loading = false;
      },
      error: (err) => {
        this.error = 'Nem sikerült betölteni a filmeket. Ellenőrizd a backend szervert!';
        this.loading = false;
        console.error(err);
      }
    });
  }

  editMovie(movie: Movie): void {
    alert(`Film szerkesztése: ${movie.title}\n(Még nincs implementálva)`);
  }

  deleteMovie(movie: Movie): void {
    if (confirm(`Biztosan törölni szeretnéd a következő filmet: "${movie.title}"?`)) {
      this.adminService.deleteMovie(movie.id).subscribe({
        next: () => {
          this.loadMovies();
        },
        error: (err) => {
          alert('Hiba történt a törlés során!');
          console.error(err);
        }
      });
    }
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
  }
}
