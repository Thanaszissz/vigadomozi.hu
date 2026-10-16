import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AdminService } from '../../../../services/admin.service';

@Component({
  selector: 'app-test-mode-panel',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './test-mode-panel.component.html',
  styleUrls: ['./test-mode-panel.component.css']
})
export class TestModePanelComponent implements OnInit {
  testMode = false;
  loading = false;
  saving = false;
  error: string | null = null;
  notice: string | null = null;

  constructor(private admin: AdminService) {}

  ngOnInit(): void {
    this.loading = true;
    this.admin.getTestMode().subscribe({
      next: (data) => {
        this.testMode = data.test_mode;
        this.loading = false;
      },
      error: () => {
        this.error = 'A beállítás betöltése sikertelen.';
        this.loading = false;
      }
    });
  }

  toggle(): void {
    const wanted = this.testMode;
    this.saving = true;
    this.error = null;
    this.notice = null;

    this.admin.saveTestMode(wanted).subscribe({
      next: (data) => {
        this.testMode = data.test_mode;
        this.notice = data.test_mode
          ? 'A teszt üzem jelzés be van kapcsolva: a nyilvános oldalak tetején megjelenik a figyelmeztetés.'
          : 'A teszt üzem jelzés ki van kapcsolva.';
        this.saving = false;
      },
      error: () => {
        this.testMode = !wanted;
        this.error = 'A mentés nem sikerült. Próbáld újra.';
        this.saving = false;
      }
    });
  }
}
