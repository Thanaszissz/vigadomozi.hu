import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-site-footer',
  standalone: true,
  imports: [RouterLink],
  template: `
    <footer class="site-footer">
      <div class="footer-inner">
        <div class="footer-grid">
          <section class="footer-col">
            <h4>Információk</h4>
            <a routerLink="/hazirend">Házirend</a>
            <a routerLink="/fizetesi-tajekoztato">Fizetési tájékoztató</a>
            <a routerLink="/adatkezelesi-tajekoztato">Adatkezelési tájékoztató</a>
            <!-- <a routerLink="/mediaajanlat">Médiaajánlat</a> -->
          </section>

          <section class="footer-col">
            <h4>Kapcsolat</h4>
            <p class="name">Vigadó Online Jegyértékesítő - Élményt adunk!</p>
            <p><span class="ico">📍</span>5310 Kisújszállás, Szabadság tér 7.</p>
            <p><span class="ico">📞</span><a href="tel:+3659328083">+36-59-328-083</a></p>
            <p><span class="ico">✉️</span><a href="mailto:info&#64;vigadokisuj.hu">info&#64;vigadokisuj.hu</a></p>
          </section>

          <section class="footer-col">
            <h4>Impresszum</h4>
            <p>Kisújszállási Művelődési Központ és Könyvtár</p>
            <p>5310 Kisújszállás, Szabadság tér 7.</p>
            <p>Adószám: 15576976-2-16</p>
            <div class="pay-logo">
                          <img src="assets/simplepay.webp" alt="SimplePay" width="690" height="205" loading="lazy" />
                        </div>
                      </section>
        </div>

        <div class="footer-bottom">© {{ year }} Vigadó Online Jegyértékesítő, Kisújszállás. Minden jog fenntartva.</div>
      </div>
    </footer>
  `,
  styles: [`
    :host {
      display: block;
      margin-top: 40px;
    }

    .site-footer {
      background: linear-gradient(180deg, #2f1e12 0%, #24160d 100%);
      border-top: 2px solid #d4a574;
      color: #cdb596;
      font-size: 14px;
      line-height: 1.6;
    }

    .footer-inner {
      max-width: 1200px;
      margin: 0 auto;
      padding: 36px 20px 20px;
      box-sizing: border-box;
    }

    .footer-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 32px;
    }

    .footer-col h4 {
      margin: 0 0 14px;
      padding-bottom: 8px;
      border-bottom: 1px solid rgba(212, 165, 116, 0.35);
      color: #f0d9b5;
      font-size: 15px;
      font-weight: 700;
      letter-spacing: 0.8px;
      text-transform: uppercase;
    }

    .footer-col p {
      margin: 0 0 8px;
      color: #cdb596;
    }

    .footer-col .name {
      color: #f0d9b5;
      font-weight: 600;
    }

    .ico {
      display: inline-block;
      width: 26px;
    }

    .footer-col a {
      display: block;
      margin-bottom: 8px;
      color: #cdb596;
      text-decoration: none;
      transition: color 0.2s ease, transform 0.2s ease;
    }

    .footer-col p a {
      display: inline;
    }

    .footer-col a:hover {
      color: #f0c27a;
    }

    .footer-col > a:hover {
      transform: translateX(4px);
    }

    .pay-logo {
      display: inline-block;
      margin-top: 10px;
      padding: 8px 12px;
      background: #fff;
      border-radius: 8px;
    }

    .pay-logo img {
      display: block;
      width: 100%;
      max-width: 200px;
      height: auto;
    }

    .footer-bottom {
      margin-top: 28px;
      padding-top: 16px;
      border-top: 1px solid rgba(212, 165, 116, 0.18);
      color: #9c8468;
      font-size: 12px;
      text-align: center;
    }

    @media (max-width: 768px) {
      .footer-grid {
        grid-template-columns: 1fr;
        gap: 24px;
      }
    }
  `]
})
export class SiteFooterComponent {
  year = new Date().getFullYear();
}
