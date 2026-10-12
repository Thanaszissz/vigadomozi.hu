import { Injectable } from '@angular/core';
import { environment } from '../../environments/environment';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

export interface Movie {
  id: number;
  title: string;
  description: string;
  duration_minutes: number;
  poster_url?: string;
  rating?: 'general' | '6' | '12' | '16' | '18';
  genre?: string;
  duration_min?: number;
  poster_path?: string;
  trailer_youtube_url?: string;
}

export interface TheaterShow {
  id: number;
  title: string;
  description: string;
  duration_minutes: number;
  poster_url?: string;
  rating?: 'general' | '6' | '12' | '16' | '18';
  duration_min?: number;
  poster_path?: string;
  trailer_youtube_url?: string;
}

export type PaymentMethod = 'cash' | 'card';

export interface Ticket {
  id: number;
  ticket_year: number;
  ticket_seq: number;
  ticket_number: string;
  reservation_id: number | null;
  showtime_id: number | null;
  movie_title: string;
  starts_at: string;
  auditorium_name: string | null;
  seat_key: string;
  row_label: string;
  seat_number: number | null;
  price_amount: number;
  currency: string;
  payment_method: string;
  channel: string;
  sold_by_name: string | null;
  sold_at: string;
}

export interface TicketPage {
  year: number;
  summary: {
    tickets: number;
    amount: number;
    by_method: Array<{ payment_method: string; tickets: number; amount: number }>;
  };
  tickets: {
    data: Ticket[];
    current_page: number;
    last_page: number;
    total: number;
  };
}

export interface MethodTotals {
  payment_method: string;
  tickets: number;
  amount: number;
}

export interface SellerTotals {
  tickets: number;
  amount: number;
  by_method: MethodTotals[];
}

export interface DailyClose {
  date: string;
  generated_at: string;
  totals: SellerTotals;
  sellers: Array<{
    user_id: number | null;
    user_name: string;
    totals: SellerTotals;
    tickets: Ticket[];
  }>;
}

export interface SalesLogEntry {
  id: number;
  occurred_at: string;
  user_id: number | null;
  user_name: string | null;
  channel: string;
  reservation_id: number | null;
  movie_title: string | null;
  starts_at: string | null;
  ticket_count: number;
  total_amount: number;
  payment_method: string;
  ticket_numbers: string;
  ip_address: string | null;
}

export interface ActivityLogEntry {
  id: number;
  occurred_at: string;
  user_id: number | null;
  user_name: string | null;
  user_email: string | null;
  category: string;
  action: string;
  description: string;
  subject_type: string | null;
  subject_id: number | null;
  subject_label: string | null;
  properties: any;
  ip_address: string | null;
  user_agent: string | null;
}

export interface ActivityLogPage {
  total: number;
  logs: {
    data: ActivityLogEntry[];
    current_page: number;
    last_page: number;
  };
}

export interface ActivityMeta {
  categories: Array<{ key: string; label: string }>;
  actions: Array<{ key: string; category: string; label: string }>;
  users: Array<{ id: number; name: string }>;
}

@Injectable({
  providedIn: 'root'
})
export class AdminService {
  private apiUrl = environment.apiUrl;

  constructor(private http: HttpClient) {}

  // Movies
  getMovies(): Observable<Movie[]> {
    return this.http.get<Movie[]>(`${this.apiUrl}/movies-admin`);
  }

  getMovie(id: number): Observable<Movie> {
    return this.http.get<Movie>(`${this.apiUrl}/movies-admin/${id}`);
  }

  createMovie(movie: Partial<Movie>): Observable<Movie> {
    return this.http.post<Movie>(`${this.apiUrl}/movies-admin`, movie);
  }

  createMovieWithFile(formData: FormData): Observable<Movie> {
    return this.http.post<Movie>(`${this.apiUrl}/movies-admin`, formData);
  }

  updateMovie(id: number, movie: Partial<Movie>): Observable<Movie> {
    return this.http.put<Movie>(`${this.apiUrl}/movies-admin/${id}`, movie);
  }

  updateMovieWithFile(id: number, formData: FormData): Observable<Movie> {
    return this.http.post<Movie>(`${this.apiUrl}/movies-admin/${id}/upload`, formData);
  }

