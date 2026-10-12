import { Component, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AdminService, NavPreview, NavSendPlan, NavState, NavTestResult } from '../../../../services/admin.service';

@Component({
  selector: 'app-nav-panel',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './nav-panel.component.html',
  styleUrls: ['./nav-panel.component.css']
})
export class NavPanelComponent implements OnInit, OnDestroy {
  data: NavState | null = null;
  loading = false;
  error: string | null = null;
  notice: string | null = null;

  form = { enabled: false, environment: 'test', tax_number: '', login: '', tech_password: '', signature_key: '', software_name: '', vat_category: '5%' };
  password = '';
  saving = false;
  saveError: string | null = null;
  showSecrets = false;

  testing = false;
  registering = false;
  testResult: NavTestResult | null = null;
  actionMessage: string | null = null;
  actionError: string | null = null;

  schedule = { auto_enabled: false, auto_time: '02:00', catchup_days: 7 };
  scheduleBusy = false;
  scheduleError: string | null = null;

  // Kézi beküldés
  date = '';
  preview: NavPreview | null = null;
  previewLoading = false;
  previewError: string | null = null;
  showXml = false;

  sendOpen = false;
  sendModify = false;
  sendPassword1 = '';
  sendPassword2 = '';
  plan: NavSendPlan | null = null;
  phrase = '';
  acknowledge = false;
  sendBusy = false;
  sendError: string | null = null;
  sendDone: string | null = null;
  secondsLeft = 0;
  private timer?: number;

  constructor(private admin: AdminService) {}

  ngOnInit(): void {
    this.load(true);
  }

  ngOnDestroy(): void {
    this.stopTimer();
  }

