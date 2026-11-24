import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { takeUntilDestroyed, toObservable, toSignal } from '@angular/core/rxjs-interop';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { InputText } from 'primeng/inputtext';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { MoneyPipe } from '../../core/format/pipes';
import { FIELD } from '../../ui/field';
import { SELECT } from '../../ui/select';
import { RawMaterial } from '../production/production-data';

/** The units a quantity of raw material is counted in (the API accepts the same). */
export const MATERIAL_UNITS = ['m', 'kg', 'piece'] as const;
const QUANTITY = [Validators.min(0), Validators.pattern(/^\d+([.,]\d{1,4})?$/)];
const PRICE = [Validators.min(0), Validators.pattern(/^\d+([.,]\d{1,5})?$/)];

/** The controls of a lot's raw material: which one, which batch, how much of it, and a price per unit. */
export type LotMaterialForm = FormGroup<{
  raw_material: FormControl<string | null>;
  raw_material_batch: FormControl<string | null>;
  material_quantity: FormControl<string>;
  material_unit: FormControl<string | null>;
  material_unit_price: FormControl<string>;
}>;

export interface LotMaterialStart {
  raw_material?: string | null;
  raw_material_batch?: string | null;
  material_quantity?: string | null;
  material_unit?: string | null;
  material_unit_price?: string | null;
}

export function lotMaterialForm(start: LotMaterialStart = {}): LotMaterialForm {
  return new FormGroup({
    raw_material: new FormControl<string | null>(start.raw_material ?? null),
    raw_material_batch: new FormControl<string | null>(start.raw_material_batch ?? null),
    material_quantity: new FormControl(start.material_quantity ? trimZeros(start.material_quantity) : '', { nonNullable: true, validators: QUANTITY }),
    material_unit: new FormControl<string | null>(start.material_unit ?? null),
    material_unit_price: new FormControl(start.material_unit_price ? trimZeros(start.material_unit_price) : '', { nonNullable: true, validators: PRICE }),
  });
}

/**
 * What the API receives. `priced`: the person may see production costs, so the price is sent (empty = the batches
 * price the lot); otherwise it is left out and the lot keeps the price it has.
 */
export function lotMaterialValue(form: LotMaterialForm, priced: boolean): Record<string, string | null> {
  const value = form.getRawValue();
  const decimal = (text: string) => (text.trim() === '' ? null : text.trim().replace(',', '.'));
  const quantity = decimal(value.material_quantity);

  return {
    raw_material: value.raw_material,
    raw_material_batch: value.raw_material ? value.raw_material_batch : null,
    material_quantity: quantity,
    material_unit: quantity === null && !value.material_unit ? null : value.material_unit,
    ...(priced ? { material_unit_price: decimal(value.material_unit_price) } : {}),
  };
}

function trimZeros(value: string): string {
  return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}

/**
 * The raw material a waste lot comes from, as a scrap report counts it: the material (any of the company's, those of
 * the lot's OF first), the batch it was cut from, how much of it in one of its units, and the price of a unit. The price
 * field is for people who may see production costs; left empty, the price of the batch shows as its placeholder.
 */
