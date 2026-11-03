import { ChangeDetectionStrategy, Component, effect, input, signal } from '@angular/core';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { InputText } from 'primeng/inputtext';

import { SegmentedComponent } from './more';
import { SELECT } from './select';

/**
 * One price, the same way everywhere (C-02): who pays, how much, and per what — on one line. Replaces the three fields
 * "Qui paie ? / Prix / Montant" of contracts, annonces and offers. `revenue` is money the company receives, `cost` money
 * it pays; a provider reads the same price from its own side, so the labels turn around for it.
 *
 *   <dw-price-input [direction]="form.controls.direction" [mode]="form.controls.pricing_mode" [amount]="form.controls.unit_price" currency="TND" />
 */
@Component({
  selector: 'dw-price-input',
  imports: [ReactiveFormsModule, TranslocoDirective, InputText, SegmentedComponent, ...SELECT],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <div class="price">
        <dw-segmented [options]="[{ value: 'revenue', label: t(provider() ? 'price.payMine' : 'price.receive') }, { value: 'cost', label: t(provider() ? 'price.receiveMine' : 'price.pay') }]"
          [value]="who()" (valueChange)="direction().setValue($any($event)); direction().markAsDirty()" [ariaLabel]="t('price.who')" />
        <span class="amount">
          <input pInputText [formControl]="amount()" inputmode="decimal" [attr.aria-label]="t('price.amount')" [required]="required()" placeholder="0" />
          <span class="cur">{{ currency() }}</span>
        </span>
        @if (mode(); as unit) {
        <dw-select class="mode" [formControl]="unit" [ariaLabel]="t('price.per')">
          @for (option of modes(); track option) { <dw-option [value]="option" [label]="t('price.modes.' + option)" /> }
        </dw-select>
        }
      </div>
    </ng-container>
  `,
  styles: `
    :host { display: block; min-width: 0; }
    .price { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .amount { position: relative; display: inline-flex; align-items: center; flex: 1 1 120px; min-width: 110px; max-width: 200px; }
    .amount input { width: 100%; padding-inline-end: 48px; text-align: end; font-variant-numeric: tabular-nums; font-weight: 600; }
    .cur { position: absolute; inset-inline-end: 12px; font-size: 12px; font-weight: 600; color: var(--dw-ink-3); pointer-events: none; }
    .mode { flex: 0 1 150px; min-width: 120px; }
  `,
})
export class PriceComponent {
  readonly direction = input.required<FormControl<string>>();
  /** Absent for a fee: an amount per pickup or per month has no unit to choose. */
  readonly mode = input<FormControl<string> | null>(null);
  readonly amount = input.required<FormControl<string>>();
  readonly currency = input('TND');
  readonly modes = input<string[]>(['per_tonne', 'per_kg', 'flat']);
  readonly required = input(false);
  /** Read by a provider: "Je paie" is the industrial's revenue. */
  readonly provider = input(false);

  /** The direction as a signal: the control may change from outside (patchValue), and the switch follows it. */
  protected readonly who = signal('');

  constructor() {
    effect((onCleanup) => {
      const control = this.direction();
      this.who.set(control.value);
      const subscription = control.valueChanges.subscribe((value) => this.who.set(value));
      onCleanup(() => subscription.unsubscribe());
    });
  }
}
