import { Component } from '@angular/core';
import { ActivatedRoute } from '@angular/router';

@Component({
  selector: 'app-info-page',
  standalone: true,
  template: `
    <div class="page">
      <section class="content">
        <h1>{{ title }}</h1>
      </section>
    </div>
  `,
  styles: [`
    :host {
      display: block;
      background: #3d2817;
      color: #e8d5b7;
      min-height: 60vh;
    }

    .page {
      max-width: 1200px;
      margin: 0 auto;
      padding: 20px 40px;
    }

    h1 {
      color: #e8d5b7;
      font-size: 32px;
      margin: 20px 0;
    }
  `]
})
export class InfoPageComponent {
  title: string;

  constructor(route: ActivatedRoute) {
    this.title = route.snapshot.data['title'] ?? '';
  }
}
