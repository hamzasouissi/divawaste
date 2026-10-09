import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';

import { Api } from '../../core/api/api';
import { Pickup, PickupLine } from '../../core/api/models';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { LocalizedPipe, MoneyPipe, QuantityPipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { DIALOG_PARTS, DialogRef, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';
import { counted, measure } from './tolerance';

export type LinesMode = 'collect' | 'receive' | 'treat' | 'price';

export interface LinesDialogData {
  pickup: Pickup;
  mode: LinesMode;
}

interface Row {
  line: PickupLine;
  checked: boolean;
  weight: string;
  channel: string;
  direction: 'sale' | 'cost';
  mode: 'per_kg' | 'per_tonne' | 'flat';
  price: string;
}

const decimal = (value: string): string => value.replace(',', '.');
const valid = (value: string): boolean => /^\d+([.,]\d{1,3})?$/.test(value);
/** At loading, a weighed lot may leave without a weight (it is then unknown, not zero); a counted lot leaves with its count. */
const loadable = (row: Row): boolean => valid(row.weight) || (row.weight.trim() === '' && !counted(row.line));

/**
 * The four per-lot steps of a pickup share one table: loading (industrial), reception and treatment (provider),
 * pricing (industrial). Each shows only its own column and sends only its own data.
 */
@Component({
  imports: [FormsModule, TranslocoDirective, QuantityPipe, LocalizedPipe, MoneyPipe, IconComponent, ButtonDirective, InputText, ...SELECT, CheckboxComponent, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('pickups.actions.' + data.mode) }} — {{ data.pickup.pickup_number }}</dw-dialog-title>
      <div class="dw-dialog-body">
        <p class="dw-muted hint">{{ t('pickups.' + data.mode + 'Hint') }}</p>
        <div class="scroll">
          <table class="dw-table">
            <thead>
              <tr>
                @if (data.mode === 'collect') { <th>{{ t('pickups.loaded') }}</th> }
                <th>{{ t('lots.number') }}</th><th>{{ t('fields.wasteType') }}</th>
                <th class="dw-num">{{ t('pickups.quantity.' + (data.mode === 'collect' ? 'declared' : data.mode === 'receive' ? 'departure' : 'received')) }}</th>
                @switch (data.mode) {
                  @case ('collect') { <th>{{ t('pickups.quantity.departure') }}</th> }
                  @case ('receive') { <th>{{ t('pickups.quantity.received') }}</th> }
                  @case ('treat') { <th>{{ t('pickups.channel') }}</th> }
                  @case ('price') { <th>{{ t('pickups.direction.label') }}</th><th>{{ t('pickups.pricingMode.label') }}</th><th>{{ t('pickups.unitPrice') }}</th><th class="dw-num">{{ t('pickups.amount') }}</th> }
                }
              </tr>
            </thead>
            <tbody>
              @for (row of rows(); track row.line.lot_number_snapshot; let index = $index) {
                <tr [class.off]="data.mode === 'collect' && !row.checked">
                  @if (data.mode === 'collect') {
                    <td><dw-checkbox [checked]="row.checked" (checkedChange)="patch(index, { checked: $event })" [ariaLabel]="row.line.lot_number_snapshot" /></td>
                  }
                  <td class="dw-mono">{{ row.line.lot_number_snapshot }} @if (row.line.is_hazardous) { <dw-icon class="hazard" [title]="t('common.hazardous')">warning</dw-icon> }</td>
                  <td>{{ row.line.waste_type_label_snapshot }}</td>
                  <td class="dw-num">{{ reference(row.line).value | dwQty: reference(row.line).unit }}</td>
                  @switch (data.mode) {
                    @case ('collect') {
                      <td><span class="measure"><input pInputText class="cell" inputmode="decimal" [disabled]="!row.checked" [ngModel]="row.weight" (ngModelChange)="patch(index, { weight: $event })" [attr.aria-label]="t('pickups.departure')" /><span class="unit">{{ t('units.' + unit(row.line), { count: 2 }) }}</span></span></td>
                    }
                    @case ('receive') {
                      <td><span class="measure"><input pInputText class="cell" inputmode="decimal" [ngModel]="row.weight" (ngModelChange)="patch(index, { weight: $event })" [attr.aria-label]="t('pickups.received')" /><span class="unit">{{ t('units.' + unit(row.line), { count: 2 }) }}</span></span></td>
                    }
                    @case ('treat') {
                      <td>
                        <dw-select class="cell wide" [ngModel]="row.channel" (ngModelChange)="patch(index, { channel: $event })" [ariaLabel]="t('pickups.channel')">
                          <dw-option value="" label="—" />
                          @for (channel of vocabulary()?.treatment_channels ?? []; track channel.code) { <dw-option [value]="channel.code" [label]="channel.name | dwLocalized" /> }
                        </dw-select>
                      </td>
                    }
                    @case ('price') {
                      <td>
                        <dw-select class="cell" [ngModel]="row.direction" (ngModelChange)="patch(index, { direction: $event })" [ariaLabel]="t('pickups.direction.label')">
                          <dw-option value="sale" [label]="t('pickups.direction.sale')" /><dw-option value="cost" [label]="t('pickups.direction.cost')" />
                        </dw-select>
                      </td>
                      <td>
                        <dw-select class="cell" [ngModel]="row.mode" (ngModelChange)="patch(index, { mode: $event })" [ariaLabel]="t('pickups.pricingMode.label')">
                          <dw-option value="per_kg" [label]="t('pickups.pricingMode.per_kg')" /><dw-option value="per_tonne" [label]="t('pickups.pricingMode.per_tonne')" /><dw-option value="flat" [label]="t('pickups.pricingMode.flat')" />
                        </dw-select>
                      </td>
                      <td><input pInputText class="cell" inputmode="decimal" [ngModel]="row.price" (ngModelChange)="patch(index, { price: $event })" [attr.aria-label]="t('pickups.unitPrice')" /></td>
                      <td class="dw-num" [class.neg]="amount(row) < 0">
                        @if (row.price && !priceable(row)) { <span class="neg">{{ t('pickups.priceNeedsWeight') }}</span> }
                        @else { {{ row.price ? (amount(row) | dwMoney: data.pickup.currency_code) : '—' }} }
                      </td>
                    }
                  }
                </tr>
              }
            </tbody>
          </table>
        </div>
        @if (data.mode === 'price') { <p class="total">{{ t('pickups.netAmount') }} : <strong [class.neg]="net() < 0">{{ net() | dwMoney: data.pickup.currency_code }}</strong></p> }
      </div>
      <div class="dw-dialog-foot">
        <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
        <button pButton type="button" [disabled]="busy() || !ready()" (click)="submit()">{{ t('common.save') }}</button>
      </div>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { min-width: min(820px, 88vw); } .hint { margin-block-end: var(--dw-3); font-size: 13px; }
    .scroll { overflow-x: auto; margin-inline: -20px; }
    td { padding-block: 4px; } tr.off td { color: var(--dw-ink-3); }
    th:first-child, td:first-child { padding-inline-start: 20px; } th:last-child, td:last-child { padding-inline-end: 20px; }
    .measure { display: inline-flex; align-items: center; gap: 6px; } .unit { color: var(--dw-ink-2); font-size: 13px; min-width: 44px; }
    .cell { width: 124px; } input.cell { text-align: end; font-variant-numeric: tabular-nums; } .cell.wide { width: 240px; }
    .hazard { font-size: 15px; width: 15px; height: 15px; color: var(--dw-warning); vertical-align: -2px; }
    .neg { color: var(--dw-danger); } .total { text-align: end; margin-block-start: var(--dw-3); }
  `,
})
export class PickupLinesDialog {
  protected readonly data = dialogData<LinesDialogData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly vocabulary = toSignal(inject(ReferenceData).vocabulary());
  protected readonly busy = signal(false);
  protected readonly rows = signal<Row[]>(
    (this.data.pickup.lots ?? [])
      .filter((line) => this.concerns(line))
      .map((line) => ({
        line, checked: true,
        // What is typed is the measure of the waste: a weight for weighed waste, a count for waste counted in pieces or litres.
        weight: this.initial(line),
        channel: line.treatment_channel ?? '', direction: Number(line.net_amount ?? 0) < 0 ? 'cost' : 'sale', mode: line.pricing_mode ?? (this.weightOf(line) === null ? 'flat' : 'per_kg'), price: line.unit_price ? Number(line.unit_price).toString() : '',
      })),
  );

  protected readonly net = computed(() => this.rows().reduce((sum, row) => sum + (row.price ? this.amount(row) : 0), 0));
  protected readonly ready = computed(() => {
    const rows = this.rows();
    switch (this.data.mode) {
      case 'collect':
        return rows.some((row) => row.checked) && rows.every((row) => !row.checked || loadable(row));
      case 'receive':
        return rows.length > 0 && rows.every((row) => valid(row.weight));
      case 'treat':
        return rows.some((row) => row.channel !== '');
      case 'price':
        return rows.some((row) => valid(row.price)) && rows.every((row) => !valid(row.price) || this.priceable(row));
    }
  });

  protected patch(index: number, change: Partial<Row>): void {
    this.rows.update((rows) => rows.map((row, position) => (position === index ? { ...row, ...change } : row)));
  }

  protected reference(line: PickupLine): { value: string | null; unit: string } {
    const stage = this.data.mode === 'collect' ? 'declared' : this.data.mode === 'receive' || measure(line, 'received').value === null ? 'departure' : 'received';

    return measure(line, stage);
  }

  protected unit(line: PickupLine): string {
    return measure(line, 'declared').unit;
  }

  private initial(line: PickupLine): string {
    const value = this.data.mode === 'collect' ? measure(line, 'declared').value : (measure(line, 'departure').value ?? measure(line, 'declared').value);

    return value === null ? '' : Number(value).toString();
  }

  /** The weight a price per kilogram can rest on: what was received, else what left — unless that is only an estimate. */
  private weightOf(line: PickupLine): number | null {
    if (line.received_weight_kg !== null) {
      return Number(line.received_weight_kg);
    }

    return line.departure_weight_kg === null || line.weight_basis === 'estimated' ? null : Number(line.departure_weight_kg);
  }

  /** A flat amount needs nothing; a price by weight needs a weight that was measured or computed. */
  protected priceable(row: Row): boolean {
    return row.mode === 'flat' || this.weightOf(row.line) !== null;
  }

  /** Same rule as the API: priced on the received weight when known, signed negative for a cost. */
  protected amount(row: Row): number {
    const weight = this.weightOf(row.line) ?? 0;
    const price = Number(decimal(row.price)) || 0;
    const gross = row.mode === 'per_kg' ? weight * price : row.mode === 'per_tonne' ? (weight * price) / 1000 : price;

    return row.direction === 'cost' ? -gross : gross;
  }

  protected async submit(): Promise<void> {
    const rows = this.rows();
    const number = (row: Row): string => row.line.lot_number_snapshot;
    const base = `pickups/${this.data.pickup.ulid}`;
    const request = {
      collect: () => this.api.post<Pickup>(`${base}/collect`, { lots: rows.filter((row) => row.checked).map((row) => ({ lot: row.line.lot, [counted(row.line) ? 'departure_quantity' : 'departure_weight_kg']: row.weight.trim() === '' ? null : decimal(row.weight) })) }),
      receive: () => this.api.post<Pickup>(`${base}/receive`, { lots: rows.map((row) => ({ lot_number: number(row), [counted(row.line) ? 'received_quantity' : 'received_weight_kg']: decimal(row.weight) })) }),
      treat: () => this.api.post<Pickup>(`${base}/treat`, { lots: rows.filter((row) => row.channel).map((row) => ({ lot_number: number(row), channel: row.channel })) }),
      price: () => this.api.put<Pickup>(`${base}/pricing`, { lots: rows.filter((row) => valid(row.price)).map((row) => ({ lot_number: number(row), direction: row.direction, pricing_mode: row.mode, unit_price: decimal(row.price) })) }),
    }[this.data.mode];

    this.busy.set(true);
    try {
      const pickup = await firstValueFrom(request());
      this.toast.success(`pickups.done.${{ collect: 'collected', receive: 'received', treat: 'treated', price: 'priced' }[this.data.mode]}`);
      this.ref.close(pickup);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  private concerns(line: PickupLine): boolean {
    switch (this.data.mode) {
      case 'collect':
        return line.line_status === 'planned';
      case 'receive':
        return line.line_status === 'loaded';
      case 'treat':
        return line.line_status === 'received';
      case 'price':
        return ['loaded', 'received', 'treated'].includes(line.line_status);
    }
  }
}

/** M5-04: recycling or destruction certificate, attached to lots of the pickup. */
@Component({
  imports: [FormsModule, TranslocoDirective, IconComponent, ButtonDirective, ...FIELD, ...SELECT, CheckboxComponent, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('pickups.actions.certificate') }} — {{ pickup.pickup_number }}</dw-dialog-title>
      <div class="dw-dialog-body dw-stack-sm">
        <dw-field [label]="t('pickups.certificateType.label')">
          <dw-select [ngModel]="type()" (ngModelChange)="type.set($event)">
            <dw-option value="recycling" [label]="t('pickups.certificateType.recycling')" />
            <dw-option value="destruction" [label]="t('pickups.certificateType.destruction')" />
          </dw-select>
        </dw-field>
        <fieldset>
          <legend class="dw-label">{{ t('pickups.lots') }}</legend>
          @for (line of lines; track line.lot_number_snapshot) {
            <dw-checkbox [checked]="selected().includes(line.lot_number_snapshot)" (checkedChange)="toggle(line.lot_number_snapshot)">
              <span class="dw-mono">{{ line.lot_number_snapshot }}</span> — {{ line.waste_type_label_snapshot }}
            </dw-checkbox>
          }
        </fieldset>
        <label class="file">
          <input type="file" accept="application/pdf,image/jpeg,image/png" (change)="pick($event)" />
          <dw-icon aria-hidden="true">upload_file</dw-icon><span>{{ file()?.name || t('pickups.certificateFile') }}</span>
        </label>
      </div>
      <div class="dw-dialog-foot">
        <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
        <button pButton type="button" [disabled]="busy() || !file() || selected().length === 0" (click)="submit()">{{ t('common.save') }}</button>
      </div>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { min-width: min(480px, 84vw); } fieldset { border: 0; padding: 0; margin: 0; display: grid; }
    .file { display: flex; align-items: center; gap: 8px; padding: 12px; border: 1px dashed var(--dw-line); border-radius: var(--dw-radius); cursor: pointer; font-size: 13px; }
    .file:focus-within { outline: 2px solid var(--dw-primary); outline-offset: 2px; } .file input { position: absolute; opacity: 0; width: 1px; height: 1px; }
  `,
})
export class CertificateDialog {
  protected readonly pickup = dialogData<Pickup>();
  private readonly http = inject(HttpClient);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly lines = (this.pickup.lots ?? []).filter((line) => ['loaded', 'received', 'treated'].includes(line.line_status));
  protected readonly type = signal<'recycling' | 'destruction'>('recycling');
  protected readonly selected = signal<string[]>(this.lines.map((line) => line.lot_number_snapshot));
  protected readonly file = signal<File | null>(null);
  protected readonly busy = signal(false);

  protected toggle(number: string): void {
    this.selected.update((items) => (items.includes(number) ? items.filter((item) => item !== number) : [...items, number]));
  }

  protected pick(event: Event): void {
    this.file.set((event.target as HTMLInputElement).files?.[0] ?? null);
  }

  protected async submit(): Promise<void> {
    const file = this.file();
    if (!file) {
      return;
    }
    const body = new FormData();
    body.append('file', file);
    body.append('type', this.type());
    this.selected().forEach((number) => body.append('lot_numbers[]', number));
    this.busy.set(true);
    try {
      await firstValueFrom(this.http.post(`/api/v1/pickups/${this.pickup.ulid}/certificates`, body));
      this.toast.success('pickups.done.certificate');
      this.ref.close(true);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
