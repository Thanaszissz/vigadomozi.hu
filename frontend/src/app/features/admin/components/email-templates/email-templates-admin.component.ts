import { Component, ElementRef, OnInit, ViewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { AdminService, EmailTemplate, EmailTemplatesResponse } from '../../../../services/admin.service';

@Component({
  selector: 'app-email-templates-admin',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './email-templates-admin.component.html',
  styleUrls: ['./email-templates-admin.component.css']
})
export class EmailTemplatesAdminComponent implements OnInit {
  @ViewChild('bodyBox') bodyBox?: ElementRef<HTMLTextAreaElement>;

  data: EmailTemplatesResponse | null = null;
  selectedKey = '';
  form = { subject: '', body: '', is_active: true };
  private saved = { subject: '', body: '', is_active: true };

  loading = false;
  saving = false;
  error: string | null = null;
  notice: string | null = null;

  previewHtml: SafeHtml | null = null;
  previewSubject = '';
  previewing = false;

  testTo = '';
  testing = false;

  constructor(private admin: AdminService, private router: Router, private sanitizer: DomSanitizer) {}

  ngOnInit(): void {
    this.loading = true;
    this.admin.getEmailTemplates().subscribe({
      next: (data) => {
        this.data = data;
        this.loading = false;
        if (data.templates.length) {
          this.apply(data.templates[0]);
        }
      },
      error: () => {
        this.error = 'Az e-mail sablonok betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  goBack(): void {
    if (this.confirmDiscard()) {
      this.router.navigate(['/admin/dashboard']);
    }
  }

  get selected(): EmailTemplate | undefined {
    return this.data?.templates.find(t => t.key === this.selectedKey);
  }

  get dirty(): boolean {
    return this.form.subject !== this.saved.subject
      || this.form.body !== this.saved.body
      || this.form.is_active !== this.saved.is_active;
  }

  select(template: EmailTemplate): void {
    if (template.key === this.selectedKey || !this.confirmDiscard()) return;
    this.apply(template);
  }

  save(): void {
    if (!this.selected || this.saving) return;

    this.saving = true;
    this.error = null;
    this.notice = null;

    this.admin.saveEmailTemplate(this.selectedKey, { ...this.form }).subscribe({
      next: (template) => {
        this.replace(template);
        this.saving = false;
        this.notice = 'A sablon mentve.';
      },
      error: (err) => {
        this.saving = false;
        this.error = err?.error?.message || 'A mentés sikertelen.';
      }
    });
  }

  reset(): void {
    if (!this.selected) return;
    if (!confirm('Biztosan visszaállítod az alapértelmezett szöveget? A saját módosításaid elvesznek.')) return;

    this.error = null;
    this.notice = null;
    this.admin.resetEmailTemplate(this.selectedKey).subscribe({
      next: (template) => {
        this.replace(template);
        this.apply(template);
        this.notice = 'Az alapértelmezett szöveg visszaállítva.';
      },
      error: () => { this.error = 'A visszaállítás sikertelen.'; }
    });
  }

  preview(): void {
    if (!this.selected) return;

    this.previewing = true;
    this.error = null;
    this.admin.previewEmailTemplate(this.selectedKey, { subject: this.form.subject, body: this.form.body }).subscribe({
      next: (result) => {
        this.previewSubject = result.subject;
        this.previewHtml = this.sanitizer.bypassSecurityTrustHtml(result.html);
        this.previewing = false;
      },
      error: (err) => {
        this.previewing = false;
        this.error = err?.error?.message || 'Az előnézet nem készíthető el.';
      }
    });
  }

  closePreview(): void {
    this.previewHtml = null;
  }

  sendTest(): void {
    if (!this.selected || this.testing) return;
    if (!this.testTo.trim()) {
      this.error = 'Add meg a teszt e-mail címzettjét.';
      return;
    }

    this.testing = true;
    this.error = null;
    this.notice = null;

    this.admin.testEmailTemplate(this.selectedKey, { subject: this.form.subject, body: this.form.body, to: this.testTo.trim() }).subscribe({
      next: (result) => {
        this.testing = false;
        this.notice = result.delivers
          ? `A teszt e-mail elküldve ide: ${result.to}`
          : 'A rendszer jelenleg csak naplózza a leveleket, ezért a teszt nem érkezik meg a címzetthez (lásd a figyelmeztetést fent).';
      },
      error: (err) => {
        this.testing = false;
        this.error = err?.error?.message || 'A teszt e-mail küldése sikertelen.';
      }
    });
  }

  // A változót a kurzor helyére szúrja be a szövegbe
  insert(key: string): void {
    const token = `{{${key}}}`;
    const box = this.bodyBox?.nativeElement;

    if (!box) {
      this.form.body += token;
      return;
    }

    const start = box.selectionStart ?? this.form.body.length;
    const end = box.selectionEnd ?? start;
    this.form.body = this.form.body.slice(0, start) + token + this.form.body.slice(end);

    setTimeout(() => {
      box.focus();
      box.setSelectionRange(start + token.length, start + token.length);
    });
  }

  private apply(template: EmailTemplate): void {
    this.selectedKey = template.key;
    this.form = { subject: template.subject, body: template.body, is_active: template.is_active };
    this.saved = { ...this.form };
    this.previewHtml = null;
    this.error = null;
    this.notice = null;
  }

  private replace(template: EmailTemplate): void {
    if (!this.data) return;
    this.data.templates = this.data.templates.map(t => t.key === template.key ? template : t);
    this.saved = { subject: template.subject, body: template.body, is_active: template.is_active };
  }

  private confirmDiscard(): boolean {
    return !this.dirty || confirm('Vannak nem mentett módosítások. Biztosan elveted őket?');
  }
}
