import { Injectable } from '@angular/core';
import { HttpRequest, HttpHandler, HttpEvent, HttpInterceptor, HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, catchError, throwError } from 'rxjs';
import { AuthService } from './auth.service';

@Injectable()
export class AuthInterceptor implements HttpInterceptor {
  constructor(private authService: AuthService, private router: Router) {}

  intercept(request: HttpRequest<unknown>, next: HttpHandler): Observable<HttpEvent<unknown>> {
    const token = this.authService.getToken();
    
    if (token) {
      request = request.clone({
        setHeaders: {
          Authorization: `Bearer ${token}`
        }
      });
    }

    return next.handle(request).pipe(
      catchError((error: unknown) => {
        // Visszavont vagy lejárt token (pl. szerepkör-módosítás után): vissza a bejelentkezéshez
        if (error instanceof HttpErrorResponse && error.status === 401 && token && !request.url.endsWith('/login')) {
          this.authService.clearSession();
          this.router.navigate(['/admin/login']);
        }
        return throwError(() => error);
      })
    );
  }
}
