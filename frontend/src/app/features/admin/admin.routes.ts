import { Routes } from '@angular/router';
import { AdminLoginComponent } from './components/login/login.component';
import { AdminDashboardComponent } from './components/dashboard/dashboard.component';
import { MoviesAdminComponent } from './components/movies/movies-admin.component';
import { ReservationsAdminComponent } from './components/reservations/reservations-admin.component';
import { ReportsAdminComponent } from './components/reports/reports-admin.component';
import { ShowtimesAdminComponent } from './components/showtimes/showtimes-admin.component';
import { AuditoriaAdminComponent } from './components/auditoria/auditoria-admin.component';
import { UsersAdminComponent } from './components/users/users-admin.component';
import { AuthGuard } from '../../services/auth.guard';

export const ADMIN_ROUTES: Routes = [
  {
    path: 'login',
    component: AdminLoginComponent
  },
  {
    path: 'dashboard',
    component: AdminDashboardComponent,
    canActivate: [AuthGuard]
  },
  {
    path: 'movies',
    component: MoviesAdminComponent,
    canActivate: [AuthGuard]
  },
  {
    path: 'showtimes',
    component: ShowtimesAdminComponent,
    canActivate: [AuthGuard]
  },
  {
    path: 'auditoria',
    component: AuditoriaAdminComponent,
    canActivate: [AuthGuard]
  },
  {
    path: 'reservations',
    component: ReservationsAdminComponent,
    canActivate: [AuthGuard]
  },
  {
    path: 'reports',
    component: ReportsAdminComponent,
    canActivate: [AuthGuard]
  },
  {
    path: 'users',
    component: UsersAdminComponent,
    canActivate: [AuthGuard]
  },
  {
    path: '',
    redirectTo: 'dashboard',
    pathMatch: 'full'
  }
];
