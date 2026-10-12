import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { NavigationEnd, Router, RouterOutlet, RouterLink } from '@angular/router';
import { SiteHeaderComponent } from './shared/site-header.component';
import { SiteFooterComponent } from './shared/site-footer.component';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, RouterOutlet, RouterLink, SiteHeaderComponent, SiteFooterComponent],
  template: `
    <div class="app-container">
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

  constructor(router: Router) {
    this.showHeader = !router.url.startsWith('/admin');
    router.events.subscribe(event => {
      if (event instanceof NavigationEnd) {
        this.showHeader = !event.urlAfterRedirects.startsWith('/admin');
      }
    });
  }
}
