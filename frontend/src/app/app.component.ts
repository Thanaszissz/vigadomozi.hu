import { Component, HostListener, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { NavigationEnd, Router, RouterOutlet, RouterLink } from '@angular/router';
import { SiteHeaderComponent } from './shared/site-header.component';
import { SiteFooterComponent } from './shared/site-footer.component';
import { TestModeBannerComponent } from './shared/test-mode-banner.component';
import { CookieNoticeComponent } from './shared/cookie-notice.component';
import { MaintenancePageComponent } from './shared/maintenance-page.component';
import { SeoService } from './core/seo/seo.service';
import { seoForPath } from './core/seo/seo-routes';
import { ApiService } from './core/api/api.service';
import { KioskService } from './core/kiosk/kiosk.service';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, RouterOutlet, RouterLink, SiteHeaderComponent, SiteFooterComponent, TestModeBannerComponent, CookieNoticeComponent, MaintenancePageComponent],
  template: `
    <app-maintenance-page *ngIf="blocked" [message]="maintenanceMessage"></app-maintenance-page>
    <div class="app-container" *ngIf="!blocked">
      <app-test-mode-banner *ngIf="!isAdmin"></app-test-mode-banner>
      <app-site-header *ngIf="showHeader"></app-site-header>
      <main [class.kiosk-pad]="kiosk.active && !onKioskHome && !isAdmin">
        <router-outlet></router-outlet>
      </main>
      <app-site-footer *ngIf="showHeader"></app-site-footer>
      <app-cookie-notice *ngIf="showHeader"></app-cookie-notice>
      <a *ngIf="kiosk.active && !onKioskHome && !isAdmin" routerLink="/kiosk" class="kiosk-home">⌂ Kezdőoldal</a>
    </div>
  `,
  styles: [`
    .app-container {
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      margin: 0;
      padding: 0;
    }

    main {
      flex: 1;
      width: 100%;
      margin: 0;
      padding: 0;
    }

    main.kiosk-pad {
      padding-bottom: 96px;
    }

    .kiosk-home {
      position: fixed;
      left: 16px;
      bottom: 16px;
      z-index: 2500;
      padding: 16px 26px;
      font-size: 22px;
      font-weight: 800;
      color: #2c1810;
      text-decoration: none;
      background: #d4a574;
      border-radius: 999px;
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.45);
    }
  `]
})
export class AppComponent implements OnDestroy {
  title = 'Jegyfoglalási Rendszer';
  showHeader = true;
  isAdmin = false;
  onKioskHome = false;

  maintenance = false;
  maintenanceMessage = '';
  private currentUrl = '';
  private pollTimer?: ReturnType<typeof setInterval>;
  private lastActivity = Date.now();
  private idleTimer?: ReturnType<typeof setInterval>;

  constructor(private router: Router, private seo: SeoService, private api: ApiService, public kiosk: KioskService) {
    this.applyUrl(router.url);
    router.events.subscribe(event => {
      if (event instanceof NavigationEnd) {
        this.currentUrl = event.urlAfterRedirects;
        this.applyUrl(this.currentUrl);
        this.updateSeo(this.currentUrl);
        this.checkStatus();
      }
    });

    // Karbantartás alatt az oldal félpercenként ellenőrzi, véget ért-e, és magától visszatér
    this.pollTimer = setInterval(() => {
      if (this.blocked) {
        this.checkStatus();
      }
    }, 30000);

    // Kiosk: ha senki nem használja, az állomás visszatér a kezdőoldalra (a következő vásárló tiszta lappal indul)
    this.idleTimer = setInterval(() => this.checkIdle(), 5000);
  }

  @HostListener('document:pointerdown')
  @HostListener('document:keydown')
  touched(): void {
    this.lastActivity = Date.now();
  }

  private applyUrl(url: string): void {
    this.isAdmin = url.startsWith('/admin');
    if (url === '/kiosk' || url.startsWith('/kiosk/') || url.startsWith('/kiosk?')) {
      this.kiosk.activate();
    }
    this.onKioskHome = url.split(/[?#]/)[0] === '/kiosk';
    this.showHeader = !this.isAdmin && !this.kiosk.active;
  }

  private checkIdle(): void {
    if (!this.kiosk.active || this.onKioskHome) {
      return;
    }

    // a sikeres vásárlás után hamarabb visszaáll, a foglalás közben több időt kap
    const limit = this.currentUrl.startsWith('/success') ? 45_000 : 150_000;
    if (Date.now() - this.lastActivity > limit) {
      this.router.navigate(['/kiosk']);
    }
  }

  /** Karbantartás módban a nyilvános oldal nem látszik; az admin felület és a fizetés utáni oldal (/success) igen. */
  get blocked(): boolean {
    return this.maintenance && !this.isAdmin && !this.currentUrl.startsWith('/success');
  }

  ngOnDestroy(): void {
    clearInterval(this.pollTimer);
    clearInterval(this.idleTimer);
  }

  private checkStatus(): void {
    if (this.isAdmin) {
      return;
    }

    this.api.getSiteStatus().subscribe({
      next: (status) => {
        this.maintenance = !!status.maintenance;
        this.maintenanceMessage = status.maintenance_message || '';
      },
      error: () => {}
    });
  }

  private updateSeo(url: string): void {
    this.seo.update(url, seoForPath(url));
  }
}
