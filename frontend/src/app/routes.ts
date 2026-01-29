import { Routes } from '@angular/router';

export const routes: Routes = [
  {
    path: '',
    loadComponent: () => import('./features/home/home.component').then(m => m.HomeComponent)
  },
  {
    path: 'movies',
    loadComponent: () => import('./features/movies/movies-list.component').then(m => m.MoviesListComponent)
  },
  {
    path: 'schedule',
    loadComponent: () => import('./features/schedule/schedule.component').then(m => m.ScheduleComponent)
  },
  {
    path: 'prices',
    loadComponent: () => import('./features/prices/prices.component').then(m => m.PricesComponent)
  },
  {
    path: 'movies/:id',
    loadComponent: () => import('./features/movies/movie-detail.component').then(m => m.MovieDetailComponent)
  },
  {
    path: 'showtimes/:id/select-seats',
    loadComponent: () => import('./features/checkout/seat-select.component').then(m => m.SeatSelectComponent)
  },
  {
    path: 'checkout/:reservationId',
    loadComponent: () => import('./features/checkout/checkout.component').then(m => m.CheckoutComponent)
  },
  {
    path: 'hold-summary/:reservationId',
    loadComponent: () => import('./features/checkout/hold-summary.component').then(m => m.HoldSummaryComponent)
  },
  {
    path: 'success/:reservationId',
    loadComponent: () => import('./features/checkout/success.component').then(m => m.SuccessComponent)
  },
  {
    path: 'admin',
    loadChildren: () => import('./features/admin/admin.routes').then(m => m.ADMIN_ROUTES)
  },
];
