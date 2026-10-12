import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { AuthService } from '../../../../services/auth.service';

@Component({
  selector: 'app-admin-dashboard',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './dashboard.component.html',
  styleUrls: ['./dashboard.component.css']
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

    // A szerepkör időközben változhatott: a friss jogokat a szerverről olvassuk be
    this.authService.refreshUser().subscribe({
      next: (user) => (this.currentUser = user),
      error: () => {}
    });
  }

  can(permission: string): boolean {
    return this.authService.can(permission);
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
