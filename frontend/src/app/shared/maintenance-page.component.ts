import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

/** A nyilvános oldal helyett megjelenő karbantartási üzenet. */
@Component({
  selector: 'app-maintenance-page',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="maintenance" role="alert">
      <img src="assets/vigado_logo_beige.png" alt="Vigadó Jegy" class="logo" />
      <h1>🛠️ Karbantartás alatt vagyunk</h1>
      <p class="message">{{ message }}</p>
      <p class="hint">Az oldal automatikusan újratöltődik, amint a karbantartás véget ér.</p>
    </div>
  `,
  styles: [`
    .maintenance {
      min-height: 100vh;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 14px;
      padding: 32px 20px;
      background: #3d2817;
      color: #e8d5b7;
      text-align: center;
    }

    .logo { width: 220px; max-width: 70%; margin-bottom: 10px; }

    h1 { margin: 0; font-size: 30px; color: #fdf7ed; }

    .message { margin: 0; max-width: 560px; font-size: 18px; line-height: 1.5; }

    .hint { margin: 6px 0 0; font-size: 13px; color: #a08060; }
  `]
})
export class MaintenancePageComponent {
  @Input() message = 'Rendszerfrissítés miatt az oldal átmenetileg nem érhető el. Kérjük, nézzen vissza hamarosan!';
}
