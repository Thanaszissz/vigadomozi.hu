import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AdminService, AnalyticsSettingsState } from '../../../../services/admin.service';

@Component({
  selector: 'app-analytics-panel',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './analytics-panel.component.html',
  styleUrls: ['./analytics-panel.component.css']
})
export class AnalyticsPanelComponent implements OnInit {
  form: Omit<AnalyticsSettingsState, 'active'> = {
    enabled: false,
    gtm_id: '',
    tax_rate: null,
    variant: 'Felnőtt',
    transaction_id_mode: 'order_ref',
    transaction_prefix: 'VIG',
    item_prefix_movie: 'MO',
    item_prefix_show: 'EL',
    send_postal_code: true
  };
  active = false;
  loading = false;
  saving = false;
  error: string | null = null;
  notice: string | null = null;

  constructor(private admin: AdminService) {}

  ngOnInit(): void {
    this.loading = true;
    this.admin.getAnalytics().subscribe({
      next: (state) => {
        this.apply(state);
        this.loading = false;
      },
      error: () => {
        this.error = 'A beállítások betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  save(): void {
    this.saving = true;
    this.error = null;
    this.notice = null;

    const payload = { ...this.form, tax_rate: this.form.tax_rate === null || (this.form.tax_rate as unknown) === '' ? null : Number(this.form.tax_rate) };

    this.admin.saveAnalytics(payload).subscribe({
      next: (state) => {
        this.apply(state);
        this.notice = state.active
          ? 'A mérés be van kapcsolva: a nyilvános oldalak a Tag Manager tárolót betöltik, és a dataLayer események kimennek.'
          : 'A beállítások mentve. A mérés ki van kapcsolva.';
        this.saving = false;
      },
      error: (err) => {
        this.error = err?.error?.message || Object.values(err?.error?.errors ?? {}).flat().join(' ') || 'A mentés nem sikerült.';
        this.saving = false;
      }
    });
  }

  private apply(state: AnalyticsSettingsState): void {
    const { active, ...form } = state;
    this.form = form;
    this.active = active;
  }
}
