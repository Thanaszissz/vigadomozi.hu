import { Component, ElementRef, HostListener, OnDestroy, OnInit } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ApiService, ShowtimeDetail, Seat } from '../../core/api/api.service';
import { rowLabelOf, seatNumberOf, seatLabel, seatPlaceLabel, sectionBlocks, newlyIsolatedSeats } from '../../core/util/seat';
import { KioskService } from '../../core/kiosk/kiosk.service';
import { AnalyticsService } from '../../core/analytics/analytics.service';
import { BotGuardService } from '../../core/api/bot-guard.service';

@Component({
  selector: 'app-seat-select',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './seat-select.component.html',
  styleUrls: ['./seat-select.component.css']
})
export class SeatSelectComponent implements OnInit, OnDestroy {
  showtime: ShowtimeDetail | null = null;
  selectedSeats: Seat[] = [];
  email = '';
  name = '';
  phone = '';
  zip = '';
  website = '';
  loading = false;
  isProcessing = false;
  error: string | null = null;
  lockError: string | null = null;
  showtimeId: number = 0;

  readonly rowLabelOf = rowLabelOf;
  readonly seatNumberOf = seatNumberOf;
  readonly seatLabel = seatLabel;
  readonly seatPlaceLabel = seatPlaceLabel;

  // a székek mérete a kijelzőhöz igazodik, hogy a legszélesebb sor is elférjen
  seatSize = 28;
  seatHeight = 28;
  private titleScrolled = false;
  private trackedShowtime = 0;
  seatGap = 6;
  seatAisle = 26;
  seatFont = 10;

  constructor(
    private apiService: ApiService,
    private route: ActivatedRoute,
    private router: Router,
    private host: ElementRef<HTMLElement>,
    private kiosk: KioskService,
    private analytics: AnalyticsService,
    private botGuard: BotGuardService
  ) {}

  ngOnInit() {
    this.botGuard.prepare();
    this.route.params.subscribe(params => {
      this.showtimeId = parseInt(params['id'], 10);
      this.loadShowtime();
    });
    this.refreshTimer = setInterval(() => {
      if (!document.hidden && this.showtime && !this.isProcessing) this.loadShowtime(true);
    }, 15000);
  }

  ngOnDestroy() {
    clearInterval(this.refreshTimer);
  }

  private refreshTimer?: ReturnType<typeof setInterval>;

  loadShowtime(silent = false) {
    if (!silent) {
      this.loading = true;
      this.error = null;
    }

    this.apiService.getShowtimeById(this.showtimeId).subscribe({
      next: (data) => {
        // Külső jegyértékesítőnél nincs nálunk nézőtér: átirányítjuk a vásárlót a szolgáltató oldalára
        if (data.is_external && data.external_url) {
          window.location.replace(data.external_url);
          return;
        }
        if (silent && this.isProcessing) return;

        this.showtime = data;
        this.loading = false;
        this.dropUnavailableSelection();
        if (this.trackedShowtime !== data.id) {
          this.trackedShowtime = data.id;
          this.analytics.selectShowtime(data);
        }
        if (!silent) {
          setTimeout(() => {
            this.fitSeats();
            this.scrollToTitle();
          });
        }
      },
      error: (err) => {
        if (silent) return;
        this.error = 'Előadás betöltése sikertelen. Próbálja később.';
        console.error(err);
        this.loading = false;
      },
    });
  }

  /** A friss nézőtéren már nem szabad székeket kivesszük a kijelölésből, hogy más székét ne próbálhassa lefoglalni. */
  private dropUnavailableSelection(): void {
    const rows = this.showtime?.seatMap?.seats;
    if (!rows || this.selectedSeats.length === 0) return;

    const status = new Map<string, string>();
    for (const rowSeats of Object.values(rows)) {
      for (const [key, seat] of Object.entries(rowSeats as Record<string, Seat>)) status.set(key, seat.status);
    }

    const kept = this.selectedSeats.filter(s => status.get(s.key) === 'available');
    if (kept.length < this.selectedSeats.length) {
      this.selectedSeats = kept;
      this.lockError = 'A kijelölt székek közül néhányat időközben lefoglaltak, ezért kikerült a kijelölésből. Kérjük, válasszon másikat.';
    }
  }

  /** Telefonon a székválasztó megnyitásakor az előadás címére ugrik az oldal (ne a lap alján, a láblécnél maradjon). */
  private scrollToTitle(): void {
    if (this.titleScrolled || window.innerWidth > 768) return;
    this.titleScrolled = true;

    const header = this.host.nativeElement.querySelector('.header') as HTMLElement | null;
    header?.scrollIntoView({ block: 'start' });
  }

