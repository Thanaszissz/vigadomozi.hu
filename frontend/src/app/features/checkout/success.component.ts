import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { firstValueFrom } from 'rxjs';
import { ApiService, Reservation } from '../../core/api/api.service';
import { TICKET_CSS, ticketMarkup, waitForImages } from '../../core/ticket/ticket-template';

@Component({
  selector: 'app-success',
  standalone: true,
  imports: [CommonModule, RouterLink],
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
    private route: ActivatedRoute
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
      host.innerHTML = `<style>${TICKET_CSS}</style>` + tickets.map(t => ticketMarkup(t)).join('');
      document.body.appendChild(host);
      await waitForImages(host);

      // Jegyenként egy 210 mm x 100 mm-es fekvő oldal
      const format: [number, number] = [210, 100];
      let pdf: InstanceType<typeof jsPDF> | null = null;
      const nodes = Array.from(host.querySelectorAll<HTMLElement>('.tk-box'));
      for (const node of nodes) {
        const canvas = await html2canvas(node, { scale: 2, backgroundColor: '#ffffff' });
        if (!pdf) {
          pdf = new jsPDF({ unit: 'mm', format, orientation: 'landscape' });
        } else {
          pdf.addPage(format, 'landscape');
        }
        pdf.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', 0, 0, 210, 100, undefined, 'FAST');
      }

      pdf?.save(`vigado-jegyek-${this.reservationId}.pdf`);
    } catch (err: any) {
      this.downloadError = err?.error?.message || 'A jegyek letöltése nem sikerült. Próbáld újra később.';
      console.error(err);
    } finally {
      host?.remove();
      this.downloading = false;
    }
  }
}
