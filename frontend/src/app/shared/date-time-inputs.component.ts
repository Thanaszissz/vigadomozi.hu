import { Component, ElementRef, EventEmitter, Input, Output, ViewChild, forwardRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

const STYLES = `
  :host { display: block; position: relative; }
  .field { position: relative; }
  input.text {
    width: 100%; box-sizing: border-box; padding: 10px 12px; font: inherit; color: #2c1810; background: #fff;
    border: 2px solid #e0e0e0; border-radius: 8px; letter-spacing: 0.04em; font-variant-numeric: tabular-nums;
  }
  input.text.has-button { padding-right: 40px; }
  input.text:focus { outline: none; border-color: #d4a574; }
  input.text.invalid { border-color: #c0392b; }
  input.text:disabled { background: #f3f3f3; color: #888; }
  button.picker {
    position: absolute; right: 4px; top: 50%; transform: translateY(-50%); border: none; background: transparent;
    cursor: pointer; font-size: 18px; padding: 4px 8px; line-height: 1; width: auto !important; min-width: 0 !important;
    height: auto !important; margin: 0 !important; box-shadow: none;
  }
  button.picker:disabled { cursor: default; opacity: 0.4; }
  input.native { position: absolute; right: 8px; top: 100%; width: 1px; height: 1px; opacity: 0; pointer-events: none; border: 0; padding: 0; }
`;

/** Dátum mező éééé-hh-nn formátumban (a böngésző nyelvétől függetlenül), naptár-választóval. Értéke: "yyyy-MM-dd". */
@Component({
  selector: 'app-date-input',
  standalone: true,
  imports: [CommonModule],
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => DateInputComponent), multi: true }],
  template: `
    <div class="field">
      <input
        class="text has-button" type="text" inputmode="numeric" autocomplete="off" maxlength="10"
        placeholder="éééé-hh-nn" [value]="text" [disabled]="disabled" [class.invalid]="touched && text !== '' && !valid"
        (input)="onInput($any($event.target).value)" (blur)="onBlur()">
      <button type="button" class="picker" tabindex="-1" title="Naptár" [disabled]="disabled" (click)="openPicker()">📅</button>
      <input #native class="native" type="date" tabindex="-1" aria-hidden="true" [min]="min" [max]="max" [value]="valid ? text : ''" (change)="onPicked($any($event.target).value)">
    </div>
  `,
  styles: [STYLES]
})
export class DateInputComponent implements ControlValueAccessor {
  @Input() min: string | null = null;
  @Input() max: string | null = null;
  @Output() blurred = new EventEmitter<void>();
  @ViewChild('native') native!: ElementRef<HTMLInputElement>;

  text = '';
  disabled = false;
  touched = false;
  private onChange: (v: string) => void = () => {};
  private onTouched: () => void = () => {};

  get valid(): boolean {
    return DateInputComponent.isValid(this.text);
  }

  static isValid(value: string): boolean {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (!m) return false;
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    return d.getFullYear() === Number(m[1]) && d.getMonth() === Number(m[2]) - 1 && d.getDate() === Number(m[3]);
  }

  writeValue(value: string | null): void {
    this.text = value ? String(value).slice(0, 10) : '';
  }

  registerOnChange(fn: (v: string) => void): void { this.onChange = fn; }
  registerOnTouched(fn: () => void): void { this.onTouched = fn; }
  setDisabledState(isDisabled: boolean): void { this.disabled = isDisabled; }

  onInput(raw: string): void {
    // beillesztett, más elválasztójú dátumot is elfogad (pl. 2026.10.16.)
    const pasted = /^\s*(\d{4})\D+(\d{1,2})\D+(\d{1,2})/.exec(raw);
    let formatted: string;
    if (pasted) {
      formatted = `${pasted[1]}-${pasted[2].padStart(2, '0')}-${pasted[3].padStart(2, '0')}`;
    } else {
      const digits = raw.replace(/\D/g, '').slice(0, 8);
      formatted = digits.length > 6 ? `${digits.slice(0, 4)}-${digits.slice(4, 6)}-${digits.slice(6)}`
        : digits.length > 4 ? `${digits.slice(0, 4)}-${digits.slice(4)}` : digits;
    }
    this.text = formatted;
    this.onChange(this.valid ? this.text : '');
  }

  onBlur(): void {
    this.touched = true;
    this.onTouched();
    this.blurred.emit();
  }

  openPicker(): void {
    const el = this.native?.nativeElement;
    if (!el || this.disabled) return;
    try {
      el.showPicker();
    } catch {
      el.focus();
    }
  }

  onPicked(value: string): void {
    if (!value) return;
    this.text = value;
    this.touched = true;
    this.onChange(value);
  }
}

/** Időpont mező 24 órás HH:mm formátumban (AM/PM nélkül); gépelhető: 1800 → 18:00. Értéke: "HH:mm". */
@Component({
  selector: 'app-time-input',
  standalone: true,
  imports: [CommonModule],
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => TimeInputComponent), multi: true }],
  template: `
    <div class="field">
      <input
        class="text" type="text" inputmode="numeric" autocomplete="off" maxlength="5"
        placeholder="ÓÓ:PP" [value]="text" [disabled]="disabled" [class.invalid]="touched && text !== '' && !valid"
        (input)="onInput($any($event.target).value)" (blur)="onBlur()">
    </div>
  `,
  styles: [STYLES]
})
export class TimeInputComponent implements ControlValueAccessor {
  text = '';
  disabled = false;
  touched = false;
  private onChange: (v: string) => void = () => {};
  private onTouched: () => void = () => {};

  get valid(): boolean {
    const m = /^(\d{2}):(\d{2})$/.exec(this.text);
    return !!m && Number(m[1]) <= 23 && Number(m[2]) <= 59;
  }

  writeValue(value: string | null): void {
    this.text = value ? String(value).slice(0, 5) : '';
  }

  registerOnChange(fn: (v: string) => void): void { this.onChange = fn; }
  registerOnTouched(fn: () => void): void { this.onTouched = fn; }
  setDisabledState(isDisabled: boolean): void { this.disabled = isDisabled; }

  onInput(raw: string): void {
    const digits = raw.replace(/\D/g, '').slice(0, 4);
    this.text = digits.length > 2 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits;
    this.onChange(this.valid ? this.text : '');
  }

  onBlur(): void {
    // rövid bevitel kiegészítése: 9 → 09:00, 18 → 18:00, 930 → 09:30
    const digits = this.text.replace(/\D/g, '');
    if (digits.length > 0 && digits.length < 4) {
      const hours = digits.length === 3 ? digits.slice(0, 1) : digits;
      const minutes = digits.length === 3 ? digits.slice(1) : '00';
      this.text = `${hours.padStart(2, '0')}:${minutes.padEnd(2, '0')}`;
      this.onChange(this.valid ? this.text : '');
    }
    this.touched = true;
    this.onTouched();
  }
}