  @HostListener('window:resize')
  fitSeats(): void {
    const section = this.host.nativeElement.querySelector('.seat-map-section') as HTMLElement | null;
    if (!section || !this.showtime?.seatMap.seats) return;

    const available = section.clientWidth;
    const mobile = window.innerWidth <= 640;
    // Telefonon a székek szorosabban állnak, és magasabbak, mint szélesek
    const pad = mobile ? 22 : 34;
    const gapRatio = mobile ? 0.12 : 0.22;
    const aisleRatio = mobile ? 0.8 : 0.9;
    const aisles = this.showtime.seatMap.layout?.aisles ?? {};
    const blockGap = 18;
    const blocks = this.getSectionBlocks().map(block => block.map(group => ({
      rows: group.rows.map(key => ({ count: this.getSeatsInRow(key).length, aisle: aisles[key] !== undefined }))
    })));

    let size = 10;
    for (let s = mobile ? 34 : this.kiosk.active ? 44 : 28; s >= 10; s--) {
      const gap = Math.max(2, Math.round(s * gapRatio));
      const aisle = Math.round(s * aisleRatio);
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
    this.seatHeight = mobile ? Math.round(size * 1.4) : size;
    this.seatGap = Math.max(2, Math.round(size * gapRatio));
    this.seatAisle = Math.round(size * aisleRatio);
    this.seatFont = mobile ? Math.max(8, Math.round(size * 0.42)) : Math.max(7, Math.round(size * 0.36));
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
            g => Math.max(0, ...g.rows.map(key => this.getSeatsInRow(key).length)))
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
    return `${seatPlaceLabel(seat.key)} - ${seat.price} Ft`;
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
      // Az első kiválasztott hely után a háttérben elindul a láthatatlan ellenőrzés, mire a vásárló kitölti az adatait, kész
      this.botGuard.prepare();
    }
  }

  get totalPrice(): number {
    return this.selectedSeats.reduce((sum, seat) => sum + seat.price, 0);
  }

  get zipValid(): boolean {
    return !this.zip.trim() || /^[0-9A-Za-z\- ]{1,10}$/.test(this.zip.trim());
  }

  private gapCacheKey = '';
  private gapMapRef: unknown = null;
  private gapCache: string[] = [];

  /** A kijelölés miatt egyedül maradó üres székek (a kijelölés vagy a nézőtér változásáig gyorsítótárazva). */
  get isolatedSeatKeys(): string[] {
    const seatMap = this.showtime?.seatMap;
    const keys = this.selectedSeats.map(s => s.key);
    const cacheKey = keys.join(',');
    if (cacheKey !== this.gapCacheKey || seatMap !== this.gapMapRef) {
      this.gapCacheKey = cacheKey;
      this.gapMapRef = seatMap;
      this.gapCache = seatMap ? newlyIsolatedSeats(seatMap.seats, seatMap.layout?.aisles, keys) : [];
    }
    return this.gapCache;
  }

  get isolatedSeatLabels(): string {
    return this.isolatedSeatKeys.map(key => seatLabel(key)).join(', ');
  }

  canReserve(): boolean {
    return this.selectedSeats.length > 0 && 
           this.email.trim().length > 0 && 
           this.name.trim().length > 0 && 
           this.phone.trim().length > 0 && 
           this.zipValid &&
           !this.isProcessing;
  }

  reserve() {
    if (!this.canReserve()) return;

    this.isProcessing = true;
    this.lockError = null;

    const seatKeys = this.selectedSeats.map(s => s.key);

    this.apiService.lockSeats(this.showtimeId, this.email, this.name, this.phone, seatKeys, this.zip.trim(), this.website).subscribe({
      next: (response) => {
        if (this.showtime) {
          this.analytics.addToCart(this.showtime, this.selectedSeats);
        }
        this.router.navigate(['/checkout', response.reservation.id]);
      },
      error: (err) => {
        this.isProcessing = false;
        if (err.status === 409) {
          this.lockError = 'Néhány ülés már foglalt. Frissítse az oldalt.';
          this.loadShowtime();
        } else {
          this.lockError = err.error?.code === 'captcha_failed' ? err.error.message : 'Foglalás sikertelen. Próbálja újra.';
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

    this.apiService.holdSeats(this.showtimeId, this.email, this.name, this.phone, seatKeys, this.zip.trim(), this.website).subscribe({
      next: (response) => {
        this.router.navigate(['/hold-summary', response.reservation.id]);
      },
      error: (err) => {
        this.isProcessing = false;
        if (err.status === 409) {
          this.lockError = 'Néhány ülés már foglalt. Frissítse az oldalt.';
          this.loadShowtime();
        } else {
          this.lockError = err.error?.code === 'captcha_failed' ? err.error.message : 'Foglalás sikertelen. Próbálja újra.';
        }
        console.error(err);
      },
    });
  }
}
