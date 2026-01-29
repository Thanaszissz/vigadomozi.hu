import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService } from '../../../../services/admin.service';

@Component({
  selector: 'app-showtimes-admin',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './showtimes-admin.component.html',
  styleUrls: ['./showtimes-admin.component.css']
})
export class ShowtimesAdminComponent implements OnInit {
  showtimes: any[] = [];
  movies: any[] = [];
  auditoria: any[] = [];
  loading = true;
  error = '';
  searchTerm = '';
  statusFilter: 'all' | 'active' | 'inactive' = 'all';
  sortOption: 'created_desc' | 'created_asc' | 'starts_desc' | 'starts_asc' | 'movie_asc' | 'movie_desc' | 'auditorium_asc' | 'auditorium_desc' = 'created_desc';
  showEditModal = false;
  editingShowtime: any = null;
  isNewShowtime = false;
  movieSearchTerm: string = '';
  showMovieDropdown = false;
  auditoriumSearchTerm: string = '';
  showAuditoriumDropdown = false;
  dateString: string = '';
  timeString: string = '';

  constructor(
    private adminService: AdminService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.loadShowtimes();
    this.loadMovies();
    this.loadAuditoria();
  }

  loadMovies(): void {
    this.adminService.getMovies().subscribe({
      next: (movies) => {
        this.movies = movies;
      },
      error: (err) => {
        console.error('Error loading movies:', err);
      }
    });
  }

  loadAuditoria(): void {
    this.adminService.getAuditoria().subscribe({
      next: (auditoria) => {
        this.auditoria = auditoria;
      },
      error: (err) => {
        console.error('Error loading auditoria:', err);
      }
    });
  }

  loadShowtimes(): void {
    this.loading = true;
    this.adminService.getShowtimes().subscribe({
      next: (showtimes) => {
        this.showtimes = showtimes;
        this.loading = false;
      },
      error: (err) => {
        this.error = 'Hiba történt az előadások betöltése során';
        this.loading = false;
        console.error('Error loading showtimes:', err);
      }
    });
  }

  editShowtime(showtime: any): void {
    this.editingShowtime = { ...showtime };
    this.movieSearchTerm = showtime.movie?.title || '';
    this.auditoriumSearchTerm = showtime.auditorium?.name || '';
    
    if (showtime.starts_at) {
      const date = new Date(showtime.starts_at);
      this.dateString = this.formatDateForInput(date);
      this.timeString = this.formatTimeForInput(date);
    }
    
    this.isNewShowtime = false;
    this.showEditModal = true;
  }

  createNewShowtime(): void {
    this.editingShowtime = {
      movie_id: null,
      auditorium_id: null,
      starts_at: '',
      status: 'active'
    };
    this.movieSearchTerm = '';
    this.auditoriumSearchTerm = '';
    const now = new Date();
    this.dateString = this.formatDateForInput(now);
    this.timeString = this.formatTimeForInput(now);
    this.isNewShowtime = true;
    this.showEditModal = true;
  }

  closeModal(): void {
    this.showEditModal = false;
    this.editingShowtime = null;
  }

  saveShowtime(): void {
    if (!this.editingShowtime) return;

    const startsAt = this.combineDateAndTime(this.dateString, this.timeString);

    const showtimeData = {
      movie_id: this.editingShowtime.movie_id,
      auditorium_id: this.editingShowtime.auditorium_id,
      starts_at: startsAt,
      status: this.editingShowtime.status || 'active'
    };

    if (this.isNewShowtime) {
      this.adminService.createShowtime(showtimeData).subscribe({
        next: (newShowtime) => {
          this.showtimes.unshift(newShowtime);
          // Trigger change detection by creating a new array reference
          this.showtimes = [...this.showtimes];
          this.error = '';
          this.closeModal();
        },
        error: (err) => {
          this.error = 'Hiba történt az előadás létrehozása során: ' + (err.error?.message || err.message);
          console.error('Error creating showtime:', err);
        }
      });
    } else {
      this.adminService.updateShowtime(this.editingShowtime.id, showtimeData).subscribe({
        next: (updatedShowtime) => {
          const index = this.showtimes.findIndex(s => s.id === updatedShowtime.id);
          if (index !== -1) {
            this.showtimes[index] = updatedShowtime;
            // Trigger change detection by creating a new array reference
            this.showtimes = [...this.showtimes];
          }
          this.error = '';
          this.closeModal();
        },
        error: (err) => {
          this.error = 'Hiba történt a mentés során: ' + (err.error?.message || err.message);
          console.error('Error updating showtime:', err);
        }
      });
    }
  }

