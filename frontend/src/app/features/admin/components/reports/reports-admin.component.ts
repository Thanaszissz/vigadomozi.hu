import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { DateInputComponent } from '../../../../shared/date-time-inputs.component';
import { SalesStatsAdminComponent } from '../sales-stats/sales-stats-admin.component';
import { Router } from '@angular/router';
import { AdminService } from '../../../../services/admin.service';
import { AuthService } from '../../../../services/auth.service';
import { buildDailyReportHtml } from './daily-report-print';

@Component({
  selector: 'app-reports-admin',
  standalone: true,
  imports: [CommonModule, FormsModule, DateInputComponent, SalesStatsAdminComponent],
  templateUrl: './reports-admin.component.html',
  styleUrls: ['./reports-admin.component.css']
})
export class ReportsAdminComponent implements OnInit {
  selectedDate = new Date().toISOString().split('T')[0];
  activeTab: 'daily' | 'stats' = 'daily';
  loading = false;
  error = '';
  
  summary: any = null;
  reservations: any[] = [];

  constructor(
    private adminService: AdminService,
    private auth: AuthService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.loadReport();
  }

  loadReport(): void {
    this.loading = true;
    this.error = '';

    this.adminService.getDailyReport(this.selectedDate).subscribe({
      next: (data) => {
        this.summary = data.summary;
        this.reservations = data.reservations;
        this.loading = false;
      },
      error: (err) => {
        this.error = 'Hiba történt a riport betöltése során';
        this.loading = false;
        console.error('Error loading report:', err);
      }
    });
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
  }

  /** A napi riport nyomtatása / PDF-be mentése rejtett keretből (nem blokkolja a felugró ablak tiltás). */
  exportPDF(): void {
    if (!this.summary) return;

    const html = buildDailyReportHtml({
      date: this.selectedDate,
      summary: this.summary,
      reservations: this.reservations,
      issuedBy: this.auth.getCurrentUser()?.name ?? '',
      logoUrl: `${window.location.origin}/assets/vigado_logo_beige.png`
    });

    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(frame);
    const doc = frame.contentDocument!;
    doc.open();
    doc.write(html);
    doc.close();

    // a logó betöltése után nyomtatunk (legfeljebb 2 mp-et várunk)
    let fired = false;
    const done = () => {
      if (fired) return;
      fired = true;
      frame.contentWindow?.focus();
      frame.contentWindow?.print();
      setTimeout(() => frame.remove(), 1000);
    };
    const logo = doc.querySelector('img');
    if (logo && !logo.complete) {
      logo.addEventListener('load', done);
      logo.addEventListener('error', done);
      setTimeout(done, 2000);
    } else {
      setTimeout(done, 100);
    }
  }
}
