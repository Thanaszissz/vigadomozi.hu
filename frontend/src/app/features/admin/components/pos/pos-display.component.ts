import { ChangeDetectorRef, Component, HostListener, NgZone, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Seat } from '../../../../core/api/api.service';
import { rowLabelOf, seatNumberOf, sectionBlocks } from '../../../../core/util/seat';
import {
  DisplayLine, DisplayMessage, DisplaySale, DisplaySeatMap, DisplayState, EMPTY_DISPLAY_STATE, POS_DISPLAY_CHANNEL
} from './pos-display.service';

interface SectionGroup { name: string; rows: string[] }

/** A vevő felé forduló második képernyő: csak a nézőtér, a kiválasztott székek és az összegek látszanak. */
@Component({
  selector: 'app-pos-display',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './pos-display.component.html',
  styleUrls: ['./pos-display.component.css']
})
export class PosDisplayComponent implements OnInit, OnDestroy {
  state: DisplayState = EMPTY_DISPLAY_STATE;
  seatMap: DisplaySeatMap | null = null;
  sale: DisplaySale | null = null;
  seatSize = 32;
  fullscreen = false;
  connected = false;

  readonly rowLabelOf = rowLabelOf;
  readonly seatNumberOf = seatNumberOf;

  private channel: BroadcastChannel | null = null;
  private saleTimer?: ReturnType<typeof setTimeout>;
  private blocks: SectionGroup[][] = [];
  private selected = new Set<string>();

  constructor(private cdr: ChangeDetectorRef, private zone: NgZone) {}

  ngOnInit(): void {
    document.title = 'Vigadó – Vevői kijelző';
    if (typeof BroadcastChannel === 'undefined') return;

    this.channel = new BroadcastChannel(POS_DISPLAY_CHANNEL);
    this.channel.onmessage = (event: MessageEvent<DisplayMessage>) => this.zone.run(() => this.receive(event.data));
    this.channel.postMessage({ type: 'hello' } satisfies DisplayMessage);
    this.onFullscreenChange();
  }

  ngOnDestroy(): void {
    this.channel?.close();
    clearTimeout(this.saleTimer);
  }

  private receive(message: DisplayMessage): void {
    this.connected = true;
    switch (message?.type) {
      case 'map':
        this.seatMap = message.seatMap;
        this.rebuild();
        break;
      case 'state':
        this.state = message.state;
        this.selected = new Set(message.state.selectedKeys);
        // Új tételek kiválasztásakor a "Köszönjük" képernyő azonnal átadja a helyét
        if (message.state.lines.length > 0) this.dismissSale();
        break;
      case 'sale':
        this.sale = message.sale;
        clearTimeout(this.saleTimer);
        this.saleTimer = setTimeout(() => this.zone.run(() => this.dismissSale()), 15000);
        break;
    }
    this.cdr.markForCheck();
  }

  private dismissSale(): void {
    this.sale = null;
    clearTimeout(this.saleTimer);
  }

  get hasLines(): boolean {
    return this.state.lines.length > 0;
  }

  trackLine(index: number, line: DisplayLine): string {
    return `${index}-${line.label}`;
  }

  isSelected(seat: Seat): boolean {
    return this.selected.has(seat.key);
  }

  seatClass(seat: Seat): string {
    if (seat.type === 'aisle') return 'aisle';
    if (this.isSelected(seat)) return 'selected';
    return seat.status === 'available' ? 'free' : 'taken';
  }

  getBlocks(): SectionGroup[][] {
    return this.blocks;
  }

  getSeatsInRow(row: string): Seat[] {
    return this.seatMap ? Object.values(this.seatMap.seats[row] || {}) : [];
  }

  hasAisleBefore(row: string, index: number): boolean {
    const aisleAfter = this.seatMap?.layout?.aisles?.[row];
    return aisleAfter !== undefined && index === aisleAfter;
  }

  get multipleSections(): boolean {
    return this.blocks.reduce((n, b) => n + b.length, 0) > 1;
  }

  private rebuild(): void {
    const map = this.seatMap;
    if (!map) {
      this.blocks = [];
      return;
    }

    const rows = Object.keys(map.seats);
    const groups = new Map<string, string[]>();
    for (const row of rows) {
      const seats = Object.values(map.seats[row] || {});
      const section = seats.find(s => !!s.section)?.section || 'Nézőtér';
      groups.set(section, [...(groups.get(section) ?? []), row]);
    }
    const sectionGroups: SectionGroup[] = Array.from(groups.entries()).map(([name, r]) => ({ name, rows: r }));
    this.blocks = sectionBlocks(sectionGroups, map.layout?.inlineSections,
      g => Math.max(0, ...g.rows.map(row => this.getSeatsInRow(row).length)));
    this.fitSeats();
  }

  /** A székméretet úgy veszi, hogy a legszélesebb sor (és az egymás melletti szintek) elférjen a térkép oldalán. */
  @HostListener('window:resize')
  fitSeats(): void {
    const widest = this.blocks.reduce((max, block) => {
      const width = block.reduce((sum, g) => sum + Math.max(0, ...g.rows.map(r => this.getSeatsInRow(r).length)) + 1, 0);
      return Math.max(max, width);
    }, 10);
    const rowCount = this.blocks.reduce((sum, b) => sum + Math.max(...b.map(g => g.rows.length)) + 1, 0);
    const availableWidth = Math.max(320, window.innerWidth * 0.64 - 120);
    const availableHeight = Math.max(240, window.innerHeight - 260);
    const byWidth = availableWidth / (widest * 1.18);
    const byHeight = availableHeight / (Math.max(rowCount, 1) * 1.25);
    this.seatSize = Math.max(14, Math.min(54, Math.floor(Math.min(byWidth, byHeight))));
  }

  @HostListener('document:fullscreenchange')
  onFullscreenChange(): void {
    this.fullscreen = !!document.fullscreenElement;
  }

  @HostListener('document:dblclick')
  toggleFullscreen(): void {
    if (document.fullscreenElement) {
      void document.exitFullscreen();
    } else {
      void document.documentElement.requestFullscreen?.().catch(() => {});
    }
  }
}
