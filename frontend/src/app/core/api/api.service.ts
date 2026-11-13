import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, defer, from, throwError } from 'rxjs';
import { catchError, map, switchMap } from 'rxjs/operators';
import { environment } from 'src/environments/environment';
import { BotGuardService } from './bot-guard.service';

const API_URL = environment.apiUrl;

export interface PaidTicket {
  ticket_number: string;
  movie_title: string;
  starts_at: string;
  auditorium_name: string | null;
  section?: string | null;
  row_label: string;
  seat_number: number | null;
  price_amount: number;
  sold_at: string;
}

export interface Movie {
  id: number;
  title: string;
  description: string;
  poster_path: string;
  trailer_youtube_url: string;
  duration_min: number;
  rating: string;
  film_info?: string | null;
  badges?: string[] | null;
  is_theater?: boolean;
  category?: string | null;
  performer?: string | null;
  venue?: string | null;
  show_info?: string | null;
  ticket_price?: number | null;
  created_at: string;
  updated_at: string;
}

export interface Seat {
  key: string;
  type: string;
  section?: string | null;
  bookable: boolean;
  status: 'available' | 'booked' | 'locked';
  price: number;
}

export interface Auditorium {
  id: number;
  name: string;
  layout_json: any;
}

export interface Showtime {
  id: number;
  movie_id: number | null;
  theater_show_id?: number | null;
  is_theater?: boolean;
  auditorium_id: number;
  starts_at: string;
  status: string;
  /** Külső jegyértékesítő: a jegyek nem nálunk, hanem az external_url címen vásárolhatók. */
  is_external?: boolean;
  external_url?: string | null;
  movie: Movie;
  auditorium: Auditorium;
}

export interface ShowtimeDetail extends Showtime {
  seatMap: {
    layout: any;
    seats: { [row: string]: { [seatKey: string]: Seat } };
    status: {
      booked: string[];
      locked: string[];
    };
  };
}

export interface ReservationItem {
  id: number;
  seat_key: string;
  section?: string | null;
  row_label: string;
  seat_number: number;
  price_amount: number;
}

export interface Reservation {
  id: number;
  showtime_id: number;
  customer_email: string;
  customer_name: string;
  customer_phone: string;
  customer_zip?: string | null;
  status: string;
  total_amount: number;
  currency: string;
  expires_at: string;
  items: ReservationItem[];
  showtime?: ShowtimeDetail;
  payment?: ReservationPayment | null;
}

export interface ReservationPayment {
  order_ref: string;
  transaction_id: string;
  status: string;
  amount: number;
  currency: string;
  date: string | null;
}

export interface AnalyticsConfig {
  enabled: boolean;
  gtm_id?: string;
  variant?: string;
  item_prefix_movie?: string;
  item_prefix_show?: string;
}

export interface PurchaseEventData {
  first: boolean;
  transaction_id?: string;
  value?: number;
  tax?: number | null;
  fee?: number;
  payment_type?: string;
  postal_code?: string;
}

@Injectable({
  providedIn: 'root'
})
export class ApiService {
  constructor(private http: HttpClient, private botGuard: BotGuardService) {}

  // Movies
  // Public API methods
  getTheaterShowById(id: number): Observable<Movie> {
    return this.http.get<Movie>(`${API_URL}/theater-shows/${id}`);
  }

  getSiteStatus(): Observable<{ test_mode: boolean; maintenance?: boolean; maintenance_message?: string | null }> {
    return this.http.get<{ test_mode: boolean; maintenance?: boolean; maintenance_message?: string | null }>(`${API_URL}/site-status`);
  }

  getAnalyticsConfig(): Observable<AnalyticsConfig> {
    return this.http.get<AnalyticsConfig>(`${API_URL}/analytics-config`);
  }

  /** A purchase mérési esemény egyszeri jóváhagyása (rendelésenként csak az első hívás kap first: true választ). */
  claimPurchaseEvent(reservationId: number, key: string): Observable<PurchaseEventData> {
    return this.http.post<PurchaseEventData>(`${API_URL}/reservations/${reservationId}/analytics-purchase`, {}, { params: { k: key } });
  }

  getMovies(): Observable<Movie[]> {
    return this.http.get<Movie[]>(`${API_URL}/movies`);
  }

  getMovieById(id: number): Observable<Movie> {
    return this.http.get<Movie>(`${API_URL}/movies/${id}`);
  }

  // Showtimes
  getShowtimes(filters?: any): Observable<Showtime[]> {
    let url = `${API_URL}/showtimes`;
    if (filters) {
      const params = new URLSearchParams(filters).toString();
      url += '?' + params;
    }
    return this.http.get<Showtime[]>(url);
  }

  getShowtimeById(id: number): Observable<ShowtimeDetail> {
    return this.http.get<any>(`${API_URL}/showtimes/${id}`).pipe(
      map((response: any) => ({
        ...response.showtime,
        seatMap: response.seatMap
      }))
    );
  }

  // Seat locking and reservations (a láthatatlan robotvédelem bizonyítékával és a rejtett csali mezővel)
  lockSeats(showtimeId: number, email: string, name: string, phone: string, seatKeys: string[], zip?: string, website = ''): Observable<{ reservation: Reservation }> {
    return this.guardedPost<{ reservation: Reservation }>(
      `${API_URL}/showtimes/${showtimeId}/lock`,
      { email, name, phone, seatKeys, website, ...(zip ? { zip } : {}) }
    );
  }

  holdSeats(showtimeId: number, email: string, name: string, phone: string, seatKeys: string[], zip?: string, website = ''): Observable<{ reservation: Reservation }> {
    return this.guardedPost<{ reservation: Reservation }>(
      `${API_URL}/showtimes/${showtimeId}/hold`,
      { email, name, phone, seatKeys, website, ...(zip ? { zip } : {}) }
    );
  }

  private guardedPost<T>(url: string, body: object): Observable<T> {
    const attempt = () => defer(() => from(this.botGuard.proof())).pipe(
      switchMap(captcha => this.http.post<T>(url, { ...body, captcha }))
    );
    // Ha a bizonyíték lejárt vagy elveszett, egyszer új feladattal újrapróbáljuk
    return attempt().pipe(
      catchError(err => err?.error?.code === 'captcha_failed' ? attempt() : throwError(() => err))
    );
  }

  getReservation(reservationId: number, key?: string): Observable<Reservation> {
    return this.http.get<Reservation>(`${API_URL}/reservations/${reservationId}`, key ? { params: { k: key } } : {});
  }

  getPaidTickets(reservationId: number, key: string): Observable<PaidTicket[]> {
    return this.http.get<PaidTicket[]>(`${API_URL}/reservations/${reservationId}/tickets`, { params: { k: key } });
  }

  initiatePayment(reservationId: number): Observable<{ checkout_url: string }> {
    return this.http.post<{ checkout_url: string }>(
      `${API_URL}/reservations/${reservationId}/pay`,
      {}
    );
  }
}
