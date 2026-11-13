import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { AnalyticsService } from '../core/analytics/analytics.service';

const STORAGE_KEY = 'vigado_cookie_consent';

/** Diszkrét süti-tájékoztató: a döntést a böngésző helyi tárolója őrzi, elfogadás után nem jelenik meg újra. */
@Component({
  selector: 'app-cookie-notice',
  standalone: true,
  imports: [CommonModule, RouterLink],
  template: `
    <div class="cookie-notice" *ngIf="visible" role="dialog" aria-label="Süti tájékoztató">
      <p *ngIf="!measuring">
        🍪 Az oldal a működéséhez szükséges technikai sütiket használ, a beágyazott YouTube előzetesek pedig saját sütiket helyezhetnek el.
        Marketing vagy nyomkövető sütiket nem használunk.
        <a routerLink="/adatkezelesi-tajekoztato">Adatkezelési tájékoztató</a>
      </p>
      <p *ngIf="measuring">
        🍪 Az oldal a működéséhez szükséges technikai sütiket használ. Hozzájárulásoddal statisztikai és hirdetési sütiket is (Google Analytics, Meta),
        hogy mérni és fejleszteni tudjuk az oldalt. A beágyazott YouTube előzetesek saját sütiket helyezhetnek el.
        <a routerLink="/adatkezelesi-tajekoztato">Adatkezelési tájékoztató</a>
      </p>
      <div class="buttons">
        <button type="button" class="secondary" *ngIf="measuring" (click)="choose(false)">Csak a szükségesek</button>
        <button type="button" (click)="choose(true)">{{ measuring ? 'Mindet elfogadom' : 'Rendben, elfogadom' }}</button>
      </div>
    </div>
  `,
  styles: [`
    .cookie-notice {
      position: fixed;
      left: 16px;
      bottom: 16px;
      z-index: 3000;
      display: flex;
      align-items: center;
      gap: 14px;
      max-width: 520px;
      padding: 12px 16px;
      background: #2c1810;
      color: #e8d5b7;
      border: 1px solid #5c3d2e;
      border-radius: 10px;
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
      font-size: 13px;
      line-height: 1.4;
    }

    p { margin: 0; }

    a { color: #d4a574; text-decoration: underline; white-space: nowrap; }

    button {
      flex-shrink: 0;
      padding: 8px 14px;
      border: 0;
      border-radius: 8px;
      background: #d4a574;
      color: #2c1810;
      font-weight: 700;
      font-size: 13px;
      cursor: pointer;
    }

    button:hover { background: #e6bd89; }

    .buttons { display: flex; gap: 8px; flex-shrink: 0; }

    button.secondary {
      background: transparent;
      color: #e8d5b7;
      border: 1px solid #8b6f47;
    }

    button.secondary:hover { background: rgba(212, 165, 116, 0.15); }

    @media (max-width: 600px) {
      .cookie-notice {
        left: 8px;
        right: 8px;
        bottom: 8px;
        max-width: none;
        flex-direction: column;
        align-items: stretch;
        text-align: left;
      }

      .buttons { flex-direction: column; }
    }
  `]
})
export class CookieNoticeComponent implements OnInit {
  visible = false;
  measuring = false;

  constructor(private analytics: AnalyticsService) {}

  ngOnInit(): void {
    this.visible = this.stored() === null;

    // Ha a webes mérés be van kapcsolva, a korábban csak tájékoztatóval elfogadó látogatótól is hozzájárulást kérünk
    this.analytics.config$.subscribe(config => {
      this.measuring = config.enabled;
      if (config.enabled && !this.hasChoice()) {
        this.visible = true;
      }
    });
  }

  choose(all: boolean): void {
    try {
      localStorage.setItem(STORAGE_KEY, all ? 'all' : 'necessary');
    } catch {
      // a böngésző nem engedi a tárolást: az üzenet ekkor is eltűnik az oldal megtekintésének idejére
    }
    this.analytics.setConsent(all);
    this.visible = false;
  }

  private stored(): string | null {
    try {
      return localStorage.getItem(STORAGE_KEY);
    } catch {
      return null;
    }
  }

  private hasChoice(): boolean {
    const value = this.stored();
    return value === 'all' || value === 'necessary';
  }
}