@Component({
  selector: 'dw-lot-material',
  imports: [ReactiveFormsModule, TranslocoDirective, InputText, MoneyPipe, ...FIELD, ...SELECT],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t" [formGroup]="form()">
      <dw-field class="dw-span-2" [label]="t('lots.rawMaterial')">
        <dw-select formControlName="raw_material" [placeholder]="t('lots.materialPick')" clearable>
          <dw-option [value]="null" [label]="fallback() ? t('lots.materialOfOrder') : t('lots.noMaterial')" />
          @for (material of choices(); track material.ulid) {
            <dw-option [value]="material.ulid" [label]="material.reference + ' — ' + material.name + (preferred().includes(material.ulid) ? ' ★' : '')" />
          }
        </dw-select>
        @if (preferred().length > 0) { <small dwHint>{{ t('lots.materialOfOrderHint') }}</small> }
      </dw-field>
      @if (chosen(); as material) {
        @if (material.batches.length > 0) {
          <dw-field class="dw-span-2" [label]="t('lots.materialBatch')">
            <dw-select formControlName="raw_material_batch">
              <dw-option [value]="null" [label]="t('lots.batchLatest')" />
              @for (batch of material.batches; track batch.ulid) {
                <dw-option [value]="batch.ulid" [label]="batch.batch_number + (batch.received_on ? ' · ' + batch.received_on : '') + (batch.supplier ? ' · ' + batch.supplier.name : '')" />
              }
            </dw-select>
          </dw-field>
        }
        <dw-field [label]="t('lots.materialQuantity')">
          <input pInputText formControlName="material_quantity" inputmode="decimal" [placeholder]="t('lots.materialQuantityPh')" />
          <small dwHint>{{ t('lots.materialQuantityHint') }}</small>
        </dw-field>
        <dw-field [label]="t('lots.materialUnit')">
          <dw-select formControlName="material_unit">
            <dw-option [value]="null" [label]="t('lots.unitOfMaterial', { unit: t('units.' + material.unit, { count: 2 }) })" />
            @for (unit of units; track unit) { <dw-option [value]="unit" [label]="t('units.' + unit, { count: 2 })" /> }
          </dw-select>
        </dw-field>
        @if (priced()) {
          <dw-field class="dw-span-2" [label]="t('lots.materialUnitPrice', { unit: t('units.' + unit(), { count: 1 }) })">
            <input pInputText formControlName="material_unit_price" inputmode="decimal" [placeholder]="suggested() ? (suggested()! | dwMoney: currency()) : ''" />
            <small dwHint>
              @if (suggested(); as price) { {{ t('lots.materialPriceFromBatch', { price: (price | dwMoney: currency()), unit: t('units.' + material.unit, { count: 1 }) }) }} }
              @else { {{ t('lots.materialPriceNone') }} }
            </small>
          </dw-field>
          @if (total(); as amount) {
            <p class="dw-span-2 total">{{ t('lots.materialTotal') }} <strong>{{ amount | dwMoney: currency() }}</strong></p>
          }
        }
      }
    </ng-container>
  `,
  styles: ':host { display: contents; } .dw-span-2 { grid-column: 1 / -1; } .total { margin: 0; font-size: 13px; color: var(--dw-ink-2); }',
})
export class LotMaterialComponent {
  private readonly api = inject(Api);
  private readonly session = inject(Session);

  readonly form = input.required<LotMaterialForm>();
  /** The materials of the lot's OF: listed first, marked. */
  readonly preferred = input<string[]>([]);
  /** Left empty, the lot takes the material of its OF (when it has only one). */
  readonly fallback = input(false);
  protected readonly units = MATERIAL_UNITS;
  protected readonly priced = computed(() => this.session.can('production.finance.view'));

  /** The company's raw materials, with their batches (and prices, for who may see them). */
  private readonly materials = toSignal(
    this.api.list<RawMaterial>('production/materials', { per_page: 200, with_inactive: true }).pipe(map((page) => page.data), catchError(() => of([] as RawMaterial[]))),
    { initialValue: [] as RawMaterial[] },
  );
  protected readonly choices = computed(() => {
    const first = this.preferred();
    // A material no longer in use stays listed for the lot that already names it.
    const current = this.form().controls.raw_material.value;

    return this.materials().filter((material) => material.is_active || material.ulid === current).sort((a, b) => Number(first.includes(b.ulid)) - Number(first.includes(a.ulid)) || a.reference.localeCompare(b.reference));
  });
  private readonly value = toSignal(
    toObservable(this.form).pipe(switchMap((form) => form.valueChanges.pipe(startWith(form.getRawValue()), map(() => form.getRawValue())))),
  );
  protected readonly chosen = computed(() => this.materials().find((material) => material.ulid === this.value()?.raw_material) ?? null);
  protected readonly unit = computed(() => this.value()?.material_unit ?? this.chosen()?.unit ?? 'kg');
  private readonly batch = computed(() => {
    const material = this.chosen();
    const ulid = this.value()?.raw_material_batch;

    return material?.batches.find((batch) => (ulid ? batch.ulid === ulid : batch.unit_cost !== null)) ?? null;
  });
  protected readonly currency = computed(() => this.batch()?.currency_code ?? this.session.company()?.currency_code ?? 'TND');
  /** The price of a unit of the material from its batch (the chosen one, else the latest priced): shown, not typed. */
  protected readonly suggested = computed(() => {
    const material = this.chosen();
    const cost = this.batch()?.unit_cost ?? material?.average_unit_cost ?? null;

    return material && cost !== null && this.unit() === material.unit ? cost : null;
  });
  protected readonly total = computed(() => {
    const value = this.value();
    const quantity = Number((value?.material_quantity ?? '').replace(',', '.'));
    const typed = (value?.material_unit_price ?? '').trim();
    const price = typed !== '' ? Number(typed.replace(',', '.')) : Number(this.suggested() ?? NaN);

    return value?.material_quantity && Number.isFinite(quantity) && Number.isFinite(price) ? quantity * price : null;
  });

  constructor() {
    // Another material: its batch no longer applies.
    toObservable(this.form).pipe(switchMap((form) => form.controls.raw_material.valueChanges.pipe(map(() => form))), takeUntilDestroyed()).subscribe((form) => {
      form.controls.raw_material_batch.setValue(null, { emitEvent: false });
    });
  }
}

