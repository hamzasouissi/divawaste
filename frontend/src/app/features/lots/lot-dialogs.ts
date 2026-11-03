import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormArray, FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { catchError, firstValueFrom, forkJoin, of } from 'rxjs';
import { ulid } from 'ulid';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';
import { AutoFocus } from 'primeng/autofocus';

import { Api, openBlob } from '../../core/api/api';
import { Lot, OpenOrder, Zone } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { Locale } from '../../core/i18n/i18n';
import { CodePipe, LocalizedPipe, NumberPipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { MoreComponent, SegmentedComponent } from '../../ui/more';
import { DIALOG_PARTS, DialogRef, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';
import { zoneMismatch } from '../../ui/zone-fill';
import { COLOR } from '../../ui/swatch';
import { CompositionEditorComponent, compositionArray, compositionLine, compositionTotal, compositionValue } from '../../ui/composition';

const DIALOG = [ReactiveFormsModule, TranslocoDirective] as const;
const WEIGHT = [Validators.required, Validators.min(0), Validators.max(999999), Validators.pattern(/^\d+([.,]\d{1,3})?$/)];
const PACKAGES = [Validators.pattern(/^[1-9]\d{0,5}$/)];
const AMOUNT = [Validators.min(0), Validators.max(999999), Validators.pattern(/^\d+([.,]\d{1,3})?$/)];
const decimal = (value: string | number): string => String(value).replace(',', '.');

const PLACE_KEY = 'dw.lot.lastZone';
const PRINT_KEY = 'dw.lot.printOnCreate';

function readText(key: string): string | null {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function readFlag(key: string): boolean {
  return readText(key) === '1';
}

function writeText(key: string, value: string): void {
  try {
    localStorage.setItem(key, value);
  } catch {
    // Private browsing: remembered for this visit only.
  }
}


/**
 * M2-01 on the desktop: site, zone, type and weight are the only required fields; the rest takes the
 * defaults of the waste type. The lot ULID is generated here so a double submission cannot create two lots.
 */
@Component({
  imports: [...DIALOG, LocalizedPipe, CodePipe, ButtonDirective, InputText, Textarea, CheckboxComponent, ...FIELD, ...SELECT, ...COLOR, ...DIALOG_PARTS, AutoFocus, CompositionEditorComponent, MoreComponent, SegmentedComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('lots.createTitle') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit(false)" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <!-- Two short parts: the waste (where it is, what it is), then how much of it. -->
            <h3 class="dw-span-2 part">{{ t('lots.partWhat') }}</h3>
            <!-- L-01: one place, always "Site · Zone" (a zone name alone does not say where it is); the last one used is chosen again. -->
            <dw-field class="dw-span-2" [label]="t('lots.where')">
              <dw-select formControlName="zone" required (valueChange)="placeChosen($any($event))">
                @for (place of places(); track place.zone.ulid) { <dw-option [value]="place.zone.ulid" [label]="place.siteName + ' · ' + place.zone.name" /> }
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
            <h3 class="dw-span-2 part">{{ t('lots.partHowMuch') }}</h3>
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
              <!-- A reading of a scale or a guess: two clear choices instead of a checkbox to interpret. -->
              <div class="basis dw-span-2">
                <span class="dw-field-label">{{ t('lots.weightBasisLabel') }}</span>
                <dw-segmented [options]="[{ value: false, label: t('lots.basisWeighed') }, { value: true, label: t('lots.basisEstimated') }]"
                  [value]="form.controls.weight_estimated.value" (valueChange)="form.controls.weight_estimated.setValue(!!$event)" [ariaLabel]="t('lots.weightBasisLabel')" />
              </div>
            }

            <!-- L-02: the rest, prefilled from the waste type, folded. -->
            <dw-more [summary]="defaultsSummary()" [(open)]="more">
              <dw-field [label]="t('lots.packaging')">
                <dw-select formControlName="packaging_type">
                  <dw-option [value]="null" [label]="chosenType()?.packaging_type ? t('lots.fromType') : '—'" />
                  @for (packaging of vocabulary()?.packaging_types ?? []; track packaging.code) { <dw-option [value]="packaging.code" [label]="packaging.name | dwLocalized" /> }
                </dw-select>
              </dw-field>
              @if (packaging(); as kind) {
                <!-- One lot may be several bags or bales. Left empty, the lot counts as one, marked as a default. -->
                <dw-field [label]="t('lots.packageCount', { packaging: (kind | dwCode: 'packaging') })">
                  <input pInputText formControlName="package_count" inputmode="numeric" placeholder="1" />
                  <small dwHint>{{ t('lots.packageCountHint') }}</small>
                </dw-field>
              }
              @if (session.uses('colors')) {
                <dw-field [label]="t('lots.color')">
                  <dw-select formControlName="color_family">
                    <dw-option [value]="null" label="—" />
                    @for (color of vocabulary()?.color_families ?? []; track color.code) {
                      <dw-option [value]="color.code" [label]="(color.name | dwLocalized) | dwColorName: color.pantone_reference" [swatch]="color.hex_color" />
                    }
                  </dw-select>
                </dw-field>
              }
              <dw-field [label]="t('lots.grammage')"><input pInputText formControlName="grammage_gsm" inputmode="decimal" /></dw-field>
              @if (session.uses('production') && orders().length > 0) {
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
              <!-- What the waste is made of: known from the label, the supplier or the operator — no raw material needed. -->
              <div class="dw-span-2 composition">
                <h3 class="dw-h3">{{ t('lots.composition') }}</h3>
                @if (composition.length === 0) {
                  <p class="dw-faint hint">
                    @if (typeComposition(); as label) { {{ t('lots.compositionDefault', { label: label }) }} } @else { {{ t('lots.compositionHint') }} }
                    @if (chosenType()?.default_composition?.length) { <button type="button" class="linkish" (click)="useTypeComposition()">{{ t('lots.compositionUseType') }}</button> }
                  </p>
                }
                <dw-composition-editor [lines]="composition" />
              </div>
              <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="1000"></textarea></dw-field>
            </dw-more>
          </div>
        </div>
        <div class="dw-dialog-foot">
          <!-- L-03: print the label at once, and enter the next lot of the same place and type. -->
          <dw-checkbox class="print" [checked]="printLabel()" (checkedChange)="setPrint($event)">{{ t('lots.printOnCreate') }}</dw-checkbox>
          <span class="dw-spacer"></span>
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="button" severity="secondary" outlined [disabled]="busy() || compositionBlocked()" (click)="submit(true)">{{ t('lots.createAnother') }}</button>
          <button pButton type="submit" [disabled]="busy() || compositionBlocked()">{{ t('common.create') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { min-width: min(620px, 82vw); } .mismatch { color: var(--dw-warning); }
    .part { margin: 4px 0 -4px; font-size: 12px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--dw-ink-3); }
    .part:not(:first-child) { margin-block-start: 10px; padding-block-start: 14px; border-block-start: 1px solid var(--dw-line-soft); }
    .basis { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 14px; }
    .composition { display: grid; gap: var(--dw-2); } .hint { margin: 0; font-size: 13px; }
    .linkish { all: unset; cursor: pointer; color: var(--dw-primary); margin-inline-start: 6px; } .linkish:hover { text-decoration: underline; } .linkish:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: 2px; }
  `,
})
export class CreateLotDialog {
  private readonly fb = inject(FormBuilder);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly reference = inject(ReferenceData);
  private readonly ref = inject(DialogRef);

  protected readonly session = inject(Session);
  private readonly transloco = inject(TranslocoService);
  private readonly locale = inject(Locale);
  protected readonly sites = this.session.sitesFor('lots.create');
  protected readonly types = toSignal(this.reference.wasteTypes(), { initialValue: [] });
  /** Every zone of the sites where the user may create lots, as "Site · Zone" (L-01). */
  protected readonly places = signal<{ site: string; siteName: string; zone: Zone }[]>([]);
  protected readonly more = signal(false);
  protected readonly printLabel = signal(readFlag(PRINT_KEY));
  /** Lots created by "Créer et un autre" while the dialog stays open. */
  private readonly created = signal(0);
  protected readonly vocabulary = toSignal(this.reference.vocabulary());
  protected readonly zones = signal<Zone[]>([]);
  protected readonly busy = signal(false);
  /** One identifier for the whole life of the dialog: retrying after a network error cannot duplicate the lot. */
  private lotUlid = ulid();

  protected readonly form = inject(FormBuilder).nonNullable.group({
    site: [this.sites[0]?.ulid ?? '', Validators.required],
    zone: ['', Validators.required],
    waste_type: ['', Validators.required],
    // Required for weighed waste, optional for counted waste: checked on submit, once the type is known.
    net_weight_kg: ['', AMOUNT],
    quantity: ['', AMOUNT],
    // A guess of the person, not a reading of a scale: kept as such, and not accepted where a measure is required.
    weight_estimated: [false],
    package_count: ['', [Validators.pattern(/^[1-9]\d{0,5}$/)]],
    // Opened from the page of an OF: that OF is proposed.
    production_order: [(dialogData<{ production_order?: string } | undefined>()?.production_order ?? null) as string | null],
    raw_material: [null as string | null],
    packaging_type: [null as string | null],
    color_family: [null as string | null],
    grammage_gsm: [''],
    notes: [''],
  });
  /** Typed here, or left empty: the lot then takes the composition of its raw material, else the defaults of its type. */
  protected readonly composition = compositionArray(this.fb);
  protected readonly compositionTotal = compositionTotal;

  private readonly chosen = toSignal(this.form.valueChanges, { initialValue: this.form.getRawValue() });
  private readonly openOrders = toSignal(this.api.get<OpenOrder[]>('production/open-orders').pipe(catchError(() => of([] as OpenOrder[]))), { initialValue: [] });
  /** The manufacturing orders that come to the chosen site (an OF without stages may be anywhere). */
  protected readonly orders = computed(() => this.openOrders().filter((order) => order.steps.length === 0 || order.steps.some((step) => step.site === this.chosen().site)));
  /** The materials of the chosen OF: with a single one, the lot takes it without asking. */
  protected readonly materials = computed(() => this.openOrders().find((order) => order.ulid === this.chosen().production_order)?.materials ?? []);
  protected readonly chosenType = computed(() => this.types().find((type) => type.ulid === this.chosen().waste_type));
  /** The defaults of the chosen type, in words, as the lot will take them when nothing is typed. */
  protected readonly typeComposition = computed(() => (this.chosenType()?.default_composition ?? []).map((line) => `${line.material} ${Number(line.pct)} %`).join(' / '));
  /** How the lot will be packed: what is chosen here, else what the waste type plans; null = loose, no packages to count. */
  protected readonly packaging = computed(() => this.chosen().packaging_type ?? this.chosenType()?.packaging_type ?? null);
  /** The unit of a type counted in pieces or litres; null for weighed waste. */
  protected readonly countedUnit = computed(() => ((this.chosenType()?.measure_dimension ?? 'mass') === 'mass' ? null : (this.chosenType()?.unit ?? 'piece')));
  /** The type says how much one piece (or one m³) weighs: the weight is then computed when none is typed. */
  protected readonly convertible = computed(() => (this.chosenType()?.measure_dimension === 'count' ? !!this.chosenType()?.unit_weight_kg : !!this.chosenType()?.density_kg_m3));
  /** A warning, never a refusal: the operator may have a good reason to put the lot there. */
  protected readonly mismatch = computed(() => {
    const value = this.chosen();

    return zoneMismatch(this.zones().find((zone) => zone.ulid === value.zone), value.waste_type || null, value.color_family ?? null);
  });

  /** What the folded fields will hold when nobody opens them, in words. */
  protected readonly defaultsSummary = computed(() => {
    const type = this.chosenType();
    const t = (key: string, params?: Record<string, unknown>): string => this.transloco.translate(key, params);
    const parts: string[] = [];
    if (type?.packaging_type) {
      const name = this.vocabulary()?.packaging_types.find((entry) => entry.code === type.packaging_type)?.name;
      parts.push(t('lots.defaults.packaging', { name: name ? this.locale.pick(name, type.packaging_type) : type.packaging_type }));
    }
    if (this.typeComposition()) {
      parts.push(t('lots.defaults.composition', { label: this.typeComposition() }));
    }
    return parts.length ? parts.join(' · ') : t('lots.defaults.none');
  });

  constructor() {
    // L-01: all the places at once; the one used last is chosen again.
    forkJoin(this.sites.map((site) => this.reference.zones(site.ulid).pipe(catchError(() => of([] as Zone[]))))).subscribe((lists) => {
      const places = lists.flatMap((zones, index) => zones.map((zone) => ({ site: this.sites[index].ulid, siteName: this.sites[index].name, zone })));
      this.places.set(places);
      const last = readText(PLACE_KEY);
      const place = places.find((candidate) => candidate.zone.ulid === last) ?? places[0];
      if (place) {
        this.placeChosen(place.zone.ulid);
      }
    });
  }

  /** The zone decides the site. */
  protected placeChosen(zone: string): void {
    const place = this.places().find((candidate) => candidate.zone.ulid === zone);
    if (!place) {
      return;
    }
    this.zones.set(this.places().filter((candidate) => candidate.site === place.site).map((candidate) => candidate.zone));
    this.form.patchValue({ site: place.site, zone });
  }

  protected setPrint(on: boolean): void {
    this.printLabel.set(on);
    writeText(PRINT_KEY, on ? '1' : '0');
  }

  protected compositionBlocked(): boolean {
    return this.composition.length > 0 && compositionTotal(this.composition) !== 100;
  }

  /** Starts from the defaults of the type, to be corrected for this lot. */
  protected useTypeComposition(): void {
    this.composition.clear();
    (this.chosenType()?.default_composition ?? []).forEach((line) => this.composition.push(compositionLine(this.fb, line.material, String(Number(line.pct)))));
  }

  protected loadZones(site: string): void {
    this.form.controls.zone.reset('');
    this.reference.zones(site).subscribe((zones) => {
      this.zones.set(zones);
      this.form.controls.zone.setValue(zones[0]?.ulid ?? '');
    });
  }

  protected async submit(another: boolean): Promise<void> {
    const unit = this.countedUnit();
    // Counted waste needs its quantity. A weight is never required here: a lot may be registered before it is weighed.
    if (unit && this.form.controls.quantity.value.trim() === '') {
      this.form.controls.quantity.setErrors({ required: true });
    }
    if (this.form.invalid || this.composition.invalid || (this.composition.length > 0 && compositionTotal(this.composition) !== 100)) {
      this.form.markAllAsTouched();
      this.composition.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    try {
      const lot = await firstValueFrom(this.api.post<Lot>('lots', {
        composition: this.composition.length > 0 ? compositionValue(this.composition) : null,
        ...value, ulid: this.lotUlid, net_weight_kg: value.net_weight_kg ? decimal(value.net_weight_kg) : null,
        weight_estimated: value.net_weight_kg ? value.weight_estimated : false,
        package_count: this.packaging() && value.package_count ? Number(value.package_count) : null,
        quantity: unit ? decimal(value.quantity) : null, unit, grammage_gsm: value.grammage_gsm ? decimal(value.grammage_gsm) : null, notes: value.notes || null,
      }));
      this.toast.success('lots.created', { number: lot.lot_number });
      writeText(PLACE_KEY, value.zone);
      if (this.printLabel()) {
        this.api.blob(`lots/${lot.ulid}/label`).subscribe({ next: (blob) => openBlob(blob), error: (error: unknown) => this.toast.error(error) });
      }
      if (another) {
        // Same place, same type: only what changes from one lot to the next is emptied.
        this.created.update((count) => count + 1);
        this.lotUlid = ulid();
        this.form.patchValue({ net_weight_kg: '', quantity: '', package_count: '', notes: '', weight_estimated: false });
        this.form.markAsUntouched();
        this.composition.clear();
      } else {
        this.ref.close(lot);
      }
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

/**
 * What the lot is made of, entered or corrected on the lot itself: a company that does not follow its raw materials
 * or its production knows it here. Allowed while the lot is on site; a pickup that has not left follows.
 */
@Component({
  imports: [...DIALOG, ButtonDirective, ...DIALOG_PARTS, CompositionEditorComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('lots.editComposition') }} — {{ data.lot.lot_number }}</dw-dialog-title>
      <form (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <p class="dw-muted">{{ t('lots.compositionEditHint') }}</p>
          @if (data.lot.composition_source && data.lot.composition_source !== 'entered') { <p class="dw-faint">{{ t('lots.compositionSource.' + data.lot.composition_source) }}</p> }
          <dw-composition-editor [lines]="lines" />
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy() || (lines.length > 0 && total() !== 100)">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: '.dw-dialog-body { display: grid; gap: var(--dw-3); inline-size: min(520px, 88vw); } p { margin: 0; font-size: 13px; }',
})
export class LotCompositionDialog {
  protected readonly data = dialogData<LotActionData & { composition?: { material: string; pct: string }[] }>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly busy = signal(false);
  protected readonly lines = compositionArray(inject(FormBuilder), this.data.composition ?? []);
  private readonly value = toSignal(this.lines.valueChanges, { initialValue: this.lines.getRawValue() });
  protected readonly total = computed(() => {
    this.value();

    return compositionTotal(this.lines);
  });

  protected async submit(): Promise<void> {
    if (this.lines.invalid) {
      this.lines.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    try {
      this.ref.close(await firstValueFrom(this.api.put<Lot>(`lots/${this.data.lot.ulid}/composition`, { composition: compositionValue(this.lines), version: this.data.lot.version })));
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
          <p class="dw-muted">{{ t('lots.splitHint') }}@if (data.lot.packaging_type) { {{ t('lots.splitPackagesHint', { count: data.lot.package_count ?? 1 }) }} }</p>
          <div formArrayName="outputs" class="outputs">
            @for (control of outputs.controls; track control; let index = $index) {
              <div class="output">
                <dw-field>
                  <span dwLabel>{{ t('lots.splitOutput', { index: index + 1 }) }}</span>
                  <input pInputText [formControlName]="index" inputmode="decimal" required />
                </dw-field>
                @if (data.lot.packaging_type) {
                  <dw-field class="packages">
                    <span dwLabel>{{ t('lots.packages') }}</span>
                    <input pInputText [formControl]="packages.at(index)" inputmode="numeric" placeholder="1" />
                  </dw-field>
                }
                @if (outputs.length > 2) {
                  <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="outputs.removeAt(index); packages.removeAt(index); recompute()" [attr.aria-label]="t('common.remove')" [title]="t('common.remove')"><dw-icon>close</dw-icon></button>
                }
              </div>
            }
          </div>
          <button pButton severity="secondary" outlined type="button" (click)="add()" class="add"><dw-icon>add</dw-icon>{{ t('lots.addOutput') }}</button>
          <p class="split-total" [class.gap]="gap()" role="status">
            {{ t('lots.splitTotal', { total: (total() | dwNumber: 3), source: (data.lot.net_weight_kg | dwNumber: 3) }) }}
            @if (remaining() !== 0) { <strong>· {{ t(remaining() > 0 ? 'lots.splitRemaining' : 'lots.splitOver', { kg: ((remaining() > 0 ? remaining() : -remaining()) | dwNumber: 3) }) }}</strong> }
            @else { <span class="ok">· {{ t('lots.splitBalanced') }}</span> }
          </p>
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
    .add { justify-self: start; } .gap { color: var(--dw-warning); font-weight: 600; } .packages { max-width: 110px; }
    .split-total { padding: 10px 12px; border-radius: 10px; background: var(--dw-surface-2); font-variant-numeric: tabular-nums; } .ok { color: var(--dw-success); font-weight: 600; }
  `,
})
export class SplitLotDialog {
  protected readonly data = dialogData<LotActionData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly fb = inject(FormBuilder).nonNullable;
  private readonly ref = inject(DialogRef);

  /** LD-07: two lots of half each to start with, corrected from there. */
  private readonly half = Math.round((Number(this.data.lot.net_weight_kg ?? 0) / 2) * 1000) / 1000;
  protected readonly form = this.fb.group({
    outputs: this.fb.array([this.fb.control(this.half ? String(this.half) : '', WEIGHT), this.fb.control(this.half ? String(Math.round((Number(this.data.lot.net_weight_kg ?? 0) - this.half) * 1000) / 1000) : '', WEIGHT)]),
    notes: [''],
  });
  /** What is left to share out (negative: more than the lot). */
  protected readonly remaining = computed(() => Math.round((Number(this.data.lot.net_weight_kg ?? 0) - this.total()) * 1000) / 1000);
  /** The packages of each new lot, as counted after the split: never copied from the lot that is split. Empty = one, as a default. */
  protected readonly packages = this.fb.array([this.fb.control('', PACKAGES), this.fb.control('', PACKAGES)]);
  protected readonly busy = signal(false);
  protected readonly total = signal(0);
  /** Beyond 2 % of difference with the original weight, the API requires an explanation. */
  protected readonly gap = computed(() => {
    const source = Number(this.data.lot.net_weight_kg);

    return source > 0 && this.total() > 0 && Math.abs(this.total() - source) / source > 0.02;
  });

  constructor() {
    this.form.controls.outputs.valueChanges.subscribe(() => this.recompute());
    this.recompute();
  }

  protected get outputs(): FormArray {
    return this.form.controls.outputs;
  }

  protected add(): void {
    this.outputs.push(this.fb.control('', WEIGHT));
    this.packages.push(this.fb.control('', PACKAGES));
  }

  protected recompute(): void {
    this.total.set(this.form.controls.outputs.getRawValue().reduce((sum, value) => sum + (Number(decimal(value)) || 0), 0));
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid || this.packages.invalid) {
      this.form.markAllAsTouched();
      this.packages.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const packages = this.packages.getRawValue();
    try {
      const lots = await firstValueFrom(this.api.post<Lot[]>(`lots/${this.data.lot.ulid}/split`, {
        outputs: value.outputs.map((weight, index) => ({ net_weight_kg: decimal(weight), package_count: this.data.lot.packaging_type && packages[index] ? Number(packages[index]) : null })), notes: value.notes.trim() || null,
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
  imports: [...DIALOG, NumberPipe, ButtonDirective, InputText, ...FIELD, ...SELECT, ...DIALOG_PARTS],
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
        @if (data.lots[0].packaging_type) {
          <dw-field [label]="t('lots.groupPackages')">
            <input pInputText [formControl]="packages" inputmode="numeric" placeholder="1" />
            <small dwHint>{{ t('lots.groupPackagesHint') }}</small>
          </dw-field>
        }
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
  /** The packages of the grouped lot, as counted: three lots put in one bale are one package, not three. */
  protected readonly packages = inject(FormBuilder).nonNullable.control('', PACKAGES);
  protected readonly busy = signal(false);

  protected async submit(): Promise<void> {
    this.busy.set(true);
    try {
      const lot = await firstValueFrom(this.api.post<Lot>('lots/group', { lots: this.data.lots.map((item) => item.ulid), zone: this.zone.value, package_count: this.data.lots[0].packaging_type && this.packages.valid && this.packages.value ? Number(this.packages.value) : null }));
      this.toast.success('lots.groupDone', { number: lot.lot_number });
      this.ref.close(lot);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

/**
 * How many packages the lot is made of. On site it is a simple entry; once the lot has left it is a correction, with
 * its reason: the figure the pickup and the authorisation were decided with is kept, the corrected one stands beside it.
 */
@Component({
  imports: [...DIALOG, CodePipe, ButtonDirective, InputText, Textarea, ...FIELD, ...DIALOG_PARTS, AutoFocus],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('lots.packagesTitle') }} — {{ data.lot.lot_number }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body dw-stack-sm">
          <p class="dw-muted">{{ t(departed ? 'lots.packagesAfterDeparture' : 'lots.packagesOnSite') }}</p>
          <dw-field [label]="t('lots.packageCount', { packaging: (data.lot.packaging_type | dwCode: 'packaging') })">
            <input pInputText formControlName="package_count" inputmode="numeric" required [pAutoFocus]="true" />
            @if (!data.lot.package_count_confirmed) { <small dwHint>{{ t('lots.packagesDefaultHint') }}</small> }
          </dw-field>
          @if (departed) {
            <dw-field [label]="t('lots.packagesReason')">
              <textarea pTextarea formControlName="reason" rows="2" maxlength="500" required></textarea>
            </dw-field>
          }
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy() || form.invalid">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: '.dw-dialog-body { min-width: min(420px, 82vw); }',
})
export class LotPackagesDialog {
  protected readonly data = dialogData<LotActionData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  /** The lot has left the site: the count is corrected, not edited, and the correction says why. */
  protected readonly departed = !['created', 'stored', 'awaiting_pickup'].includes(this.data.lot.status);
  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    package_count: [String(this.data.lot.package_count ?? 1), [Validators.required, ...PACKAGES]],
    reason: ['', this.departed ? [Validators.required] : []],
  });

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    try {
      this.ref.close(await firstValueFrom(this.api.put<Lot>(`lots/${this.data.lot.ulid}/packages`, { package_count: Number(value.package_count), reason: value.reason.trim() || null })));
      this.toast.success('lots.packagesSaved');
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

export interface MoveLotsData {
  lots: Lot[];
}

/** L-04: several lots of one site to another zone at once; each move is recorded on its lot. */
@Component({
  imports: [...DIALOG, ButtonDirective, ...FIELD, ...SELECT, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('lots.moveSelected', { count: data.lots.length }) }}</dw-dialog-title>
      <div class="dw-dialog-body">
        <dw-field [label]="t('lots.targetZone')">
          <dw-select [value]="zone()" (valueChange)="zone.set($any($event))" required>
            @for (item of zones(); track item.ulid) { <dw-option [value]="item.ulid" [label]="item.name" /> }
          </dw-select>
        </dw-field>
      </div>
      <div class="dw-dialog-foot">
        <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
        <button pButton type="button" [disabled]="busy() || !zone()" (click)="submit()">{{ t('common.confirm') }}</button>
      </div>
    </ng-container>
  `,
  styles: `.dw-dialog-body { min-width: min(420px, 82vw); }`,
})
export class MoveLotsDialog {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  protected readonly data = dialogData<MoveLotsData>();
  protected readonly zones = signal<Zone[]>([]);
  protected readonly zone = signal('');
  protected readonly busy = signal(false);

  constructor() {
    inject(ReferenceData).zones(this.data.lots[0].site?.ulid ?? '').subscribe((zones) => this.zones.set(zones));
  }

  protected async submit(): Promise<void> {
    this.busy.set(true);
    let moved = 0;
    for (const lot of this.data.lots) {
      try {
        await firstValueFrom(this.api.post<Lot>(`lots/${lot.ulid}/store`, { zone: this.zone() }));
        moved++;
      } catch (error) {
        this.toast.error(error);
      }
    }
    this.busy.set(false);
    this.toast.success('lots.movedCount', { count: moved });
    this.ref.close(moved);
  }
}

