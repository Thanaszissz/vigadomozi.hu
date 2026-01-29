import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService } from '../../../../services/admin.service';

@Component({
  selector: 'app-reports-admin',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './reports-admin.component.html',
  styleUrls: ['./reports-admin.component.css']
})
export class ReportsAdminComponent implements OnInit {
  selectedDate = new Date().toISOString().split('T')[0];
  loading = false;
  error = '';
  
  summary: any = null;
  reservations: any[] = [];

  constructor(
    private adminService: AdminService,
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

  exportPDF(): void {
    if (!this.summary) return;

    const printWindow = window.open('', '_blank');
    if (!printWindow) return;

    const html = `
      <!DOCTYPE html>
      <html>
      <head>
        <meta charset="UTF-8">
        <title>Napi zárás - ${this.selectedDate}</title>
        <style>
          * { margin: 0; padding: 0; box-sizing: border-box; }
          body {
            font-family: Arial, sans-serif;
            padding: 30px;
            background: white;
            color: #2c1810;
          }
          .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #6b4423;
            padding-bottom: 20px;
          }
          .logo {
            width: 120px;
            margin-bottom: 15px;
          }
          h1 { font-size: 28px; color: #6b4423; margin-bottom: 5px; }
          .date { font-size: 18px; color: #8b6434; }
          .summary-cards {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            justify-content: center;
          }
          .summary-card {
            flex: 1;
            max-width: 200px;
            background: #f5f0e8;
            border: 2px solid #d4c4a8;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
          }
          .summary-card.highlight {
            background: #6b4423;
            color: white;
            border-color: #8b6434;
          }
          .card-icon { font-size: 32px; margin-bottom: 8px; }
          .card-value { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
          .card-label { font-size: 12px; opacity: 0.8; }
          .section {
            margin-bottom: 30px;
            page-break-inside: avoid;
          }
          h2 {
            font-size: 18px;
            color: #6b4423;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #d4c4a8;
          }
          table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 13px;
          }
          th {
            background: #6b4423;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: bold;
          }
          td {
            padding: 8px 10px;
            border-bottom: 1px solid #e8dcc8;
          }
          tr:nth-child(even) { background: #f9f6f0; }
          .amount { text-align: right; font-weight: bold; }
          tfoot {
            background: #8b6434;
            color: white;
            font-weight: bold;
          }
          tfoot td {
            padding: 12px 10px;
            border-top: 3px solid #6b4423;
          }
          .payment-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
          }
          .payment-badge.cash { background: #90EE90; color: #2d5016; }
          .payment-badge.card { background: #ADD8E6; color: #1a4d6d; }
          .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 11px;
            color: #8b6434;
            border-top: 2px solid #d4c4a8;
            padding-top: 15px;
          }
          @media print {
            body { padding: 15px; }
            .no-print { display: none; }
          }
        </style>
      </head>
      <body>
        <div class="header">
          <img src="/assets/vigado-logo.png" alt="Vigadó" class="logo">
          <h1>📊 Napi Zárás</h1>
          <div class="date">${this.selectedDate}</div>
        </div>

        <div class="summary-cards">
          <div class="summary-card">
            <div class="card-icon">🎫</div>
            <div class="card-value">${this.summary.total_reservations}</div>
            <div class="card-label">Foglalások</div>
          </div>
          <div class="summary-card">
            <div class="card-icon">🪑</div>
            <div class="card-value">${this.summary.total_tickets}</div>
            <div class="card-label">Jegyek</div>
          </div>
          <div class="summary-card highlight">
            <div class="card-icon">💰</div>
            <div class="card-value">${this.summary.total_amount.toLocaleString('hu-HU')} Ft</div>
            <div class="card-label">Bevétel</div>
          </div>
        </div>

        ${this.summary.by_admin?.length ? `
        <div class="section">
          <h2>👨‍💼 Adminisztrátorónkénti bontás</h2>
          <table>
            <thead>
              <tr>
                <th>Adminisztrátor neve</th>
                <th>Email</th>
                <th style="text-align: center;">Foglalások</th>
                <th style="text-align: center;">Jegyek</th>
                <th style="text-align: right;">Bevétel</th>
              </tr>
            </thead>
            <tbody>
              ${this.summary.by_admin.map((item: any) => `
                <tr>
                  <td>${item.admin_name}</td>
                  <td>${item.admin_email}</td>
                  <td style="text-align: center;">${item.reservations_count}</td>
                  <td style="text-align: center;">${item.tickets_count}</td>
                  <td class="amount">${item.total_amount.toLocaleString('hu-HU')} Ft</td>
                </tr>
              `).join('')}
            </tbody>
            <tfoot>
              <tr>
                <td colspan="2"><strong>ÖSSZESEN</strong></td>
                <td style="text-align: center;"><strong>${this.summary.total_reservations}</strong></td>
                <td style="text-align: center;"><strong>${this.summary.total_tickets}</strong></td>
                <td class="amount"><strong>${this.summary.total_amount.toLocaleString('hu-HU')} Ft</strong></td>
              </tr>
            </tfoot>
          </table>
        </div>
        ` : ''}

        ${this.summary.by_showtime?.length ? `
        <div class="section">
          <h2>🎟️ Előadások szerinti bevétel</h2>
          <table>
            <thead>
              <tr>
                <th>Film</th>
                <th>Előadás időpont</th>
                <th>Terem</th>
                <th style="text-align: center;">Foglalások</th>
                <th style="text-align: center;">Jegyek</th>
                <th style="text-align: right;">Bevétel</th>
              </tr>
            </thead>
            <tbody>
              ${this.summary.by_showtime.map((item: any) => `
                <tr>
                  <td>${item.movie_title}</td>
                  <td>${new Date(item.starts_at).toLocaleString('hu-HU')}</td>
                  <td>${item.auditorium_name}</td>
                  <td style="text-align: center;">${item.reservations_count}</td>
                  <td style="text-align: center;">${item.tickets_count}</td>
                  <td class="amount">${item.total_amount.toLocaleString('hu-HU')} Ft</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
        ` : ''}

        ${this.summary.by_payment_method?.length ? `
        <div class="section">
          <h2>💳 Fizetési módok</h2>
          <table>
            <thead>
              <tr>
                <th>Fizetési mód</th>
                <th style="text-align: center;">Tranzakciók</th>
                <th style="text-align: right;">Összeg</th>
              </tr>
            </thead>
            <tbody>
              ${this.summary.by_payment_method.map((item: any) => `
                <tr>
                  <td>
                    ${item.method === 'cash' ? '💵 Készpénz' : item.method === 'card' ? '💳 Kártya' : item.method}
                  </td>
                  <td style="text-align: center;">${item.count}</td>
                  <td class="amount">${item.amount.toLocaleString('hu-HU')} Ft</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
        ` : ''}

        <div class="footer">
          <p><strong>Vigadó Mozi</strong></p>
          <p>Nyomtatva: ${new Date().toLocaleString('hu-HU')}</p>
        </div>

        <script>
          window.onload = function() {
            window.print();
          };
        </script>
      </body>
      </html>
    `;

    printWindow.document.write(html);
    printWindow.document.close();
  }
}
