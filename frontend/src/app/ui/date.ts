import { ChangeDetectionStrategy, Component, booleanAttribute, computed, forwardRef, input, model, signal } from '@angular/core';
import { ControlValueAccessor, FormsModule, NG_VALUE_ACCESSOR } from '@angular/forms';
import { DatePicker } from 'primeng/datepicker';

const pad = (value: number): string => String(value).padStart(2, '0');
const toIso = (date: Date | null): string => (date ? `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` : '');

function toDate(value: string | null | undefined): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value ?? '');

  return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
}

/**
 * Date field (PrimeNG DatePicker). The API speaks ISO dates (`2026-10-04`), people read `04/10/2026`: the
 * value of the control stays the ISO string, the calendar and the typed text follow the language.
 */
@Component({
  selector: 'dw-date',
  imports: [FormsModule, DatePicker],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => DateComponent), multi: true }],
  template: `
    <p-datepicker [ngModel]="date()" (ngModelChange)="set($event)" [minDate]="minDate()" [maxDate]="maxDate()" [placeholder]="placeholder()" [disabled]="off()"
      [showIcon]="true" iconDisplay="input" [showButtonBar]="true" [fluid]="true" [ariaLabel]="ariaLabel() || placeholder()" (onBlur)="touched()" />
  `,
  styles: ':host { display: block; min-width: 0; }',
  host: { class: 'dw-date', '[class.has-value]': 'value() !== ""' },
})
export class DateComponent implements ControlValueAccessor {
  readonly value = model('');
  readonly min = input<string | null>(null);
  readonly max = input<string | null>(null);
  readonly placeholder = input('');
  readonly disabled = input(false, { transform: booleanAttribute });
  readonly ariaLabel = input('');

  protected readonly date = computed(() => toDate(this.value()));
  protected readonly minDate = computed(() => toDate(this.min()));
  protected readonly maxDate = computed(() => toDate(this.max()));

  private readonly disabledByForm = signal(false);
  protected readonly off = computed(() => this.disabled() || this.disabledByForm());

  private onChange: (value: string) => void = () => undefined;
  protected touched: () => void = () => undefined;

  protected set(date: Date | null): void {
    const value = toIso(date instanceof Date && !Number.isNaN(date.getTime()) ? date : null);
    this.value.set(value);
    this.onChange(value);
  }

  writeValue(value: string | null): void {
    this.value.set(value ?? '');
  }

  registerOnChange(fn: (value: string) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.touched = fn;
  }

  setDisabledState(disabled: boolean): void {
    this.disabledByForm.set(disabled);
  }
}
