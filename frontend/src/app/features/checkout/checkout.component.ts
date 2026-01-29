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
        // Redirect to Stripe Checkout
        if (response.checkout_url) {
          window.location.href = response.checkout_url;
        }
      },
      error: (err) => {
        this.isProcessingPayment = false;
        this.paymentError = 'Fizetés indítása sikertelen. Próbálja később.';
        console.error(err);
      },
    });
  }
}