  deleteShowtime(showtime: any): void {
    if (!confirm('Biztosan törölni szeretnéd ezt az előadást?')) {
      return;
    }

    this.adminService.deleteShowtime(showtime.id).subscribe({
      next: () => {
        this.showtimes = this.showtimes.filter(s => s.id !== showtime.id);
      },
      error: (err) => {
        this.error = 'Hiba történt a törlés során';
        console.error('Error deleting showtime:', err);
      }
    });
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
  }

  get filteredShowtimes(): any[] {
    const search = this.searchTerm.trim().toLowerCase();

    let result = [...this.showtimes];

    if (this.statusFilter !== 'all') {
      result = result.filter(showtime => {
        const status = (showtime.status || '').toLowerCase();
        if (this.statusFilter === 'active') {
          return status === 'active';
        }
        return status !== 'active';
      });
    }

    if (search.length > 0) {
      result = result.filter(showtime => {
        const movieTitle = (showtime.movie?.title || '').toLowerCase();
        const auditoriumName = (showtime.auditorium?.name || '').toLowerCase();
        const startsAt = showtime.starts_at ? new Date(showtime.starts_at).toLocaleString('hu-HU').toLowerCase() : '';
        const idText = String(showtime.id || '').toLowerCase();
        return movieTitle.includes(search) || auditoriumName.includes(search) || startsAt.includes(search) || idText.includes(search);
      });
    }

    const compareText = (a: string, b: string) => a.localeCompare(b, 'hu');
    const compareDate = (a?: string, b?: string) => {
      const aTime = a ? new Date(a).getTime() : 0;
      const bTime = b ? new Date(b).getTime() : 0;
      return aTime - bTime;
    };

    result.sort((a, b) => {
      switch (this.sortOption) {
        case 'created_asc':
          return compareDate(a.created_at, b.created_at);
        case 'created_desc':
          return compareDate(b.created_at, a.created_at);
        case 'starts_asc':
          return compareDate(a.starts_at, b.starts_at);
        case 'starts_desc':
          return compareDate(b.starts_at, a.starts_at);
        case 'movie_asc':
          return compareText(a.movie?.title || '', b.movie?.title || '');
        case 'movie_desc':
          return compareText(b.movie?.title || '', a.movie?.title || '');
        case 'auditorium_asc':
          return compareText(a.auditorium?.name || '', b.auditorium?.name || '');
        case 'auditorium_desc':
          return compareText(b.auditorium?.name || '', a.auditorium?.name || '');
        default:
          return 0;
      }
    });

    return result;
  }

  get filteredMovies(): any[] {
    if (!this.movieSearchTerm.trim()) {
      return this.movies;
    }
    const search = this.movieSearchTerm.toLowerCase();
    return this.movies.filter(movie => 
      movie.title.toLowerCase().includes(search)
    );
  }

  get filteredAuditoria(): any[] {
    if (!this.auditoriumSearchTerm.trim()) {
      return this.auditoria;
    }
    const search = this.auditoriumSearchTerm.toLowerCase();
    return this.auditoria.filter(auditorium => 
      auditorium.name.toLowerCase().includes(search)
    );
  }

  onMovieSearchFocus(): void {
    this.showMovieDropdown = true;
  }

  onMovieSearchBlur(): void {
    setTimeout(() => {
      this.showMovieDropdown = false;
    }, 200);
  }

  onAuditoriumSearchFocus(): void {
    this.showAuditoriumDropdown = true;
  }

  onAuditoriumSearchBlur(): void {
    setTimeout(() => {
      this.showAuditoriumDropdown = false;
    }, 200);
  }

  selectAuditorium(auditorium: any): void {
    this.editingShowtime.auditorium_id = auditorium.id;
    this.auditoriumSearchTerm = auditorium.name;
    this.showAuditoriumDropdown = false;
  }

  selectMovie(movie: any): void {
    this.editingShowtime.movie_id = movie.id;
    this.movieSearchTerm = movie.title;
    this.showMovieDropdown = false;
  }

  getSelectedMovieTitle(): string {
    if (!this.editingShowtime.movie_id) return '';
    const movie = this.movies.find(m => m.id === this.editingShowtime.movie_id);
    return movie ? movie.title : '';
  }

  formatDateForInput(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  formatTimeForInput(date: Date): string {
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${hours}:${minutes}`;
  }

  combineDateAndTime(dateStr: string, timeStr: string): string {
    // dateStr format: YYYY-MM-DD
    // timeStr format: HH:mm
    return `${dateStr} ${timeStr}:00`;
  }
}
