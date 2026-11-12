import { Component, EventEmitter, Input, Output } from '@angular/core';

export const SIMPLEPAY_PRIVACY_URL = 'https://simplepay.hu/adatkezelesi-tajekoztatok/';
export const SIMPLEPAY_INFO_PDF_URL = 'https://simplepartner.hu/PaymentService/Fizetesi_tajekoztato.pdf';

/** A SimplePay által előírt adattovábbítási nyilatkozat, kötelezően elfogadtatva a bankkártyás fizetés előtt. */
@Component({
  selector: 'app-simplepay-consent',
  standalone: true,
  template: `
    <label class="consent">
      <input type="checkbox" [checked]="accepted" (change)="accepted = $any($event.target).checked; acceptedChange.emit(accepted)">
      <span>
        Tudomásul veszem, hogy a Kisújszállási Művelődési Központ és Könyvtár (5310 Kisújszállás, Szabadság tér 7.) adatkezelő által
        a vigadojegy.hu felhasználói adatbázisában tárolt alábbi személyes adataim átadásra kerülnek a SimplePay Zrt.
        (székhely: 1138 Budapest, Váci út 135-139. B. ép. 5. em., cégjegyzékszám: 01-10-140190), mint adatfeldolgozó részére.
        Az adatkezelő által továbbított adatok köre: név, e-mail cím, telefonszám, irányítószám.
        Az adatfeldolgozó által végzett adatfeldolgozási tevékenység jellege és célja a SimplePay Zrt. Adatkezelési tájékoztatójában,
        az alábbi linken tekinthető meg:
        <a [href]="privacyUrl" target="_blank" rel="noopener">{{ privacyUrl }}</a>
      </span>
    </label>
  `,
  styles: [`
    .consent {
      display: flex;
      gap: 10px;
      align-items: flex-start;
      margin: 14px 0;
      padding: 12px 14px;
      border-radius: 10px;
      background: rgba(92, 61, 46, 0.08);
      border: 1px solid rgba(139, 111, 71, 0.3);
      color: #5c3d2e;
      font-size: 12.5px;
      line-height: 1.5;
      cursor: pointer;
      text-align: left;
    }

    .consent input {
      flex: 0 0 auto;
      width: 18px;
      height: 18px;
      margin-top: 2px;
      cursor: pointer;
    }

    .consent a {
      color: inherit;
      font-weight: 700;
      word-break: break-all;
    }
  `]
})
export class SimplePayConsentComponent {
  @Input() accepted = false;
  @Output() acceptedChange = new EventEmitter<boolean>();

  readonly privacyUrl = SIMPLEPAY_PRIVACY_URL;
}
