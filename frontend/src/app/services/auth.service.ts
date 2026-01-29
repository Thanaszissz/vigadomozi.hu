import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { BehaviorSubject, Observable, tap } from 'rxjs';

export interface LoginResponse {
  user: {
    id: number;
    name: string;
    email: string;
  };
  token: string;
  message: string;
}

@Injectable({
  providedIn: 'root'
})
export class AuthService {
  private apiUrl = 'http://localhost:8000/api';
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
    localStorage.removeItem('admin_token');
    localStorage.removeItem('admin_user');
    this.tokenSubject.next(null);
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
