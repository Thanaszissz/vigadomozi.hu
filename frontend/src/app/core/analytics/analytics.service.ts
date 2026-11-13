import { Injectable } from '@angular/core';
import { ReplaySubject } from 'rxjs';
import { AnalyticsConfig, ApiService, Movie, PurchaseEventData, Reservation, Seat, Showtime } from '../api/api.service';
import { showCategoryLabel } from '../../shared/movie-badges.component';

const CONSENT_KEY = 'vigado_cookie_consent';

declare global {
  interface Window {
    dataLayer: unknown[];
  }
}

/** A jegyrendszer mérési adatai (Google Tag Manager dataLayer); a mérőkódokat (GA4, Meta) a Tag Managerben kezelik. */
export interface DataLayerItem {
  item_id: string;
  item_name: string;
  item_category: string;
  item_category2?: string;
  item_variant?: string;
  show_date: string;
  show_time: string;
  venue?: string;
  seat_zone?: string;
  seats?: string;
  price: number;
  quantity?: number;
  index?: number;
}

export type ShowtimeLike = Pick<Showtime, 'id' | 'starts_at'> & { is_theater?: boolean; movie: Movie; auditorium?: { name: string } | null };

@Injectable({ providedIn: 'root' })
export class AnalyticsService {
  private config: AnalyticsConfig = { enabled: false };
  private started = false;
  private loaded = false;
  private queue: Record<string, unknown>[] = [];

  /** A mérési beállítások betöltése után egyszer jelez (a süti-tájékoztató ebből tudja, hogy kell-e hozzájárulást kérnie). */
  readonly config$ = new ReplaySubject<AnalyticsConfig>(1);

  constructor(private api: ApiService) {
    // A nyilvános oldalakon az első eseményig készen kell állnia (az admin felületen nem fut)
    if (!window.location.pathname.startsWith('/admin')) {
      this.start();
    }
  }

  /** Az adatréteg és az alapértelmezett (elutasított) hozzájárulás beállítása, majd a Tag Manager betöltése. Az admin felületen nem fut. */
  start(): void {
    if (this.started) return;
    this.started = true;

    window.dataLayer = window.dataLayer || [];
    // Consent Mode v2: minden jel alapértelmezetten elutasított, amíg a látogató nem dönt
    this.gtag('consent', 'default', {
      ad_storage: 'denied',
      analytics_storage: 'denied',
      ad_user_data: 'denied',
      ad_personalization: 'denied',
      wait_for_update: 500
    });
    const stored = this.storedChoice();
    if (stored) {
      this.applyConsent(stored === 'all');
    }

    this.api.getAnalyticsConfig().subscribe({
      next: (config) => {
        this.config = config;
        this.loaded = true;
        if (config.enabled && config.gtm_id) {
          this.loadTagManager(config.gtm_id);
          this.queue.forEach(event => this.reallyPush(event));
        }
        this.queue = [];
        this.config$.next(config);
      },
      error: () => {
        this.loaded = true;
        this.queue = [];
        this.config$.next({ enabled: false });
      }
    });
  }

  /** A süti-hozzájárulás frissítése a látogató döntése alapján. */
  setConsent(all: boolean): void {
    window.dataLayer = window.dataLayer || [];
    this.applyConsent(all);
  }

  // ------------------------------------------------------------ események

  viewItemList(listId: string, listName: string, showtimes: ShowtimeLike[]): void {
    const items = this.programItems(showtimes);
    if (!items.length) return;
    this.ecommerce('view_item_list', { item_list_id: listId, item_list_name: listName, items: items.map((item, index) => this.listItem(item, index)) });
  }

  selectItem(listId: string, listName: string, showtime: ShowtimeLike, index: number): void {
    this.ecommerce('select_item', { item_list_id: listId, item_list_name: listName, items: [this.listItem(this.item(showtime), index)] });
  }

  viewItem(showtime: ShowtimeLike | null): void {
    if (!showtime) return;
    const item = this.item(showtime);
    this.ecommerce('view_item', { currency: 'HUF', value: item.price, items: [item] });
  }

  selectShowtime(showtime: ShowtimeLike): void {
    this.push({ event: 'select_showtime', event_id: this.eventId(), items: [this.listItem(this.item(showtime), 0)] });
  }

  addToCart(showtime: ShowtimeLike, seats: Seat[]): void {
    const items = this.seatItems(showtime, seats.map(s => ({ section: s.section ?? '', price: s.price, label: this.seatName(s.key) })));
    this.ecommerce('add_to_cart', { currency: 'HUF', value: this.total(items), items });
  }

  beginCheckout(reservation: Reservation): void {
    const items = this.reservationItems(reservation);
    if (!items.length) return;
    this.ecommerce('begin_checkout', { currency: 'HUF', value: this.total(items), items });
  }

  addPaymentInfo(reservation: Reservation): void {
    const items = this.reservationItems(reservation);
    if (!items.length) return;
    this.ecommerce('add_payment_info', { currency: 'HUF', value: this.total(items), payment_type: 'bankkártya', items });
  }

  purchase(reservation: Reservation, data: PurchaseEventData): void {
    const items = this.reservationItems(reservation);
    if (!data.first || !data.transaction_id || !items.length) return;

    const ecommerce: Record<string, unknown> = {
      transaction_id: data.transaction_id,
      currency: 'HUF',
      value: data.value ?? this.total(items),
      fee: data.fee ?? 0,
      coupon: '',
      payment_type: data.payment_type ?? 'bankkártya',
      items
    };
    if (data.tax !== null && data.tax !== undefined) {
      ecommerce['tax'] = data.tax;
    }
    if (data.postal_code) {
      ecommerce['postal_code'] = data.postal_code;
    }

    this.push({ ecommerce: null });
    this.push({ event: 'purchase', event_id: `purchase-${data.transaction_id}`, ecommerce });
  }

