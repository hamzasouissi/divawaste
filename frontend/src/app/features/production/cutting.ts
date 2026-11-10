import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormArray, FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, KgPipe, NumberPipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { DateComponent } from '../../ui/date';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';
import { CuttingSpread, ManufacturingOrder, ProductionLists, SizeGrid, decimal } from './production-data';

/** "XS, S M;L" → ["XS", "S", "M", "L"]: sizes typed in one line, in order, each once. */
export const parseSizes = (text: string): string[] => [...new Set(text.split(/[\s,;]+/).map((size) => size.trim()).filter(Boolean))];

/** A grid of sizes: its name and its sizes in order, typed in one line. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, ...FIELD, ...DIALOG_PARTS, CheckboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'production.sizes.edit' : 'production.sizes.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field class="dw-span-2" [label]="t('production.sizes.name')"><input pInputText formControlName="name" maxlength="80" required [placeholder]="t('production.sizes.namePlaceholder')" /></dw-field>
            <dw-field class="dw-span-2" [label]="t('production.sizes.sizes')">
              <input pInputText formControlName="sizes" required placeholder="XS, S, M, L, XL, XXL" />
              <small dwHint>{{ t('production.sizes.sizesHint') }}</small>
            </dw-field>
            <div class="dw-span-2 preview">@for (size of preview(); track size) { <span class="chip">{{ size }}</span> }</div>
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
    .dw-dialog-body { inline-size: min(520px, 86vw); }
    .preview { display: flex; flex-wrap: wrap; gap: 6px; min-block-size: 24px; }
    .chip { padding: 2px 10px; border-radius: 999px; background: var(--dw-surface-2); font-size: 13px; font-weight: 600; }
  `,
})
export class SizeGridDialog {
  protected readonly data = dialogData<SizeGrid | null>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    name: [this.data?.name ?? '', Validators.required],
    sizes: [(this.data?.sizes ?? []).join(', '), Validators.required],
    is_active: [this.data?.is_active ?? true],
  });
  private readonly typed = toSignal(this.form.controls.sizes.valueChanges, { initialValue: this.form.controls.sizes.value });
  protected readonly preview = computed(() => parseSizes(this.typed()));

  protected async submit(): Promise<void> {
    if (this.form.invalid || this.preview().length === 0) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const body = { name: this.form.controls.name.value.trim(), sizes: this.preview(), is_active: this.form.controls.is_active.value };
    try {
      this.ref.close(await firstValueFrom(this.data ? this.api.put<SizeGrid>(`production/size-grids/${this.data.ulid}`, body) : this.api.post<SizeGrid>('production/size-grids', body)));
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

/** The company's size grids, in the catalogue. */
@Component({
  selector: 'dw-size-grids',
  imports: [TranslocoDirective, ButtonDirective, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <div class="head">
        <p class="dw-muted">{{ t('production.sizes.intro') }}</p>
        @if (canManage) { <button pButton type="button" (click)="edit(null)"><dw-icon>add</dw-icon>{{ t('production.sizes.new') }}</button> }
      </div>
      @if (grids().length === 0) {
        <p class="empty">{{ t('production.sizes.empty') }}</p>
      } @else {
        <table class="dw-table">
          <thead><tr><th>{{ t('production.sizes.name') }}</th><th>{{ t('production.sizes.sizes') }}</th><th class="num">{{ t('production.sizes.used') }}</th><th></th></tr></thead>
          <tbody>
            @for (grid of grids(); track grid.ulid) {
              <tr>
                <td><strong>{{ grid.name }}</strong>@if (!grid.is_active) { <span class="dw-faint"> · {{ t('common.inactive') }}</span> }</td>
                <td>@for (size of grid.sizes; track size) { <span class="chip">{{ size }}</span> }</td>
                <td class="num">{{ t('production.sizes.ofCount', { count: grid.orders }) }}</td>
                <td class="num">@if (canManage) { <button pButton text rounded size="small" severity="secondary" class="dw-icon-btn" type="button" (click)="edit(grid)" [attr.aria-label]="t('common.edit')" [title]="t('common.edit')"><dw-icon>edit</dw-icon></button> }</td>
              </tr>
            }
          </tbody>
        </table>
      }
    </ng-container>
  `,
  styles: `
    .head { display: flex; justify-content: space-between; align-items: center; gap: var(--dw-3); flex-wrap: wrap; padding: var(--dw-2) var(--dw-3); border-block-end: 1px solid var(--dw-line); }
    .head p { margin: 0; font-size: 13px; }
    .empty { margin: 0; padding: var(--dw-5) var(--dw-4); text-align: center; color: var(--dw-ink-3); }
    table { inline-size: 100%; } .num { text-align: end; white-space: nowrap; }
    .chip { display: inline-block; margin: 2px 4px 2px 0; padding: 1px 9px; border-radius: 999px; background: var(--dw-surface-2); font-size: 12px; font-weight: 600; }
  `,
})
export class SizeGridsComponent {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  protected readonly canManage = inject(Session).canEditProduction();
  protected readonly grids = signal<SizeGrid[]>([]);

  constructor() {
    this.load();
  }

  private load(): void {
    this.api.get<SizeGrid[]>('production/size-grids', { with_inactive: true }).subscribe((grids) => this.grids.set(grids));
  }

  protected edit(grid: SizeGrid | null): void {
    this.dialog.open<SizeGridDialog, SizeGrid | null, SizeGrid>(SizeGridDialog, { data: grid }).afterClosed().subscribe((saved) => saved && this.load());
  }
}

export interface SpreadDialogData {
  of: ManufacturingOrder;
  spread: CuttingSpread | null;
}

/**
 * A spread (matelas): the fabric, the placement (length, efficiency, pieces of each size per layer) and its layers,
 * planned then cut. What it makes and leaves is shown as the figures are typed.
 */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, ...FIELD, ...SELECT, ...DIALOG_PARTS, DateComponent, IconComponent, NumberPipe, KgPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data.spread ? 'production.cutting.editSpread' : 'production.cutting.newSpread') }} — {{ data.of.order_number }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field class="dw-span-2" [label]="t('production.cutting.fabric')">
              <dw-select formControlName="material" required>
                @for (m of fabrics(); track m.ulid) { <dw-option [value]="m.ulid" [label]="m.reference + (m.width_cm && m.grammage_gsm ? ' — ' + (+m.width_cm) + ' cm · ' + (+m.grammage_gsm) + ' g/m²' : '')" /> }
              </dw-select>
              @if (chosen() && (!chosen()!.width_cm || !chosen()!.grammage_gsm)) { <small class="dw-field-error">{{ t('production.cutting.noWidth') }}</small> }
            </dw-field>
            <dw-field [label]="t('production.cutting.markerLength')"><input pInputText formControlName="marker_length_m" inputmode="decimal" required placeholder="6,02" /></dw-field>
            <dw-field [label]="t('production.cutting.efficiency')"><input pInputText formControlName="efficiency_pct" inputmode="decimal" required placeholder="82" /></dw-field>

            <section class="dw-span-2 part">
              <h3 class="dw-h3">{{ t('production.cutting.ratio') }}</h3>
              <p class="dw-muted">{{ t('production.cutting.ratioHint') }}</p>
              <div class="ratio">
                @for (line of ratio.controls; track line; let i = $index) {
                  <label class="size" [formGroup]="line">
                    @if (fixedSizes) { <span>{{ line.value.size }}</span> }
                    @else { <input pInputText formControlName="size" maxlength="20" [placeholder]="t('production.cutting.size')" [attr.aria-label]="t('production.cutting.size')" /> }
                    <input pInputText type="number" min="0" formControlName="pieces" [attr.aria-label]="t('production.cutting.piecesOf', { size: line.value.size })" />
                  </label>
                }
                @if (!fixedSizes) { <button pButton text type="button" (click)="addSize()"><dw-icon>add</dw-icon>{{ t('production.cutting.addSize') }}</button> }
              </div>
            </section>

            <dw-field [label]="t('production.cutting.layersPlanned')"><input pInputText type="number" min="1" formControlName="layers_planned" required /></dw-field>
            <dw-field [label]="t('production.cutting.layersDone')"><input pInputText type="number" min="0" formControlName="layers_done" /><small dwHint>{{ t('production.cutting.layersDoneHint') }}</small></dw-field>
            <dw-field [label]="t('production.cutting.cutOn')"><dw-date formControlName="cut_on" /></dw-field>
            <dw-field [label]="t('production.received.one')">
              <dw-select formControlName="batch">
                <dw-option value="" [label]="t('production.of.anyBatch')" />
                @for (b of chosen()?.batches ?? []; track b.ulid) { <dw-option [value]="b.ulid" [label]="b.batch_number" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('production.cutting.allowance')"><input pInputText formControlName="end_allowance_cm" inputmode="decimal" placeholder="0" /><small dwHint>{{ t('production.cutting.allowanceHint') }}</small></dw-field>
            <dw-field [label]="t('fields.notes')"><input pInputText formControlName="notes" maxlength="500" /></dw-field>
          </div>

          @if (preview(); as p) {
            <div class="preview">
              <span><small>{{ t('production.cutting.piecesPerLayer') }}</small><strong>{{ p.perLayer }}</strong></span>
              <span><small>{{ t('production.cutting.pieces') }}</small><strong>{{ p.pieces | dwNumber }}</strong></span>
              <span><small>{{ t('production.cutting.fabricUsed') }}</small><strong>{{ p.metres | dwNumber: 1 }} m</strong>@if (p.kg !== null) { <span class="dw-faint"> · {{ p.kg | dwKg }}</span> }</span>
              <span><small>{{ t('production.cutting.expectedWaste') }}</small><strong>{{ p.waste === null ? '—' : (p.waste | dwKg) }}</strong></span>
            </div>
          }
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { inline-size: min(720px, 90vw); }
    .part { display: grid; gap: var(--dw-2); } .part p { margin: 0; font-size: 13px; }
    .ratio { display: flex; flex-wrap: wrap; gap: var(--dw-2); align-items: flex-end; }
    .size { display: grid; gap: 4px; inline-size: 84px; font-size: 13px; font-weight: 600; text-align: center; }
    .size input { inline-size: 100%; min-inline-size: 0; box-sizing: border-box; text-align: center; }
    .preview { display: flex; flex-wrap: wrap; gap: var(--dw-5); margin-block-start: var(--dw-4); padding: var(--dw-3) var(--dw-4); border-radius: var(--dw-radius); background: var(--dw-surface-2); }
    .preview span { display: grid; } .preview small { font-size: 12px; color: var(--dw-ink-3); }
  `,
})
export class SpreadDialog {
  protected readonly data = dialogData<SpreadDialogData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  private readonly fb = inject(FormBuilder);

  /** The fabrics: materials counted in metres, those with a laize and a grammage first. */
  private readonly materials = toSignal(inject(ProductionLists).materials(), { initialValue: [] });
  protected readonly fabrics = computed(() => this.materials().filter((m) => m.unit === 'm' && m.is_active)
    .sort((a, b) => Number(!!b.width_cm && !!b.grammage_gsm) - Number(!!a.width_cm && !!a.grammage_gsm) || a.reference.localeCompare(b.reference)));
  /** An OF that follows a grid cuts its sizes: one box per size, nothing to type. */
  protected readonly fixedSizes = this.data.of.sizes.length > 0;
  protected readonly busy = signal(false);
  protected readonly ratio: FormArray<FormGroup> = this.fb.array(this.initialRatio());
  protected readonly form = this.fb.nonNullable.group({
    material: [this.data.spread?.material.ulid ?? '', Validators.required],
    batch: [this.data.spread?.batch?.ulid ?? ''],
    marker_length_m: [this.data.spread ? String(+this.data.spread.marker_length_m) : '', [Validators.required, Validators.pattern(/^\d+([.,]\d{1,3})?$/)]],
    efficiency_pct: [this.data.spread ? String(+this.data.spread.efficiency_pct) : '', [Validators.required, Validators.pattern(/^\d{1,3}([.,]\d{1,2})?$/)]],
    end_allowance_cm: [this.data.spread?.end_allowance_cm ? String(+this.data.spread.end_allowance_cm) : '', Validators.pattern(/^\d{1,3}([.,]\d{1,2})?$/)],
    layers_planned: [this.data.spread?.layers_planned ?? (null as number | null), [Validators.required, Validators.min(1)]],
    layers_done: [this.data.spread?.layers_done ?? (null as number | null), Validators.min(0)],
    cut_on: [this.data.spread?.cut_on ?? ''],
    notes: [this.data.spread?.notes ?? ''],
    ratio: this.ratio,
  });
  private readonly value = toSignal(this.form.valueChanges, { initialValue: this.form.getRawValue() });
  protected readonly chosen = computed(() => this.materials().find((m) => m.ulid === this.value().material) ?? null);
  /** What the spread makes and leaves, as typed: the layers cut when given, else those planned. */
  protected readonly preview = computed(() => {
    const v = this.value();
    const perLayer = (v.ratio ?? []).reduce((sum, line) => sum + (Number(line['pieces']) || 0), 0);
    const layers = Number(v.layers_done ?? NaN) >= 0 && v.layers_done !== null && v.layers_done !== undefined ? Number(v.layers_done) : Number(v.layers_planned) || 0;
    const length = Number(decimal(v.marker_length_m ?? '') ?? 0) + Number(decimal(v.end_allowance_cm ?? '') ?? 0) / 100;
    if (!perLayer || !layers || !length) {
      return null;
    }
    const metres = layers * length;
    const m = this.chosen();
    const kg = m?.width_cm && m.grammage_gsm ? (metres * Number(m.width_cm) * Number(m.grammage_gsm)) / 100000 : null;
    const efficiency = Number(decimal(v.efficiency_pct ?? '') ?? NaN);

    return { perLayer, pieces: perLayer * layers, metres, kg, waste: kg !== null && efficiency > 0 && efficiency <= 100 ? (kg * (100 - efficiency)) / 100 : null };
  });

  private initialRatio(): FormGroup[] {
    const given = new Map((this.data.spread?.ratio ?? []).map((line) => [line.size, line.pieces]));
    const sizes = this.data.of.sizes.length > 0 ? this.data.of.sizes.map((line) => line.size) : (this.data.spread?.ratio ?? []).map((line) => line.size);

    return (sizes.length > 0 ? sizes : ['']).map((size) => this.sizeLine(size, given.get(size) ?? 0));
  }

  private sizeLine(size: string, pieces: number): FormGroup {
    return this.fb.nonNullable.group({ size: [size, Validators.required], pieces: [pieces, [Validators.required, Validators.min(0)]] });
  }

  protected addSize(): void {
    this.ratio.push(this.sizeLine('', 1));
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const v = this.form.getRawValue();
    const body = {
      material: v.material, batch: v.batch || null, marker_length_m: decimal(v.marker_length_m), efficiency_pct: decimal(v.efficiency_pct),
      end_allowance_cm: decimal(v.end_allowance_cm), layers_planned: v.layers_planned, layers_done: v.layers_done === null || (v.layers_done as unknown) === '' ? null : v.layers_done,
      cut_on: v.cut_on || null, notes: v.notes || null,
      ratio: v.ratio.map((line) => ({ size: String(line['size']).trim(), pieces: Number(line['pieces']) || 0 })).filter((line) => line.size !== ''),
    };
    try {
      const spread = this.data.spread;
      this.ref.close(await firstValueFrom(spread ? this.api.put<ManufacturingOrder>(`production/spreads/${spread.ulid}`, body)
        : this.api.post<ManufacturingOrder>(`production/manufacturing-orders/${this.data.of.ulid}/spreads`, body)));
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

/**
 * The cutting of an OF: its spreads, what they make of each size and what they are expected to leave, and the
 * metres cut of each fabric beside the metres the OF says it consumed.
 */
@Component({
  selector: 'dw-cutting',
  imports: [TranslocoDirective, RouterLink, ButtonDirective, IconComponent, NumberPipe, KgPipe, DatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <section class="dw-panel">
        <div class="dw-panel-head">
          <h2 class="dw-h2">{{ t('production.cutting.title') }}</h2>
          @if (canManage()) { <button pButton text size="small" type="button" (click)="edit(null)"><dw-icon>add</dw-icon>{{ t('production.cutting.newSpread') }}</button> }
        </div>
        <p class="dw-muted intro">{{ t('production.cutting.intro') }}</p>
        @if (of().cutting; as c) {
          <div class="scroll">
            <table class="dw-table">
              <thead><tr>
                <th>{{ t('production.cutting.fabric') }}</th><th class="num">{{ t('production.cutting.placement') }}</th><th>{{ t('production.cutting.ratio') }}</th>
                <th class="num">{{ t('production.cutting.layers') }}</th><th class="num">{{ t('production.cutting.pieces') }}</th>
                <th class="num">{{ t('production.cutting.fabricUsed') }}</th><th class="num">{{ t('production.cutting.expectedWaste') }}</th><th></th>
              </tr></thead>
              <tbody>
                @for (s of c.spreads; track s.ulid) {
                  @let f = s.done ?? s.planned;
                  <tr>
                    <td class="fabric"><a class="dw-mono" [routerLink]="['/production/catalog', s.material.ulid]">{{ s.material.reference }}</a>
                      <div class="dw-faint">@if (s.batch) { {{ s.batch.batch_number }} · }@if (s.cut_on) { {{ s.cut_on | dwDate }} · }{{ s.waste_type?.name ?? '' }}</div></td>
                    <td class="num">{{ +s.marker_length_m | dwNumber: 2 }} m<div class="dw-faint">{{ +s.efficiency_pct | dwNumber: 1 }} %</div></td>
                    <td>@for (r of s.ratio; track r.size) { <span class="chip">{{ r.size }} <b>{{ r.pieces }}</b></span> }</td>
                    <td class="num">@if (s.layers_done !== null) { <strong>{{ s.layers_done }}</strong> / {{ s.layers_planned }} } @else { <span class="tag">{{ t('production.cutting.toCut') }}</span> {{ s.layers_planned }} }</td>
                    <td class="num">{{ f.pieces | dwNumber }}</td>
                    <td class="num">{{ +f.metres | dwNumber: 1 }} m@if (f.fabric_kg !== null) { <div class="dw-faint">{{ f.fabric_kg | dwKg }}</div> }</td>
                    <td class="num"><strong>{{ f.waste_kg === null ? '—' : (f.waste_kg | dwKg) }}</strong></td>
                    <td class="act">
                      @if (canManage()) {
                        @if (s.layers_done && !s.consumption_recorded) {
                          <button pButton text rounded size="small" class="dw-icon-btn" type="button" (click)="record(s)" [attr.aria-label]="t('production.cutting.record')" [title]="t('production.cutting.recordHint')"><dw-icon>playlist_add</dw-icon></button>
                        } @else if (s.consumption_recorded) { <span class="recorded" [title]="t('production.cutting.recorded')" [attr.aria-label]="t('production.cutting.recorded')"><dw-icon>playlist_add_check</dw-icon></span> }
                        <button pButton text rounded size="small" severity="secondary" class="dw-icon-btn" type="button" (click)="edit(s)" [attr.aria-label]="t('common.edit')" [title]="t('common.edit')"><dw-icon>edit</dw-icon></button>
                        <button pButton text rounded size="small" severity="secondary" class="dw-icon-btn" type="button" (click)="remove(s)" [attr.aria-label]="t('common.delete')" [title]="t('common.delete')"><dw-icon>delete</dw-icon></button>
                      }
                    </td>
                  </tr>
                }
              </tbody>
              <tfoot><tr>
                <td colspan="4">{{ c.totals.done ? t('production.cutting.totalCut') : t('production.cutting.totalPlanned') }}@if (c.totals.spreads_to_cut > 0 && c.totals.done) { <span class="dw-faint"> · {{ t('production.cutting.stillToCut', { count: c.totals.spreads_to_cut }) }}</span> }
                  @if (c.spreads.length > 1) { <div class="dw-faint main">{{ t('production.cutting.mainFabric', { fabric: c.main_material.reference }) }}</div> }</td>
                <td class="num">{{ (c.totals.done ?? c.totals.planned).pieces | dwNumber }}</td>
                <td class="num">{{ +(c.totals.done ?? c.totals.planned).metres | dwNumber: 1 }} m</td>
                <td class="num"><strong>{{ (c.totals.done ?? c.totals.planned).waste_kg === null ? '—' : ((c.totals.done ?? c.totals.planned).waste_kg | dwKg) }}</strong></td><td></td>
              </tr></tfoot>
            </table>
          </div>

          <div class="split">
            <div>
              <h3 class="dw-h3">{{ t('production.cutting.bySize') }}</h3>
              <table class="dw-table">
                <thead><tr><th>{{ t('production.cutting.size') }}</th><th class="num">{{ t('production.cutting.ordered') }}</th><th class="num">{{ t('production.cutting.plannedPieces') }}</th>
                  <th class="num">{{ t('production.cutting.cutPieces') }}</th><th class="num" [title]="t('production.cutting.shareHint')">{{ t('production.cutting.wasteShare') }}</th></tr></thead>
                <tbody>
                  @for (row of c.by_size; track row.size) {
                    <tr>
                      <td><strong>{{ row.size }}</strong></td>
                      <td class="num">{{ row.ordered === null ? '—' : (row.ordered | dwNumber) }}</td>
                      <td class="num" [class.short]="row.ordered !== null && row.planned < row.ordered">{{ row.planned | dwNumber }}</td>
                      <td class="num" [class.short]="row.ordered !== null && row.cut !== null && row.cut < row.ordered">{{ row.cut === null ? '—' : (row.cut | dwNumber) }}</td>
                      <td class="num">{{ row.waste_kg === null ? '—' : (row.waste_kg | dwKg) }}</td>
                    </tr>
                  }
                </tbody>
              </table>
              <p class="dw-faint note">{{ t('production.cutting.shareHint') }}</p>
            </div>
            <div>
              <h3 class="dw-h3">{{ t('production.cutting.cutVsConsumed') }}</h3>
              @for (fab of c.fabrics; track fab.material.ulid) {
                <div class="fab">
                  <span class="dw-mono">{{ fab.material.reference }}</span>
                  <span>{{ t('production.cutting.cutM', { m: (fab.cut_m === null ? '—' : (+fab.cut_m | dwNumber: 1)) }) }} · {{ t('production.cutting.consumedM', { m: (+fab.consumed_m | dwNumber: 1) }) }}</span>
                </div>
              }
              <p class="dw-faint note">{{ t('production.cutting.cutVsConsumedHint') }}</p>
            </div>
          </div>
        } @else {
          <p class="empty">{{ t('production.cutting.empty') }}</p>
        }
      </section>
    </ng-container>
  `,
  styles: `
    .intro { margin: 0; padding: var(--dw-2) var(--dw-4) var(--dw-3); font-size: 13px; }
    .empty { margin: 0; padding: var(--dw-5) var(--dw-4); text-align: center; color: var(--dw-ink-3); }
    .scroll { overflow-x: auto; } table { inline-size: 100%; }
    .num { text-align: end; white-space: nowrap; } .act { text-align: end; white-space: nowrap; }
    .fabric { white-space: normal; min-inline-size: 160px; } .fabric a { display: block; max-inline-size: 220px; white-space: normal; overflow-wrap: anywhere; }
    .chip { display: inline-block; margin: 2px 4px 2px 0; padding: 1px 8px; border-radius: 999px; background: var(--dw-surface-2); font-size: 12px; white-space: nowrap; }
    .tag { display: inline-block; padding: 0 6px; border-radius: 4px; background: var(--dw-surface-2); color: var(--dw-ink-3); font-size: 11px; }
    .recorded { display: inline-flex; align-items: center; padding: 0 8px; vertical-align: middle; } .recorded dw-icon { font-size: 20px; color: var(--dw-success); }
    .split { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: var(--dw-5); padding: var(--dw-4); border-block-start: 1px solid var(--dw-line-soft); }
    @media (max-width: 900px) { .split { grid-template-columns: minmax(0, 1fr); } }
    .split h3 { margin: 0 0 var(--dw-2); }
    .short { color: var(--dw-danger); font-weight: 600; }
    .note { margin: var(--dw-2) 0 0; font-size: 12px; } .main { font-size: 12px; font-weight: 400; }
    .fab { display: grid; gap: 2px; padding: 6px 0; border-block-end: 1px solid var(--dw-line-soft); font-size: 13px; }
  `,
})
export class CuttingComponent {
  readonly of = input.required<ManufacturingOrder>();
  readonly canManage = input(false);
  readonly changed = output<ManufacturingOrder>();

  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly toast = inject(Toast);

  protected edit(spread: CuttingSpread | null): void {
    this.dialog.open<SpreadDialog, SpreadDialogData, ManufacturingOrder>(SpreadDialog, { data: { of: this.of(), spread } }).afterClosed()
      .subscribe((saved) => saved && this.changed.emit(saved));
  }

  /** The metres really cut become a consumption of the OF: asked for, once. */
  protected async record(spread: CuttingSpread): Promise<void> {
    try {
      this.changed.emit(await firstValueFrom(this.api.post<ManufacturingOrder>(`production/spreads/${spread.ulid}/consumption`, {})));
      this.toast.success('production.cutting.recordedToast');
    } catch (error) {
      this.toast.error(error);
    }
  }

  /** Deleted at once; "Annuler" enters the same spread again. */
  protected async remove(spread: CuttingSpread): Promise<void> {
    const of = this.of();
    try {
      this.changed.emit(await firstValueFrom(this.api.delete<ManufacturingOrder>(`production/spreads/${spread.ulid}`)));
      this.toast.undoable('production.cutting.deleted', () => void this.restore(of.ulid, spread), { fabric: spread.material.reference });
    } catch (error) {
      this.toast.error(error);
    }
  }

  private async restore(of: string, s: CuttingSpread): Promise<void> {
    try {
      this.changed.emit(await firstValueFrom(this.api.post<ManufacturingOrder>(`production/manufacturing-orders/${of}/spreads`, {
        material: s.material.ulid, batch: s.batch?.ulid ?? null, marker_length_m: s.marker_length_m, efficiency_pct: s.efficiency_pct, end_allowance_cm: s.end_allowance_cm,
        ratio: s.ratio, layers_planned: s.layers_planned, layers_done: s.layers_done, cut_on: s.cut_on, notes: s.notes,
      })));
    } catch (error) {
      this.toast.error(error);
    }
  }
}
