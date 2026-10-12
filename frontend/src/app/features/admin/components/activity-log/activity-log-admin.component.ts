import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { ActivityLogEntry, ActivityLogPage, ActivityMeta, AdminService } from '../../../../services/admin.service';

@Component({
  selector: 'app-activity-log-admin',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './activity-log-admin.component.html',
  styleUrls: ['./activity-log-admin.component.css']
})
export class ActivityLogAdminComponent implements OnInit {
  meta: ActivityMeta = { categories: [], actions: [], users: [] };

  from = this.dayKey(new Date());
  to = this.dayKey(new Date());
  userId = '';
  category = '';
  action = '';
  query = '';
  page = 1;

  result: ActivityLogPage | null = null;
  loading = false;
  error: string | null = null;
  expandedId: number | null = null;

  constructor(private admin: AdminService, private router: Router) {}

  ngOnInit(): void {
    this.admin.getActivityMeta().subscribe({
      next: (meta) => { this.meta = meta; },
      error: () => {}
    });
    this.load();
  }

  goBack(): void {
    this.router.navigate(['/admin/dashboard']);
  }

  get actionOptions(): ActivityMeta['actions'] {
    return this.category ? this.meta.actions.filter(a => a.category === this.category) : this.meta.actions;
  }

  onCategoryChange(): void {
    if (this.action && !this.actionOptions.some(a => a.key === this.action)) {
      this.action = '';
    }
    this.load();
  }

  load(page = 1): void {
    this.page = page;
    this.loading = true;
    this.error = null;
    this.expandedId = null;

    this.admin.getActivityLogs({
      from: this.from,
      to: this.to,
      user_id: this.userId,
      category: this.category,
      action: this.action,
      q: this.query.trim(),
      page
    }).subscribe({
      next: (result) => {
        this.result = result;
        this.loading = false;
      },
      error: () => {
        this.error = 'A napló betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  setRange(days: number): void {
    const end = new Date();
    const start = new Date();
    start.setDate(start.getDate() - days);
    this.from = this.dayKey(start);
    this.to = this.dayKey(end);
    this.load();
  }

  clearRange(): void {
    this.from = '';
    this.to = '';
    this.load();
  }

  reset(): void {
    this.from = this.dayKey(new Date());
    this.to = this.from;
    this.userId = '';
    this.category = '';
    this.action = '';
    this.query = '';
    this.load();
  }

  toggle(entry: ActivityLogEntry): void {
    this.expandedId = this.expandedId === entry.id ? null : entry.id;
  }

  hasDetails(entry: ActivityLogEntry): boolean {
    return !!entry.properties && Object.keys(entry.properties).length > 0 || !!entry.user_agent;
  }

  categoryLabel(key: string): string {
    return this.meta.categories.find(c => c.key === key)?.label ?? key;
  }

  actionLabel(key: string): string {
    return this.meta.actions.find(a => a.key === key)?.label ?? key;
  }

  actor(entry: ActivityLogEntry): string {
    return entry.user_name || entry.user_email || 'Ismeretlen';
  }

  // A módosítások "mező: régi → új" soronként
  changeRows(entry: ActivityLogEntry): Array<{ field: string; old: string; next: string; note: string }> {
    const changes = entry.properties?.changes;
    if (!changes) return [];

    return Object.entries(changes).map(([field, value]: [string, any]) =>
      typeof value === 'object' && value !== null
        ? { field, old: this.show(value.old), next: this.show(value.new), note: '' }
        : { field, old: '', next: '', note: String(value) }
    );
  }

  // Egyéb tulajdonságok (felvétel/törlés pillanatképe, jegyszámok stb.) kulcs-érték párokban
  extraRows(entry: ActivityLogEntry): Array<{ key: string; value: string }> {
    const props = entry.properties;
    if (!props) return [];

    const source = props.attributes ?? Object.fromEntries(Object.entries(props).filter(([k]) => k !== 'changes'));
    return Object.entries(source).map(([key, value]) => ({ key, value: this.show(value) }));
  }

  private show(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (Array.isArray(value)) return value.join(', ');
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
  }

  private dayKey(d: Date): string {
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }
}
