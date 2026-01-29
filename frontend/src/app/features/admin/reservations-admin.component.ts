import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { AdminService } from '../../../services/admin.service';

@Component({
  selector: 'app-reservations-admin',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="admin-container">
      <div class="header">
        <h1>🎫 Foglalások</h1>
        <button (click)="goBack()" class="btn-back">← Vissza</button>
      </div>

      <div class="reservations-list" *ngIf="!loading">
        <table *ngIf="reservations.length > 0">
          <thead>
            <tr>
              <th>ID</th>
              <th>Film</th>
              <th>Terem</th>
              <th>Időpont</th>
              <th>Ülések</th>
              <th>Ár</th>
              <th>Állapot</th>
            </tr>
          </thead>
          <tbody>
            <tr *ngFor="let reservation of reservations">
              <td>#{{ reservation.id }}</td>
              <td>{{ reservation.showtime?.movie?.title || 'N/A' }}</td>
              <td>{{ reservation.showtime?.auditorium?.name || 'N/A' }}</td>
              <td>{{ formatDate(reservation.showtime?.start_time) }}</td>
              <td>{{ reservation.items?.length || 0 }}</td>
              <td>{{ reservation.total_price }} Ft</td>
              <td>
                <span class="status" [class.paid]="reservation.status === 'PAID'" [class.pending]="reservation.status === 'PENDING'">
                  {{ reservation.status }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>

        <div *ngIf="reservations.length === 0" class="empty-state">
          <p>📭 Még nincsenek foglalások.</p>
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
      max-width: 1400px;
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
    }

    table {
      width: 100%;
      background: white;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      border-collapse: collapse;
    }

    thead {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
    }

    th, td {
      padding: 15px;
      text-align: left;
    }

    tbody tr:nth-child(even) {
      background: #f9f9f9;
    }

    tbody tr:hover {
      background: #f0f0f0;
    }

    .status {
      padding: 5px 10px;
      border-radius: 4px;
      font-weight: 600;
      font-size: 12px;
      text-transform: uppercase;
    }

    .status.paid {
      background: #4CAF50;
      color: white;
    }

    .status.pending {
      background: #FFC107;
      color: #333;
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
export class ReservationsAdminComponent implements OnInit {
  reservations: any[] = [];
  loading = true;
  error: string | null = null;

  constructor(
    private adminService: AdminService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.loadReservations();
  }

  loadReservations(): void {
    this.loading = true;
    this.adminService.getReservations().subscribe({
      next: (reservations) => {
        this.reservations = reservations;
        this.loading = false;
      },
      error: (err) => {
        this.error = 'Nem sikerült betölteni a foglalásokat!';
        this.loading = false;
        console.error(err);
      }
    });
  }

  formatDate(dateString: string): string {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('hu-HU') + ' ' + date.toLocaleTimeString('hu-HU', { hour: '2-digit', minute: '2-digit' });
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
  }
}
