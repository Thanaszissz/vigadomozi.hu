import { Component, OnDestroy, OnInit } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { ApiService, Reservation } from '../../core/api/api.service';
import { placeLabel } from '../../core/util/seat';
import { SimplePayConsentComponent } from '../../shared/simplepay-consent.component';
import { KioskService } from '../../core/kiosk/kiosk.service';
import { AnalyticsService } from '../../core/analytics/analytics.service';

@Component({
  selector: 'app-checkout',
  standalone: true,
  imports: [CommonModule, RouterLink, SimplePayConsentComponent],
  templateUrl: './checkout.component.html',
  styleUrls: ['./checkout.component.css']
})
export class CheckoutComponent implements OnInit, OnDestroy {
  reservation: Reservation | null = null;
  loading = false;
  error: string | null = null;
  reservationId: number = 0;
  accessKey = '';
  consentAccepted = false;
  private checkoutTracked = false;
  readonly placeLabel = placeLabel;
  isProcessing = false;
  isProcessingPayment = false;
  paymentError: string | null = null;
  paymentNotice: { title: string; text: string } | null = null;
  remaining = '';
  remainingSeconds: number | null = null;
  private timer: ReturnType<typeof setInterval> | null = null;

  constructor(
    private apiService: ApiService,
    private route: ActivatedRoute,
    private router: Router,
    public kiosk: KioskService,
    private analytics: AnalyticsService
  ) {}

  ngOnDestroy() {
    if (this.timer) clearInterval(this.timer);
  }

  private startCountdown() {
    if (this.timer) clearInterval(this.timer);
    const tick = () => {
      const expires = this.reservation?.expires_at ? new Date(this.reservation.expires_at).getTime() : NaN;
      if (isNaN(expires)) {
        this.remaining = '';
        this.remainingSeconds = null;
        return;
      }
      const s = Math.max(0, Math.floor((expires - Date.now()) / 1000));
      this.remainingSeconds = s;
      this.remaining = s > 0 ? `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}` : '';
    };
    tick();
    this.timer = setInterval(tick, 1000);
  }

  ngOnInit() {
    // Visszatérés a fizetési oldalról: a sikertelen fizetésről tájékoztatjuk a vásárlót (először, hogy a kulcs már megvan a betöltéskor)
    this.route.queryParams.subscribe(params => {
      this.accessKey = params['k'] ?? '';
      const notices: Record<string, { title: string; text: string }> = {
        fail: {
          title: 'Sikertelen tranzakció.',
          text: 'Kérjük, ellenőrizze a tranzakció során megadott adatok helyességét. Amennyiben minden adatot helyesen adott meg, a visszautasítás okának kivizsgálása kapcsán kérjük, szíveskedjen kapcsolatba lépni kártyakibocsátó bankjával.'
        },
        cancel: { title: 'Megszakított tranzakció.', text: 'A fizetést megszakította. A foglalása még él, a fizetést bármikor újra elindíthatja.' },
        timeout: { title: 'Időtúllépés.', text: 'A fizetési idő lejárt. Kérjük, indítsa újra a fizetést.' },
        unknown: { title: 'A fizetés eredménye nem állapítható meg.', text: 'Kérjük, ellenőrizze a bankkártya-egyenlegét, és szükség esetén vegye fel velünk a kapcsolatot.' },
        conflict: { title: 'A kiválasztott székek időközben mások számára lettek lefoglalva.', text: 'A fizetés megtörtént, de a foglalás nem véglegesíthető. A kifizetett összeget visszatérítjük, kérjük, vegye fel velünk a kapcsolatot.' }
      };
      this.paymentNotice = notices[params['payment']] ?? null;
    });

    this.route.params.subscribe(params => {
      this.reservationId = parseInt(params['reservationId'], 10);
      this.loadReservation();
    });
  }

  loadReservation() {
    this.loading = true;
    this.error = null;

    this.apiService.getReservation(this.reservationId, this.accessKey).subscribe({
      next: (data) => {
        this.reservation = data;
        this.loading = false;
        this.startCountdown();
        if (!this.checkoutTracked && data.status === 'PENDING') {
          this.checkoutTracked = true;
          this.analytics.beginCheckout(data);
        }
      },
      error: (err) => {
        this.error = 'Foglalás betöltése sikertelen.';
        console.error(err);
        this.loading = false;
      },
    });
  }

  initiatePayment() {
    if (!this.reservation) return;

    this.isProcessingPayment = true;
    this.paymentError = null;
    this.analytics.addPaymentInfo(this.reservation);

    this.apiService.initiatePayment(this.reservation.id).subscribe({
      next: (response) => {
        // Átirányítás a fizetési oldalra
        if (response.checkout_url) {
          window.location.href = response.checkout_url;
        }
      },
      error: (err) => {
        this.isProcessingPayment = false;
        this.paymentError = err?.error?.message || 'Fizetés indítása sikertelen. Próbálja később.';
        console.error(err);
      },
    });
  }
}
