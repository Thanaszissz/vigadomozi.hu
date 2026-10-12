import { Routes } from '@angular/router';
import { AdminLoginComponent } from './components/login/login.component';
import { AdminDashboardComponent } from './components/dashboard/dashboard.component';
import { MoviesAdminComponent } from './components/movies/movies-admin.component';
import { TheaterShowsAdminComponent } from './components/theater-shows/theater-shows-admin.component';
import { ReservationsAdminComponent } from './components/reservations/reservations-admin.component';
import { ReportsAdminComponent } from './components/reports/reports-admin.component';
import { ShowtimesAdminComponent } from './components/showtimes/showtimes-admin.component';
import { AuditoriaAdminComponent } from './components/auditoria/auditoria-admin.component';
import { UsersAdminComponent } from './components/users/users-admin.component';
import { PosAdminComponent } from './components/pos/pos-admin.component';
import { TicketsAdminComponent } from './components/tickets/tickets-admin.component';
import { DailyCloseAdminComponent } from './components/daily-close/daily-close-admin.component';
import { ActivityLogAdminComponent } from './components/activity-log/activity-log-admin.component';
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
    path: 'pos',
    component: PosAdminComponent,
    canActivate: [AuthGuard]
  },
  {
    path: 'tickets',
    component: TicketsAdminComponent,
    canActivate: [AuthGuard]
  },
  {
    path: 'daily-close',
    component: DailyCloseAdminComponent,
    canActivate: [AuthGuard]
  },
  {
    path: 'activity-log',
    component: ActivityLogAdminComponent,
    canActivate: [AuthGuard]
  },
  {
    path: 'movies',
    component: MoviesAdminComponent,
    canActivate: [AuthGuard]
  },
  {
    path: 'theater-shows',
    component: TheaterShowsAdminComponent,
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
