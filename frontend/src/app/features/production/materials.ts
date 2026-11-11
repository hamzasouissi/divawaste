import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { firstValueFrom, map } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Tab, TabList, TabPanel, TabPanels, Tabs } from 'primeng/tabs';
import { Textarea } from 'primeng/textarea';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { CodePipe, LocalizedPipe, NumberPipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { Column, TABLE } from '../../ui/data-table';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { FilterBarComponent } from '../../ui/filter-bar';
import { IconComponent } from '../../ui/icon';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { SELECT } from '../../ui/select';
import { Article, DECIMAL, KIND_ICON, MaterialKind, ProductionLists, RawMaterial, WasteMethod, blankToNull, decimal } from './production-data';

/** A figure typed with a comma or a point; nothing typed is 0. */
const num = (value: unknown): number => Number(String(value ?? '').replace(',', '.')) || 0;
import { Company, WasteType } from '../../core/api/models';
import { SizeGridsComponent } from './sizes';
import { WasteTypeDialog } from '../catalog/waste-types';
import { HelpComponent } from '../../ui/help';
import { COLOR } from '../../ui/swatch';

/** What a raw material can be, with its icon: what it is used for decides how its waste is estimated by default. */
export const MATERIAL_KINDS: { code: MaterialKind; icon: string }[] = [
  { code: 'fabric', icon: 'texture' }, { code: 'thread', icon: 'gesture' }, { code: 'trim', icon: 'radio_button_checked' },
  { code: 'packaging', icon: 'inventory_2' }, { code: 'chemical', icon: 'science' }, { code: 'other', icon: 'category' },
];

/**
 * A raw material: what it is, who supplies it, how it is counted, what it is made of, and the waste it leaves, each
 * type with its own calculation. Its composition and colour are what the waste coming from it will carry.
 */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, LocalizedPipe, NumberPipe, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...COLOR, ...DIALOG_PARTS, IconComponent, CheckboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'production.materials.edit' : 'production.materials.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field [label]="t('production.reference')"><input pInputText formControlName="reference" maxlength="60" required /></dw-field>
            <dw-field [label]="t('production.materials.unit')">
              <dw-select formControlName="unit" required>
                @for (unit of vocabulary()?.material_units ?? []; track unit.code) { <dw-option [value]="unit.code" [label]="(unit.name | dwLocalized) + ' (' + unit.symbol + ')'" /> }
              </dw-select>
            </dw-field>
            <dw-field class="dw-span-2" [label]="t('production.designation')"><input pInputText formControlName="name" maxlength="191" required /></dw-field>

            <div class="dw-span-2 kinds-field">
              <span class="label">{{ t('production.materials.kind') }}</span>
              <div class="kinds" role="radiogroup" [attr.aria-label]="t('production.materials.kind')">
                @for (k of kinds; track k.code) {
                  <button type="button" class="kind" role="radio" [class.on]="v().kind === k.code" [attr.aria-checked]="v().kind === k.code" (click)="pickKind(k.code)">
                    <dw-icon>{{ k.icon }}</dw-icon><span>{{ t('production.materials.kinds.' + k.code) }}</span>
                  </button>
                }
              </div>
            </div>

            <dw-field [label]="t('production.material.category')"><input pInputText formControlName="category" maxlength="80" /></dw-field>
            <dw-field [label]="t('production.material.defaultSupplier')">
              <dw-select formControlName="supplier">
                <dw-option value="" label="—" />
                @for (supplier of suppliers(); track supplier.ulid) { <dw-option [value]="supplier.ulid" [label]="supplier.name" /> }
              </dw-select>
              <small dwHint>{{ t('production.material.defaultSupplierHint') }}</small>
            </dw-field>
            @if (v().unit === 'm') {
              <dw-field [label]="t('lots.grammage')"><input pInputText formControlName="grammage_gsm" inputmode="decimal" /></dw-field>
              <dw-field [label]="t('production.materials.width')">
                <input pInputText formControlName="width_cm" inputmode="decimal" />
                <small dwHint>{{ t('production.materials.widthHint') }}</small>
              </dw-field>
            } @else if (v().unit !== 'kg') {
              <dw-field [label]="t('production.materials.unitWeight')">
                <input pInputText formControlName="unit_weight_kg" inputmode="decimal" placeholder="0,45" />
                <small dwHint>{{ t('production.materials.unitWeightHint') }}</small>
              </dw-field>
            }

            <section class="dw-span-2 waste">
              <header class="part-head">
                <div>
                  <h3 class="dw-h3">{{ t('production.materials.wasteProduced') }}</h3>
                  <p class="dw-muted">{{ t(waste.length === 0 ? (cuttable() ? 'production.materials.wasteProducedNone' : 'production.materials.wasteProducedNoneOther') : 'production.materials.wasteProducedHint') }}</p>
                </div>
                @if (canCreateType) { <button pButton text size="small" type="button" (click)="newType()"><dw-icon>add_circle</dw-icon>{{ t('production.materials.newWasteType') }}</button> }
              </header>
              @for (line of waste.controls; track line; let i = $index) {
                @let l = wasteLines()[i] ?? {};
                <div class="wline" [formGroup]="line">
                  <dw-select formControlName="waste_type" [ariaLabel]="t('production.materials.wasteType')" [placeholder]="t('production.materials.wasteTypePick')">
                    @for (type of wasteTypes(); track type.ulid) { <dw-option [value]="type.ulid" [label]="type.name" [disabled]="wasteTaken(type.ulid, i)" /> }
                  </dw-select>
                  <dw-select formControlName="method" [ariaLabel]="t('production.materials.method')">
                    @for (m of methods(); track m) { <dw-option [value]="m" [label]="t('production.materials.methods.' + m)" /> }
                  </dw-select>
                  @if (l['method'] !== 'whole' && !(l['method'] === 'cutting' && cuttingCount() === 1)) {
                    <span class="figure">
                      <input pInputText [formControlName]="figureOf(l['method'])" inputmode="decimal" [attr.aria-label]="t('production.materials.methods.' + l['method'])"
                        [class.bad]="tried() && !figure(l)" [placeholder]="l['method'] === 'per_unit' ? '0,025' : (l['method'] === 'loss_rate' ? '3' : '100')" />
                      <span class="suffix">{{ l['method'] === 'per_unit' ? t('production.materials.kgPer', { unit: unitName() }) : '%' }}</span>
                    </span>
                  } @else { <span class="figure all">100 %</span> }
                  <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="removeWaste(i)" [attr.aria-label]="t('common.remove')" [title]="t('common.remove')"><dw-icon>close</dw-icon></button>
                  <p class="how" [class.warn]="howWarns(l)">{{ how(l, t) }}</p>
                </div>
              }
              <button pButton text type="button" class="add" (click)="addWaste()"><dw-icon>add</dw-icon>{{ t(waste.length === 0 ? 'production.materials.addWasteFirst' : 'production.materials.addWaste') }}</button>
              @if (tried() && wasteMissing()) { <small class="dw-field-error" role="alert">{{ t('production.materials.wasteMissing') }}</small> }
              @if (cuttingCount() > 1 && cuttingTotal() !== 100) { <small class="dw-field-error" role="alert">{{ t('production.materials.wasteTotal', { total: cuttingTotal() | dwNumber: 2 }) }}</small> }
            </section>

            <dw-field [label]="t('lots.color')">
              <dw-select formControlName="color_family">
                <dw-option value="" label="—" />
                @for (color of vocabulary()?.color_families ?? []; track color.code) { <dw-option [value]="color.code" [label]="(color.name | dwLocalized) | dwColorName: color.pantone_reference" [swatch]="color.hex_color" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('production.materials.colorLabel')"><input pInputText formControlName="color_label" maxlength="100" placeholder="19-4052 TCX" /></dw-field>

            <section class="dw-span-2 part">
              <h3 class="dw-h3">{{ t('lots.composition') }}</h3>
              @for (line of composition.controls; track line; let i = $index) {
                <div class="line" [formGroup]="line">
                  <dw-select formControlName="material" [ariaLabel]="t('lots.composition')">
                    @for (fibre of vocabulary()?.materials ?? []; track fibre.code) { <dw-option [value]="fibre.code" [label]="fibre.name | dwLocalized" /> }
                  </dw-select>
                  <input pInputText formControlName="pct" inputmode="decimal" placeholder="%" [attr.aria-label]="'%'" />
                  <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="composition.removeAt(i)" [attr.aria-label]="t('common.remove')" [title]="t('common.remove')"><dw-icon>close</dw-icon></button>
                </div>
              }
              <button pButton text type="button" class="add" (click)="addFibre()"><dw-icon>add</dw-icon>{{ t('production.materials.addFibre') }}</button>
              @if (composition.length > 0 && total() !== 100) { <small class="dw-field-error" role="alert">{{ t('production.materials.total', { total: total() | dwNumber: 2 }) }}</small> }
            </section>

            <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="1000"></textarea></dw-field>
            @if (data) { <dw-checkbox class="dw-span-2" formControlName="is_active">{{ t('common.active') }}</dw-checkbox> }
          </div>

        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined (click)="ref.close(changed())">{{ t('common.close') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { inline-size: min(720px, 88vw); }
    .part { display: grid; gap: var(--dw-2); } .part p { margin: 0; font-size: 13px; }
    .line { display: grid; grid-template-columns: minmax(0, 1fr) 110px auto; gap: var(--dw-2); align-items: center; }
    .add { justify-self: start; }
    .label { display: block; margin-block-end: 6px; font-size: 13px; font-weight: 600; color: var(--dw-ink-2); }
    .kinds { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 6px; }
    @media (max-width: 640px) { .kinds { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    .kind { all: unset; box-sizing: border-box; cursor: pointer; display: grid; justify-items: center; gap: 4px; padding: 10px 4px 8px; border-radius: var(--dw-radius);
      background: var(--dw-surface); box-shadow: inset 0 0 0 1px var(--dw-line-strong); color: var(--dw-ink-2); font-size: 12.5px; font-weight: 600; text-align: center;
      transition: box-shadow 100ms, background 100ms, color 100ms; }
    .kind dw-icon { font-size: 22px; color: var(--dw-ink-3); }
    .kind:hover { box-shadow: inset 0 0 0 1px var(--dw-primary); color: var(--dw-ink); }
    .kind.on { background: var(--dw-primary-soft); box-shadow: inset 0 0 0 2px var(--dw-primary); color: var(--dw-primary-strong); } .kind.on dw-icon { color: var(--dw-primary); }
    .kind:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .waste { display: grid; gap: var(--dw-2); padding: var(--dw-4); border-radius: var(--dw-radius-lg); background: var(--dw-surface-2); }
    .part-head { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--dw-2); } .part-head h3 { margin: 0; } .part-head p { margin: 2px 0 0; font-size: 12.5px; }
    .part-head > div { flex: 1 1 auto; min-inline-size: 0; } .part-head button { flex: none; white-space: nowrap; }
    .wline { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1.1fr) 132px auto; gap: var(--dw-2); align-items: center; padding: 10px 10px 8px 12px;
      border-radius: var(--dw-radius); background: var(--dw-surface); box-shadow: var(--dw-shadow); }
    @media (max-width: 640px) { .wline { grid-template-columns: minmax(0, 1fr) auto; } .wline .figure { grid-column: 1; } }
    .figure { display: flex; align-items: center; gap: 6px; } .figure input { inline-size: 100%; min-inline-size: 0; text-align: end; }
    .figure .suffix { flex: none; font-size: 12.5px; color: var(--dw-ink-3); white-space: nowrap; }
    .figure.all { justify-content: flex-end; padding-inline-end: 6px; color: var(--dw-ink-3); font-size: 13px; }
    input.bad { border-color: var(--dw-danger); }
    .how { grid-column: 1 / -1; margin: 0; font-size: 12px; color: var(--dw-ink-3); } .how.warn { color: var(--dw-warning); }
  `,
})
export class MaterialDialog {
  protected readonly data = dialogData<RawMaterial | null>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  protected readonly ref = inject(DialogRef);
  private readonly fb = inject(FormBuilder);
  private readonly transloco = inject(TranslocoService);

  protected readonly kinds = MATERIAL_KINDS;
  protected readonly vocabulary = toSignal(inject(ReferenceData).vocabulary());
  protected readonly suppliers = toSignal(inject(ProductionLists).suppliers(), { initialValue: [] });
  private readonly reference = inject(ReferenceData);
  private readonly dialogs = inject(Dialogs);
  protected readonly canCreateType = inject(Session).can('waste_types.manage');
  protected readonly wasteTypes = signal<WasteType[]>([]);
  /** The waste it leaves, each type with its calculation. */
  protected readonly waste = this.fb.array((this.data?.waste_types_inherited ? [] : (this.data?.waste_types ?? []))
    .map((line) => this.wasteLine(line.waste_type?.ulid ?? '', line.method, line)));
  /** The saved material: deliveries can only be added to one that exists. */
  protected readonly material = signal<RawMaterial | null>(this.data);
  protected readonly changed = signal(false);
  protected readonly busy = signal(false);
  protected readonly tried = signal(false);
  protected readonly composition = this.fb.array((this.data?.composition ?? []).map((line) => this.fibre(line.material, line.pct)));
  protected readonly form = this.fb.nonNullable.group({
    reference: [this.data?.reference ?? '', Validators.required],
    name: [this.data?.name ?? '', Validators.required],
    unit: [this.data?.unit ?? 'm', Validators.required],
    kind: [(this.data?.kind ?? '') as MaterialKind | ''],
    category: [this.data?.category ?? ''],
    supplier: [this.data?.supplier?.ulid ?? ''],
    color_family: [this.data?.color_family ?? ''],
    color_label: [this.data?.color_label ?? ''],
    grammage_gsm: [this.data?.grammage_gsm ?? '', DECIMAL],
    width_cm: [this.data?.width_cm ?? '', DECIMAL],
    unit_weight_kg: [this.data?.unit_weight_kg ? String(+this.data.unit_weight_kg) : '', DECIMAL],
    waste_types: this.waste,
    notes: [this.data?.notes ?? ''],
    is_active: [this.data?.is_active ?? true],
    composition: this.composition,
  });
  protected readonly v = toSignal(this.form.valueChanges.pipe(map(() => this.form.getRawValue())), { initialValue: this.form.getRawValue() });
  private readonly lines = toSignal(this.composition.valueChanges, { initialValue: this.composition.getRawValue() });
  protected readonly wasteLines = toSignal(this.waste.valueChanges.pipe(map(() => this.waste.getRawValue())), { initialValue: this.waste.getRawValue() });
  /** Only a material in metres is cut from a placement. */
  protected readonly cuttable = computed(() => this.v().unit === 'm');
  protected readonly methods = computed(() => (this.cuttable() ? ['cutting', 'loss_rate', 'per_unit', 'whole'] : ['loss_rate', 'per_unit', 'whole']) as WasteMethod[]);
  protected readonly cuttingCount = computed(() => this.wasteLines().filter((line) => line['method'] === 'cutting').length);
  protected readonly cuttingTotal = computed(() => Math.round(this.wasteLines().filter((line) => line['method'] === 'cutting')
    .reduce((sum, line) => sum + num(line['share_pct']), 0) * 100) / 100);

  constructor() {
    this.loadTypes();
    // A material no longer in metres cannot share a cutting waste: its lines become a part of what is consumed.
    this.form.controls.unit.valueChanges.pipe(takeUntilDestroyed()).subscribe((unit) => {
      if (unit !== 'm') {
        this.waste.controls.filter((line) => line.value['method'] === 'cutting').forEach((line) => line.patchValue({ method: 'loss_rate' }));
      }
    });
  }

  private loadTypes(): void {
    this.reference.wasteTypes().subscribe((types) => this.wasteTypes.set(types));
  }

  protected pickKind(kind: MaterialKind): void {
    this.form.controls.kind.setValue(this.form.controls.kind.value === kind ? '' : kind);
    if (kind === 'fabric' && this.form.controls.unit.value !== 'm') {
      this.form.controls.unit.setValue('m');
    }
  }

  protected unitName(): string {
    const unit = this.v().unit;

    return this.transloco.translate(`production.materials.unitShort.${unit}`);
  }

  /** The box a calculation needs. */
  protected figureOf(method: unknown): string {
    return method === 'cutting' ? 'share_pct' : method === 'per_unit' ? 'kg_per_unit' : 'rate_pct';
  }

  protected figure(line: Record<string, unknown>): number {
    return line['method'] === 'whole' || (line['method'] === 'cutting' && this.cuttingCount() === 1) ? 1 : num(line[this.figureOf(line['method'])]);
  }

  /** In words, what the line will count, with the figures typed. */
  protected how(line: Record<string, unknown>, t: (key: string, params?: Record<string, unknown>) => string): string {
    const v = this.v();
    switch (line['method']) {
      case 'cutting':
        return t('production.materials.how.cutting', { pct: num(line['share_pct']) || '…' });
      case 'loss_rate':
        return t(v.unit === 'm' && !(num(v.width_cm) && num(v.grammage_gsm)) ? 'production.materials.how.needsWidth' : v.unit !== 'm' && v.unit !== 'kg' && !num(v.unit_weight_kg)
          ? 'production.materials.how.needsWeight' : 'production.materials.how.lossRate', { rate: num(line['rate_pct']) || '…' });
      case 'per_unit':
        return t('production.materials.how.perUnit', { kg: num(line['kg_per_unit']) || '…', unit: this.unitName() });
      default:
        return t(v.unit === 'm' && !(num(v.width_cm) && num(v.grammage_gsm)) ? 'production.materials.how.needsWidth' : v.unit !== 'm' && v.unit !== 'kg' && !num(v.unit_weight_kg)
          ? 'production.materials.how.needsWeight' : 'production.materials.how.whole');
    }
  }

  protected howWarns(line: Record<string, unknown>): boolean {
    const v = this.v();
    const noKg = (v.unit === 'm' && !(num(v.width_cm) && num(v.grammage_gsm))) || (v.unit !== 'm' && v.unit !== 'kg' && !num(v.unit_weight_kg));

    return (line['method'] === 'loss_rate' || line['method'] === 'whole') && noKg;
  }

  protected wasteMissing(): boolean {
    return this.wasteLines().some((line) => !line['waste_type']);
  }

  private wasteLine(type = '', method: WasteMethod = 'cutting', figures: Partial<Record<'share_pct' | 'rate_pct' | 'kg_per_unit', string | null>> = {}): FormGroup {
    const shown = (value: string | null | undefined) => (value === null || value === undefined || value === '' ? '' : String(+value));

    return this.fb.nonNullable.group({ waste_type: [type, Validators.required], method: [method],
      share_pct: [shown(figures.share_pct), DECIMAL], rate_pct: [shown(figures.rate_pct), DECIMAL], kg_per_unit: [shown(figures.kg_per_unit), DECIMAL] });
  }

  protected wasteTaken(ulid: string, index: number): boolean {
    return this.waste.controls.some((line, i) => i !== index && line.value.waste_type === ulid);
  }

  /** The calculation a new line starts with: what the material is decides it. */
  private defaultMethod(): WasteMethod {
    const v = this.v();

    return v.kind === 'packaging' ? 'whole' : v.unit === 'm' && (v.kind === 'fabric' || v.kind === '') ? 'cutting' : 'loss_rate';
  }

  /** A line more: a second cutting line shares the cutting waste with the first (what is free, or evenly). */
  protected addWaste(type = ''): void {
    const method = this.defaultMethod();
    const cutting = this.waste.controls.filter((line) => line.value['method'] === 'cutting');
    const free = Math.round((100 - this.cuttingTotal()) * 100) / 100;
    this.waste.push(this.wasteLine(type, method, method === 'cutting' ? { share_pct: cutting.length === 0 ? '100' : free > 0 ? String(free) : '' } : {}));
    if (method === 'cutting' && cutting.length > 0 && free <= 0) {
      const all = this.waste.controls.filter((line) => line.value['method'] === 'cutting');
      const even = Math.floor(10000 / all.length) / 100;
      all.forEach((line, i) => line.patchValue({ share_pct: String(i === 0 ? Math.round((100 - even * (all.length - 1)) * 100) / 100 : even) }));
    }
  }

  protected removeWaste(index: number): void {
    this.waste.removeAt(index);
    const cutting = this.waste.controls.filter((line) => line.value['method'] === 'cutting');
    if (cutting.length === 1) {
      cutting[0].patchValue({ share_pct: '100' });
    }
  }

  /** A type of waste the company does not have yet, made here and added to the material. */
  protected newType(): void {
    this.dialogs.open<WasteTypeDialog, WasteType | null, WasteType>(WasteTypeDialog, { data: null }).afterClosed().subscribe((type) => {
      if (type) {
        this.reference.wasteTypes().subscribe((types) => {
          this.wasteTypes.set(types.some((item) => item.ulid === type.ulid) ? types : [...types, type]);
          const empty = this.waste.controls.findIndex((line) => !line.value.waste_type);
          if (empty >= 0) {
            this.waste.at(empty).patchValue({ waste_type: type.ulid });
          } else {
            this.addWaste(type.ulid);
          }
        });
      }
    });
  }

  protected total(): number {
    return Math.round(this.lines().reduce((sum, line) => sum + Number(String(line['pct'] ?? '').replace(',', '.') || 0), 0) * 100) / 100;
  }

  private fibre(material = '', pct = ''): FormGroup {
    return this.fb.nonNullable.group({ material: [material, Validators.required], pct: [pct, [Validators.required, DECIMAL]] });
  }

  protected addFibre(): void {
    this.composition.push(this.fibre());
  }

  protected async submit(): Promise<void> {
    this.tried.set(true);
    const figuresMissing = this.wasteLines().some((line) => !this.figure(line));
    if (this.form.invalid || figuresMissing || (this.composition.length > 0 && this.total() !== 100) || (this.cuttingCount() > 1 && this.cuttingTotal() !== 100)) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const metres = value.unit === 'm';
    const body = blankToNull({
      ...value,
      kind: value.kind || null,
      grammage_gsm: metres ? decimal(value.grammage_gsm) : null, width_cm: metres ? decimal(value.width_cm) : null,
      unit_weight_kg: metres ? null : decimal(value.unit_weight_kg),
      composition: value.composition.map((line) => ({ material: line['material'] as string, pct: decimal(line['pct'] as string) })),
      waste_types: value.waste_types.map((line) => {
        const method = line['method'] as WasteMethod;
        const key = this.figureOf(method);

        return { waste_type: line['waste_type'] as string, method,
          ...(method === 'whole' ? {} : { [key]: method === 'cutting' && this.cuttingCount() === 1 ? '100' : decimal(line[key] as string) }) };
      }),
    });
    const current = this.material();
    try {
      const saved = await firstValueFrom(current ? this.api.put<RawMaterial>(`production/materials/${current.ulid}`, body) : this.api.post<RawMaterial>('production/materials', body));
      this.toast.success('common.saved');
      this.ref.close(saved);
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }


}

/** An article, the client it is made for, and what one piece is expected to consume. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...DIALOG_PARTS, IconComponent, CheckboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'production.articles.edit' : 'production.articles.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field [label]="t('production.reference')"><input pInputText formControlName="reference" maxlength="60" required /></dw-field>
            <dw-field [label]="t('production.articles.family')"><input pInputText formControlName="family" maxlength="80" /></dw-field>
            <dw-field class="dw-span-2" [label]="t('production.designation')"><input pInputText formControlName="name" maxlength="191" required /></dw-field>
            <dw-field class="dw-span-2" [label]="t('production.clients.one')">
              <dw-select formControlName="client">
                <dw-option value="" label="—" />
                @for (client of clients(); track client.ulid) { <dw-option [value]="client.ulid" [label]="client.name" /> }
              </dw-select>
            </dw-field>

            <section class="dw-span-2 part">
              <h3 class="dw-h3">{{ t('production.articles.consumption') }}</h3>
              <p class="dw-muted">{{ t('production.articles.consumptionHint') }}</p>
              @for (line of materials.controls; track line; let i = $index) {
                <div class="line" [formGroup]="line">
                  <dw-select formControlName="material" [ariaLabel]="t('production.materials.one')" [placeholder]="t('production.materials.one')">
                    @for (material of options(); track material.ulid) { <dw-option [value]="material.ulid" [label]="material.reference + ' — ' + material.name + ' (' + material.unit + ')'" [disabled]="taken(material.ulid, i)" /> }
                  </dw-select>
                  <input pInputText formControlName="quantity_per_piece" inputmode="decimal" [placeholder]="t('production.articles.perPiece')" [attr.aria-label]="t('production.articles.perPiece')" />
                  @if (isFabric(line.value.material)) {
                    <input pInputText formControlName="efficiency_pct" inputmode="decimal" [placeholder]="t('production.articles.efficiency')" [attr.aria-label]="t('production.articles.efficiency')"
                      [title]="t('production.articles.efficiencyHint', { pct: defaultEfficiency })" />
                  } @else { <span></span> }
                  <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="materials.removeAt(i)" [attr.aria-label]="t('common.remove')" [title]="t('common.remove')"><dw-icon>close</dw-icon></button>
                </div>
              }
              <button pButton text type="button" class="add" (click)="addMaterial()"><dw-icon>add</dw-icon>{{ t('production.articles.addMaterial') }}</button>
              <p class="dw-faint">{{ t('production.articles.efficiencyHint', { pct: defaultEfficiency }) }}</p>
            </section>

            <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="1000"></textarea></dw-field>
            @if (data) { <dw-checkbox class="dw-span-2" formControlName="is_active">{{ t('common.active') }}</dw-checkbox> }
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { inline-size: min(640px, 86vw); }
    .part { display: grid; gap: var(--dw-2); } .part p { margin: 0; font-size: 13px; }
    .line { display: grid; grid-template-columns: minmax(0, 1fr) 120px 110px auto; gap: var(--dw-2); align-items: center; }
    @media (max-width: 560px) { .line { grid-template-columns: minmax(0, 1fr) 90px 80px auto; } }
    .add { justify-self: start; }
  `,
})
export class ArticleDialog {
  protected readonly data = dialogData<Article | null>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  private readonly fb = inject(FormBuilder);
  private readonly lists = inject(ProductionLists);

  protected readonly clients = toSignal(this.lists.clients(), { initialValue: [] });
  protected readonly options = toSignal(this.lists.materials(), { initialValue: [] });
  protected readonly busy = signal(false);
  private readonly session = inject(Session);
  protected readonly defaultEfficiency = +(this.session.company()?.cutting_efficiency_pct ?? '80');
  protected readonly materials = this.fb.array((this.data?.materials ?? []).map((line) => this.line(line.material, line.quantity_per_piece, line.efficiency_pct ?? '')));
  protected readonly form = this.fb.nonNullable.group({
    reference: [this.data?.reference ?? '', Validators.required],
    name: [this.data?.name ?? '', Validators.required],
    family: [this.data?.family ?? ''],
    client: [this.data?.client?.ulid ?? ''],
    notes: [this.data?.notes ?? ''],
    is_active: [this.data?.is_active ?? true],
    materials: this.materials,
  });

  private line(material = '', quantity = '', efficiency = ''): FormGroup {
    return this.fb.nonNullable.group({ material: [material, Validators.required], quantity_per_piece: [quantity, [Validators.required, DECIMAL]],
      efficiency_pct: [efficiency ? String(+efficiency) : '', Validators.pattern(/^\d{1,3}([.,]\d{1,2})?$/)] });
  }

  /** A fabric is cut from a placement: counted in metres, with its width and its weight per m². */
  protected isFabric(ulid: string): boolean {
    const material = this.options().find((m) => m.ulid === ulid);

    return !!material && material.unit === 'm' && material.width_cm !== null && material.grammage_gsm !== null;
  }

  protected addMaterial(): void {
    this.materials.push(this.line());
  }

  protected taken(ulid: string, index: number): boolean {
    return this.materials.controls.some((line, i) => i !== index && line.value.material === ulid);
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const body = blankToNull({ ...value, materials: value.materials.map((line) => ({ material: line['material'] as string, quantity_per_piece: decimal(line['quantity_per_piece'] as string),
      efficiency_pct: this.isFabric(line['material'] as string) && line['efficiency_pct'] ? decimal(line['efficiency_pct'] as string) : null })) });
    try {
      this.ref.close(await firstValueFrom(this.data ? this.api.put<Article>(`production/articles/${this.data.ulid}`, body) : this.api.post<Article>('production/articles', body)));
      this.toast.success('common.saved');
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

/** Raw materials and articles: what is consumed, and what is made with it. */
@Component({
  imports: [
    HelpComponent,
    TranslocoDirective, PageHeaderComponent, ...TABLE, StatusComponent, SearchComponent, FilterBarComponent, CheckboxComponent, ButtonDirective, IconComponent, CodePipe, NumberPipe, ...COLOR,
    Tabs, TabList, Tab, TabPanels, TabPanel, InputText, SizeGridsComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('production.catalog.title')" [subtitle]="t('production.catalog.subtitle')" />
      <dw-help topic="productionCatalog" />
      <div class="dw-panel">
        <p-tabs [value]="0">
          <p-tablist><p-tab [value]="0">{{ t('production.materials.title') }}</p-tab><p-tab [value]="1">{{ t('production.articles.title') }}</p-tab><p-tab [value]="2">{{ t('production.sizes.tab') }}</p-tab></p-tablist>
          <p-tabpanels>
            <p-tabpanel [value]="0">
              <dw-filter-bar [active]="(materialSearch() ? 1 : 0) + (inactive() ? 1 : 0)" (cleared)="materialSearch.set(''); inactive.set(false); materials.reload()">
                <dw-search [placeholder]="t('common.search')" [query]="materialSearch()" (queryChange)="materialSearch.set($event); materials.reload()" />
                <dw-checkbox [checked]="inactive()" (checkedChange)="inactive.set($event); materials.reload(); articles.reload()">{{ t('production.showInactive') }}</dw-checkbox>
                <span class="dw-spacer"></span>
                @if (canManage) { <button pButton type="button" (click)="editMaterial(null)"><dw-icon>add</dw-icon>{{ t('production.materials.new') }}</button> }
              </dw-filter-bar>
              <dw-data-table [columns]="materialColumns" [rows]="materials.rows()" [loading]="materials.loading()" [error]="materials.error()" [total]="materials.total()" [pageIndex]="materials.page() - 1"
                [pageSize]="materials.perPage()" [clickable]="true" [emptyTitle]="t('production.materials.empty')" [emptyHint]="t('production.emptyHint')" emptyIcon="texture"
                (page)="materials.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="openMaterial($event)" (retry)="materials.reload()">
                <ng-template dwCell="reference" let-m><span class="dw-mono ref">{{ m.reference }}</span></ng-template>
                <ng-template dwCell="kind" let-m>@if (m.kind) { <span class="kind"><dw-icon>{{ kindIconOf(m.kind) }}</dw-icon>{{ t('production.materials.kinds.' + m.kind) }}</span> } @else { <span class="dw-faint">—</span> }</ng-template>
                <ng-template dwCell="composition" let-m>@for (line of m.composition; track line.material) { <span class="chip">{{ line.material | dwCode: 'material' }} {{ +line.pct }} %</span> } @empty { — }</ng-template>
                <ng-template dwCell="color" let-m>@if (m.color_family) { <dw-color [code]="m.color_family" /> }@if (m.color_family && m.color_label) { · }{{ m.color_label ?? (m.color_family ? '' : '—') }}</ng-template>
                <ng-template dwCell="batches" let-m>{{ m.batches.length || '—' }}</ng-template>
                <ng-template dwCell="status" let-m><dw-status kind="member" [value]="m.is_active ? 'active' : 'suspended'" [label]="t(m.is_active ? 'common.active' : 'common.inactive')" /></ng-template>
              </dw-data-table>
            </p-tabpanel>
            <p-tabpanel [value]="1">
              <dw-filter-bar [active]="articleSearch() ? 1 : 0" (cleared)="articleSearch.set(''); articles.reload()">
                <dw-search [placeholder]="t('common.search')" [query]="articleSearch()" (queryChange)="articleSearch.set($event); articles.reload()" />
                <span class="dw-spacer"></span>
                @if (canManage) { <button pButton type="button" (click)="editArticle(null)"><dw-icon>add</dw-icon>{{ t('production.articles.new') }}</button> }
              </dw-filter-bar>
              <!-- The efficiency a fabric is cut with when its article does not say its own. -->
              <div class="efficiency">
                @if (editingDefault()) {
                  <label class="dw-faint" for="default-efficiency">{{ t('production.articles.defaultEfficiencyLabel') }}</label>
                  <input pInputText id="default-efficiency" inputmode="decimal" class="pct" [value]="defaultEfficiency()" (input)="draftEfficiency.set($any($event.target).value)" />
                  <button pButton type="button" size="small" [disabled]="savingDefault()" (click)="saveDefault()">{{ t('common.save') }}</button>
                  <button pButton text size="small" type="button" severity="secondary" (click)="editingDefault.set(false)">{{ t('common.cancel') }}</button>
                } @else {
                  <span class="dw-faint" [title]="t('production.articles.cuttingFormula')">{{ t('production.articles.defaultEfficiency', { pct: defaultEfficiency() }) }}</span>
                  @if (session.can('company.update')) { <button pButton text size="small" type="button" (click)="draftEfficiency.set(defaultEfficiency()); editingDefault.set(true)"><dw-icon>edit</dw-icon>{{ t('common.edit') }}</button> }
                }
              </div>
              <dw-data-table [columns]="articleColumns" [rows]="articles.rows()" [loading]="articles.loading()" [error]="articles.error()" [total]="articles.total()" [pageIndex]="articles.page() - 1"
                [pageSize]="articles.perPage()" [clickable]="true" [emptyTitle]="t('production.articles.empty')" [emptyHint]="t('production.emptyHint')" emptyIcon="checkroom"
                (page)="articles.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="openArticle($event)" (retry)="articles.reload()">
                <ng-template dwCell="reference" let-a><span class="dw-mono ref">{{ a.reference }}</span></ng-template>
                <ng-template dwCell="consumption" let-a>{{ a.materials.length ? t('production.articles.materialsCount', { count: a.materials.length }) : '—' }}</ng-template>
                <ng-template dwCell="cutting" let-a>@if (a.cutting_waste.waste_kg_per_piece; as kg) {
                    <span [title]="t('production.articles.cuttingFormula')">{{ t('production.articles.cuttingWastePerPiece', { g: (+kg * 1000 | dwNumber: 1) }) }}</span>
                  } @else { <span class="dw-faint" [title]="t('production.articles.cuttingWasteNone')">—</span> }</ng-template>
                <ng-template dwCell="status" let-a><dw-status kind="member" [value]="a.is_active ? 'active' : 'suspended'" [label]="t(a.is_active ? 'common.active' : 'common.inactive')" /></ng-template>
              </dw-data-table>
            </p-tabpanel>
            <p-tabpanel [value]="2"><dw-size-grids /></p-tabpanel>
          </p-tabpanels>
        </p-tabs>
      </div>
    </ng-container>
  `,
  styles: `
    .chip { display: inline-block; margin-inline-end: 4px; padding: 1px 8px; border-radius: 999px; background: var(--dw-surface-2); font-size: 12px; }
    .kind { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; } .kind dw-icon { font-size: 17px; color: var(--dw-ink-3); }
    .efficiency { display: flex; flex-wrap: wrap; align-items: center; gap: var(--dw-2); padding: var(--dw-2) var(--dw-3); border-block-end: 1px solid var(--dw-line); font-size: 13px; }
    .efficiency .pct { inline-size: 90px; }
    .ref { display: block; max-inline-size: 280px; white-space: normal; overflow-wrap: anywhere; }
  `,
})
export class MaterialsPage {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  protected readonly session = inject(Session);
  private readonly dialog = inject(Dialogs);
  private readonly router = inject(Router);

  private readonly productionSession = inject(Session);
  protected get canManage(): boolean {
    return this.productionSession.canEditProduction();
  }
  protected readonly materialSearch = signal('');
  protected readonly articleSearch = signal('');
  protected readonly inactive = signal(false);
  protected readonly editingDefault = signal(false);
  protected readonly savingDefault = signal(false);
  protected readonly draftEfficiency = signal('');
  protected readonly defaultEfficiency = (): string => String(+(this.session.company()?.cutting_efficiency_pct ?? '80'));
  protected readonly materials = new ListStore<RawMaterial>((query) => this.api.list<RawMaterial>('production/materials', { ...query, search: this.materialSearch(), with_inactive: this.inactive() }));
  protected readonly articles = new ListStore<Article>((query) => this.api.list<Article>('production/articles', { ...query, search: this.articleSearch(), with_inactive: this.inactive() }));
  protected kindIconOf(kind: string): string {
    return KIND_ICON[kind as MaterialKind] ?? 'category';
  }
  protected readonly materialColumns: Column<RawMaterial>[] = [
    { key: 'reference', header: 'production.reference', wrap: true },
    { key: 'name', header: 'production.designation', value: (m) => (m.name === m.reference ? '' : m.name), wrap: true, hideBelow: 1200 },
    { key: 'supplier', header: 'production.suppliers.one', value: (m) => m.supplier?.name ?? '—', hideBelow: 900 },
    { key: 'kind', header: 'production.materials.kind', hideBelow: 1000 },
    { key: 'unit', header: 'production.materials.unit', value: (m) => ({ piece: 'pc' } as Record<string, string>)[m.unit] ?? m.unit, width: '80px', hideBelow: 900 },
    { key: 'composition', header: 'lots.composition', hideBelow: 1100 },
    { key: 'color', header: 'lots.color', hideBelow: 1100 },
    { key: 'batches', header: 'production.materials.batches', align: 'end', hideBelow: 1100 },
    { key: 'status', header: 'fields.status' },
  ];
  protected readonly articleColumns: Column<Article>[] = [
    { key: 'reference', header: 'production.reference', width: '260px', wrap: true },
    { key: 'name', header: 'production.designation', value: (a) => (a.name === a.reference ? '' : a.name), wrap: true, hideBelow: 1200 },
    { key: 'family', header: 'production.articles.family', value: (a) => a.family ?? '—', hideBelow: 1200 },
    { key: 'client', header: 'production.clients.one', value: (a) => a.client?.name ?? '—', hideBelow: 1200 },
    { key: 'consumption', header: 'production.articles.materialsHeader', align: 'end', width: '110px', hideBelow: 900 },
    { key: 'cutting', header: 'production.articles.cuttingWaste', align: 'end', width: '170px' },
    { key: 'status', header: 'fields.status' },
  ];

  /** The company's usual placement efficiency: every fabric line without its own follows it. */
  protected async saveDefault(): Promise<void> {
    // The API checks it (a percentage above 0, at most 100) and says what is wrong.
    const value = this.draftEfficiency().trim().replace(',', '.');
    this.savingDefault.set(true);
    try {
      this.session.setCompany(await firstValueFrom(this.api.put<Company>('company', { cutting_efficiency_pct: value })));
      this.editingDefault.set(false);
      this.articles.reload();
      this.toast.success('common.saved');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.savingDefault.set(false);
    }
  }

  /** The page of the material: its received lots, its consumption, its prices. */
  protected openMaterial(material: RawMaterial): void {
    void this.router.navigate(['/production/catalog', material.ulid]);
  }

  /** A new material opens on its own page, where its received lots are recorded. */
  protected editMaterial(material: RawMaterial | null): void {
    this.dialog.open<MaterialDialog, RawMaterial | null, RawMaterial | boolean>(MaterialDialog, { data: material }).afterClosed()
      .subscribe((saved) => (saved && typeof saved === 'object' ? this.openMaterial(saved) : saved && this.materials.reload()));
  }

  /** The page of the article: its composition, line by line, and the waste its cutting is expected to leave. */
  protected openArticle(article: Article): void {
    void this.router.navigate(['/production/articles', article.ulid]);
  }

  /** A new article opens on its page once saved. */
  protected editArticle(article: Article | null): void {
    this.dialog.open<ArticleDialog, Article | null, Article>(ArticleDialog, { data: article }).afterClosed().subscribe((saved) => saved && this.openArticle(saved));
  }
}
