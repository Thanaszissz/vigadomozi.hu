import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AdminService } from '../../../../services/admin.service';

@Component({
  selector: 'app-maintenance-panel',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './maintenance-panel.component.html',
  styleUrls: ['./maintenance-panel.component.css']
})
export class MaintenancePanelComponent implements OnInit {
  maintenance = false;
  message = '';
  defaultMessage = '';
  loading = false;
  saving = false;
  error: string | null = null;
  notice: string | null = null;

  constructor(private admin: AdminService) {}

  ngOnInit(): void {
    this.loading = true;
    this.admin.getMaintenance().subscribe({
      next: (data) => {
        this.apply(data);
        this.loading = false;
      },
      error: () => {
        this.error = 'A beállítás betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  toggle(): void {
    const wanted = this.maintenance;
    if (wanted && !confirm('Biztosan bekapcsolod a karbantartás módot? A nyilvános oldal azonnal elérhetetlenné válik a látogatók számára.')) {
      this.maintenance = false;
      return;
    }
    this.save(wanted);
  }

  saveMessage(): void {
    this.save(this.maintenance);
  }

  private save(maintenance: boolean): void {
    this.saving = true;
    this.error = null;
    this.notice = null;

    this.admin.saveMaintenance(maintenance, this.message).subscribe({
      next: (data) => {
        this.apply(data);
        this.notice = data.maintenance
          ? 'A karbantartás mód be van kapcsolva: a nyilvános oldal nem érhető el, az admin felület használható.'
          : 'A karbantartás mód ki van kapcsolva, a nyilvános oldal újra elérhető.';
        this.saving = false;
      },
      error: () => {
        this.maintenance = !maintenance;
        this.error = 'A mentés nem sikerült. Próbáld újra.';
        this.saving = false;
      }
    });
  }

  private apply(data: { maintenance: boolean; message: string; default_message: string }): void {
    this.maintenance = data.maintenance;
    this.message = data.message;
    this.defaultMessage = data.default_message;
  }
}
