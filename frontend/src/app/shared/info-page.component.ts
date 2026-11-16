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

          <article *ngSwitchCase="'hazirend'" class="doc">
            <p class="org">
              <b>Kisújszállási Művelődési Központ és Könyvtár</b><br>
              <b>Vigadó Kulturális Központ</b><br>
              5310 Kisújszállás, Szabadság tér 7.
            </p>

            <p>
              Jelen házirend célja a Vigadó Kulturális Központ zavartalan működésének, a látogatók biztonságának, valamint az épület,
              a berendezések és az eszközök megóvásának biztosítása.
            </p>
            <p>
              A házirend Kisújszállás Város Önkormányzata Képviselő-testületének a helyi közművelődési tevékenység támogatásáról szóló
              16/2012. (XII. 19.) számú rendeletének 4/a pontja alapján határozza meg az intézmény használatának szabályait.
            </p>

            <h2>1. A házirend hatálya</h2>
            <p>A házirend betartása kötelező:</p>
            <ul>
              <li>az intézmény vezetője és valamennyi munkatársa;</li>
              <li>az intézményben működő gazdasági egységek, szervezetek és közösségek;</li>
              <li>a rendezvények szervezői és résztvevői;</li>
              <li>az intézmény valamennyi látogatója és szolgáltatásainak igénybevevője számára.</li>
            </ul>

            <h2>2. Nyitvatartás</h2>
            <h3>Vigadó Kulturális Központ</h3>
            <table>
              <thead><tr><th>Nap</th><th>Nyitvatartás</th></tr></thead>
              <tbody>
                <tr><td>Hétfő</td><td>Zárva</td></tr>
                <tr><td>Kedd–csütörtök</td><td>10:00–21:00</td></tr>
                <tr><td>Péntek</td><td>10:00–21:00, valamint a pénteki rendezvények időpontjaiban</td></tr>
                <tr><td>Szombat</td><td>A rendezvények időpontjaiban</td></tr>
                <tr><td>Vasárnap</td><td>Zárva</td></tr>
              </tbody>
            </table>

            <h3>Vigadó Kávéház</h3>
            <table>
              <thead><tr><th>Nap</th><th>Nyitvatartás</th></tr></thead>
              <tbody>
                <tr><td>Hétfő–vasárnap</td><td>9:00–21:00</td></tr>
              </tbody>
            </table>

            <h3>Büfé és bowlingpálya</h3>
            <table>
              <thead><tr><th>Nap</th><th>Nyitvatartás</th></tr></thead>
              <tbody>
                <tr><td>Hétfő</td><td>Szünnap</td></tr>
                <tr><td>Kedd–csütörtök</td><td>14:00–22:00</td></tr>
                <tr><td>Péntek–szombat</td><td>10:00–24:00</td></tr>
                <tr><td>Vasárnap</td><td>14:00–21:00</td></tr>
              </tbody>
            </table>

            <p>A nyitvatartás a rendezvények időpontjához igazodva változhat.</p>
            <p>
              Az intézmény és a vállalkozók által üzemeltetett egységek eltérő nyitvatartása miatt a mindenkori üzemeltetők külön
              megállapodásban rögzítik a létesítmény vagyonvédelmét és biztonságát szolgáló feltételeket.
            </p>

            <h2>3. Az intézmény és a helyiségek használata</h2>
            <p>
              Az intézmény szolgáltatásait nyitvatartási időben bárki igénybe veheti, aki betartja a házirend előírásait. Az egyes
              szolgáltatások a meghirdetett programoknak és a terembeosztásnak megfelelő időpontban és helyiségben érhetők el.
            </p>
            <h3>Folyamatos használat</h3>
            <p>
              A folyamatos használat keretében igénybe vehető szolgáltatásokat, valamint használatuk módját és feltételeit bérleti
              szerződés rögzíti.
            </p>
            <h3>Eseti használat</h3>
            <p>
              A helyiségek eseti használatához előzetes időpont- és helyiségegyeztetés szükséges. Az egyeztetés az intézmény havi
              terembeosztása alapján történik, és arra az intézmény közművelődési szakalkalmazottai jogosultak.
            </p>

            <h2>4. Térítésmentes és térítésköteles helyiséghasználat</h2>
            <h3>Térítésmentes helyiséghasználat</h3>
            <p>Nem az intézmény által szervezett programok esetében a helyiségek az alábbi célokra vehetők igénybe térítésmentesen:</p>
            <ul>
              <li>
                A településen működő nevelési-oktatási intézmények közművelődési célú programjaihoz, kivéve, ha a rendezvény esetében
                lehetőség van teremhasználati díj megállapítására, például pályázati vagy haszonszerzési célú programoknál.
              </li>
              <li>Az önkormányzat közcélú programjainak lebonyolításához.</li>
              <li>
                A kisújszállási székhelyű civil szervezetek és közösségek taggyűléseihez, vezetőségi értekezleteihez, kulturális és
                közművelődési rendezvényeihez, amennyiben azok időtartama nem haladja meg a három órát.
              </li>
            </ul>
            <p>
              A három óránál hosszabb civil szervezeti rendezvények esetében a felek kölcsönös megállapodása alapján bérleti díj
              állapítható meg.
            </p>

            <h3>Térítésköteles helyiséghasználat</h3>
            <p>Nem az intézmény által szervezett programok esetében a helyiségek használata térítésköteles:</p>
            <ul>
              <li>gazdasági egységek és vállalkozók saját népszerűsítését szolgáló, illetve gazdasági tevékenységükhöz kapcsolódó rendezvényeihez;</li>
              <li>civil szervezetek és magánszemélyek egyértelműen reklámcélú rendezvényeihez;</li>
              <li>
                civil szervezetek, magánszemélyek és egyéb szervezők olyan rendezvényeihez, amelyeken belépődíjat, részvételi díjat vagy
                térítési díjat szednek, a jótékonysági célú rendezvények kivételével;
              </li>
              <li>vásárok, termékbemutatók és árusítások lebonyolításához;</li>
              <li>politikai szervezetek politikai rendezvényeihez.</li>
            </ul>

            <h3>Technikai eszközök használata</h3>
            <p>
              Az intézmény technikai eszközei műszaki tartalmukra és magas értékükre tekintettel kizárólag térítési díj ellenében vehetők
              igénybe. A térítési díj az eszköz bérleti díját és a kezelőszemélyzet bérköltségét tartalmazza.
            </p>

            <h2>5. Külső szervezésű rendezvények</h2>
            <p>
              Külső szervezők éjszakai rendezvényei esetében eseti szerződés rögzíti az intézmény és a rendezvényszervező jogait,
              kötelezettségeit, valamint a szervezőre vonatkozó jogszabályi kötelezettségeket.
            </p>
            <p>A rendezvény típusától függően az intézmény a külső szervezőt a rend fenntartását biztosító szakemberek alkalmazására kötelezi.</p>
            <p>A rendezvény szervezője teljes körű felelősséget vállal:</p>
            <ul>
              <li>a használt létesítmény és helyiségek rendeltetésszerű használatáért;</li>
              <li>a technikai eszközök, berendezések és felszerelések rendeltetésszerű használatáért;</li>
              <li>a rendezvény résztvevőinek biztonságáért.</li>
            </ul>

            <h2>6. Magatartási és biztonsági szabályok</h2>
            <p>
              Az intézmény valamennyi használója köteles betartani a tűzvédelmi, balesetvédelmi és egyéb biztonsági előírásokat, valamint
              a közösségi együttélés általános szabályait.
            </p>
            <p>
              Az intézmény munkatársai és használói kötelesek tiszteletben tartani egymás személyiségi jogait, vallási, világnézeti és
              politikai meggyőződését, valamint ízlését.
            </p>
            <p>
              Az épületben egyidejűleg zajló programok résztvevői magatartásukkal nem zavarhatják és nem akadályozhatják egymás
              rendezvényeinek lebonyolítását.
            </p>
            <p>Minden látogató köteles:</p>
            <ul>
              <li>megóvni az épület, a berendezések és a felszerelések állapotát;</li>
              <li>a helyiségeket és az eszközöket rendeltetésszerűen, anyagi felelőssége tudatában használni;</li>
              <li>ügyelni a tisztaságra és a rendre;</li>
              <li>tiszteletben tartani mások zavartalan intézményhasználatát.</li>
            </ul>
            <p>Az intézmény munkatársai a büntetőjogi védelem szempontjából közfeladatot ellátó személynek minősülnek.</p>

            <h2>7. Károkozás és felelősség</h2>
            <p>Az intézmény látogatói a szándékosan okozott károkért kártérítési kötelezettséggel tartoznak.</p>
            <p>Az intézmény a területére behozott, magántulajdonban lévő tárgyakért és technikai berendezésekért felelősséget nem vállal.</p>
            <p>
              Az intézmény vezetője ideiglenesen megtagadhatja az intézmény használatát azoktól, akik magatartásukkal a többi használót
              jogaik gyakorlásában tartósan akadályozzák.
            </p>

            <h2>8. Panaszok, észrevételek és javaslatok</h2>
            <p>
              A látogatók az intézmény munkatársainak intézkedéseivel kapcsolatos panaszaikkal, valamint a működési rendre vonatkozó
              észrevételeikkel és javaslataikkal az intézmény igazgatójához fordulhatnak.
            </p>

            <p class="closing">
              <b>Köszönjük, hogy a házirend betartásával hozzájárulnak a Vigadó Kulturális Központ rendezett működéséhez és a közösségi
              programok zavartalanságához!</b>
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

    .doc h3 {
      margin: 20px 0 6px;
      font-size: 16px;
      color: #f0d9b5;
    }

    .doc ul { padding-left: 22px; }

    .doc table {
      width: 100%;
      max-width: 620px;
      border-collapse: collapse;
      margin: 6px 0 14px;
    }

    .doc th, .doc td {
      text-align: left;
      padding: 8px 12px;
      border-bottom: 1px solid #5c3d2e;
      color: #dcc7a4;
    }

    .doc th { color: #f0d9b5; background: rgba(0, 0, 0, .18); }

    .doc .org { font-size: 16px; }
    .doc .closing { margin-top: 28px; padding-top: 16px; border-top: 1px solid #5c3d2e; }

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
