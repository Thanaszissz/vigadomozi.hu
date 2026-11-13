import { Component } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { NgSwitch, NgSwitchCase, NgSwitchDefault } from '@angular/common';
import { SIMPLEPAY_INFO_PDF_URL, SIMPLEPAY_PRIVACY_URL } from './simplepay-consent.component';

@Component({
  selector: 'app-info-page',
  standalone: true,
  imports: [RouterLink, NgSwitch, NgSwitchCase, NgSwitchDefault],
  template: `
    <div class="page">
      <section class="content">
        <h1>{{ title }}</h1>

        <ng-container [ngSwitch]="slug">
          <article *ngSwitchCase="'fizetesi-tajekoztato'" class="doc">
            <h2>Online jegyvásárlás és fizetés</h2>
            <p>
              A vigadojegy.hu oldalon a jegyeket bankkártyával fizetheted ki. A fizetést a <b>SimplePay Zrt.</b>
              (1138 Budapest, Váci út 135-139. B. ép. 5. em.) biztonságos fizetési felülete bonyolítja le.
              A kártyaadataidat az oldalunk nem látja és nem tárolja, azokat kizárólag a SimplePay kezeli.
            </p>

            <h2>A vásárlás menete</h2>
            <ol>
              <li>Válaszd ki az előadást és a székeket, add meg a nevedet, e-mail címedet, telefonszámodat.</li>
              <li>A fizetési oldalon ellenőrizd a foglalás adatait, fogadd el az adattovábbítási nyilatkozatot, majd indítsd el a bankkártyás fizetést.</li>
              <li>A SimplePay oldalán add meg a kártyaadataidat. A székeket ez idő alatt számodra zároljuk.</li>
              <li>Sikeres fizetés után a visszaigazoló oldalon látod a SimplePay tranzakcióazonosítót és a rendelésszámot, a jegyeket pedig e-mailben (PDF-ben) elküldjük.</li>
              <li>Sikertelen, megszakított vagy lejárt fizetés esetén a foglalás a megadott időkorlátig él, a fizetést újra elindíthatod.</li>
            </ol>

            <h2>Elfogadott fizetési mód, árak</h2>
            <p>
              Online a SimplePay felületén elérhető bankkártyás fizetés (a SimplePay által elfogadott bankkártyák). Az árak magyar forintban (HUF),
              bruttó összegként értendők. A jegyeket a pénztárban is megvásárolhatod.
            </p>

            <h2>Tranzakcióazonosító</h2>
            <p>
              Minden online fizetéshez a SimplePay egyedi tranzakcióazonosítót rendel. Ezt a fizetés utáni oldalon és a jegyeket tartalmazó
              e-mailben is megkapod. Panasz vagy reklamáció esetén kérjük, hivatkozz erre az azonosítóra és a rendelésszámra.
            </p>

            <h2>Elmaradt előadás, visszatérítés, reklamáció</h2>
            <p>
              Elmaradt előadás esetén a jegy árát az eredeti fizetési móddal visszatérítjük. Visszatérítéssel, lemondással vagy a jegyekkel kapcsolatos
              egyéb kérdésben írj nekünk az
              <a href="mailto:info&#64;vigadokisuj.hu">info&#64;vigadokisuj.hu</a> címre, vagy hívj a
              <a href="tel:+3659328083">+36-59-328-083</a> telefonszámon. A jegyvásárlásra a <a routerLink="/hazirend">Házirend</a> is vonatkozik.
            </p>

            <h2>Szolgáltató adatai</h2>
            <p>
              Kisújszállási Művelődési Központ és Könyvtár<br>
              5310 Kisújszállás, Szabadság tér 7.<br>
              Adószám: 15576976-2-16<br>
              Telefon: <a href="tel:+3659328083">+36-59-328-083</a><br>
              E-mail: <a href="mailto:info&#64;vigadokisuj.hu">info&#64;vigadokisuj.hu</a>
            </p>

            <h2>A SimplePay fizetési tájékoztatója</h2>
            <p>
              A SimplePay bankkártyás fizetési szolgáltatásának részletes ismertetője:
              <a [href]="infoPdf" target="_blank" rel="noopener">SimplePay fizetési tájékoztató (PDF)</a>.
            </p>
            <div class="logo"><img src="assets/simplepay-logo.webp" alt="SimplePay" width="692" height="209" loading="lazy"></div>
          </article>

          <article *ngSwitchCase="'adatkezelesi-tajekoztato'" class="doc">
            <h2>Adatkezelő</h2>
            <p>
              Kisújszállási Művelődési Központ és Könyvtár, 5310 Kisújszállás, Szabadság tér 7., adószám: 15576976-2-16,
              e-mail: <a href="mailto:info&#64;vigadokisuj.hu">info&#64;vigadokisuj.hu</a>, telefon: <a href="tel:+3659328083">+36-59-328-083</a>.
            </p>

            <h2>Kezelt adatok és az adatkezelés célja</h2>
            <p>
              Jegyfoglalás és vásárlás során a nevedet, e-mail címedet, telefonszámodat és irányítószámodat kezeljük. Az adatok célja a foglalás és a
              jegyvásárlás teljesítése, a jegyek kiküldése, a kapcsolattartás az előadással kapcsolatos változásokról, valamint a fizetések
              nyilvántartása és a jogszabályi kötelezettségek teljesítése. Az adatkezelés jogalapja a szerződés teljesítése és a jogszabályi
              kötelezettség teljesítése (a bizonylatok megőrzése).
            </p>

            <h2>Adattovábbítás – SimplePay Zrt.</h2>
            <p>
              A bankkártyás fizetéshez a nevedet, e-mail címedet, telefonszámodat és irányítószámodat átadjuk a <b>SimplePay Zrt.</b> (székhely: 1138 Budapest,
              Váci út 135-139. B. ép. 5. em., cégjegyzékszám: 01-10-140190) részére, mint adatfeldolgozó részére. A fizetés előtt ezt külön
              nyilatkozatban fogadtatjuk el. Az adatfeldolgozó által végzett adatfeldolgozási tevékenység jellege és célja a SimplePay Zrt.
              Adatkezelési tájékoztatójában tekinthető meg:
              <a [href]="privacyUrl" target="_blank" rel="noopener">{{ privacyUrl }}</a>.
              A kártyaadataidat a SimplePay kezeli, azokat mi nem látjuk és nem tároljuk.
            </p>

            <h2>Az adatok megőrzése</h2>
            <p>
              A foglalási és fizetési adatokat a számviteli bizonylatokra vonatkozó jogszabályi megőrzési ideig, egyéb adatokat az adatkezelési cél
              megvalósulásáig kezeljük.
            </p>

            <h2>Jogaid</h2>
            <p>
              Kérhetsz tájékoztatást a kezelt adataidról, kérheted azok helyesbítését, törlését vagy kezelésének korlátozását, tiltakozhatsz az
              adatkezelés ellen, és panasszal fordulhatsz a Nemzeti Adatvédelmi és Információszabadság Hatósághoz (NAIH, 1055 Budapest, Falk Miksa u. 9-11.,
              <a href="https://www.naih.hu" target="_blank" rel="noopener">www.naih.hu</a>). Kérésedet az
              <a href="mailto:info&#64;vigadokisuj.hu">info&#64;vigadokisuj.hu</a> címen add le.
            </p>

            <h2>Sütik</h2>
            <p>
              Az oldal a működéséhez szükséges technikai adatokat és sütiket használ. Hozzájárulásod esetén statisztikai és hirdetési célú sütiket is
              (Google Analytics, Meta) a Google Tag Manageren keresztül, az oldal használatának méréséhez és a hirdetések hatékonyságának
              méréséhez. A hozzájárulást az oldalon megjelenő süti-tájékoztatóban adhatod meg, vagy utasíthatod el; a döntésed a böngésződben
              tárolódik. A mérési adatokba nem kerül név, e-mail cím vagy telefonszám.
            </p>
          </article>

          <ng-container *ngSwitchDefault></ng-container>
        </ng-container>
      </section>
    </div>
  `,
  styles: [`
    :host {
      display: block;
      background: #3d2817;
      color: #e8d5b7;
      min-height: 60vh;
    }

    .page {
      max-width: 1200px;
      margin: 0 auto;
      padding: 20px 40px;
    }

    h1 {
      color: #e8d5b7;
      font-size: 32px;
      margin: 20px 0;
    }

    .doc {
      max-width: 860px;
      line-height: 1.65;
      font-size: 15px;
    }

    .doc h2 {
      margin: 26px 0 8px;
      font-size: 19px;
      color: #f0d9b5;
    }

    .doc p, .doc li {
      color: #dcc7a4;
    }

    .doc a {
      color: #f0c27a;
    }

    .logo {
      display: inline-block;
      margin-top: 8px;
      padding: 8px 12px;
      background: #fff;
      border-radius: 8px;
    }

    .logo img {
      display: block;
      width: 100%;
      max-width: 200px;
      height: auto;
    }

    @media (max-width: 768px) {
      .page { padding: 16px 18px; }
    }
  `]
})
export class InfoPageComponent {
  title: string;
  slug: string;
  readonly infoPdf = SIMPLEPAY_INFO_PDF_URL;
  readonly privacyUrl = SIMPLEPAY_PRIVACY_URL;

  constructor(route: ActivatedRoute) {
    this.title = route.snapshot.data['title'] ?? '';
    this.slug = route.snapshot.routeConfig?.path ?? '';
  }
}
