import { Injectable } from '@angular/core';

const STORAGE_KEY = 'vigado_kiosk';

/** A kiosk állomásról indított munkamenetben a fizetési folyamat oldalai is kiosk megjelenéssel (fejléc és lábléc nélkül) nyílnak meg. */
const FLOW_PREFIXES = ['/showtimes', '/checkout', '/hold-summary', '/success'];

@Injectable({ providedIn: 'root' })
export class KioskService {
  private flag = false;

  constructor() {
    // A SimplePay-ből a vásárló ugyanabban a lapban tér vissza, ezért a kiosk mód a munkamenetben megmarad
    const path = window.location.pathname;
    if (path === '/kiosk' || path.startsWith('/kiosk/')) {
      this.activate();
    } else if (FLOW_PREFIXES.some(prefix => path.startsWith(prefix))) {
      this.flag = this.read();
    } else {
      this.deactivate();
    }
  }

  get active(): boolean {
    return this.flag;
  }

  get homeLink(): string {
    return this.flag ? '/kiosk' : '/movies';
  }

  activate(): void {
    this.flag = true;
    try {
      sessionStorage.setItem(STORAGE_KEY, '1');
    } catch {
      // a kiosk mód enélkül is működik, csak a fizetésből visszatérve nem marad meg
    }
  }

  private deactivate(): void {
    this.flag = false;
    try {
      sessionStorage.removeItem(STORAGE_KEY);
    } catch {
      // nincs teendő
    }
  }

  private read(): boolean {
    try {
      return sessionStorage.getItem(STORAGE_KEY) === '1';
    } catch {
      return false;
    }
  }
}
