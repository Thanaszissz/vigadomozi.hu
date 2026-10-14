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
import { CustomersAdminComponent } from './components/customers/customers-admin.component';
import { PosAdminComponent } from './components/pos/pos-admin.component';
import { TicketsAdminComponent } from './components/tickets/tickets-admin.component';
import { DailyCloseAdminComponent } from './components/daily-close/daily-close-admin.component';
import { ShowtimeDayAdminComponent } from './components/showtime-day/showtime-day-admin.component';
import { ActivityLogAdminComponent } from './components/activity-log/activity-log-admin.component';
import { EmailTemplatesAdminComponent } from './components/email-templates/email-templates-admin.component';
import { SettingsAdminComponent } from './components/settings/settings-admin.component';
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
    canActivate: [AuthGuard],
    data: { permission: 'pos.sell' }
  },
  {
    path: 'tickets',
    component: TicketsAdminComponent,
    canActivate: [AuthGuard],
    data: { permission: 'tickets.archive' }
  },
  {
    path: 'daily-close',
    component: DailyCloseAdminComponent,
    canActivate: [AuthGuard],
    data: { permission: 'daily_close.view' }
  },
  {
    path: 'showtime-day',
    component: ShowtimeDayAdminComponent,
    canActivate: [AuthGuard],
    data: { permission: 'daily_close.all' }
  },
  {
    path: 'activity-log',
    component: ActivityLogAdminComponent,
    canActivate: [AuthGuard],
    data: { permission: 'activity.view' }
  },
  {
    path: 'email-templates',
    component: EmailTemplatesAdminComponent,
    canActivate: [AuthGuard],
    data: { permission: 'email.manage' }
  },
  {
    path: 'settings',
    component: SettingsAdminComponent,
    canActivate: [AuthGuard],
    data: { permission: 'settings.manage' }
  },
  {
    path: 'movies',
    component: MoviesAdminComponent,
    canActivate: [AuthGuard],
    data: { permission: 'catalog.manage' }
  },
  {
    path: 'theater-shows',
    component: TheaterShowsAdminComponent,
    canActivate: [AuthGuard],
    data: { permission: 'catalog.manage' }
  },
  {
    path: 'showtimes',
    component: ShowtimesAdminComponent,
    canActivate: [AuthGuard],
    data: { permission: 'catalog.manage' }
  },
  {
    path: 'auditoria',
    component: AuditoriaAdminComponent,
    canActivate: [AuthGuard],
    data: { permission: 'catalog.manage' }
  },
  {
    path: 'reservations',
    component: ReservationsAdminComponent,
    canActivate: [AuthGuard],
    data: { permission: 'reservations.manage' }
  },
  {
    path: 'reports',
    component: ReportsAdminComponent,
    canActivate: [AuthGuard],
    data: { permission: 'reports.view' }
  },
  {
    path: 'users',
    component: UsersAdminComponent,
    canActivate: [AuthGuard],
    data: { permission: 'users.manage' }
  },
  {
    path: 'customers',
    component: CustomersAdminComponent,
    canActivate: [AuthGuard],
    data: { permission: 'customers.manage' }
  },
  {
    path: '',
    redirectTo: 'dashboard',
    pathMatch: 'full'
  }
];
