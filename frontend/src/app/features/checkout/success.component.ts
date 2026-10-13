import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { CommonModule, DatePipe, DecimalPipe } from '@angular/common';
import { firstValueFrom } from 'rxjs';
import { ApiService, PaidTicket, Reservation } from '../../core/api/api.service';

// A letöltött jegy kinézete (ugyanaz, mint az e-mailben és a pénztári nyomtatásban)
const TICKET_STYLE = `
  .dl-ticket { width: 340px; box-sizing: border-box; background: #fff; color: #111; padding: 16px; text-align: center;
    border: 2px dashed #8b6f47; font-family: Arial, Helvetica, sans-serif; }
  .dl-cinema { font-size: 20px; font-weight: 800; letter-spacing: 3px; }
  .dl-place { font-size: 11px; color: #555; margin-bottom: 8px; }
  .dl-kind { font-size: 12px; font-weight: 700; letter-spacing: 2px; border-top: 1px solid #111; border-bottom: 1px solid #111; padding: 3px 0; margin-bottom: 10px; }
  .dl-movie { font-size: 20px; font-weight: 800; line-height: 1.2; }
  .dl-when { font-size: 15px; margin: 4px 0 10px; }
  .dl-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 10px; }
  .dl-grid div { display: flex; flex-direction: column; border: 1px solid #ccc; border-radius: 4px; padding: 4px 2px; }
  .dl-grid span { font-size: 10px; color: #666; text-transform: uppercase; }
  .dl-grid b { font-size: 15px; }
  .dl-number { font-size: 22px; font-weight: 800; letter-spacing: 2px; font-family: 'Courier New', monospace; margin: 6px 0; }
  .dl-foot { font-size: 11px; color: #555; }
`;

@Component({
  selector: 'app-success',
  standalone: true,
  imports: [CommonModule, RouterLink],
  providers: [DatePipe, DecimalPipe],
  templateUrl: './success.component.html',
  styleUrls: ['./success.component.css']
})
export class SuccessComponent implements OnInit {
  reservation: Reservation | null = null;
  loading = false;
  error: string | null = null;
  reservationId: number = 0;
  accessKey = '';
  downloading = false;
  downloadError: string | null = null;

  constructor(
    private apiService: ApiService,
    private route: ActivatedRoute,
    private date: DatePipe,
    private number: DecimalPipe
  ) {}

  ngOnInit() {
    this.route.queryParamMap.subscribe(q => (this.accessKey = q.get('k') ?? ''));
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
        this.error = 'Foglalás adatai nem találhatók.';
        console.error(err);
        this.loading = false;
      },
    });
  }

  async downloadTickets() {
    if (this.downloading || !this.accessKey) return;
    this.downloading = true;
    this.downloadError = null;

    let host: HTMLElement | null = null;
    try {
      const tickets = await firstValueFrom(this.apiService.getPaidTickets(this.reservationId, this.accessKey));
      // A PDF-készítő könyvtárak csak a letöltéskor töltődnek be, így nem lassítják az oldalt
      const [{ default: html2canvas }, { jsPDF }] = await Promise.all([import('html2canvas'), import('jspdf')]);

      host = document.createElement('div');
      host.style.cssText = 'position:fixed;left:-10000px;top:0;';
      host.innerHTML = `<style>${TICKET_STYLE}</style>` + tickets.map(t => this.ticketHtml(t)).join('');
      document.body.appendChild(host);

      const pageW = 90;
      let pdf: InstanceType<typeof jsPDF> | null = null;
      const nodes = Array.from(host.querySelectorAll<HTMLElement>('.dl-ticket'));
      for (const node of nodes) {
        const canvas = await html2canvas(node, { scale: 2, backgroundColor: '#ffffff' });
        const h = (canvas.height / canvas.width) * (pageW - 10);
        const format: [number, number] = [pageW, h + 10];
        if (!pdf) {
          pdf = new jsPDF({ unit: 'mm', format });
        } else {
          pdf.addPage(format);
        }
        pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 5, 5, pageW - 10, h);
      }

      pdf?.save(`vigado-mozi-jegyek-${this.reservationId}.pdf`);
    } catch (err: any) {
      this.downloadError = err?.error?.message || 'A jegyek letöltése nem sikerült. Próbáld újra később.';
      console.error(err);
    } finally {
      host?.remove();
      this.downloading = false;
    }
  }

  private ticketHtml(t: PaidTicket): string {
    const esc = (v: unknown) => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!));
    const when = this.date.transform(t.starts_at, 'yyyy.MM.dd. HH:mm');
    const price = this.number.transform(t.price_amount, '1.0-0');
    return `<div class="dl-ticket">
      <div class="dl-cinema">VIGADÓ MOZI</div>
      <div class="dl-place">Kisújszállás • Szabadság tér 7.</div>
      <div class="dl-kind">BELÉPŐJEGY</div>
      <div class="dl-movie">${esc(t.movie_title)}</div>
      <div class="dl-when">${esc(when)}</div>
      <div class="dl-grid">
        <div><span>Terem</span><b>${esc(t.auditorium_name || '—')}</b></div>
        <div><span>Sor</span><b>${esc(t.row_label)}</b></div>
        <div><span>Szék</span><b>${esc(t.seat_number ?? '—')}</b></div>
        <div><span>Ár</span><b>${esc(price)} Ft</b></div>
      </div>
      <div class="dl-number">${esc(t.ticket_number)}</div>
      <div class="dl-foot">Online vásárlás • ${esc(this.date.transform(t.sold_at, 'yyyy.MM.dd. HH:mm'))}</div>
    </div>`;
  }
}