  load(initial = false): void {
    this.loading = true;
    this.error = null;

    this.admin.getNav().subscribe({
      next: (data) => {
        this.apply(data);
        if (initial || !this.date) {
          this.date = data.yesterday;
        }
        this.loadPreview();
        this.loading = false;
      },
      error: () => {
        this.error = 'A NAV beállítások betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  private apply(data: NavState): void {
    this.data = data;
    this.form = {
      enabled: data.enabled,
      environment: data.environment,
      tax_number: data.tax_number,
      login: data.login,
      tech_password: '',
      signature_key: '',
      software_name: data.software_name,
      vat_category: data.vat_category
    };
    this.schedule = {
      auto_enabled: data.schedule.auto_enabled,
      auto_time: data.schedule.auto_time,
      catchup_days: data.schedule.catchup_days
    };
  }

  get vatChoices(): string[] {
    const options = this.data?.vat_options ?? [];
    return options.includes(this.form.vat_category) ? options : [...options, this.form.vat_category];
  }

  get canSave(): boolean {
    return !!this.password && !!this.form.software_name && !this.saving;
  }

  save(): void {
    if (!this.canSave) return;

    this.saving = true;
    this.saveError = null;
    this.notice = null;
    this.testResult = null;

    this.admin.saveNav({
      enabled: this.form.enabled,
      environment: this.form.environment,
      tax_number: this.form.tax_number.trim(),
      login: this.form.login.trim(),
      tech_password: this.form.tech_password || undefined,
      signature_key: this.form.signature_key.trim() || undefined,
      software_name: this.form.software_name.trim(),
      vat_category: this.form.vat_category,
      password: this.password
    }).subscribe({
      next: (data) => {
        this.saving = false;
        this.password = '';
        this.showSecrets = false;
        this.apply(data);
        this.notice = data.active
          ? 'A NAV kapcsolat beállításai elmentve, az integráció AKTÍV.'
          : 'A NAV kapcsolat beállításai elmentve. Az integráció nincs aktiválva, automatikus beküldés nem történik.';
        this.loadPreview();
      },
      error: (err) => {
        this.saving = false;
        this.saveError = this.message(err, 'A mentés sikertelen.');
      }
    });
  }

  runTest(): void {
    this.testing = true;
    this.testResult = null;
    this.actionError = null;

    this.admin.testNav().subscribe({
      next: (result) => {
        this.testing = false;
        this.testResult = result;
      },
      error: (err) => {
        this.testing = false;
        this.actionError = this.message(err, 'A kapcsolat-ellenőrzés sikertelen.');
      }
    });
  }

  registerSoftware(): void {
    this.registering = true;
    this.actionMessage = null;
    this.actionError = null;

    this.admin.registerNavSoftware().subscribe({
      next: (r) => {
        this.registering = false;
        this.actionMessage = r.message;
        this.runTest();
      },
      error: (err) => {
        this.registering = false;
        this.actionError = this.message(err, 'A regisztráció sikertelen.');
      }
    });
  }

  saveSchedule(): void {
    this.scheduleBusy = true;
    this.scheduleError = null;
    this.notice = null;

    this.admin.saveNavSchedule({
      auto_enabled: this.schedule.auto_enabled,
      auto_time: this.schedule.auto_time || '02:00',
      catchup_days: Number(this.schedule.catchup_days)
    }).subscribe({
      next: (data) => {
        this.scheduleBusy = false;
        this.apply(data);
        this.notice = data.schedule.auto_enabled
          ? (data.active
            ? `Az automatikus beküldés beállítva: naponta ${data.schedule.auto_time}.`
            : 'Az ütemezés elmentve, de az integráció nincs aktiválva, ezért automatikus beküldés nem történik.')
          : 'Az automatikus beküldés kikapcsolva.';
      },
      error: (err) => {
        this.scheduleBusy = false;
        this.scheduleError = this.message(err, 'Az ütemezés mentése sikertelen.');
      }
    });
  }

  // ---- Kézi beküldés ----

  setDate(which: 'today' | 'yesterday'): void {
    if (!this.data) return;
    this.date = which === 'today' ? this.data.today : this.data.yesterday;
    this.loadPreview();
  }

  loadPreview(): void {
    if (!this.date) return;

    this.previewLoading = true;
    this.previewError = null;

    this.admin.previewNav(this.date).subscribe({
      next: (p) => {
        this.preview = p;
        this.previewLoading = false;
      },
      error: (err) => {
        this.preview = null;
        this.previewLoading = false;
        this.previewError = this.message(err, 'Az előnézet betöltése sikertelen.');
      }
    });
  }

  get canOpenSend(): boolean {
    return !!this.preview && this.preview.data.count > 0 && !this.preview.blocker && !!this.data?.configured;
  }

  openSend(modify: boolean): void {
    this.sendOpen = true;
    this.sendModify = modify;
    this.plan = null;
    this.sendPassword1 = this.sendPassword2 = this.phrase = '';
    this.acknowledge = false;
    this.sendError = null;
    this.sendDone = null;
    this.notice = null;
  }

  closeSend(): void {
    if (this.sendBusy) return;
    this.sendOpen = false;
    this.plan = null;
    this.stopTimer();
    if (this.sendDone) {
      this.load();
    }
  }

  prepareSend(): void {
    if (!this.sendPassword1 || this.sendBusy) return;

    this.sendBusy = true;
    this.sendError = null;

    this.admin.prepareNavSend({ date: this.date, modify: this.sendModify, password: this.sendPassword1 }).subscribe({
      next: (plan) => {
        this.sendBusy = false;
        this.plan = plan;
        this.sendPassword1 = '';
        this.secondsLeft = plan.expires_in;
        this.stopTimer();
        this.timer = window.setInterval(() => {
          this.secondsLeft = Math.max(0, this.secondsLeft - 1);
          if (this.secondsLeft === 0) this.stopTimer();
        }, 1000);
      },
      error: (err) => {
        this.sendBusy = false;
        this.sendError = this.message(err, 'Az előkészítés sikertelen.');
      }
    });
  }

  get phraseMatches(): boolean {
    return !!this.plan && this.phrase.trim() === this.plan.phrase;
  }

  get canApply(): boolean {
    return this.phraseMatches && this.acknowledge && !!this.sendPassword2 && !this.sendBusy && this.secondsLeft > 0;
  }

  applySend(): void {
    if (!this.plan || !this.canApply) return;

    this.sendBusy = true;
    this.sendError = null;

    this.admin.applyNavSend({ token: this.plan.token, phrase: this.phrase.trim(), password: this.sendPassword2, acknowledge: this.acknowledge }).subscribe({
      next: (r) => {
        this.sendBusy = false;
        this.stopTimer();
        this.plan = null;
        this.sendDone = `Az adatszolgáltatás beküldve. NAV azonosító: ${r.submission.nav_id}`;
        this.apply(r.state);
        this.loadPreview();
      },
      error: (err) => {
        this.sendBusy = false;
        this.sendError = this.message(err, 'A beküldés sikertelen.');
        this.sendPassword2 = '';
        const retryable = (err?.status === 422 || err?.status === 429)
          && !/lejárt|megváltoz|NAV elutasította|nincs/i.test(this.sendError ?? '');
        if (!retryable) {
          this.plan = null;
          this.stopTimer();
          this.load();
        }
      }
    });
  }

  get minutes(): number { return Math.floor(this.secondsLeft / 60); }
  get seconds(): number { return this.secondsLeft % 60; }

  statusLabel(s: string): string {
    return ({ SENT: 'Beküldve', FAILED: 'Sikertelen', PENDING: 'Folyamatban', REPLACED: 'Helyesbítve' } as Record<string, string>)[s] ?? s;
  }

  private message(err: any, fallback: string): string {
    const errors = err?.error?.errors;
    return (errors && (Object.values(errors) as string[][])[0]?.[0]) || err?.error?.message || fallback;
  }

  private stopTimer(): void {
    if (this.timer) {
      window.clearInterval(this.timer);
      this.timer = undefined;
    }
  }
}
