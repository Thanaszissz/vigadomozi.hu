import { Injectable } from '@angular/core';
import { ShowtimeDetail } from '../../../../core/api/api.service';

export const POS_DISPLAY_PATH = '/admin/pos-display';
export const POS_DISPLAY_CHANNEL = 'vigado-pos-display';
const CHANNEL_NAME = POS_DISPLAY_CHANNEL;

export interface DisplayLine {
  label: string;
  detail?: string;
  tag?: string;
  price: number;
}

/** A vevői kijelzőre kerülő adatok: szándékosan nincs bennük név, telefonszám vagy más személyes adat. */
export interface DisplayState {
  title: string | null;
  startsAt: string | null;
  auditorium: string | null;
  selectedKeys: string[];
  lines: DisplayLine[];
  total: number;
  covered: number;
  payable: number;
  paymentLabel: string | null;
  cashGiven: number | null;
  change: number | null;
}

export interface DisplaySale {
  total: number;
  payable: number;
  paymentLabel: string | null;
  cashGiven: number | null;
  change: number | null;
}

export type DisplaySeatMap = ShowtimeDetail['seatMap'];

export type DisplayMessage =
  | { type: 'map'; seatMap: DisplaySeatMap | null }
  | { type: 'state'; state: DisplayState }
  | { type: 'sale'; sale: DisplaySale }
  | { type: 'hello' };

export const EMPTY_DISPLAY_STATE: DisplayState = {
  title: null, startsAt: null, auditorium: null, selectedKeys: [], lines: [],
  total: 0, covered: 0, payable: 0, paymentLabel: null, cashGiven: null, change: null
};

/** A pénztár és a vevői kijelző (második ablak, második monitor) közti kapcsolat; csak ugyanabban a böngészőben működik. */
@Injectable({ providedIn: 'root' })
export class PosDisplayService {
  private channel: BroadcastChannel | null = typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel(CHANNEL_NAME) : null;
  private lastMap: DisplaySeatMap | null = null;
  private lastState: DisplayState = EMPTY_DISPLAY_STATE;
  private lastStateJson = '';
  private displayWindow: Window | null = null;

  constructor() {
    // A később megnyitott (vagy újratöltött) kijelző kéri a pillanatnyi állapotot
    this.channel?.addEventListener('message', (event: MessageEvent<DisplayMessage>) => {
      if (event.data?.type === 'hello') {
        this.channel?.postMessage({ type: 'map', seatMap: this.lastMap } satisfies DisplayMessage);
        this.channel?.postMessage({ type: 'state', state: this.lastState } satisfies DisplayMessage);
      }
    });
  }

  get supported(): boolean {
    return this.channel !== null;
  }

  publishMap(seatMap: DisplaySeatMap | null): void {
    if (seatMap === this.lastMap) return;
    this.lastMap = seatMap;
    this.channel?.postMessage({ type: 'map', seatMap } satisfies DisplayMessage);
  }

  publishState(state: DisplayState): void {
    const json = JSON.stringify(state);
    if (json === this.lastStateJson) return;
    this.lastStateJson = json;
    this.lastState = state;
    this.channel?.postMessage({ type: 'state', state } satisfies DisplayMessage);
  }

  publishSale(sale: DisplaySale): void {
    this.channel?.postMessage({ type: 'sale', sale } satisfies DisplayMessage);
  }

  /**
   * Megnyitja a vevői kijelzőt, és ha a böngésző engedi (Chrome / Edge ablakkezelés), áthelyezi a második monitorra.
   * Az ablakot azonnal, a kattintás idején kell megnyitni, különben a böngésző felugró ablaknak tekinti és letiltja.
   */
  async open(): Promise<boolean> {
    if (this.displayWindow && !this.displayWindow.closed) {
      this.displayWindow.focus();
      return true;
    }

    const win = window.open(POS_DISPLAY_PATH, 'vigado-vevoi-kijelzo', 'popup=yes,width=1280,height=800');
    if (!win) return false;
    this.displayWindow = win;

    try {
      const details = await (window as any).getScreenDetails?.();
      const other = details?.screens?.find((s: any) => s !== details.currentScreen);
      if (other) {
        win.moveTo(other.availLeft, other.availTop);
        win.resizeTo(other.availWidth, other.availHeight);
      }
    } catch {
      // Az engedély megtagadva vagy nem támogatott: az ablakot kézzel kell a másik monitorra húzni
    }

    return true;
  }
}
