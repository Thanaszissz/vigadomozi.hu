import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { AuthService } from '../../services/auth.service';

@Component({
  selector: 'app-admin-dashboard',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="admin-container">
      <nav class="admin-navbar">
        <div class="navbar-brand">
          <h2>🎬 VIGADÓ Admin</h2>
        </div>
        <div class="navbar-user">
          <span>👤 {{ currentUser?.name }}</span>
          <button (click)="logout()" class="btn-logout">Kijelentkezés</button>
        </div>
      </nav>

      <div class="admin-content">
        <h1>Üdvözöljük az Admin Panelben!</h1>
        <p class="subtitle">Jó {{greeting}}! 👋</p>

        <div class="dashboard-grid">
          <div class="dashboard-card">
            <h3>🎥 Filmek</h3>
            <p>Filmek kezelése, szerkesztése</p>
            <button (click)="navigateTo('/admin/movies')" class="btn-card">Filmek</button>
          </div>

          <div class="dashboard-card">
            <h3>🎞️ Előadások</h3>
            <p>Vetítések szerkesztése és ütemezése</p>
            <button class="btn-card" disabled>Előadások (hamarosan)</button>
          </div>

          <div class="dashboard-card">
            <h3>🎫 Foglalások</h3>
            <p>Jegyfoglalások megtekintése és exportálása</p>
            <button (click)="navigateTo('/admin/reservations')" class="btn-card">Foglalások</button>
          </div>

          <div class="dashboard-card">
            <h3>🪑 Termek</h3>
            <p>Auditorium beállítások és ülések</p>
            <button class="btn-card" disabled>Termek (hamarosan)</button>
          </div>
        </div>

        <div class="info-box">
          <h3>ℹ️ Bejelentkezési Adatok</h3>
          <p><strong>Email:</strong> {{ currentUser?.email || 'N/A' }}</p>
          <p><strong>Név:</strong> {{ currentUser?.name || 'N/A' }}</p>
          <p><strong>Admin Token:</strong> <code>{{ token ? token.substring(0, 20) + '...' : 'N/A' }}</code></p>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .admin-container {
      min-height: 100vh;
      background: #f5f5f5;
    }

    .admin-navbar {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 20px 40px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .navbar-brand h2 {
      margin: 0;
      font-size: 24px;
    }

    .navbar-user {
      display: flex;
      align-items: center;
      gap: 20px;
    }

    .btn-logout {
      padding: 8px 16px;
      background: rgba(255, 255, 255, 0.2);
      color: white;
      border: 1px solid white;
      border-radius: 4px;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.3s;
    }

    .btn-logout:hover {
      background: rgba(255, 255, 255, 0.3);
    }

    .admin-content {
      padding: 40px;
      max-width: 1200px;
      margin: 0 auto;
    }

    h1 {
      color: #333;
      margin: 0 0 10px;
      font-size: 32px;
    }

    .subtitle {
      color: #666;
      margin-bottom: 40px;
      font-size: 16px;
    }

    .dashboard-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 20px;
      margin-bottom: 40px;
    }

    .dashboard-card {
      background: white;
      border-radius: 8px;
      padding: 25px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
      transition: all 0.3s;
      text-align: center;
    }

    .dashboard-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
    }

    .dashboard-card h3 {
      margin: 0 0 10px;
      color: #333;
      font-size: 18px;
    }

    .dashboard-card p {
      color: #666;
      margin: 0 0 20px;
      font-size: 14px;
    }

    .btn-card {
      padding: 10px 20px;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.3s;
    }

    .btn-card:hover {
      transform: scale(1.05);
    }

    .info-box {
      background: white;
      padding: 25px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .info-box h3 {
      margin-top: 0;
      color: #333;
    }

    .info-box p {
      margin: 10px 0;
      color: #666;
    }

    code {
      background: #f5f5f5;
      padding: 2px 6px;
      border-radius: 3px;
      font-family: monospace;
      color: #d63031;
    }
  `]
})
export class AdminDashboardComponent implements OnInit {
  currentUser: any;
  token: string | null = null;
  greeting = 'reggelt';

  constructor(
    private authService: AuthService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.currentUser = this.authService.getCurrentUser();
    this.token = this.authService.getToken();
    this.setGreeting();
  }

  setGreeting(): void {
    const hour = new Date().getHours();
    if (hour < 12) {
      this.greeting = 'reggelt';
    } else if (hour < 18) {
      this.greeting = 'délutánt';
    } else {
      this.greeting = 'estét';
    }
  }

  logout(): void {
    this.authService.logout();
    this.router.navigate(['/admin/login']);
  }

  navigateTo(path: string): void {
    this.router.navigate([path]);
  }
}
