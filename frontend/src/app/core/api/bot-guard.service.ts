import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { environment } from 'src/environments/environment';

export interface BotProof {
  token: string;
  solution: string;
}

interface Challenge {
  token: string;
  difficulty: number;
}

const MAX_AGE_MS = 25 * 60 * 1000;
const MIN_AGE_MS = 1500;
const BATCH = 64;

/**
 * Láthatatlan robotvédelem: a háttérben megold egy szerver által aláírt számítási feladatot (proof-of-work).
 * A vásárló semmit nem lát belőle; a foglalási kérések ezt a bizonyítékot küldik a szervernek.
 */
@Injectable({ providedIn: 'root' })
export class BotGuardService {
  private pending: { promise: Promise<BotProof>; startedAt: number } | null = null;

  constructor(private http: HttpClient) {}

  /** Elindítja a feladat megoldását a háttérben (hívható többször is). */
  prepare(): void {
    if (this.pending && Date.now() - this.pending.startedAt < MAX_AGE_MS) return;
    const promise = this.solve();
    promise.catch(() => { if (this.pending?.promise === promise) this.pending = null; });
    this.pending = { promise, startedAt: Date.now() };
  }

  /** A következő kéréshez használható bizonyíték; egyszer használatos, ezért a következőt rögtön elindítjuk. */
  async proof(): Promise<BotProof> {
    this.prepare();
    const current = this.pending!.promise;
    this.pending = null;
    try {
      return await current;
    } finally {
      this.prepare();
    }
  }

  private async solve(): Promise<BotProof> {
    const challenge = await firstValueFrom(this.http.get<Challenge>(`${environment.apiUrl}/bot-challenge`));
    const fetchedAt = Date.now();
    const encoder = new TextEncoder();
    const subtle = crypto.subtle;

    let counter = 0;
    for (;;) {
      const candidates = Array.from({ length: BATCH }, (_, i) => counter + i);
      const hashes = await Promise.all(
        candidates.map(n => subtle.digest('SHA-256', encoder.encode(`${challenge.token}:${n}`)))
      );
      for (let i = 0; i < hashes.length; i++) {
        if (this.hasLeadingZeroBits(new Uint8Array(hashes[i]), challenge.difficulty)) {
          // A szerver a túl gyorsan visszaküldött megoldást gyanúsnak tartja
          const wait = MIN_AGE_MS - (Date.now() - fetchedAt);
          if (wait > 0) await new Promise(r => setTimeout(r, wait));
          return { token: challenge.token, solution: String(candidates[i]) };
        }
      }
      counter += BATCH;
      if ((counter / BATCH) % 16 === 0) await new Promise(r => setTimeout(r));
    }
  }

  private hasLeadingZeroBits(hash: Uint8Array, bits: number): boolean {
    for (let i = 0; i < bits; i++) {
      if ((hash[i >> 3] >> (7 - (i & 7))) & 1) return false;
    }
    return true;
  }
}
