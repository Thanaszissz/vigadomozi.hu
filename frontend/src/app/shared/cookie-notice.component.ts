import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';

const STORAGE_KEY = 'vigado_cookie_consent';

/** Diszkrét süti-tájékoztató: a döntést a böngésző helyi tárolója őrzi, elfogadás után nem jelenik meg újra. */
@Component({
  selector: 'app-cookie-notice',
  standalone: true,
  imports: [CommonModule, RouterLink],
  template: `
    <div class="cookie-notice" *ngIf="visible" role="dialog" aria-label="Süti tájékoztató">
      <p>
        🍪 Az oldal a működéséhez szükséges technikai sütiket használ, a beágyazott YouTube előzetesek pedig saját sütiket helyezhetnek el.
        Marketing vagy nyomkövető sütiket nem használunk.
        <a routerLink="/adatkezelesi-tajekoztato">Adatkezelési tájékoztató</a>
      </p>
      <button type="button" (click)="accept()">Rendben, elfogadom</button>
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
    }
  `]
})
export class CookieNoticeComponent implements OnInit {
  visible = false;

  ngOnInit(): void {
    try {
      this.visible = localStorage.getItem(STORAGE_KEY) === null;
    } catch {
      this.visible = true;
    }
  }

  accept(): void {
    try {
      localStorage.setItem(STORAGE_KEY, new Date().toISOString());
    } catch {
      // a böngésző nem engedi a tárolást: az üzenet ekkor is eltűnik az oldal megtekintésének idejére
    }
    this.visible = false;
  }
}
