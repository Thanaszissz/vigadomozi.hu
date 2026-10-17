import { Component, ElementRef, HostListener, OnInit } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ApiService, ShowtimeDetail, Seat } from '../../core/api/api.service';
import { rowLabelOf, seatNumberOf, seatLabel, sectionBlocks } from '../../core/util/seat';

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

  readonly rowLabelOf = rowLabelOf;
  readonly seatNumberOf = seatNumberOf;
  readonly seatLabel = seatLabel;

  // a székek mérete a kijelzőhöz igazodik, hogy a legszélesebb sor is elférjen
  seatSize = 28;
  seatGap = 6;
  seatAisle = 26;
  seatFont = 10;

  constructor(
    private apiService: ApiService,
    private route: ActivatedRoute,
    private router: Router,
    private host: ElementRef<HTMLElement>
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
        setTimeout(() => this.fitSeats());
      },
      error: (err) => {
        this.error = 'Előadás betöltése sikertelen. Próbálja később.';
        console.error(err);
        this.loading = false;
      },
    });
  }

  @HostListener('window:resize')
  fitSeats(): void {
    const section = this.host.nativeElement.querySelector('.seat-map-section') as HTMLElement | null;
    if (!section || !this.showtime?.seatMap.seats) return;

    const available = section.clientWidth;
    const pad = window.innerWidth <= 640 ? 26 : 34;
    const aisles = this.showtime.seatMap.layout?.aisles ?? {};
    const blockGap = 18;
    const blocks = this.getSectionBlocks().map(block => block.map(group => ({
      rows: group.rows.map(key => ({ count: this.getSeatsInRow(key).length, aisle: aisles[key] !== undefined }))
    })));

    let size = 10;
    for (let s = 28; s >= 10; s--) {
      const gap = Math.max(2, Math.round(s * 0.22));
      const aisle = Math.round(s * 0.9);
      const groupWidth = (g: { rows: Array<{ count: number; aisle: boolean }> }) =>
        Math.max(0, ...g.rows.map(r => r.count * s + Math.max(0, r.count - 1) * gap + (r.aisle ? aisle : 0))) + 2 * pad;
      // az egymás mellé kerülő szintek szélessége összeadódik
      const widest = Math.max(0, ...blocks.map(block => block.reduce((sum, g) => sum + groupWidth(g), 0) + blockGap * (block.length - 1)));
      if (widest <= available) {
        size = s;
        break;
      }
    }

    this.seatSize = size;
    this.seatGap = Math.max(2, Math.round(size * 0.22));
    this.seatAisle = Math.round(size * 0.9);
    this.seatFont = Math.max(7, Math.round(size * 0.36));
  }

  getRowsArray(): string[] {
    if (!this.showtime?.seatMap.seats) return [];
    return Object.keys(this.showtime.seatMap.seats);
  }

  private blocksSource: unknown = null;
  private blocksCache: Array<Array<{ name: string; rows: string[] }>> = [];

  /** A szintek megjelenítési blokkjai (az "egy sorba" jelölésű kis szintek egymás mellé kerülnek); a nézőtér betöltésekor egyszer épül fel. */
  getSectionBlocks(): Array<Array<{ name: string; rows: string[] }>> {
    const source = this.showtime?.seatMap ?? null;
    if (source !== this.blocksSource) {
      this.blocksSource = source;
      this.blocksCache = source
        ? sectionBlocks(this.getSectionGroups(), source.layout?.inlineSections,
            g => g.rows.reduce((sum, key) => sum + this.getSeatsInRow(key).length, 0))
        : [];
    }
    return this.blocksCache;
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

  /** Folyosó a sor közepén: a megadott sorszámú szék előtt szünet van. */
  hasAisleBefore(rowLabel: string, index: number): boolean {
    const aisleAfter = this.showtime?.seatMap.layout?.aisles?.[rowLabel];
    return aisleAfter !== undefined && index === aisleAfter;
  }

  getSeatClasses(seat: Seat): string {
    if (this.selectedSeats.some(s => s.key === seat.key)) {
      return 'selected';
    }
    return seat.status;
  }

  getSeatTitle(seat: Seat): string {
    return `${seatLabel(seat.key)} - ${seat.price} Ft`;
  }

  /** A különböző árú (szektoros) székek színjelölése; egyetlen ár esetén nincs rá szükség. */
  get priceGroups(): Array<{ price: number; color: string }> {
    if (!this.showtime?.seatMap.seats) return [];

    const prices = new Set<number>();
    for (const rowLabel of this.getRowsArray()) {
      for (const seat of Object.values(this.showtime.seatMap.seats[rowLabel] || {})) {
        if (seat.type !== 'aisle' && seat.bookable) {
          prices.add(Number(seat.price));
        }
      }
    }
    if (prices.size < 2) return [];

    const palette = ['#2f5aa6', '#c0392b', '#8e44ad', '#e67e22', '#16a085', '#d4a574'];
    return Array.from(prices).sort((a, b) => a - b).map((price, i) => ({ price, color: palette[i % palette.length] }));
  }

  getPriceColor(seat: Seat): string | null {
    return this.priceGroups.find(g => g.price === Number(seat.price))?.color ?? null;
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
