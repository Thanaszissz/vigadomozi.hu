import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { ApiService, Reservation } from '../../core/api/api.service';

@Component({
  selector: 'app-checkout',
  standalone: true,
  imports: [CommonModule, RouterLink],
  templateUrl: './checkout.component.html',
  styleUrls: ['./checkout.component.css']
})
export class CheckoutComponent implements OnInit {
  reservation: Reservation | null = null;
  loading = false;
  error: string | null = null;
  reservationId: number = 0;
  isProcessing = false;
  isProcessingPayment = false;
  paymentError: string | null = null;
  paymentNotice: string | null = null;

  constructor(
    private apiService: ApiService,
    private route: ActivatedRoute,
    private router: Router
  ) {}

  ngOnInit() {
    this.route.params.subscribe(params => {
      this.reservationId = parseInt(params['reservationId'], 10);
      this.loadReservation();
    });

    // Visszatérés a fizetési oldalról: a sikertelen fizetésről tájékoztatjuk a vásárlót
    this.route.queryParams.subscribe(params => {
      this.paymentNotice = ({
        fail: 'A bankkártyás fizetés nem sikerült. Kérjük, próbálja meg újra, vagy használjon másik kártyát.',
        cancel: 'A fizetést megszakította. A foglalása még él, a fizetést bármikor újra elindíthatja.',
        timeout: 'A fizetési idő lejárt. Kérjük, indítsa újra a fizetést.',
        conflict: 'A fizetés megtörtént, de a kiválasztott székek időközben mások számára lettek lefoglalva, ezért a foglalás nem véglegesíthető. A kifizetett összeget visszatérítjük, kérjük, vegye fel velünk a kapcsolatot.'
      } as Record<string, string>)[params['payment']] ?? null;
    });
  }

  loadReservation() {
    this.loading = true;
    this.error = null;

    this.apiService.getReservation(this.reservationId).subscribe({
      next: (data) => {
        this.reservation = data;
        this.loading = false;
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