  deleteMovie(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/movies-admin/${id}`);
  }

  // Theater Shows
  getTheaterShows(): Observable<TheaterShow[]> {
    return this.http.get<TheaterShow[]>(`${this.apiUrl}/theater-shows-admin`);
  }

  getTheaterShow(id: number): Observable<TheaterShow> {
    return this.http.get<TheaterShow>(`${this.apiUrl}/theater-shows-admin/${id}`);
  }

  createTheaterShowWithFile(formData: FormData): Observable<TheaterShow> {
    return this.http.post<TheaterShow>(`${this.apiUrl}/theater-shows-admin`, formData);
  }

  updateTheaterShowWithFile(id: number, formData: FormData): Observable<TheaterShow> {
    return this.http.post<TheaterShow>(`${this.apiUrl}/theater-shows-admin/${id}/upload`, formData);
  }

  deleteTheaterShow(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/theater-shows-admin/${id}`);
  }

  // Showtimes
  getShowtimes(): Observable<any[]> {
    return this.http.get<any[]>(`${this.apiUrl}/showtimes-admin`);
  }

  getShowtime(id: number): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/showtimes-admin/${id}`);
  }

  createShowtime(showtime: any): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/showtimes-admin`, showtime);
  }

  updateShowtime(id: number, showtime: any): Observable<any> {
    return this.http.put<any>(`${this.apiUrl}/showtimes-admin/${id}`, showtime);
  }

  deleteShowtime(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/showtimes-admin/${id}`);
  }

  // Reservations
  getReservations(): Observable<any[]> {
    return this.http.get<any[]>(`${this.apiUrl}/reservations`);
  }

  getGroupedReservations(): Observable<any[]> {
    return this.http.get<any[]>(`${this.apiUrl}/reservations/grouped`);
  }

  startReservationPayment(reservationId: number, method: 'cash' | 'card'): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/reservations/${reservationId}/start-payment`, { method });
  }

  // Reports
  getDailyReport(date: string): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/reports/daily?date=${date}`);
  }

  // Helyszíni jegyeladás
  getShowtimeReservations(showtimeId: number): Observable<any[]> {
    return this.http.get<any[]>(`${this.apiUrl}/showtimes/${showtimeId}/reservations`);
  }

  posSell(payload: {
    showtime_id: number;
    seatKeys: string[];
    payment_method: PaymentMethod;
    customer_name?: string;
  }): Observable<{ reservation: any; tickets: Ticket[] }> {
    return this.http.post<{ reservation: any; tickets: Ticket[] }>(`${this.apiUrl}/pos/sell`, payload);
  }

  posPayReservation(reservationId: number, paymentMethod: PaymentMethod): Observable<{ reservation: any; tickets: Ticket[] }> {
    return this.http.post<{ reservation: any; tickets: Ticket[] }>(
      `${this.apiUrl}/pos/reservations/${reservationId}/pay`,
      { payment_method: paymentMethod }
    );
  }

  // Eladott jegyek archívuma
  getTicketYears(): Observable<number[]> {
    return this.http.get<number[]>(`${this.apiUrl}/tickets/years`);
  }

  getTickets(params: { year: number; page?: number; payment_method?: string; date?: string; q?: string }): Observable<TicketPage> {
    const query = Object.entries(params)
      .filter(([, value]) => value !== undefined && value !== null && value !== '')
      .map(([key, value]) => `${key}=${encodeURIComponent(String(value))}`)
      .join('&');
    return this.http.get<TicketPage>(`${this.apiUrl}/tickets?${query}`);
  }

  exportTickets(year: number): Observable<Blob> {
    return this.http.get(`${this.apiUrl}/tickets/export?year=${year}`, { responseType: 'blob' });
  }

  getDailyClose(date: string): Observable<DailyClose> {
    return this.http.get<DailyClose>(`${this.apiUrl}/tickets/daily-close?date=${date}`);
  }

  getSalesLog(date: string): Observable<{ date: string; logs: SalesLogEntry[] }> {
    return this.http.get<{ date: string; logs: SalesLogEntry[] }>(`${this.apiUrl}/tickets/sales-log?date=${date}`);
  }

  // Tevékenységnapló
  getActivityMeta(): Observable<ActivityMeta> {
    return this.http.get<ActivityMeta>(`${this.apiUrl}/activity-logs/meta`);
  }

  getActivityLogs(params: Record<string, string | number | undefined>): Observable<ActivityLogPage> {
    const query = Object.entries(params)
      .filter(([, value]) => value !== undefined && value !== null && value !== '')
      .map(([key, value]) => `${key}=${encodeURIComponent(String(value))}`)
      .join('&');
    return this.http.get<ActivityLogPage>(`${this.apiUrl}/activity-logs?${query}`);
  }

  // A nyomtatás csak a böngészőben látszik, ezért onnan jelentjük a szervernek
  logClientEvent(action: 'ticket.print' | 'report.daily_close_print', description: string, details: object = {}): void {
    this.http.post(`${this.apiUrl}/activity-logs/event`, { action, description, details }).subscribe({ error: () => {} });
  }

  // Auditoria
  getAuditoria(): Observable<any[]> {
    return this.http.get<any[]>(`${this.apiUrl}/auditoria`);
  }

  getAuditorium(id: number): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/auditoria/${id}`);
  }

  getAuditoriumSeats(id: number): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/auditoria/${id}/seats`);
  }

  updateAuditoriumSeats(id: number, seats: any[], sectors: any[], sections: any[], rows: any[]): Observable<any> {
    return this.http.put<any>(`${this.apiUrl}/auditoria/${id}/seats`, { seats, sectors, sections, rows });
  }

  createAuditorium(auditorium: any): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/auditoria`, auditorium);
  }

  updateAuditorium(id: number, auditorium: any): Observable<any> {
    return this.http.put<any>(`${this.apiUrl}/auditoria/${id}`, auditorium);
  }

  deleteAuditorium(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/auditoria/${id}`);
  }

  // Users
  getUsers(): Observable<any[]> {
    return this.http.get<any[]>(`${this.apiUrl}/users`);
  }

  getUser(id: number): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/users/${id}`);
  }

  createUser(user: any): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/users`, user);
  }

  updateUser(id: number, user: any): Observable<any> {
    return this.http.put<any>(`${this.apiUrl}/users/${id}`, user);
  }

  deleteUser(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/users/${id}`);
  }
}
