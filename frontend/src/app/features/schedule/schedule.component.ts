import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ApiService, Showtime } from '../../core/api/api.service';

interface ShowtimeGroup {
  key: string;
  label: string;
  dayLabel: string;
  showtimes: Showtime[];
}

@Component({
  selector: 'app-schedule',
  standalone: true,
  imports: [CommonModule, RouterLink],
  templateUrl: './schedule.component.html',
  styleUrls: ['./schedule.component.css']
})
export class ScheduleComponent implements OnInit {
  loading = true;
  error: string | null = null;
  groups: ShowtimeGroup[] = [];

  constructor(private apiService: ApiService) {}

  ngOnInit(): void {
    this.apiService.getShowtimes().subscribe({
      next: (showtimes) => {
        const sorted = [...showtimes].sort(
          (a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime()
        );

        const dayNames = ['Vasárnap', 'Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat'];
        const monthNames = ['Január', 'Február', 'Március', 'Április', 'Május', 'Június', 'Július', 'Augusztus', 'Szeptember', 'Október', 'November', 'December'];

        const map = new Map<string, ShowtimeGroup>();

        for (const showtime of sorted) {
          const date = new Date(showtime.starts_at);
          date.setHours(0, 0, 0, 0);
          const key = `${date.getFullYear()}-${date.getMonth() + 1}-${date.getDate()}`;

          if (!map.has(key)) {
            const label = `${monthNames[date.getMonth()]} ${date.getDate()}.`;
            const dayLabel = dayNames[date.getDay()];
            map.set(key, { key, label, dayLabel, showtimes: [] });
          }
          map.get(key)!.showtimes.push(showtime);
        }

        this.groups = Array.from(map.values());
        this.loading = false;
      },
      error: () => {
        this.error = 'A műsorlista betöltése sikertelen.';
        this.loading = false;
      }
    });
  }
}
