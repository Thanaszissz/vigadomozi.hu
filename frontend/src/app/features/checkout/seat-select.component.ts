import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ApiService, ShowtimeDetail, Seat } from '../../core/api/api.service';

@Component({
  selector: 'app-seat-select',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './seat-select.component.html',
  styleUrls: ['./seat-select.component.css']
})
export class SeatSelectComponent implements OnInit {
  showtime: ShowtimeDetail | null = null;
  selectedSeats: Seat[] = [];
  email = '';
  name = '';
  phone = '';
  loading = false;
  isProcessing = false;
  error: string | null = null;
  lockError: string | null = null;
  showtimeId: number = 0;

  constructor(
    private apiService: ApiService,
    private route: ActivatedRoute,
    private router: Router
  ) {}

  ngOnInit() {
    this.route.params.subscribe(params => {
      this.showtimeId = parseInt(params['id'], 10);
      this.loadShowtime();
    });
  }

  loadShowtime() {
    this.loading = true;
    this.error = null;

    this.apiService.getShowtimeById(this.showtimeId).subscribe({
      next: (data) => {
        this.showtime = data;
        this.loading = false;
      },
      error: (err) => {
        this.error = 'Előadás betöltése sikertelen. Próbálja később.';
        console.error(err);
        this.loading = false;
      },
    });
  }

  getRowsArray(): string[] {
    if (!this.showtime?.seatMap.seats) return [];
    return Object.keys(this.showtime.seatMap.seats);
  }

  getSectionGroups(): Array<{ name: string; rows: string[] }> {
    if (!this.showtime?.seatMap.seats) return [];

    const groups = new Map<string, string[]>();
    for (const rowLabel of this.getRowsArray()) {
      const seats = Object.values(this.showtime.seatMap.seats[rowLabel] || {});
      const section = seats.find(seat => !!seat.section)?.section || 'Nincs szint';
      if (!groups.has(section)) {
        groups.set(section, []);
      }
      groups.get(section)!.push(rowLabel);
    }

    const result = Array.from(groups.entries()).map(([name, rows]) => ({ name, rows }));
    const priority = (name: string) => {
      if (name.startsWith('Földszint')) return 0;
      if (name.startsWith('Emelet')) return 1;
      if (name === 'Nincs szint') return 3;
      return 2;
    };
    return result.sort((a, b) => priority(a.name) - priority(b.name));
  }

  getSeatsInRow(rowLabel: string): Seat[] {
    if (!this.showtime?.seatMap.seats[rowLabel]) return [];
    return Object.values(this.showtime.seatMap.seats[rowLabel]);
  }

  getSeatClasses(seat: Seat): string {
    if (this.selectedSeats.some(s => s.key === seat.key)) {
      return 'selected';
    }
    return seat.status;
  }

  getSeatTitle(seat: Seat): string {
    return `${seat.key} - ${seat.price} Ft`;
  }

  isAisle(seat: Seat): boolean {
    return seat.type === 'aisle';
  }

  isSeatSelectable(seat: Seat): boolean {
    return seat.status === 'available' && seat.bookable;
  }

  toggleSeat(seat: Seat) {
    if (!this.isSeatSelectable(seat)) return;

    const index = this.selectedSeats.findIndex(s => s.key === seat.key);
    if (index > -1) {
      this.selectedSeats.splice(index, 1);
    } else {
      this.selectedSeats.push(seat);
    }
  }

  get totalPrice(): number {
    return this.selectedSeats.reduce((sum, seat) => sum + seat.price, 0);
  }

  canReserve(): boolean {
    return this.selectedSeats.length > 0 && 
           this.email.trim().length > 0 && 
           this.name.trim().length > 0 && 
           this.phone.trim().length > 0 && 
           !this.isProcessing;
  }

  reserve() {
    if (!this.canReserve()) return;

    this.isProcessing = true;
    this.lockError = null;

    const seatKeys = this.selectedSeats.map(s => s.key);

    this.apiService.lockSeats(this.showtimeId, this.email, this.name, this.phone, seatKeys).subscribe({
      next: (response) => {
        this.router.navigate(['/checkout', response.reservation.id]);
      },
      error: (err) => {
        this.isProcessing = false;
        if (err.status === 409) {
          this.lockError = 'Néhány ülés már foglalt. Frissítse az oldalt.';
          this.loadShowtime();
        } else {
          this.lockError = 'Foglalás sikertelen. Próbálja újra.';
        }
        console.error(err);
      },
    });
  }

  hold() {
    if (!this.canReserve()) return;

    this.isProcessing = true;
    this.lockError = null;

    const seatKeys = this.selectedSeats.map(s => s.key);

    this.apiService.holdSeats(this.showtimeId, this.email, this.name, this.phone, seatKeys).subscribe({
      next: (response) => {
        this.router.navigate(['/hold-summary', response.reservation.id]);
      },
      error: (err) => {
        this.isProcessing = false;
        if (err.status === 409) {
          this.lockError = 'Néhány ülés már foglalt. Frissítse az oldalt.';
          this.loadShowtime();
        } else {
          this.lockError = 'Foglalás sikertelen. Próbálja újra.';
        }
        console.error(err);
      },
    });
  }
}