  // ------------------------------------------------------------ tételek

  /** Egy előadás-időpont jegysora (listákhoz és adatlaphoz; a pontos ár a helyválasztáskor derül ki, addig a program jegyára, ha van). */
  private item(showtime: ShowtimeLike, extra: Partial<DataLayerItem> = {}): DataLayerItem {
    const movie = showtime.movie;
    const theater = !!(showtime.is_theater ?? movie.is_theater);
    const when = this.budapest(showtime.starts_at);
    const prefix = theater ? (this.config.item_prefix_show ?? 'EL') : (this.config.item_prefix_movie ?? 'MO');

    const item: DataLayerItem = {
      item_id: `${prefix}-${when.date.slice(0, 4)}-${when.date.slice(5, 7)}${when.date.slice(8, 10)}-${when.time.replace(':', '')}-${showtime.id}`,
      item_name: movie.title,
      item_category: theater ? 'Saját előadás' : 'Mozi',
      item_variant: this.config.variant ?? 'Felnőtt',
      show_date: when.date,
      show_time: when.time,
      price: Number(movie.ticket_price ?? 0),
      ...extra
    };

    if (theater && showCategoryLabel(movie.category)) {
      item.item_category2 = showCategoryLabel(movie.category);
    }
    if (showtime.auditorium?.name) {
      item.venue = showtime.auditorium.name;
    }

    return item;
  }

  /** A listákban és a kiválasztásnál elég a rövid mezőkészlet. */
  private listItem(item: DataLayerItem, index: number): DataLayerItem {
    const { item_id, item_name, item_category, item_category2, show_date, show_time, price } = item;
    return { item_id, item_name, item_category, ...(item_category2 ? { item_category2 } : {}), show_date, show_time, price, index };
  }

  /** Programonként egy tétel, a legközelebbi időponttal. */
  private programItems(showtimes: ShowtimeLike[]): DataLayerItem[] {
    const seen = new Set<string>();
    const items: DataLayerItem[] = [];
    for (const st of showtimes) {
      if (!st.movie) continue;
      const key = `${st.is_theater ? 't' : 'm'}-${st.movie.id}`;
      if (seen.has(key)) continue;
      seen.add(key);
      items.push(this.item(st));
    }
    return items;
  }

  /** A kiválasztott helyek tételei: zónánként és áranként egy sor. */
  private seatItems(showtime: ShowtimeLike, seats: Array<{ section: string; price: number; label: string }>): DataLayerItem[] {
    const groups = new Map<string, { section: string; price: number; labels: string[] }>();
    for (const seat of seats) {
      const key = `${seat.section}|${seat.price}`;
      const group = groups.get(key) ?? { section: seat.section, price: seat.price, labels: [] };
      group.labels.push(seat.label);
      groups.set(key, group);
    }

    return [...groups.values()].map((group, index) => {
      const item = this.item(showtime, { price: group.price, quantity: group.labels.length, seats: group.labels.join(', '), index });
      if (group.section) {
        item.seat_zone = group.section;
      }
      return item;
    });
  }

  private reservationItems(reservation: Reservation): DataLayerItem[] {
    if (!reservation.showtime) return [];

    return this.seatItems(
      reservation.showtime as unknown as ShowtimeLike,
      reservation.items.map(i => ({ section: i.section ?? '', price: Number(i.price_amount), label: `${i.row_label}-${i.seat_number}` }))
    );
  }

  private total(items: DataLayerItem[]): number {
    return items.reduce((sum, item) => sum + item.price * (item.quantity ?? 1), 0);
  }

  /** "Földszint|3-06" kulcsból "3-06". */
  private seatName(key: string): string {
    const bar = key.indexOf('|');
    return bar >= 0 ? key.slice(bar + 1) : key;
  }

  // ------------------------------------------------------------ alap

  private ecommerce(event: string, ecommerce: Record<string, unknown>): void {
    this.push({ ecommerce: null });
    this.push({ event, event_id: this.eventId(), ecommerce });
  }

  private push(event: Record<string, unknown>): void {
    if (!this.started) return;

    if (!this.loaded) {
      this.queue.push(event);
      return;
    }
    if (this.config.enabled) {
      this.reallyPush(event);
    }
  }

  private reallyPush(event: Record<string, unknown>): void {
    window.dataLayer.push(event);
  }

  private eventId(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
      ? crypto.randomUUID()
      : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
  }

  private budapest(iso: string): { date: string; time: string } {
    const d = new Date(iso);
    if (isNaN(d.getTime())) return { date: '', time: '' };
    // sv-SE formátum: ÉÉÉÉ-HH-NN ÓÓ:PP:MM
    const text = d.toLocaleString('sv-SE', { timeZone: 'Europe/Budapest', hour12: false });
    return { date: text.slice(0, 10), time: text.slice(11, 16) };
  }

  private applyConsent(granted: boolean): void {
    const value = granted ? 'granted' : 'denied';
    this.gtag('consent', 'update', {
      ad_storage: value,
      analytics_storage: value,
      ad_user_data: value,
      ad_personalization: value
    });
  }

  private storedChoice(): 'all' | 'necessary' | null {
    try {
      const value = localStorage.getItem(CONSENT_KEY);
      return value === 'all' || value === 'necessary' ? value : null;
    } catch {
      return null;
    }
  }

  // A Tag Manager a gtag-stílusú, arguments objektumként érkező bejegyzéseket várja
  private gtag(..._args: unknown[]): void {
    window.dataLayer.push(arguments);
  }

  private loadTagManager(id: string): void {
    window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });

    const script = document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(id)}`;
    document.head.insertBefore(script, document.head.firstChild);
  }
}
