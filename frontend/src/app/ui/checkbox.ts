import { ChangeDetectionStrategy, Component, booleanAttribute, computed, forwardRef, input, model, signal } from '@angular/core';
import { ControlValueAccessor, FormsModule, NG_VALUE_ACCESSOR } from '@angular/forms';
import { Checkbox } from 'primeng/checkbox';

let sequence = 0;

/** Checkbox with its label (PrimeNG Checkbox). Usable with a form control or with `[checked]` / `(checkedChange)`. */
@Component({
  selector: 'dw-checkbox',
  imports: [FormsModule, Checkbox],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => CheckboxComponent), multi: true }],
  template: `
    <p-checkbox [binary]="true" [inputId]="id" [ngModel]="checked()" (ngModelChange)="set($event)" [disabled]="off()" [indeterminate]="indeterminate()" [ariaLabel]="ariaLabel()" />
    <label [attr.for]="id"><ng-content /></label>
  `,
  styles: `
    :host { display: inline-flex; align-items: center; gap: 8px; min-height: 20px; vertical-align: middle; }
    label { cursor: pointer; font-size: 13px; line-height: 18px; color: var(--dw-ink); user-select: none; }
    label:empty { display: none; }
    :host(.off) label { cursor: default; color: var(--dw-ink-3); }
  `,
  host: { class: 'dw-checkbox', '[class.off]': 'off()' },
})
export class CheckboxComponent implements ControlValueAccessor {
  readonly checked = model(false);
  readonly indeterminate = input(false);
  readonly disabled = input(false, { transform: booleanAttribute });
  readonly ariaLabel = input('');

  protected readonly id = `dw-checkbox-${++sequence}`;
  private readonly disabledByForm = signal(false);
  protected readonly off = computed(() => this.disabled() || this.disabledByForm());

  private onChange: (value: boolean) => void = () => undefined;
  private onTouched: () => void = () => undefined;

  protected set(value: boolean): void {
    this.checked.set(Boolean(value));
    this.onChange(Boolean(value));
    this.onTouched();
  }

  writeValue(value: unknown): void {
    this.checked.set(Boolean(value));
  }

  registerOnChange(fn: (value: boolean) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(disabled: boolean): void {
    this.disabledByForm.set(disabled);
  }
}
