import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { NavigationEnd, Router, RouterOutlet, RouterLink } from '@angular/router';
import { SiteHeaderComponent } from './shared/site-header.component';
import { SiteFooterComponent } from './shared/site-footer.component';
import { TestModeBannerComponent } from './shared/test-mode-banner.component';
import { SeoService } from './core/seo/seo.service';
import { seoForPath } from './core/seo/seo-routes';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, RouterOutlet, RouterLink, SiteHeaderComponent, SiteFooterComponent, TestModeBannerComponent],
  template: `
    <div class="app-container">
      <app-test-mode-banner *ngIf="showHeader"></app-test-mode-banner>
      <app-site-header *ngIf="showHeader"></app-site-header>
      <main>
        <router-outlet></router-outlet>
      </main>
      <app-site-footer *ngIf="showHeader"></app-site-footer>
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
export class AppComponent {
  title = 'Jegyfoglalási Rendszer';
  showHeader = true;

  constructor(router: Router, private seo: SeoService) {
    this.showHeader = !router.url.startsWith('/admin');
    router.events.subscribe(event => {
      if (event instanceof NavigationEnd) {
        this.showHeader = !event.urlAfterRedirects.startsWith('/admin');
        this.updateSeo(event.urlAfterRedirects);
      }
    });
  }

  private updateSeo(url: string): void {
    this.seo.update(url, seoForPath(url));
  }
}
