import { Component, EventEmitter, Input, Output, ViewEncapsulation } from '@angular/core';
import { CommonModule } from '@angular/common';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { Ticket, AdminService } from '../../../../services/admin.service';
import { TICKET_CSS, TICKET_PRINT_SCALE, ticketMarkup, waitForImages } from '../../../../core/ticket/ticket-template';

export function paymentLabel(method: string): string {
  switch (method) {
    case 'cash': return 'Készpénz';
    case 'card': return 'Bankkártya';
    case 'stripe': return 'Online';
    case 'simplepay': return 'Online (bankkártya)';
    case 'experience_card': return 'Élménykártya';
    default: return method;
  }
}

// Nyomtatáskor jegyenként egy 210 mm x 100 mm-es oldal
const PRINT_PAGE_CSS = `
  @page { size: 210mm 100mm; margin: 0; }
  html, body { margin: 0; padding: 0; background: #fff; }
  ${TICKET_CSS}
  .tk-box { width: 210mm !important; height: 99.9mm !important; page-break-after: always; break-after: page; }
  .tk-box:last-child { page-break-after: auto; break-after: auto; }
`;

// Az előnézet a 840 px-es jegy kicsinyített mása
const PREVIEW_SCALE = 0.6;

@Component({
  selector: 'app-ticket-print',
  standalone: true,
  imports: [CommonModule],
  encapsulation: ViewEncapsulation.None,
  template: `
    <div class="tp-backdrop" (click)="close.emit()"></div>
    <div class="tp-dialog" role="dialog" aria-modal="true">
      <div class="tp-toolbar">
        <h3>Jegyek ({{ tickets.length }} db)</h3>
        <div class="tp-actions">
          <button type="button" class="tp-btn tp-btn-primary" (click)="print()">🖨️ Nyomtatás</button>
          <button type="button" class="tp-btn" (click)="close.emit()">Bezár</button>
        </div>
      </div>

      <div class="tp-area">
        <div class="tp-ticket" *ngFor="let t of tickets">
          <div [innerHTML]="markup(t, previewScale)"></div>
          <div class="tp-foot">
            {{ paymentLabel(t.payment_method) }} • {{ t.sold_at | date:'yyyy.MM.dd. HH:mm' }}
            <span *ngIf="t.sold_by_name"> • {{ t.sold_by_name }}</span>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .tp-backdrop {
      position: fixed; inset: 0; background: rgba(0, 0, 0, 0.65); z-index: 2000;
    }
    .tp-dialog {
      position: fixed; z-index: 2001; top: 50%; left: 50%; transform: translate(-50%, -50%);
      width: min(560px, 96vw); max-height: 92vh; display: flex; flex-direction: column;
      background: #2c1810; border: 1px solid #5c3d2e; border-radius: 12px; overflow: hidden;
    }
    .tp-toolbar {
      display: flex; justify-content: space-between; align-items: center; gap: 12px;
      padding: 14px 18px; border-bottom: 1px solid #5c3d2e;
    }
    .tp-toolbar h3 { margin: 0; color: #e8d5b7; font-size: 18px; }
    .tp-actions { display: flex; gap: 8px; }
    .tp-btn {
      padding: 8px 16px; border-radius: 6px; border: 1px solid #5c3d2e; background: #4a3425;
      color: #e8d5b7; font-weight: 600; cursor: pointer;
    }
    .tp-btn-primary { background: #d4a574; color: #2c1810; border-color: #d4a574; }
    .tp-area { overflow: auto; padding: 16px; display: flex; flex-direction: column; gap: 14px; }
    .tp-ticket { width: fit-content; margin: 0 auto; }
    .tp-ticket .tk-box { border-radius: 6px; }
    .tp-foot { margin-top: 4px; font-size: 12px; color: #c9b79a; text-align: center; }
    ${TICKET_CSS}
  `]
})
export class TicketPrintComponent {
  @Input() tickets: Ticket[] = [];
  @Output() close = new EventEmitter<void>();

  paymentLabel = paymentLabel;
  previewScale = PREVIEW_SCALE;

  constructor(private admin: AdminService, private sanitizer: DomSanitizer) {}

  // A jegy HTML-je saját, escape-elt sablonból készül, ezért biztonságos
  markup(t: Ticket, scale: number): SafeHtml {
    return this.sanitizer.bypassSecurityTrustHtml(ticketMarkup(t, scale));
  }

  // A jegyeket külön, rejtett keretben nyomtatjuk, így az admin felület nem kerül rá a lapra
  async print(): Promise<void> {
    if (!this.tickets.length) return;

    const numbers = this.tickets.map(t => t.ticket_number);
    this.admin.logClientEvent('ticket.print', `Jegynyomtatás: ${numbers.join(', ')}`, { tickets: numbers });

    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(frame);

    const doc = frame.contentDocument!;
    doc.open();
    const pages = this.tickets.map(t => ticketMarkup(t, TICKET_PRINT_SCALE)).join('');
    doc.write(`<!doctype html><html><head><meta charset="utf-8"><title>Jegyek</title><style>${PRINT_PAGE_CSS}</style></head><body>${pages}</body></html>`);
    doc.close();

    await waitForImages(doc);
    setTimeout(() => {
      frame.contentWindow?.focus();
      frame.contentWindow?.print();
      setTimeout(() => frame.remove(), 1000);
    }, 100);
  }
}
