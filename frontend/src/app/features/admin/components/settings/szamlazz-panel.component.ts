import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AdminService, SzamlazzSettings } from '../../../../services/admin.service';

@Component({
  selector: 'app-szamlazz-panel',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './szamlazz-panel.component.html',
  styleUrls: ['./simplepay-panel.component.css']
})
export class SzamlazzPanelComponent implements OnInit {
  data: SzamlazzSettings | null = null;
  loading = false;
  error: string | null = null;
  notice: string | null = null;

  form = { enabled: false, agent_key: '', vat_rate: '5', e_invoice: true, send_email: true, prefix: '', email_subject: '', email_text: '' };
  password = '';
  showKey = false;
  saving = false;
  saveError: string | null = null;

  testing = false;
  testResult: { ok: boolean; message: string } | null = null;

  constructor(private admin: AdminService) {}

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading = true;
    this.error = null;

    this.admin.getSzamlazz().subscribe({
      next: (data) => {
        this.apply(data);
        this.loading = false;
      },
      error: () => {
        this.error = 'A Számlázz.hu beállítások betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  private apply(data: SzamlazzSettings): void {
    this.data = data;
    this.form = {
      enabled: data.enabled,
      agent_key: '',
      vat_rate: data.vat_rate,
      e_invoice: data.e_invoice,
      send_email: data.send_email,
      prefix: data.prefix,
      email_subject: data.email_subject,
      email_text: data.email_text
    };
  }

  vatLabel(rate: string): string {
    return ({ AAM: 'AAM – alanyi adómentes', TAM: 'TAM – tárgyi adómentes', '0': '0%' } as Record<string, string>)[rate] ?? `${rate}%`;
  }

  get canSave(): boolean {
    return !!this.password && !this.saving;
  }

  save(): void {
    if (!this.canSave) return;

    this.saving = true;
    this.saveError = null;
    this.notice = null;
    this.testResult = null;

    this.admin.saveSzamlazz({
      enabled: this.form.enabled,
      agent_key: this.form.agent_key.trim() || undefined,
      vat_rate: this.form.vat_rate,
      e_invoice: this.form.e_invoice,
      send_email: this.form.send_email,
      prefix: this.form.prefix.trim(),
      email_subject: this.form.email_subject.trim(),
      email_text: this.form.email_text.trim(),
      password: this.password
    }).subscribe({
      next: (data) => {
        this.saving = false;
        this.password = '';
        this.showKey = false;
        this.apply(data);
        this.notice = data.enabled
          ? 'A beállítások elmentve, a helyszíni jegyeladásnál kérhető ÁFÁS számla a Számlázz.hu-n keresztül.'
          : 'A beállítások elmentve. A számlázás jelenleg ki van kapcsolva.';
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

    this.admin.testSzamlazz().subscribe({
      next: (result) => {
        this.testing = false;
        this.testResult = result;
      },
      error: (err) => {
        this.testing = false;
        this.testResult = { ok: false, message: err?.error?.message || 'A kapcsolat-ellenőrzés sikertelen.' };
      }
    });
  }
}
