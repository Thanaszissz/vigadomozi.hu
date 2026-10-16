import { Component, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TimeInputComponent } from '../../../../shared/date-time-inputs.component';
import { Router } from '@angular/router';
import { AdminService, BackupItem, BackupOverview, BackupRestorePlan } from '../../../../services/admin.service';
import { AuthService } from '../../../../services/auth.service';

@Component({
  selector: 'app-backups-panel',
  standalone: true,
  imports: [CommonModule, FormsModule, TimeInputComponent],
  templateUrl: './backups-panel.component.html',
  styleUrls: ['./backups-panel.component.css']
})
export class BackupsPanelComponent implements OnInit, OnDestroy {
  data: BackupOverview | null = null;
  loading = false;
  error: string | null = null;
  notice: string | null = null;
  actionError: string | null = null;

  creating = false;
  busy = false;

  form = { enabled: false, frequency: 'daily', time: '03:00', weekday: 1, keep: 14 };
  scheduleBusy = false;
  scheduleError: string | null = null;

  readonly weekdays = [
    { value: 1, label: 'Hétfő' }, { value: 2, label: 'Kedd' }, { value: 3, label: 'Szerda' },
    { value: 4, label: 'Csütörtök' }, { value: 5, label: 'Péntek' }, { value: 6, label: 'Szombat' }, { value: 7, label: 'Vasárnap' }
  ];

  // Visszatöltés
  restoreTarget: BackupItem | null = null;
  plan: BackupRestorePlan | null = null;
  password1 = '';
  password2 = '';
  phrase = '';
  acknowledge = false;
  restoreBusy = false;
  restoreError: string | null = null;
  restored: string | null = null;
  secondsLeft = 0;
  private timer?: number;
  private redirectTimer?: number;

  constructor(private admin: AdminService, private auth: AuthService, private router: Router) {}

  ngOnInit(): void {
    this.load();
  }

  ngOnDestroy(): void {
    this.stopTimer();
    if (this.redirectTimer) window.clearTimeout(this.redirectTimer);
  }

  get lastBackup(): BackupItem | null {
    return this.data?.backups.find(b => b.valid !== false) ?? null;
  }

  get minutes(): number {
    return Math.floor(this.secondsLeft / 60);
  }

  get seconds(): number {
    return this.secondsLeft % 60;
  }

  get phraseMatches(): boolean {
    return !!this.plan && this.phrase.trim() === this.plan.phrase;
  }

  get canApply(): boolean {
    return this.phraseMatches && this.acknowledge && !!this.password2 && !this.restoreBusy && this.secondsLeft > 0;
  }

