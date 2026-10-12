import { Component, EventEmitter, Input, Output, ElementRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Ticket, AdminService } from '../../../../services/admin.service';

export function paymentLabel(method: string): string {
  switch (method) {
    case 'cash': return 'Készpénz';
    case 'card': return 'Bankkártya';
    case 'stripe': return 'Online';
    default: return method;
  }
}

// A jegy kinézete: a párbeszédablakban és a nyomtatáshoz használt külön ablakban is ez érvényes
const TICKET_CSS = `
  .tp-ticket {
    background: #fff; color: #111; padding: 14px 16px; text-align: center;
    border: 2px dashed #8b6f47; font-family: Arial, Helvetica, sans-serif; box-sizing: border-box;
  }
  .tp-cinema { font-size: 20px; font-weight: 800; letter-spacing: 3px; }
  .tp-place { font-size: 11px; color: #555; margin-bottom: 8px; }
  .tp-kind { font-size: 12px; font-weight: 700; letter-spacing: 2px; border-top: 1px solid #111; border-bottom: 1px solid #111; padding: 3px 0; margin-bottom: 10px; }
  .tp-movie { font-size: 20px; font-weight: 800; line-height: 1.2; }
  .tp-when { font-size: 15px; margin: 4px 0 10px; }
  .tp-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 10px; }
  .tp-grid div { display: flex; flex-direction: column; border: 1px solid #ccc; border-radius: 4px; padding: 4px 2px; }
  .tp-grid span { font-size: 10px; color: #666; text-transform: uppercase; }
  .tp-grid b { font-size: 15px; }
  .tp-number { font-size: 22px; font-weight: 800; letter-spacing: 2px; font-family: 'Courier New', monospace; margin: 6px 0; }
  .tp-foot { font-size: 11px; color: #555; }
`;

const PRINT_PAGE_CSS = `
  @page { margin: 6mm; }
  html, body { margin: 0; padding: 0; background: #fff; }
  ${TICKET_CSS}
  .tp-ticket { width: 86mm; margin: 0 auto; border: 1px dashed #000; page-break-after: always; break-after: page; }
  .tp-ticket:last-child { page-break-after: auto; break-after: auto; }
`;

@Component({
  selector: 'app-ticket-print',
  standalone: true,
  imports: [CommonModule],
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
          <div class="tp-cinema">VIGADÓ MOZI</div>
          <div class="tp-place">Kisújszállás • Szabadság tér 7.</div>
          <div class="tp-kind">BELÉPŐJEGY</div>

          <div class="tp-movie">{{ t.movie_title }}</div>
          <div class="tp-when">{{ t.starts_at | date:'yyyy.MM.dd. HH:mm' }}</div>

          <div class="tp-grid">
            <div><span>Terem</span><b>{{ t.auditorium_name || '—' }}</b></div>
            <div><span>Sor</span><b>{{ t.row_label }}</b></div>
            <div><span>Szék</span><b>{{ t.seat_number ?? '—' }}</b></div>
            <div><span>Ár</span><b>{{ t.price_amount | number:'1.0-0' }} Ft</b></div>
          </div>

          <div class="tp-number">{{ t.ticket_number }}</div>

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
      width: min(520px, 94vw); max-height: 92vh; display: flex; flex-direction: column;
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
    .tp-area { overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 14px; }
    ${TICKET_CSS}
    .tp-ticket { border-radius: 8px; }
  `]
})
export class TicketPrintComponent {
  @Input() tickets: Ticket[] = [];
  @Output() close = new EventEmitter<void>();

  paymentLabel = paymentLabel;

  constructor(private host: ElementRef<HTMLElement>, private admin: AdminService) {}

  // A jegyeket külön, rejtett keretben nyomtatjuk, így az admin felület nem kerül rá a lapra
  print(): void {
    const area = this.host.nativeElement.querySelector('.tp-area');
    if (!area) return;

    const numbers = this.tickets.map(t => t.ticket_number);
    this.admin.logClientEvent('ticket.print', `Jegynyomtatás: ${numbers.join(', ')}`, { tickets: numbers });

    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(frame);

    const doc = frame.contentDocument!;
    doc.open();
    doc.write(`<!doctype html><html><head><meta charset="utf-8"><title>Jegyek</title><style>${PRINT_PAGE_CSS}</style></head><body>${area.innerHTML}</body></html>`);
    doc.close();

    setTimeout(() => {
      frame.contentWindow?.focus();
      frame.contentWindow?.print();
      setTimeout(() => frame.remove(), 1000);
    }, 150);
  }
}
