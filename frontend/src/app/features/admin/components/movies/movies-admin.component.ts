import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService, Movie } from '../../../../services/admin.service';

@Component({
  selector: 'app-movies-admin',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './movies-admin.component.html',
  styleUrls: ['./movies-admin.component.css']
})
export class MoviesAdminComponent implements OnInit {
  movies: Movie[] = [];
  searchTerm: string = '';
  currentPage: number = 1;
  itemsPerPage: number = 6;
  loading = true;
  error = '';
  editingMovie: Movie | null = null;
  showEditModal = false;
  selectedFile: File | null = null;
  previewUrl: string | null = null;

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
        this.error = 'Hiba történt a filmek betöltése során';
        this.loading = false;
        console.error('Error loading movies:', err);
      }
    });
  }

  editMovie(movie: Movie): void {
    this.editingMovie = { ...movie };
    this.showEditModal = true;
    this.selectedFile = null;
    this.previewUrl = null;
  }

  closeModal(): void {
    this.showEditModal = false;
    this.editingMovie = null;
    this.selectedFile = null;
    this.previewUrl = null;
  }

  onFileSelected(event: any): void {
    const file = event.target.files[0];
    if (file && file.type.startsWith('image/')) {
      this.selectedFile = file;
      
      const reader = new FileReader();
      reader.onload = (e: any) => {
        this.previewUrl = e.target.result;
      };
      reader.readAsDataURL(file);
    }
  }

  saveMovie(): void {
    if (!this.editingMovie) return;

    const formData = new FormData();
    formData.append('title', this.editingMovie.title);
    formData.append('description', this.editingMovie.description || '');
    formData.append('duration_min', String(this.editingMovie.duration_min || this.editingMovie.duration_minutes || 0));
    formData.append('rating', this.editingMovie.rating || 'general');
    
    if (this.selectedFile) {
      formData.append('poster_file', this.selectedFile);
    } else if (this.editingMovie.poster_path) {
      formData.append('poster_path', this.editingMovie.poster_path);
    }

    const trailerUrl = (this.editingMovie as any).trailer_youtube_url;
    if (trailerUrl) {
      formData.append('trailer_youtube_url', trailerUrl);
    }

    this.adminService.updateMovieWithFile(this.editingMovie.id, formData).subscribe({
      next: (updatedMovie) => {
        const index = this.movies.findIndex(m => m.id === updatedMovie.id);
        if (index !== -1) {
          this.movies[index] = updatedMovie;
        }
        this.closeModal();
      },
      error: (err) => {
        this.error = 'Hiba történt a mentés során';
        console.error('Error updating movie:', err);
        console.error('Error details:', err.error);
      }
    });
  }

  deleteMovie(movie: Movie): void {
    if (!confirm('Biztosan törölni szeretnéd ezt a filmet?')) {
      return;
    }

    this.adminService.deleteMovie(movie.id).subscribe({
      next: () => {
        this.movies = this.movies.filter(m => m.id !== movie.id);
      },
      error: (err) => {
        this.error = 'Hiba történt a törlés során';
        console.error('Error deleting movie:', err);
      }
    });
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
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

  get paginatedMovies(): Movie[] {
    const startIndex = (this.currentPage - 1) * this.itemsPerPage;
    const endIndex = startIndex + this.itemsPerPage;
    return this.filteredMovies.slice(startIndex, endIndex);
  }

  get totalPages(): number {
    return Math.ceil(this.filteredMovies.length / this.itemsPerPage);
  }

  get pages(): number[] {
    return Array.from({ length: this.totalPages }, (_, i) => i + 1);
  }

  goToPage(page: number): void {
    if (page >= 1 && page <= this.totalPages) {
      this.currentPage = page;
    }
  }

  onItemsPerPageChange(): void {
    this.currentPage = 1;
  }
}
