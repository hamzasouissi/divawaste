import { ChangeDetectionStrategy, Component, booleanAttribute, computed, contentChildren, forwardRef, inject, input, model, signal } from '@angular/core';
import { ControlValueAccessor, FormsModule, NG_VALUE_ACCESSOR } from '@angular/forms';
import { MultiSelect } from 'primeng/multiselect';
import { Select } from 'primeng/select';

import { FieldComponent } from './field';

/** One choice of a `dw-select`. An option whose value is empty (`''` or `null`) is the "no choice" entry. */
@Component({ selector: 'dw-option', changeDetection: ChangeDetectionStrategy.OnPush, template: '', host: { hidden: '' } })
export class OptionComponent {
  readonly value = input<unknown>(null);
  readonly label = input.required<string, string | number>({ transform: (value) => String(value) });
  readonly disabled = input(false, { transform: booleanAttribute });
}

const SEARCH_FROM = 8;
const isEmpty = (value: unknown): boolean => value === null || value === undefined || value === '';

/**
 * Selector of the product (PrimeNG Select / MultiSelect). Options are declared in the template, next to the
 * translation that names them. A search box appears as soon as the list is long, the "no choice" option becomes
 * the placeholder with a clear button, and the control looks the same in a form and in a filter bar.
 *
 *   <dw-select formControlName="zone"><dw-option [value]="zone.ulid" [label]="zone.name" /></dw-select>
 */
@Component({
  selector: 'dw-select',
  imports: [FormsModule, Select, MultiSelect],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => SelectComponent), multi: true }],
  template: `
    @if (multiple()) {
      <p-multiselect [options]="items()" optionLabel="label" optionValue="value" optionDisabled="disabled" [ngModel]="list()" (ngModelChange)="set($event)"
        [placeholder]="hint()" [filter]="searchable()" [showHeader]="searchable()" [showToggleAll]="false" [maxSelectedLabels]="2" [showClear]="clearable() && list().length > 0"
        [disabled]="off()" [fluid]="true" [ariaLabel]="ariaLabel() || hint()" [ariaLabelledBy]="labelledBy" [resetFilterOnHide]="true" scrollHeight="280px" (onPanelHide)="touched()" />
    } @else {
      <p-select [options]="items()" optionLabel="label" optionValue="value" optionDisabled="disabled" [ngModel]="value()" (ngModelChange)="set($event)"
        [placeholder]="hint()" [filter]="searchable()" [showClear]="canClear()" [disabled]="off()" [fluid]="true" [ariaLabel]="ariaLabel() || hint()"
        [ariaLabelledBy]="labelledBy" [resetFilterOnHide]="true" [autoOptionFocus]="false" scrollHeight="280px" (onHide)="touched()" />
    }
    <ng-content />
  `,
  styles: ':host { display: block; min-width: 0; }',
  host: { class: 'dw-select', '[class.has-value]': 'filled()' },
})
export class SelectComponent<T = unknown> implements ControlValueAccessor {
  readonly value = model<T>(null as T);
  readonly multiple = input(false, { transform: booleanAttribute });
  readonly placeholder = input('');
  readonly disabled = input(false, { transform: booleanAttribute });
  /** Shows a clear button (always the case when the options include a "no choice" entry). */
  readonly clearable = input(false, { transform: booleanAttribute });
  readonly ariaLabel = input('');

  protected readonly labelledBy = inject(FieldComponent, { optional: true })?.labelId;

  private readonly options = contentChildren(OptionComponent);
  private readonly blank = computed(() => this.options().find((option) => isEmpty(option.value())));
  protected readonly items = computed(() => this.options().filter((option) => !isEmpty(option.value())).map((option) => ({ label: option.label(), value: option.value(), disabled: option.disabled() })));
  protected readonly hint = computed(() => this.placeholder() || this.blank()?.label() || '');
  protected readonly searchable = computed(() => this.items().length > SEARCH_FROM);
  protected readonly list = computed(() => (Array.isArray(this.value()) ? (this.value() as unknown[]) : []));
  protected readonly filled = computed(() => (this.multiple() ? this.list().length > 0 : !isEmpty(this.value())));
  protected readonly canClear = computed(() => (this.clearable() || this.blank() !== undefined) && this.filled());

  private readonly disabledByForm = signal(false);
  protected readonly off = computed(() => this.disabled() || this.disabledByForm());

  private onChange: (value: unknown) => void = () => undefined;
  protected touched: () => void = () => undefined;

  protected set(next: unknown): void {
    // Clearing gives back the value of the "no choice" option, so a form keeps the type it started with.
    const value = this.multiple() ? (next ?? []) : isEmpty(next) ? (this.blank()?.value() ?? null) : next;
    this.value.set(value as T);
    this.onChange(value);
  }

  writeValue(value: unknown): void {
    this.value.set(value as T);
  }

  registerOnChange(fn: (value: unknown) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.touched = fn;
  }

  setDisabledState(disabled: boolean): void {
    this.disabledByForm.set(disabled);
  }
}

export const SELECT = [SelectComponent, OptionComponent] as const;
