import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AdminService, SimplePaySettings, SimplePayTestResult } from '../../../../services/admin.service';

@Component({
  selector: 'app-simplepay-panel',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './simplepay-panel.component.html',
  styleUrls: ['./simplepay-panel.component.css']
})
export class SimplePayPanelComponent implements OnInit {
  data: SimplePaySettings | null = null;
  loading = false;
  error: string | null = null;
  notice: string | null = null;

  form = { enabled: false, environment: 'sandbox', merchant: '', secret_key: '', language: 'HU', timeout_minutes: 15, site_url: '' };
  password = '';
  showSecret = false;
  saving = false;
  saveError: string | null = null;

  testing = false;
  testResult: SimplePayTestResult | null = null;
  copied: string | null = null;

  constructor(private admin: AdminService) {}

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading = true;
    this.error = null;

    this.admin.getSimplePay().subscribe({
      next: (data) => {
        this.apply(data);
        this.loading = false;
      },
      error: () => {
        this.error = 'A SimplePay beállítások betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  private apply(data: SimplePaySettings): void {
    this.data = data;
    this.form = {
      enabled: data.enabled,
      environment: data.environment,
      merchant: data.merchant,
      secret_key: '',
      language: data.language,
      timeout_minutes: data.timeout_minutes,
      site_url: data.site_url
    };
  }

  get isLive(): boolean {
    return this.form.environment === 'live';
  }

  get canSave(): boolean {
    return !!this.password && !!this.form.site_url && !this.saving;
  }

  save(): void {
    if (!this.canSave) return;

    this.saving = true;
    this.saveError = null;
    this.notice = null;
    this.testResult = null;

    this.admin.saveSimplePay({
      enabled: this.form.enabled,
      environment: this.form.environment,
      merchant: this.form.merchant.trim(),
      secret_key: this.form.secret_key.trim() || undefined,
      language: this.form.language,
      timeout_minutes: Number(this.form.timeout_minutes),
      site_url: this.form.site_url.trim(),
      password: this.password
    }).subscribe({
      next: (data) => {
        this.saving = false;
        this.password = '';
        this.showSecret = false;
        this.apply(data);
        this.notice = data.enabled
          ? 'A beállítások elmentve, az online bankkártyás fizetés a SimplePay-en keresztül működik.'
          : 'A beállítások elmentve. Az online fizetés a SimplePay-en jelenleg ki van kapcsolva.';
      },
      error: (err) => {
        this.saving = false;
        const errors = err?.error?.errors;
        this.saveError = (errors && (Object.values(errors) as string[][])[0]?.[0]) || err?.error?.message || 'A mentés sikertelen.';
      }
    });
  }

  runTest(): void {
    this.testing = true;
    this.testResult = null;

    this.admin.testSimplePay().subscribe({
      next: (result) => {
        this.testing = false;
        this.testResult = result;
      },
      error: (err) => {
        this.testing = false;
        this.testResult = { ok: false, message: err?.error?.message || 'A kapcsolat-ellenőrzés sikertelen.', http_status: null, error_codes: [], signature_valid: null };
      }
    });
  }

  copy(text: string, key: string): void {
    navigator.clipboard?.writeText(text).then(() => {
      this.copied = key;
      window.setTimeout(() => (this.copied = null), 2000);
    });
  }

  statusLabel(status: string): string {
    return ({
      INIT: 'Folyamatban', SUCCESS: 'Sikeres', FAIL: 'Sikertelen', CANCEL: 'Megszakítva', TIMEOUT: 'Lejárt',
      ERROR: 'Hiba', CONFLICT: 'Ütközés – visszatérítés', DUPLICATE: 'Dupla fizetés – visszatérítés'
    } as Record<string, string>)[status] ?? status;
  }
}
