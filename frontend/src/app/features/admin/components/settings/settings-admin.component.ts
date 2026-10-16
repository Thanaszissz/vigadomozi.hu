import { Component, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService, TicketNumberingPlan, TicketNumberingState } from '../../../../services/admin.service';
import { BackupsPanelComponent } from './backups-panel.component';
import { SimplePayPanelComponent } from './simplepay-panel.component';
import { NavPanelComponent } from './nav-panel.component';
import { SzamlazzPanelComponent } from './szamlazz-panel.component';
import { ExtraServicesPanelComponent } from './extra-services-panel.component';
import { TestModePanelComponent } from './test-mode-panel.component';

type Section = 'testmode' | 'numbering' | 'services' | 'simplepay' | 'szamlazz' | 'nav' | 'backups';

@Component({
  selector: 'app-settings-admin',
  standalone: true,
  imports: [CommonModule, FormsModule, BackupsPanelComponent, SimplePayPanelComponent, SzamlazzPanelComponent, NavPanelComponent, ExtraServicesPanelComponent, TestModePanelComponent],
  templateUrl: './settings-admin.component.html',
  styleUrls: ['./settings-admin.component.css']
})
export class SettingsAdminComponent implements OnInit, OnDestroy {
  section: Section = 'numbering';

  state: TicketNumberingState | null = null;
  loading = false;
  error: string | null = null;
  notice: string | null = null;

  // Előtag módosítása
  newPrefix = '';
  mode: 'continue' | 'reset' = 'continue';
  prefixPassword = '';
  prefixError: string | null = null;
  prefixBusy = false;

  // Számláló beállítása
  counterValue: number | null = null;
  counterPassword = '';
  counterError: string | null = null;
  counterBusy = false;

  // Megerősítő ablak (a módosítás 2. lépése)
  plan: TicketNumberingPlan | null = null;
  confirmPhrase = '';
  confirmPassword = '';
  confirmError: string | null = null;
  confirmBusy = false;
  secondsLeft = 0;
  private timer?: number;

  constructor(private admin: AdminService, private router: Router) {}

  ngOnInit(): void {
    this.load();
  }

  ngOnDestroy(): void {
    this.stopTimer();
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
  }

  load(): void {
    this.loading = true;
    this.error = null;
    this.admin.getTicketNumbering().subscribe({
      next: (state) => {
        this.state = state;
        this.loading = false;
      },
      error: () => {
        this.error = 'A beállítások betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  get formatExample(): string {
    return `${this.state?.prefix ?? ''}-000123`;
  }

  get normalizedPrefix(): string {
    return this.newPrefix.trim().toUpperCase();
  }

  get minCounter(): number {
    return this.state?.min_counter ?? 0;
  }

  get canPreparePrefix(): boolean {
    return !!this.normalizedPrefix && !!this.prefixPassword && !this.prefixBusy;
  }

  get canPrepareCounter(): boolean {
    return this.counterValue !== null && `${this.counterValue}` !== '' && !!this.counterPassword && !this.counterBusy;
  }

  get phraseMatches(): boolean {
    return !!this.plan && this.confirmPhrase.trim() === this.plan.phrase;
  }

  get canApply(): boolean {
    return this.phraseMatches && !!this.confirmPassword && !this.confirmBusy && this.secondsLeft > 0;
  }

  useSuggestion(): void {
    const suggestion = this.state?.previous_prefix_last;
    if (suggestion) {
      this.counterValue = suggestion.seq;
    }
  }

  // 1. lépés: előtagváltás előkészítése
  preparePrefix(): void {
    this.prefixError = null;
    this.prefixBusy = true;

    this.admin.prepareTicketNumbering({
      action: 'prefix',
      prefix: this.normalizedPrefix,
      mode: this.mode,
      password: this.prefixPassword
    }).subscribe({
      next: (plan) => {
        this.prefixBusy = false;
        this.confirmPassword = this.prefixPassword;
        this.openConfirm(plan);
      },
      error: (err) => {
        this.prefixBusy = false;
        this.prefixError = err?.error?.message || 'A kérés nem teljesíthető.';
      }
    });
  }

  // 1. lépés: számláló beállításának előkészítése
  prepareCounter(): void {
    this.counterError = null;
    this.counterBusy = true;

    this.admin.prepareTicketNumbering({
      action: 'counter',
      value: this.counterValue ?? '',
      password: this.counterPassword
    }).subscribe({
      next: (plan) => {
        this.counterBusy = false;
        this.confirmPassword = this.counterPassword;
        this.openConfirm(plan);
      },
      error: (err) => {
        this.counterBusy = false;
        this.counterError = err?.error?.message || 'A kérés nem teljesíthető.';
      }
    });
  }

  // 2. lépés: végrehajtás a megerősítő szöveggel
  apply(): void {
    if (!this.plan || !this.canApply) return;

    this.confirmBusy = true;
    this.confirmError = null;

    this.admin.applyTicketNumbering({
      token: this.plan.token,
      phrase: this.confirmPhrase.trim(),
      password: this.confirmPassword
    }).subscribe({
      next: (result) => {
        this.confirmBusy = false;
        this.state = result.state;
        this.notice = result.message;
        this.clearForms();
        this.closeConfirm();
      },
      error: (err) => {
        this.confirmBusy = false;
        this.confirmError = err?.error?.message || 'A módosítás sikertelen.';
        // Hibás jelszónál ugyanaz a kód még használható, minden más hibánál új előkészítés kell
        if (err?.status !== 422 || !/jelszó/i.test(this.confirmError ?? '')) {
          this.closeConfirmKeepingError();
        }
      }
    });
  }

  cancelConfirm(): void {
    this.closeConfirm();
  }

  private openConfirm(plan: TicketNumberingPlan): void {
    this.plan = plan;
    this.confirmPhrase = '';
    this.confirmError = null;
    this.notice = null;
    this.secondsLeft = plan.expires_in;
    this.stopTimer();
    this.timer = window.setInterval(() => {
      this.secondsLeft = Math.max(0, this.secondsLeft - 1);
      if (this.secondsLeft === 0) {
        this.stopTimer();
      }
    }, 1000);
  }

  private closeConfirm(): void {
    this.plan = null;
    this.confirmPhrase = '';
    this.confirmPassword = '';
    this.confirmError = null;
    this.stopTimer();
  }

  private closeConfirmKeepingError(): void {
    const message = this.confirmError;
    const action = this.plan?.action;
    this.closeConfirm();
    this.error = message;
    if (action === 'prefix') this.prefixError = message; else this.counterError = message;
    this.load();
  }

  private clearForms(): void {
    this.newPrefix = '';
    this.mode = 'continue';
    this.prefixPassword = '';
    this.prefixError = null;
    this.counterValue = null;
    this.counterPassword = '';
    this.counterError = null;
    this.error = null;
  }

  private stopTimer(): void {
    if (this.timer) {
      window.clearInterval(this.timer);
      this.timer = undefined;
    }
  }
}
