import { Component, OnDestroy } from '@angular/core';
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

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, RouterOutlet, RouterLink, SiteHeaderComponent, SiteFooterComponent, TestModeBannerComponent, CookieNoticeComponent, MaintenancePageComponent],
  template: `
    <app-maintenance-page *ngIf="blocked" [message]="maintenanceMessage"></app-maintenance-page>
    <div class="app-container" *ngIf="!blocked">
      <app-test-mode-banner *ngIf="showHeader"></app-test-mode-banner>
      <app-site-header *ngIf="showHeader"></app-site-header>
      <main>
        <router-outlet></router-outlet>
      </main>
      <app-site-footer *ngIf="showHeader"></app-site-footer>
      <app-cookie-notice *ngIf="showHeader"></app-cookie-notice>
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
  `]
})
export class AppComponent implements OnDestroy {
  title = 'Jegyfoglalási Rendszer';
  showHeader = true;

  maintenance = false;
  maintenanceMessage = '';
  private currentUrl = '';
  private pollTimer?: ReturnType<typeof setInterval>;

  constructor(router: Router, private seo: SeoService, private api: ApiService) {
    this.showHeader = !router.url.startsWith('/admin');
    router.events.subscribe(event => {
      if (event instanceof NavigationEnd) {
        this.currentUrl = event.urlAfterRedirects;
        this.showHeader = !this.currentUrl.startsWith('/admin');
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
  }

  /** Karbantartás módban a nyilvános oldal nem látszik; az admin felület és a fizetés utáni oldal (/success) igen. */
  get blocked(): boolean {
    return this.maintenance && this.showHeader && !this.currentUrl.startsWith('/success');
  }

  ngOnDestroy(): void {
    clearInterval(this.pollTimer);
  }

  private checkStatus(): void {
    if (!this.showHeader) {
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
