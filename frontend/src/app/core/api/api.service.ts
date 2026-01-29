import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

const API_URL = 'http://localhost:8000/api';

export interface Movie {
  id: number;
  title: string;
  description: string;
  poster_path: string;
  trailer_youtube_url: string;
  duration_min: number;
  rating: string;
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
  movie_id: number;
  auditorium_id: number;
  starts_at: string;
  status: string;
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
  status: string;
  total_amount: number;
  currency: string;
  expires_at: string;
  items: ReservationItem[];
  showtime?: ShowtimeDetail;
}

@Injectable({
  providedIn: 'root'
})
export class ApiService {
  constructor(private http: HttpClient) {}

  // Movies
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

  // Seat locking and reservations
  lockSeats(showtimeId: number, email: string, name: string, phone: string, seatKeys: string[]): Observable<{ reservation: Reservation }> {
    return this.http.post<{ reservation: Reservation }>(
      `${API_URL}/showtimes/${showtimeId}/lock`,
      { email, name, phone, seatKeys }
    );
  }

  holdSeats(showtimeId: number, email: string, name: string, phone: string, seatKeys: string[]): Observable<{ reservation: Reservation }> {
    return this.http.post<{ reservation: Reservation }>(
      `${API_URL}/showtimes/${showtimeId}/hold`,
      { email, name, phone, seatKeys }
    );
  }

  getReservation(reservationId: number): Observable<Reservation> {
    return this.http.get<Reservation>(`${API_URL}/reservations/${reservationId}`);
  }

  initiatePayment(reservationId: number): Observable<{ checkout_url: string }> {
    return this.http.post<{ checkout_url: string }>(
      `${API_URL}/reservations/${reservationId}/pay`,
      {}
    );
  }
}
