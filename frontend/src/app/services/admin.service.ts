import { Injectable } from '@angular/core';
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

@Injectable({
  providedIn: 'root'
})
export class AdminService {
  private apiUrl = 'http://localhost:8000/api';

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

  updateMovie(id: number, movie: Partial<Movie>): Observable<Movie> {
    return this.http.put<Movie>(`${this.apiUrl}/movies-admin/${id}`, movie);
  }

  updateMovieWithFile(id: number, formData: FormData): Observable<Movie> {
    return this.http.post<Movie>(`${this.apiUrl}/movies-admin/${id}/upload`, formData);
  }

  deleteMovie(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/movies-admin/${id}`);
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
