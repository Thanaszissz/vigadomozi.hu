import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { ApiService, Reservation } from '../../core/api/api.service';

@Component({
  selector: 'app-hold-summary',
  standalone: true,
  imports: [CommonModule, RouterLink],
  templateUrl: './hold-summary.component.html',
  styleUrls: ['./hold-summary.component.css']
})
export class HoldSummaryComponent implements OnInit {
  reservation: Reservation | null = null;
  loading = false;
  error: string | null = null;
  reservationId: number = 0;

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
}
