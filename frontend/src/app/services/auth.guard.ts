import { Injectable } from '@angular/core';
import { Router, ActivatedRouteSnapshot } from '@angular/router';
import { AuthService } from './auth.service';

@Injectable({
  providedIn: 'root'
})
export class AuthGuard {
  constructor(
    private authService: AuthService,
    private router: Router
  ) {}

  canActivate(route?: ActivatedRouteSnapshot): boolean {
    if (!this.authService.isLoggedIn()) {
      this.router.navigate(['/admin/login']);
      return false;
    }

    // A frissítés előtt mentett munkamenetből hiányoznak a jogosultságok: újra be kell jelentkezni
    if (!this.authService.getCurrentUser()?.permissions) {
      this.authService.clearSession();
      this.router.navigate(['/admin/login']);
      return false;
    }

    const required = route?.data?.['permission'] as string | undefined;
    if (required && !this.authService.can(required)) {
      this.router.navigate(['/admin/dashboard']);
      return false;
    }

    return true;
  }
}
