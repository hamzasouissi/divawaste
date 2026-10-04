import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';

import { Api } from '../../core/api/api';
import { Pickup, PickupLine } from '../../core/api/models';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { KgPipe, LocalizedPipe, MoneyPipe } from '../../core/format/pipes';

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

/**
 * The four per-lot steps of a pickup share one table: loading (industrial), reception and treatment (provider),
 * pricing (industrial). Each shows only its own column and sends only its own data.
 */
@Component({
  imports: [FormsModule, MatButtonModule, MatCheckboxModule, MatDialogModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, TranslocoDirective, KgPipe, LocalizedPipe, MoneyPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ t('pickups.actions.' + data.mode) }} — {{ data.pickup.pickup_number }}</h2>
      <mat-dialog-content>
        <p class="dw-muted hint">{{ t('pickups.' + data.mode + 'Hint') }}</p>
        <div class="scroll">
          <table>
            <thead>
              <tr>
                @if (data.mode === 'collect') { <th>{{ t('pickups.loaded') }}</th> }
                <th>{{ t('lots.number') }}</th><th>{{ t('fields.wasteType') }}</th>
                <th class="dw-num">{{ t(data.mode === 'collect' ? 'pickups.declared' : data.mode === 'receive' ? 'pickups.departure' : 'pickups.received') }}</th>
                @switch (data.mode) {
                  @case ('collect') { <th>{{ t('pickups.departure') }} (kg)</th> }
                  @case ('receive') { <th>{{ t('pickups.received') }} (kg)</th> }
                  @case ('treat') { <th>{{ t('pickups.channel') }}</th> }
                  @case ('price') { <th>{{ t('pickups.direction.label') }}</th><th>{{ t('pickups.pricingMode.label') }}</th><th>{{ t('pickups.unitPrice') }}</th><th class="dw-num">{{ t('pickups.amount') }}</th> }
                }
              </tr>
            </thead>
            <tbody>
              @for (row of rows(); track row.line.lot_number_snapshot; let index = $index) {
                <tr [class.off]="data.mode === 'collect' && !row.checked">
                  @if (data.mode === 'collect') {
                    <td><mat-checkbox [checked]="row.checked" (change)="patch(index, { checked: $event.checked })" [attr.aria-label]="row.line.lot_number_snapshot" /></td>
                  }
                  <td class="dw-mono">{{ row.line.lot_number_snapshot }} @if (row.line.is_hazardous) { <mat-icon class="hazard" [title]="t('common.hazardous')">warning</mat-icon> }</td>
                  <td>{{ row.line.waste_type_label_snapshot }}</td>
                  <td class="dw-num">{{ reference(row.line) | dwKg }}</td>
                  @switch (data.mode) {
                    @case ('collect') {
                      <td><input class="cell" inputmode="decimal" [disabled]="!row.checked" [ngModel]="row.weight" (ngModelChange)="patch(index, { weight: $event })" [attr.aria-label]="t('pickups.departure')" /></td>
                    }
                    @case ('receive') {
                      <td><input class="cell" inputmode="decimal" [ngModel]="row.weight" (ngModelChange)="patch(index, { weight: $event })" [attr.aria-label]="t('pickups.received')" /></td>
                    }
                    @case ('treat') {
                      <td>
                        <select class="cell wide" [ngModel]="row.channel" (ngModelChange)="patch(index, { channel: $event })" [attr.aria-label]="t('pickups.channel')">
                          <option value="">—</option>
                          @for (channel of vocabulary()?.treatment_channels ?? []; track channel.code) { <option [value]="channel.code">{{ channel.name | dwLocalized }}</option> }
                        </select>
                      </td>
                    }
                    @case ('price') {
                      <td>
                        <select class="cell" [ngModel]="row.direction" (ngModelChange)="patch(index, { direction: $event })" [attr.aria-label]="t('pickups.direction.label')">
                          <option value="sale">{{ t('pickups.direction.sale') }}</option><option value="cost">{{ t('pickups.direction.cost') }}</option>
                        </select>
                      </td>
                      <td>
                        <select class="cell" [ngModel]="row.mode" (ngModelChange)="patch(index, { mode: $event })" [attr.aria-label]="t('pickups.pricingMode.label')">
                          <option value="per_kg">{{ t('pickups.pricingMode.per_kg') }}</option><option value="per_tonne">{{ t('pickups.pricingMode.per_tonne') }}</option><option value="flat">{{ t('pickups.pricingMode.flat') }}</option>
                        </select>
                      </td>
                      <td><input class="cell" inputmode="decimal" [ngModel]="row.price" (ngModelChange)="patch(index, { price: $event })" [attr.aria-label]="t('pickups.unitPrice')" /></td>
                      <td class="dw-num" [class.neg]="amount(row) < 0">{{ row.price ? (amount(row) | dwMoney: data.pickup.currency_code) : '—' }}</td>
                    }
                  }
                </tr>
              }
            </tbody>
          </table>
        </div>
        @if (data.mode === 'price') { <p class="total">{{ t('pickups.netAmount') }} : <strong [class.neg]="net() < 0">{{ net() | dwMoney: data.pickup.currency_code }}</strong></p> }
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ t('common.cancel') }}</button>
        <button mat-flat-button type="button" [disabled]="busy() || !ready()" (click)="submit()">{{ t('common.save') }}</button>
      </mat-dialog-actions>
    </ng-container>
  `,
  styles: `
    mat-dialog-content { min-width: min(820px, 88vw); } .hint { margin-block-end: var(--dw-3); font-size: 13px; }
    .scroll { overflow-x: auto; } table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { text-align: start; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dw-ink-3); padding: 6px 8px; border-block-end: 1px solid var(--dw-line); white-space: nowrap; }
    td { padding: 4px 8px; border-block-end: 1px solid var(--dw-line-soft); } tr.off td { color: var(--dw-ink-3); }
    .cell { width: 110px; height: 32px; padding-inline: 8px; border: 1px solid var(--dw-line); border-radius: var(--dw-radius); font: inherit; background: var(--dw-surface); }
    .cell.wide { width: 220px; } .cell:focus { outline: 2px solid var(--dw-primary); outline-offset: 0; border-color: var(--dw-primary); } .cell:disabled { background: var(--dw-surface-2); }
    .hazard { font-size: 15px; width: 15px; height: 15px; color: var(--dw-warning); vertical-align: -2px; }
    .neg { color: var(--dw-danger); } .total { text-align: end; margin-block-start: var(--dw-3); }
  `,
})
export class PickupLinesDialog {
  protected readonly data = inject<LinesDialogData>(MAT_DIALOG_DATA);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject<MatDialogRef<PickupLinesDialog, Pickup>>(MatDialogRef);

  protected readonly vocabulary = toSignal(inject(ReferenceData).vocabulary());
  protected readonly busy = signal(false);
  protected readonly rows = signal<Row[]>(
    (this.data.pickup.lots ?? [])
      .filter((line) => this.concerns(line))
      .map((line) => ({
        line, checked: true,
        weight: Number(this.data.mode === 'collect' ? line.declared_weight_kg : (line.departure_weight_kg ?? line.declared_weight_kg)).toString(),
        channel: line.treatment_channel ?? '', direction: Number(line.net_amount ?? 0) < 0 ? 'cost' : 'sale', mode: line.pricing_mode ?? 'per_kg', price: line.unit_price ? Number(line.unit_price).toString() : '',
      })),
  );

  protected readonly net = computed(() => this.rows().reduce((sum, row) => sum + (row.price ? this.amount(row) : 0), 0));
  protected readonly ready = computed(() => {
    const rows = this.rows();
    switch (this.data.mode) {
      case 'collect':
        return rows.some((row) => row.checked) && rows.every((row) => !row.checked || valid(row.weight));
      case 'receive':
        return rows.length > 0 && rows.every((row) => valid(row.weight));
      case 'treat':
        return rows.some((row) => row.channel !== '');
      case 'price':
        return rows.some((row) => valid(row.price));
    }
  });

  protected patch(index: number, change: Partial<Row>): void {
    this.rows.update((rows) => rows.map((row, position) => (position === index ? { ...row, ...change } : row)));
  }

  protected reference(line: PickupLine): string | null {
    return this.data.mode === 'collect' ? line.declared_weight_kg : this.data.mode === 'receive' ? line.departure_weight_kg : (line.received_weight_kg ?? line.departure_weight_kg);
  }

  /** Same rule as the API: priced on the received weight when known, signed negative for a cost. */
  protected amount(row: Row): number {
    const weight = Number(row.line.received_weight_kg ?? row.line.departure_weight_kg ?? 0);
    const price = Number(decimal(row.price)) || 0;
    const gross = row.mode === 'per_kg' ? weight * price : row.mode === 'per_tonne' ? (weight * price) / 1000 : price;

    return row.direction === 'cost' ? -gross : gross;
  }

  protected async submit(): Promise<void> {
    const rows = this.rows();
    const number = (row: Row): string => row.line.lot_number_snapshot;
    const base = `pickups/${this.data.pickup.ulid}`;
    const request = {
      collect: () => this.api.post<Pickup>(`${base}/collect`, { lots: rows.filter((row) => row.checked).map((row) => ({ lot: row.line.lot, departure_weight_kg: decimal(row.weight) })) }),
      receive: () => this.api.post<Pickup>(`${base}/receive`, { lots: rows.map((row) => ({ lot_number: number(row), received_weight_kg: decimal(row.weight) })) }),
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
  imports: [FormsModule, MatButtonModule, MatCheckboxModule, MatDialogModule, MatFormFieldModule, MatIconModule, MatSelectModule, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ t('pickups.actions.certificate') }} — {{ pickup.pickup_number }}</h2>
      <mat-dialog-content class="dw-stack-sm">
        <mat-form-field>
          <mat-label>{{ t('pickups.certificateType.label') }}</mat-label>
          <mat-select [ngModel]="type()" (ngModelChange)="type.set($event)">
            <mat-option value="recycling">{{ t('pickups.certificateType.recycling') }}</mat-option>
            <mat-option value="destruction">{{ t('pickups.certificateType.destruction') }}</mat-option>
          </mat-select>
        </mat-form-field>
        <fieldset>
          <legend class="dw-label">{{ t('pickups.lots') }}</legend>
          @for (line of lines; track line.lot_number_snapshot) {
            <mat-checkbox [checked]="selected().includes(line.lot_number_snapshot)" (change)="toggle(line.lot_number_snapshot)">
              <span class="dw-mono">{{ line.lot_number_snapshot }}</span> — {{ line.waste_type_label_snapshot }}
            </mat-checkbox>
          }
        </fieldset>
        <label class="file">
          <input type="file" accept="application/pdf,image/jpeg,image/png" (change)="pick($event)" />
          <mat-icon aria-hidden="true">upload_file</mat-icon><span>{{ file()?.name || t('pickups.certificateFile') }}</span>
        </label>
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ t('common.cancel') }}</button>
        <button mat-flat-button type="button" [disabled]="busy() || !file() || selected().length === 0" (click)="submit()">{{ t('common.save') }}</button>
      </mat-dialog-actions>
    </ng-container>
  `,
  styles: `
    mat-dialog-content { min-width: min(480px, 84vw); } fieldset { border: 0; padding: 0; margin: 0; display: grid; }
    .file { display: flex; align-items: center; gap: 8px; padding: 12px; border: 1px dashed var(--dw-line); border-radius: var(--dw-radius); cursor: pointer; font-size: 13px; }
    .file:focus-within { outline: 2px solid var(--dw-primary); outline-offset: 2px; } .file input { position: absolute; opacity: 0; width: 1px; height: 1px; }
  `,
})
export class CertificateDialog {
  protected readonly pickup = inject<Pickup>(MAT_DIALOG_DATA);
  private readonly http = inject(HttpClient);
  private readonly toast = inject(Toast);
  private readonly ref = inject<MatDialogRef<CertificateDialog, boolean>>(MatDialogRef);

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
