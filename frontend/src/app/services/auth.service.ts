import { Injectable } from '@angular/core';
import { environment } from '../../environments/environment';
import { HttpClient } from '@angular/common/http';
import { BehaviorSubject, Observable, tap } from 'rxjs';

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  role: string;
  role_label: string;
  permissions: string[];
}

export interface LoginResponse {
  user: AuthUser;
  token: string;
  message: string;
}

@Injectable({
  providedIn: 'root'
})
export class AuthService {
  private apiUrl = environment.apiUrl;
  private tokenSubject = new BehaviorSubject<string | null>(this.getStoredToken());
  public token$ = this.tokenSubject.asObservable();

  constructor(private http: HttpClient) {}

  login(email: string, password: string): Observable<LoginResponse> {
    return this.http.post<LoginResponse>(`${this.apiUrl}/login`, {
      email,
      password
    }).pipe(
      tap(response => {
        localStorage.setItem('admin_token', response.token);
        localStorage.setItem('admin_user', JSON.stringify(response.user));
        this.tokenSubject.next(response.token);
      })
    );
  }

  logout(): void {
    const token = this.getToken();
    if (token) {
      // A szerveroldali kijelentkezés érvényteleníti a tokent és bekerül a tevékenységnaplóba
      this.http.post(`${this.apiUrl}/logout`, {}, { headers: { Authorization: `Bearer ${token}` } })
        .subscribe({ error: () => {} });
    }

    localStorage.removeItem('admin_token');
    localStorage.removeItem('admin_user');
    this.tokenSubject.next(null);
  }

  /** Helyi munkamenet törlése (a szerver már érvénytelenítette a tokent). */
  clearSession(): void {
    localStorage.removeItem('admin_token');
    localStorage.removeItem('admin_user');
    this.tokenSubject.next(null);
  }

  /** A szerepkör jogainak frissítése a szerverről (a szerepkör időközben változhatott). */
  refreshUser(): Observable<AuthUser> {
    return this.http.get<AuthUser>(`${this.apiUrl}/me`).pipe(
      tap(user => localStorage.setItem('admin_user', JSON.stringify(user)))
    );
  }

  can(permission: string): boolean {
    return !!this.getCurrentUser()?.permissions?.includes(permission);
  }

  isLoggedIn(): boolean {
    return !!this.getStoredToken();
  }

  getToken(): string | null {
    return this.tokenSubject.value;
  }

  private getStoredToken(): string | null {
    if (typeof window !== 'undefined') {
      return localStorage.getItem('admin_token');
    }
    return null;
  }

  getCurrentUser() {
    const user = localStorage.getItem('admin_user');
    return user ? JSON.parse(user) : null;
  }
}
