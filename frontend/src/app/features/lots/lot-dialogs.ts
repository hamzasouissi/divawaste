import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormArray, FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { catchError, firstValueFrom, of } from 'rxjs';
import { ulid } from 'ulid';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';
import { AutoFocus } from 'primeng/autofocus';

import { Api } from '../../core/api/api';
import { Lot, OpenOrder, Zone } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { LocalizedPipe, NumberPipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { DIALOG_PARTS, DialogRef, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';
import { zoneMismatch } from '../../ui/zone-fill';
import { COLOR } from '../../ui/swatch';

const DIALOG = [ReactiveFormsModule, TranslocoDirective] as const;
const WEIGHT = [Validators.required, Validators.min(0), Validators.max(999999), Validators.pattern(/^\d+([.,]\d{1,3})?$/)];
const AMOUNT = [Validators.min(0), Validators.max(999999), Validators.pattern(/^\d+([.,]\d{1,3})?$/)];
const decimal = (value: string | number): string => String(value).replace(',', '.');

/**
 * M2-01 on the desktop: site, zone, type and weight are the only required fields; the rest takes the
 * defaults of the waste type. The lot ULID is generated here so a double submission cannot create two lots.
 */
@Component({
  imports: [...DIALOG, LocalizedPipe, ButtonDirective, InputText, Textarea, CheckboxComponent, ...FIELD, ...SELECT, ...COLOR, ...DIALOG_PARTS, AutoFocus],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('lots.createTitle') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field [label]="t('fields.site')">
              <dw-select formControlName="site" required (valueChange)="loadZones($any($event))">
                @for (site of sites; track site.ulid) { <dw-option [value]="site.ulid" [label]="site.name" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('fields.zone')">
              <dw-select formControlName="zone" required>
                @for (zone of zones(); track zone.ulid) { <dw-option [value]="zone.ulid" [label]="zone.name" /> }
              </dw-select>
              @if (mismatch(); as why) { <small dwHint class="mismatch">{{ t('lots.zoneMismatch.' + why) }}</small> }
            </dw-field>
            <dw-field class="dw-span-2" [label]="t('fields.wasteType')">
              <dw-select formControlName="waste_type" required>
                @for (type of types(); track type.ulid) {
                  <dw-option [value]="type.ulid" [label]="type.name + (type.is_hazardous ? ' — ' + t('common.hazardous') : '')" />
                }
              </dw-select>
            </dw-field>
            @if (orders().length > 0) {
              <dw-field [label]="t('lots.productionOrder')">
                <dw-select formControlName="production_order">
                  <dw-option [value]="null" [label]="t('lots.noProductionOrder')" />
                  @for (order of orders(); track order.ulid) { <dw-option [value]="order.ulid" [label]="order.order_number + ' — ' + order.article.reference" /> }
                </dw-select>
                <small dwHint>{{ t('lots.productionOrderHint') }}</small>
              </dw-field>
              <dw-field [label]="t('lots.rawMaterial')">
                <dw-select formControlName="raw_material">
                  <dw-option [value]="null" [label]="t('lots.materialOfOrder')" />
                  @for (material of materials(); track material.ulid) { <dw-option [value]="material.ulid" [label]="material.reference + ' — ' + material.name" /> }
                </dw-select>
              </dw-field>
            }
            @if (countedUnit(); as unit) {
              <!-- Waste counted in pieces or litres: the quantity is what is declared; a weight is welcome, never required. -->
              <dw-field [label]="t('lots.quantityIn', { unit: t('units.' + unit, { count: 2 }) })">
                <input pInputText formControlName="quantity" inputmode="decimal" required [pAutoFocus]="true" />
                <small dwHint>{{ t('lots.countedHint') }}</small>
              </dw-field>
              <dw-field [label]="t('lots.weightIfKnown')">
                <input pInputText formControlName="net_weight_kg" inputmode="decimal" />
                <small dwHint>{{ t(convertible() ? 'lots.weightComputed' : 'lots.weightUnknown') }}</small>
              </dw-field>
            } @else {
              <dw-field [label]="t('fields.netWeight')">
                <input pInputText formControlName="net_weight_kg" inputmode="decimal" [pAutoFocus]="true" />
                <small dwHint>{{ t('lots.weighLaterHint') }}</small>
              </dw-field>
            }
            @if (form.controls.net_weight_kg.value.trim() !== '') {
              <dw-checkbox class="dw-span-2" formControlName="weight_estimated">{{ t('lots.weightEstimated') }}</dw-checkbox>
            }
            <dw-field [label]="t('lots.packaging')">
              <dw-select formControlName="packaging_type">
                <dw-option [value]="null" label="—" />
                @for (packaging of vocabulary()?.packaging_types ?? []; track packaging.code) { <dw-option [value]="packaging.code" [label]="packaging.name | dwLocalized" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('lots.color')">
              <dw-select formControlName="color_family">
                <dw-option [value]="null" label="—" />
                @for (color of vocabulary()?.color_families ?? []; track color.code) {
                  <dw-option [value]="color.code" [label]="(color.name | dwLocalized) | dwColorName: color.pantone_reference" [swatch]="color.hex_color" />
                }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('lots.grammage')"><input pInputText formControlName="grammage_gsm" inputmode="decimal" /></dw-field>
            <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="1000"></textarea></dw-field>
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.create') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: '.dw-dialog-body { min-width: min(620px, 82vw); } .mismatch { color: var(--dw-warning); }',
})
export class CreateLotDialog {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly reference = inject(ReferenceData);
  private readonly ref = inject(DialogRef);

  protected readonly sites = inject(Session).sitesFor('lots.create');
  protected readonly types = toSignal(this.reference.wasteTypes(), { initialValue: [] });
  protected readonly vocabulary = toSignal(this.reference.vocabulary());
  protected readonly zones = signal<Zone[]>([]);
  protected readonly busy = signal(false);
  /** One identifier for the whole life of the dialog: retrying after a network error cannot duplicate the lot. */
  private readonly lotUlid = ulid();

  protected readonly form = inject(FormBuilder).nonNullable.group({
    site: [this.sites[0]?.ulid ?? '', Validators.required],
    zone: ['', Validators.required],
    waste_type: ['', Validators.required],
    // Required for weighed waste, optional for counted waste: checked on submit, once the type is known.
    net_weight_kg: ['', AMOUNT],
    quantity: ['', AMOUNT],
    // A guess of the person, not a reading of a scale: kept as such, and not accepted where a measure is required.
    weight_estimated: [false],
    // Opened from the page of an OF: that OF is proposed.
    production_order: [(dialogData<{ production_order?: string } | undefined>()?.production_order ?? null) as string | null],
    raw_material: [null as string | null],
    packaging_type: [null as string | null],
    color_family: [null as string | null],
    grammage_gsm: [''],
    notes: [''],
  });

  private readonly chosen = toSignal(this.form.valueChanges, { initialValue: this.form.getRawValue() });
  private readonly openOrders = toSignal(this.api.get<OpenOrder[]>('production/open-orders').pipe(catchError(() => of([] as OpenOrder[]))), { initialValue: [] });
  /** The manufacturing orders that come to the chosen site (an OF without stages may be anywhere). */
  protected readonly orders = computed(() => this.openOrders().filter((order) => order.steps.length === 0 || order.steps.some((step) => step.site === this.chosen().site)));
  /** The materials of the chosen OF: with a single one, the lot takes it without asking. */
  protected readonly materials = computed(() => this.openOrders().find((order) => order.ulid === this.chosen().production_order)?.materials ?? []);
  private readonly chosenType = computed(() => this.types().find((type) => type.ulid === this.chosen().waste_type));
  /** The unit of a type counted in pieces or litres; null for weighed waste. */
  protected readonly countedUnit = computed(() => ((this.chosenType()?.measure_dimension ?? 'mass') === 'mass' ? null : (this.chosenType()?.unit ?? 'piece')));
  /** The type says how much one piece (or one m³) weighs: the weight is then computed when none is typed. */
  protected readonly convertible = computed(() => (this.chosenType()?.measure_dimension === 'count' ? !!this.chosenType()?.unit_weight_kg : !!this.chosenType()?.density_kg_m3));
  /** A warning, never a refusal: the operator may have a good reason to put the lot there. */
  protected readonly mismatch = computed(() => {
    const value = this.chosen();

    return zoneMismatch(this.zones().find((zone) => zone.ulid === value.zone), value.waste_type || null, value.color_family ?? null);
  });

  constructor() {
    if (this.form.controls.site.value) {
      this.loadZones(this.form.controls.site.value);
    }
  }

  protected loadZones(site: string): void {
    this.form.controls.zone.reset('');
    this.reference.zones(site).subscribe((zones) => {
      this.zones.set(zones);
      this.form.controls.zone.setValue(zones[0]?.ulid ?? '');
    });
  }

  protected async submit(): Promise<void> {
    const unit = this.countedUnit();
    // Counted waste needs its quantity. A weight is never required here: a lot may be registered before it is weighed.
    if (unit && this.form.controls.quantity.value.trim() === '') {
      this.form.controls.quantity.setErrors({ required: true });
    }
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    try {
      const lot = await firstValueFrom(this.api.post<Lot>('lots', {
        ...value, ulid: this.lotUlid, net_weight_kg: value.net_weight_kg ? decimal(value.net_weight_kg) : null,
        weight_estimated: value.net_weight_kg ? value.weight_estimated : false,
        quantity: unit ? decimal(value.quantity) : null, unit, grammage_gsm: value.grammage_gsm ? decimal(value.grammage_gsm) : null, notes: value.notes || null,
      }));
      this.toast.success('lots.created', { number: lot.lot_number });
      this.ref.close(lot);
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

export interface LotActionData {
  lot: Lot;
}

/**
 * Where the lot comes from in production, given or corrected after it was created: the operator on the floor does not
 * always know the OF. Left empty, the stage is deduced from the zone and the material from the OF.
 */
@Component({
  imports: [...DIALOG, LocalizedPipe, ButtonDirective, ...FIELD, ...SELECT, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('lots.editOrigin') }} — {{ data.lot.lot_number }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <p class="dw-muted">{{ t('lots.originHint') }}</p>
          <dw-field [label]="t('lots.productionOrder')">
            <dw-select formControlName="production_order">
              <dw-option [value]="null" [label]="t('lots.noProductionOrder')" />
              @for (order of orders(); track order.ulid) { <dw-option [value]="order.ulid" [label]="order.order_number + ' — ' + order.article.reference" /> }
            </dw-select>
          </dw-field>
          <dw-field [label]="t('production.of.step')">
            <dw-select formControlName="production_step">
              <dw-option [value]="null" [label]="t('lots.stepOfZone')" />
              @for (step of steps(); track step.code) { <dw-option [value]="step.code" [label]="step.name | dwLocalized" /> }
            </dw-select>
          </dw-field>
          <dw-field [label]="t('lots.rawMaterial')">
            <dw-select formControlName="raw_material">
              <dw-option [value]="null" [label]="t('lots.materialOfOrder')" />
              @for (material of materials(); track material.ulid) { <dw-option [value]="material.ulid" [label]="material.reference + ' — ' + material.name" /> }
            </dw-select>
          </dw-field>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: '.dw-dialog-body { display: grid; gap: var(--dw-3); inline-size: min(480px, 86vw); } .dw-muted { margin: 0; font-size: 13px; }',
})
export class LotOriginDialog {
  protected readonly data = dialogData<LotActionData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  private readonly origin = this.data.lot.origin ?? null;
  private readonly openOrders = toSignal(this.api.get<OpenOrder[]>('production/open-orders').pipe(catchError(() => of([] as OpenOrder[]))), { initialValue: [] });
  protected readonly steps = toSignal(this.api.get<{ code: string; name: Record<string, string>; is_active: boolean }[]>('production/steps').pipe(catchError(() => of([]))), { initialValue: [] });
  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).group({
    production_order: [this.origin?.production_order?.ulid ?? null],
    production_step: [this.origin?.step?.code ?? null],
    raw_material: [this.origin?.raw_material?.ulid ?? null],
  });
  private readonly chosen = toSignal(this.form.valueChanges, { initialValue: this.form.getRawValue() });
  /** The open orders, and the one the lot already names even if it is finished since. */
  protected readonly orders = computed(() => {
    const current = this.origin?.production_order;
    const open = this.openOrders();

    return current && !open.some((order) => order.ulid === current.ulid)
      ? [{ ulid: current.ulid, order_number: current.order_number, article: this.origin?.article ?? { reference: '', name: '' }, steps: [], materials: [] } as OpenOrder, ...open] : open;
  });
  protected readonly materials = computed(() => {
    const ofOrder = this.openOrders().find((order) => order.ulid === this.chosen().production_order)?.materials ?? [];
    const current = this.origin?.raw_material;

    return current && !ofOrder.some((material) => material.ulid === current.ulid) ? [current, ...ofOrder] : ofOrder;
  });

  protected async submit(): Promise<void> {
    this.busy.set(true);
    try {
      this.ref.close(await firstValueFrom(this.api.put<Lot>(`lots/${this.data.lot.ulid}/origin`, this.form.getRawValue())));
      this.toast.success('common.saved');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

/** Move a lot to a zone of its site (storing it if it was only created). */
@Component({
  imports: [...DIALOG, ButtonDirective, ...FIELD, ...SELECT, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data.lot.status === 'stored' ? 'lots.move' : 'lots.store') }} — {{ data.lot.lot_number }}</dw-dialog-title>
      <div class="dw-dialog-body">
        <dw-field [label]="t('lots.targetZone')">
          <dw-select [formControl]="zone" required>
            @for (item of zones(); track item.ulid) { <dw-option [value]="item.ulid" [label]="item.name" /> }
          </dw-select>
          @if (mismatch(); as why) { <small dwHint class="mismatch">{{ t('lots.zoneMismatch.' + why) }}</small> }
        </dw-field>
      </div>
      <div class="dw-dialog-foot">
        <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
        <button pButton type="button" [disabled]="busy() || zone.invalid" (click)="submit()">{{ t('common.confirm') }}</button>
      </div>
    </ng-container>
  `,
  styles: '.dw-dialog-body { min-width: min(420px, 80vw); } .mismatch { color: var(--dw-warning); }',
})
export class StoreLotDialog {
  protected readonly data = dialogData<LotActionData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly zones = toSignal(inject(ReferenceData).zones(this.data.lot.site?.ulid ?? ''), { initialValue: [] });
  protected readonly zone = inject(FormBuilder).nonNullable.control(this.data.lot.zone?.ulid ?? '', Validators.required);
  protected readonly busy = signal(false);
  private readonly target = toSignal(this.zone.valueChanges, { initialValue: this.zone.value });
  protected readonly mismatch = computed(() => zoneMismatch(this.zones().find((item) => item.ulid === this.target()), this.data.lot.waste_type?.ulid ?? null, this.data.lot.color_family));

  protected async submit(): Promise<void> {
    this.busy.set(true);
    try {
      this.ref.close(await firstValueFrom(this.api.post<Lot>(`lots/${this.data.lot.ulid}/store`, { zone: this.zone.value })));
      this.toast.success('lots.stored');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

/** M2-04: corrected weighing. The version is sent so two people cannot overwrite each other silently. */
@Component({
  imports: [...DIALOG, ButtonDirective, InputText, CheckboxComponent, ...FIELD, ...DIALOG_PARTS, AutoFocus],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('lots.weigh') }} — {{ data.lot.lot_number }}</dw-dialog-title>
      <div class="dw-dialog-body">
        <dw-field [label]="t('lots.newWeight')">
          <input pInputText [formControl]="weight" inputmode="decimal" required [pAutoFocus]="true" (keydown.enter)="submit()" />
        </dw-field>
        <dw-checkbox [formControl]="estimated">{{ t('lots.weightEstimated') }}</dw-checkbox>
      </div>
      <div class="dw-dialog-foot">
        <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
        <button pButton type="button" [disabled]="busy() || weight.invalid" (click)="submit()">{{ t('common.save') }}</button>
      </div>
    </ng-container>
  `,
  styles: '.dw-dialog-body { min-width: min(360px, 80vw); }',
})
export class WeighLotDialog {
  protected readonly data = dialogData<LotActionData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly weight = inject(FormBuilder).nonNullable.control(this.data.lot.net_weight_kg ?? '', WEIGHT);
  protected readonly estimated = inject(FormBuilder).nonNullable.control(this.data.lot.weight_basis === 'estimated');
  protected readonly busy = signal(false);

  protected async submit(): Promise<void> {
    if (this.weight.invalid) {
      return;
    }
    this.busy.set(true);
    try {
      this.ref.close(await firstValueFrom(this.api.post<Lot>(`lots/${this.data.lot.ulid}/weigh`, { net_weight_kg: decimal(this.weight.value), estimated: this.estimated.value, version: this.data.lot.version })));
      this.toast.success('lots.weighed');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

/** M2-06: split. The running total shows at once whether the weights still add up to the original lot. */
@Component({
  imports: [...DIALOG, NumberPipe, IconComponent, ButtonDirective, InputText, Textarea, ...FIELD, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('lots.split') }} — {{ data.lot.lot_number }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body dw-stack-sm">
          <p class="dw-muted">{{ t('lots.splitHint') }}</p>
          <div formArrayName="outputs" class="outputs">
            @for (control of outputs.controls; track control; let index = $index) {
              <div class="output">
                <dw-field>
                  <span dwLabel>{{ t('lots.splitOutput', { index: index + 1 }) }}</span>
                  <input pInputText [formControlName]="index" inputmode="decimal" required />
                </dw-field>
                @if (outputs.length > 2) {
                  <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="outputs.removeAt(index); recompute()" [attr.aria-label]="t('common.remove')"><dw-icon>close</dw-icon></button>
                }
              </div>
            }
          </div>
          <button pButton severity="secondary" outlined type="button" (click)="add()" class="add"><dw-icon>add</dw-icon>{{ t('lots.addOutput') }}</button>
          <p [class.gap]="gap()" role="status">{{ t('lots.splitTotal', { total: (total() | dwNumber: 3), source: (data.lot.net_weight_kg | dwNumber: 3) }) }}</p>
          <dw-field>
            <span dwLabel>{{ t('fields.comment') }}@if (!gap()) { ({{ t('common.optional') }}) }</span>
            <textarea pTextarea formControlName="notes" rows="2" maxlength="1000" [required]="gap()"></textarea>
            @if (gap()) { <small dwHint>{{ t('errors.SPLIT_WEIGHT_GAP') }}</small> }
          </dw-field>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy() || (gap() && !form.controls.notes.value.trim())">{{ t('lots.split') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { min-width: min(460px, 82vw); }
    .outputs { display: grid; gap: var(--dw-1); } .output { display: flex; align-items: center; gap: var(--dw-2); }
    .add { justify-self: start; } .gap { color: var(--dw-warning); font-weight: 600; }
  `,
})
export class SplitLotDialog {
  protected readonly data = dialogData<LotActionData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly fb = inject(FormBuilder).nonNullable;
  private readonly ref = inject(DialogRef);

  protected readonly form = this.fb.group({ outputs: this.fb.array([this.fb.control('', WEIGHT), this.fb.control('', WEIGHT)]), notes: [''] });
  protected readonly busy = signal(false);
  protected readonly total = signal(0);
  /** Beyond 2 % of difference with the original weight, the API requires an explanation. */
  protected readonly gap = computed(() => {
    const source = Number(this.data.lot.net_weight_kg);

    return source > 0 && this.total() > 0 && Math.abs(this.total() - source) / source > 0.02;
  });

  constructor() {
    this.form.controls.outputs.valueChanges.subscribe(() => this.recompute());
  }

  protected get outputs(): FormArray {
    return this.form.controls.outputs;
  }

  protected add(): void {
    this.outputs.push(this.fb.control('', WEIGHT));
  }

  protected recompute(): void {
    this.total.set(this.form.controls.outputs.getRawValue().reduce((sum, value) => sum + (Number(decimal(value)) || 0), 0));
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    try {
      const lots = await firstValueFrom(this.api.post<Lot[]>(`lots/${this.data.lot.ulid}/split`, {
        outputs: value.outputs.map((weight) => ({ net_weight_kg: decimal(weight) })), notes: value.notes.trim() || null,
      }));
      this.toast.success('lots.splitDone', { count: lots.length });
      this.ref.close(lots);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

export interface GroupLotsData {
  lots: Lot[];
}

@Component({
  imports: [...DIALOG, NumberPipe, ButtonDirective, ...FIELD, ...SELECT, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('lots.group') }}</dw-dialog-title>
      <div class="dw-dialog-body dw-stack-sm">
        <p class="dw-muted">{{ t('lots.groupHint') }}</p>
        <p><strong>{{ data.lots.length }}</strong> × {{ data.lots[0].waste_type?.name }} — <strong>{{ total | dwNumber: 3 }} kg</strong></p>
        <dw-field [label]="t('lots.targetZone')">
          <dw-select [formControl]="zone" required>
            @for (item of zones(); track item.ulid) { <dw-option [value]="item.ulid" [label]="item.name" /> }
          </dw-select>
        </dw-field>
      </div>
      <div class="dw-dialog-foot">
        <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
        <button pButton type="button" [disabled]="busy() || zone.invalid" (click)="submit()">{{ t('lots.group') }}</button>
      </div>
    </ng-container>
  `,
  styles: '.dw-dialog-body { min-width: min(440px, 82vw); }',
})
export class GroupLotsDialog {
  protected readonly data = dialogData<GroupLotsData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly total = this.data.lots.reduce((sum, lot) => sum + Number(lot.net_weight_kg), 0);
  protected readonly zones = toSignal(inject(ReferenceData).zones(this.data.lots[0].site?.ulid ?? ''), { initialValue: [] });
  protected readonly zone = inject(FormBuilder).nonNullable.control(this.data.lots[0].zone?.ulid ?? '', Validators.required);
  protected readonly busy = signal(false);

  protected async submit(): Promise<void> {
    this.busy.set(true);
    try {
      const lot = await firstValueFrom(this.api.post<Lot>('lots/group', { lots: this.data.lots.map((item) => item.ulid), zone: this.zone.value }));
      this.toast.success('lots.groupDone', { number: lot.lot_number });
      this.ref.close(lot);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
