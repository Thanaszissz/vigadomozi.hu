import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ApiService } from '../core/api/api.service';

/** Teszt üzem figyelmeztetés: az admin Beállításokban kapcsolható, a nyilvános oldalak tetején jelenik meg. */
@Component({
  selector: 'app-test-mode-banner',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="test-banner" *ngIf="active" role="alert">
      ⚠️ Teszt üzem - Ne vásároljon, nem éles az oldal még!
    </div>
  `,
  styles: [`
    .test-banner {
      position: sticky;
      top: 0;
      z-index: 2000;
      background: #c62828;
      color: #fff;
      text-align: center;
      font-weight: 700;
      font-size: 16px;
      padding: 10px 14px;
      letter-spacing: 0.2px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
    }

    @media (max-width: 600px) {
      .test-banner { font-size: 14px; padding: 8px 10px; }
    }
  `]
})
export class TestModeBannerComponent implements OnInit {
  active = false;

  constructor(private api: ApiService) {}

  ngOnInit(): void {
    this.api.getSiteStatus().subscribe({
      next: (status) => (this.active = !!status.test_mode),
      error: () => (this.active = false)
    });
  }
}
