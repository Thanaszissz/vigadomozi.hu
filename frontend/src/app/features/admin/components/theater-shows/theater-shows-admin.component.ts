import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService, TheaterShow } from '../../../../services/admin.service';

@Component({
  selector: 'app-theater-shows-admin',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './theater-shows-admin.component.html',
  styleUrls: ['../movies/movies-admin.component.css']
})
export class TheaterShowsAdminComponent implements OnInit {
  shows: TheaterShow[] = [];
  searchTerm: string = '';
  currentPage: number = 1;
  itemsPerPage: number = 6;
  loading = true;
  error = '';
  editingShow: TheaterShow | null = null;
  showEditModal = false;
  isCreating = false;
  selectedFile: File | null = null;
  previewUrl: string | null = null;

  constructor(
    private adminService: AdminService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.loadShows();
  }

  loadShows(): void {
    this.loading = true;
    this.adminService.getTheaterShows().subscribe({
      next: (shows) => {
        this.shows = shows;
        this.loading = false;
      },
      error: (err) => {
        this.error = 'Hiba történt az előadások betöltése során';
        this.loading = false;
        console.error('Error loading theater shows:', err);
      }
    });
  }

  editShow(show: TheaterShow): void {
    this.editingShow = { ...show };
    this.isCreating = false;
    this.showEditModal = true;
    this.selectedFile = null;
    this.previewUrl = null;
  }

  newShow(): void {
    this.editingShow = {
      id: 0,
      title: '',
      description: '',
      duration_minutes: 0,
      duration_min: 0,
      rating: 'general',
      poster_path: '',
      trailer_youtube_url: ''
    } as TheaterShow;
    this.isCreating = true;
    this.showEditModal = true;
    this.selectedFile = null;
    this.previewUrl = null;
  }

  closeModal(): void {
    this.showEditModal = false;
    this.editingShow = null;
    this.isCreating = false;
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

  saveShow(): void {
    if (!this.editingShow) return;

    const formData = new FormData();
    formData.append('title', this.editingShow.title);
    formData.append('description', this.editingShow.description || '');
    formData.append('duration_min', String(this.editingShow.duration_min || this.editingShow.duration_minutes || 0));
    formData.append('rating', this.editingShow.rating || 'general');

    if (this.selectedFile) {
      formData.append('poster_file', this.selectedFile);
    } else if (this.editingShow.poster_path) {
      formData.append('poster_path', this.editingShow.poster_path);
    }

    const trailerUrl = (this.editingShow as any).trailer_youtube_url;
    if (trailerUrl) {
      formData.append('trailer_youtube_url', trailerUrl);
    }

    if (this.isCreating) {
      this.adminService.createTheaterShowWithFile(formData).subscribe({
        next: (createdShow) => {
          this.shows = [createdShow, ...this.shows];
          this.closeModal();
        },
        error: (err) => {
          this.error = 'Hiba történt az előadás létrehozása során';
          console.error('Error creating theater show:', err);
        }
      });
    } else {
      this.adminService.updateTheaterShowWithFile(this.editingShow.id, formData).subscribe({
        next: (updatedShow) => {
          const index = this.shows.findIndex(s => s.id === updatedShow.id);
          if (index !== -1) {
            this.shows[index] = updatedShow;
          }
          this.closeModal();
        },
        error: (err) => {
          this.error = 'Hiba történt a mentés során';
          console.error('Error updating theater show:', err);
        }
      });
    }
  }

  deleteShow(show: TheaterShow): void {
    if (!confirm('Biztosan törölni szeretnéd ezt az előadást?')) {
      return;
    }

    this.adminService.deleteTheaterShow(show.id).subscribe({
      next: () => {
        this.shows = this.shows.filter(s => s.id !== show.id);
      },
      error: (err) => {
        this.error = 'Hiba történt a törlés során';
        console.error('Error deleting theater show:', err);
      }
    });
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
  }

  get filteredShows(): TheaterShow[] {
    if (!this.searchTerm.trim()) {
      return this.shows;
    }
    const search = this.searchTerm.toLowerCase();
    return this.shows.filter(show =>
      show.title.toLowerCase().includes(search) ||
      show.description?.toLowerCase().includes(search)
    );
  }

  get paginatedShows(): TheaterShow[] {
    const startIndex = (this.currentPage - 1) * this.itemsPerPage;
    return this.filteredShows.slice(startIndex, startIndex + this.itemsPerPage);
  }

  get totalPages(): number {
    return Math.ceil(this.filteredShows.length / this.itemsPerPage);
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