  load(): void {
    this.loading = true;
    this.error = null;

    this.admin.getBackups().subscribe({
      next: (data) => {
        this.data = data;
        this.form = {
          enabled: data.schedule.enabled,
          frequency: data.schedule.frequency,
          time: data.schedule.time,
          weekday: data.schedule.weekday,
          keep: data.schedule.keep
        };
        this.loading = false;
      },
      error: () => {
        this.error = 'A mentések betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  typeLabel(type: string): string {
    return ({ manual: 'Kézi', auto: 'Automatikus', prerestore: 'Visszatöltés előtti', upload: 'Feltöltött' } as Record<string, string>)[type] ?? type;
  }

  size(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) return '—';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
  }

  // ---- Mentés, letöltés, feltöltés, törlés ----

  createNow(): void {
    this.busy = this.creating = true;
    this.actionError = null;
    this.notice = null;

    this.admin.createBackup().subscribe({
      next: (b) => {
        this.busy = this.creating = false;
        this.notice = `A mentés elkészült (${b.total_rows} sor, ${this.size(b.size)}).`;
        this.load();
      },
      error: (err) => {
        this.busy = this.creating = false;
        this.actionError = err?.error?.message || 'A mentés sikertelen.';
      }
    });
  }

  download(b: BackupItem): void {
    this.actionError = null;
    this.admin.downloadBackup(b.id).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${b.id}.ndjson.gz`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
        this.load();
      },
      error: () => (this.actionError = 'A letöltés sikertelen.')
    });
  }

  onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;

    this.busy = true;
    this.actionError = null;
    this.notice = null;

    this.admin.uploadBackup(file).subscribe({
      next: (b) => {
        this.busy = false;
        this.notice = `A mentésfájl feltöltve és ellenőrizve (${b.total_rows} sor). A „Visszatöltés” gombbal tölthető vissza.`;
        this.load();
      },
      error: (err) => {
        this.busy = false;
        this.actionError = err?.error?.errors?.file?.[0] || err?.error?.message || 'A feltöltés sikertelen.';
      }
    });
  }

  remove(b: BackupItem): void {
    if (!confirm(`Biztosan törlöd ezt a mentést?\n${b.id}\nA törlés nem vonható vissza.`)) return;

    this.busy = true;
    this.actionError = null;
    this.admin.deleteBackup(b.id).subscribe({
      next: () => {
        this.busy = false;
        this.notice = 'A mentés törölve.';
        this.load();
      },
      error: (err) => {
        this.busy = false;
        this.actionError = err?.error?.message || 'A törlés sikertelen.';
      }
    });
  }

  // ---- Ütemezés ----

  saveSchedule(): void {
    this.scheduleBusy = true;
    this.scheduleError = null;
    this.notice = null;

    this.admin.saveBackupSchedule({
      enabled: this.form.enabled,
      frequency: this.form.frequency,
      time: this.form.time || '03:00',
      weekday: Number(this.form.weekday),
      keep: Number(this.form.keep)
    }).subscribe({
      next: (schedule) => {
        this.scheduleBusy = false;
        this.notice = schedule.enabled ? `Az automatikus mentés beállítva: ${schedule.description}.` : 'Az automatikus mentés kikapcsolva.';
        this.load();
      },
      error: (err) => {
        this.scheduleBusy = false;
        const errors = err?.error?.errors;
        this.scheduleError = (errors && (Object.values(errors) as string[][])[0]?.[0]) || err?.error?.message || 'Az ütemezés mentése sikertelen.';
      }
    });
  }

  // ---- Visszatöltés ----

  openRestore(b: BackupItem): void {
    this.restoreTarget = b;
    this.plan = null;
    this.password1 = '';
    this.password2 = '';
    this.phrase = '';
    this.acknowledge = false;
    this.restoreError = null;
    this.restored = null;
    this.notice = null;
  }

  closeRestore(): void {
    if (this.restoreBusy) return;
    this.restoreTarget = null;
    this.plan = null;
    this.password1 = this.password2 = this.phrase = '';
    this.acknowledge = false;
    this.restoreError = null;
    this.stopTimer();
  }

  prepareRestore(): void {
    if (!this.restoreTarget || !this.password1 || this.restoreBusy) return;

    this.restoreBusy = true;
    this.restoreError = null;

    this.admin.prepareBackupRestore(this.restoreTarget.id, this.password1).subscribe({
      next: (plan) => {
        this.restoreBusy = false;
        this.plan = plan;
        this.password1 = '';
        this.secondsLeft = plan.expires_in;
        this.stopTimer();
        this.timer = window.setInterval(() => {
          this.secondsLeft = Math.max(0, this.secondsLeft - 1);
          if (this.secondsLeft === 0) this.stopTimer();
        }, 1000);
      },
      error: (err) => {
        this.restoreBusy = false;
        this.restoreError = err?.error?.message || 'A mentés ellenőrzése sikertelen.';
      }
    });
  }

  applyRestore(): void {
    if (!this.restoreTarget || !this.plan || !this.canApply) return;

    this.restoreBusy = true;
    this.restoreError = null;

    this.admin.applyBackupRestore(this.restoreTarget.id, {
      token: this.plan.token,
      phrase: this.phrase.trim(),
      password: this.password2,
      acknowledge: this.acknowledge
    }).subscribe({
      next: (result) => {
        this.restoreBusy = false;
        this.stopTimer();
        this.restored = result.message;
        // A visszatöltés minden bejelentkezést érvénytelenít
        this.auth.clearSession();
        this.redirectTimer = window.setTimeout(() => this.goLogin(), 4000);
      },
      error: (err) => {
        this.restoreBusy = false;
        const errors = err?.error?.errors;
        this.restoreError = (errors && (Object.values(errors) as string[][])[0]?.[0]) || err?.error?.message || 'A visszatöltés sikertelen.';
        // Hibás jelszónál vagy szövegnél ugyanaz a kód még használható, minden más hibánál új előkészítés kell
        const retryable = (err?.status === 422 || err?.status === 429)
          && !/lejárt|megváltozott|sérült|nem tölthető|ellenőrzőösszeg|folyamatban/i.test(this.restoreError ?? '');
        if (!retryable) {
          this.plan = null;
          this.stopTimer();
        }
        this.password2 = '';
      }
    });
  }

  goLogin(): void {
    if (this.redirectTimer) window.clearTimeout(this.redirectTimer);
    this.router.navigate(['/admin/login']);
  }

  private stopTimer(): void {
    if (this.timer) {
      window.clearInterval(this.timer);
      this.timer = undefined;
    }
  }
}
