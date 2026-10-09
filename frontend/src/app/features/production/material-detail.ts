import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Tab, TabList, TabPanel, TabPanels, Tabs } from 'primeng/tabs';
import { Textarea } from 'primeng/textarea';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { CodePipe, DatePipe, KgPipe, MoneyPipe, NumberPipe } from '../../core/format/pipes';
import { DateComponent } from '../../ui/date';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { KpiComponent, PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SELECT } from '../../ui/select';
import { StateComponent } from '../../ui/state';
import { COLOR } from '../../ui/swatch';
import { MaterialDialog } from './materials';
import { AMOUNT, DECIMAL, MaterialBatch, ProductionLists, RawMaterial, blankToNull, decimal, statusTone } from './production-data';

export interface ReceivedLotDialogData {
  material: Pick<RawMaterial, 'ulid' | 'reference' | 'name' | 'unit' | 'supplier'>;
  batch: MaterialBatch | null;
}

/**
 * A received lot: one delivery of a raw material. Its supplier is proposed from the material and can be another one
 * for this delivery. Quantity, unit price and amount say the same thing twice: two of them are enough.
 */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...DIALOG_PARTS, DateComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data.batch ? 'production.received.edit' : 'production.received.new') }} — {{ data.material.reference }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field [label]="t('production.received.number')"><input pInputText formControlName="batch_number" maxlength="60" required /></dw-field>
            <dw-field [label]="t('production.received.receivedOn')"><dw-date formControlName="received_on" /></dw-field>
            <dw-field [label]="t('production.received.supplier')">
              <dw-select formControlName="supplier">
                <dw-option value="" label="—" />
                @for (supplier of suppliers(); track supplier.ulid) { <dw-option [value]="supplier.ulid" [label]="supplier.name" /> }
              </dw-select>
              <small dwHint>{{ t('production.received.supplierHint') }}</small>
            </dw-field>
            <dw-field [label]="t('production.received.supplierReference')"><input pInputText formControlName="supplier_batch_reference" maxlength="80" /></dw-field>
            <dw-field [label]="t('production.received.quantity', { unit: data.material.unit })"><input pInputText formControlName="quantity" inputmode="decimal" /></dw-field>
            <dw-field [label]="t('production.received.deliveryNote')"><input pInputText formControlName="delivery_note" maxlength="80" /></dw-field>

            @if (money) {
              <h3 class="dw-h3 dw-form-section">{{ t('production.received.purchase') }}</h3>
              <dw-field [label]="t('production.received.unitPrice', { unit: data.material.unit })"><input pInputText formControlName="unit_price" inputmode="decimal" /></dw-field>
              <dw-field [label]="t('production.received.totalAmount')">
                <input pInputText formControlName="total_amount" inputmode="decimal" />
                <small dwHint>{{ t('production.received.amountHint') }}</small>
              </dw-field>
              <dw-field [label]="t('production.received.deliveryCost')">
                <input pInputText formControlName="delivery_cost" inputmode="decimal" />
                <small dwHint>{{ t('production.received.deliveryCostHint') }}</small>
              </dw-field>
              <dw-field [label]="t('production.currency')"><input pInputText formControlName="currency_code" maxlength="3" [placeholder]="currency" class="dw-mono" /></dw-field>
              <dw-field [label]="t('production.received.taxRate')"><input pInputText formControlName="tax_rate_pct" inputmode="decimal" /></dw-field>
              <dw-field [label]="t('production.received.invoice')"><input pInputText formControlName="invoice_reference" maxlength="80" /></dw-field>
            }
            <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="500"></textarea></dw-field>
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: '.dw-dialog-body { inline-size: min(680px, 88vw); }',
})
export class ReceivedLotDialog {
  protected readonly data = dialogData<ReceivedLotDialogData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  private readonly session = inject(Session);

  protected readonly money = this.session.can('production.finance.view');
  protected readonly currency = this.session.company()?.currency_code ?? '';
  protected readonly suppliers = toSignal(inject(ProductionLists).suppliers(), { initialValue: [] });
  protected readonly busy = signal(false);
  private readonly batch = this.data.batch;
  protected readonly form = inject(FormBuilder).nonNullable.group({
    batch_number: [this.batch?.batch_number ?? '', Validators.required],
    received_on: [this.batch?.received_on ?? ''],
    // A new lot is proposed the supplier of its material; an existing one shows its own.
    supplier: [this.batch ? (this.batch.supplier?.ulid ?? '') : (this.data.material.supplier?.ulid ?? '')],
    supplier_batch_reference: [this.batch?.supplier_batch_reference ?? ''],
    quantity: [this.batch?.quantity ? String(+this.batch.quantity) : '', DECIMAL],
    delivery_note: [this.batch?.delivery_note ?? ''],
    unit_price: [this.batch?.unit_price ? String(+this.batch.unit_price) : '', DECIMAL],
    total_amount: [this.batch?.total_amount ? String(+this.batch.total_amount) : '', AMOUNT],
    delivery_cost: [this.batch?.delivery_cost ? String(+this.batch.delivery_cost) : '', AMOUNT],
    currency_code: [this.batch?.currency_code ?? ''],
    tax_rate_pct: [this.batch?.tax_rate_pct ? String(+this.batch.tax_rate_pct) : ''],
    invoice_reference: [this.batch?.invoice_reference ?? ''],
    notes: [this.batch?.notes ?? ''],
  });

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const body = blankToNull({
      ...value, quantity: decimal(value.quantity), unit_price: decimal(value.unit_price), total_amount: decimal(value.total_amount), delivery_cost: decimal(value.delivery_cost),
      tax_rate_pct: decimal(value.tax_rate_pct), currency_code: value.currency_code.trim().toUpperCase(),
    });
    try {
      this.ref.close(await firstValueFrom(this.batch
        ? this.api.put<RawMaterial>(`production/batches/${this.batch.ulid}`, body)
        : this.api.post<RawMaterial>(`production/materials/${this.data.material.ulid}/batches`, body)));
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
 * Everything about one raw material on one page: what it is, the lots received of it (each with its supplier and
 * its price), where it was consumed, and what it cost over time.
 */
@Component({
  imports: [
    RouterLink, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, KpiComponent, ButtonDirective, IconComponent, DatePipe, NumberPipe, MoneyPipe, KgPipe, CodePipe, ...COLOR,
    Tabs, TabList, Tab, TabPanels, TabPanel,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (material(); as m) {
          <dw-page-header [title]="m.reference + ' — ' + m.name" back="/production/catalog" [backLabel]="t('production.materials.title')">
            <dw-status dwTitleBadge kind="member" [value]="m.is_active ? 'active' : 'suspended'" [label]="t(m.is_active ? 'common.active' : 'common.inactive')" />
            @if (canManage) {
              <button pButton severity="secondary" outlined type="button" (click)="edit(m)"><dw-icon>edit</dw-icon>{{ t('common.edit') }}</button>
              <button pButton type="button" (click)="receive(m, null)"><dw-icon>add</dw-icon>{{ t('production.received.new') }}</button>
            }
          </dw-page-header>

          <div class="dw-grid-4 kpis">
            <dw-kpi [label]="t('production.received.title')" [value]="m.summary?.received_lots ?? 0"
              [hint]="m.summary?.received ? t('production.material.receivedHint', { quantity: (m.summary?.received | dwNumber: 1), unit: m.unit }) : t('production.material.noQuantity')" />
            <dw-kpi [label]="t('production.material.consumed')" [value]="(m.summary?.consumed | dwNumber: 1) + ' ' + m.unit" [hint]="t('production.material.inOrders', { count: m.summary?.orders ?? 0 })" />
            <dw-kpi [label]="t('production.material.unallocated')" [value]="(m.summary?.unallocated | dwNumber: 1) + ' ' + m.unit" [hint]="t('production.material.unallocatedHint')"
              [tone]="+(m.summary?.unallocated ?? 0) > 0 ? 'warning' : 'neutral'" />
            @if (money) {
              <dw-kpi [label]="t('production.material.averagePrice', { unit: m.unit })" [value]="m.average_unit_cost ? (m.average_unit_cost | dwMoney: currency) : t('production.unknown')"
                [hint]="(m.summary?.unpriced_lots ?? 0) > 0 ? t('production.material.unpriced', { count: m.summary?.unpriced_lots }) : t('production.material.purchased', { amount: (m.summary?.purchase_value | dwMoney: currency) })"
                [tone]="(m.summary?.unpriced_lots ?? 0) > 0 ? 'warning' : 'neutral'" />
            } @else {
              <dw-kpi [label]="t('production.material.waste')" [value]="m.summary?.waste_kg | dwKg" [hint]="t('production.material.wasteLots', { count: m.summary?.waste_lots ?? 0 })" />
            }
          </div>

          <div class="dw-panel">
            <p-tabs [value]="0">
              <p-tablist>
                <p-tab [value]="0">{{ t('production.material.overview') }}</p-tab>
                <p-tab [value]="1">{{ t('production.received.title') }} ({{ m.batches.length }})</p-tab>
                <p-tab [value]="2">{{ t('production.material.history') }}</p-tab>
                @if (money) { <p-tab [value]="3">{{ t('production.material.prices') }}</p-tab> }
              </p-tablist>
              <p-tabpanels>
                <p-tabpanel [value]="0">
                  <div class="dw-panel-body">
                    <dl class="dw-facts">
                      <div><dt>{{ t('production.reference') }}</dt><dd class="dw-mono">{{ m.reference }}</dd></div>
                      <div><dt>{{ t('production.designation') }}</dt><dd>{{ m.name }}</dd></div>
                      <div><dt>{{ t('production.material.category') }}</dt><dd>{{ m.category ?? '—' }}</dd></div>
                      <div><dt>{{ t('production.materials.unit') }}</dt><dd>{{ m.unit | dwCode: 'unit' }}</dd></div>
                      <div><dt>{{ t('production.material.defaultSupplier') }}</dt><dd>{{ m.supplier?.name ?? '—' }}<div class="dw-faint">{{ t('production.material.defaultSupplierHint') }}</div></dd></div>
                      <div><dt>{{ t('lots.composition') }}</dt><dd>@for (line of m.composition; track line.material) { <span class="chip">{{ line.material | dwCode: 'material' }} {{ +line.pct }} %</span> } @empty { — }</dd></div>
                      <div><dt>{{ t('lots.color') }}</dt><dd>@if (m.color_family) { <dw-color [code]="m.color_family" /> }@if (m.color_family && m.color_label) { · }{{ m.color_label ?? (m.color_family ? '' : '—') }}</dd></div>
                      <div><dt>{{ t('lots.grammage') }}</dt><dd>{{ m.grammage_gsm ? (+m.grammage_gsm) + ' g/m²' : '—' }}</dd></div>
                      <div><dt>{{ t('production.materials.width') }}</dt><dd>{{ m.width_cm ? (+m.width_cm) + ' cm' : '—' }}</dd></div>
                      <div><dt>{{ t('production.material.waste') }}</dt><dd>{{ m.summary?.waste_kg | dwKg }} <span class="dw-faint">· {{ t('production.material.wasteLots', { count: m.summary?.waste_lots ?? 0 }) }}</span></dd></div>
                      @if (m.notes) { <div><dt>{{ t('fields.notes') }}</dt><dd>{{ m.notes }}</dd></div> }
                    </dl>
                  </div>
                </p-tabpanel>

                <p-tabpanel [value]="1">
                  <p class="dw-muted intro">{{ t('production.received.intro') }}</p>
                  @if (m.batches.length === 0) {
                    <p class="empty">{{ t('production.received.empty') }}</p>
                  } @else {
                    <div class="scroll">
                      <table class="dw-table">
                        <thead><tr>
                          <th>{{ t('production.received.number') }}</th><th>{{ t('production.received.supplier') }}</th><th>{{ t('production.received.receivedOn') }}</th>
                          <th class="num">{{ t('production.received.received') }}</th><th class="num">{{ t('production.received.consumed') }}</th>
                          @if (money) { <th class="num">{{ t('production.received.unitCost') }}</th><th class="num">{{ t('production.received.purchaseValue') }}</th><th class="num">{{ t('production.received.consumedValue') }}</th> }
                          <th>{{ t('production.received.documents') }}</th><th></th>
                        </tr></thead>
                        <tbody>
                          @for (lot of m.batches; track lot.ulid) {
                            <tr>
                              <td><strong class="dw-mono">{{ lot.batch_number }}</strong>@if (lot.supplier_batch_reference) { <div class="dw-faint dw-mono">{{ lot.supplier_batch_reference }}</div> }</td>
                              <td>{{ lot.supplier?.name ?? '—' }}</td>
                              <td>{{ lot.received_on | dwDate }}</td>
                              <td class="num">{{ lot.quantity === null ? '—' : (lot.quantity | dwNumber: 1) + ' ' + m.unit }}</td>
                              <td class="num" [class.over]="lot.over_consumed" [title]="lot.over_consumed ? t('production.received.overConsumed') : ''">{{ lot.consumed | dwNumber: 1 }} {{ m.unit }}@if (lot.over_consumed) { <dw-icon class="warn">warning</dw-icon> }</td>
                              @if (money) {
                                <td class="num">{{ lot.unit_cost === null ? t('production.unknown') : (lot.unit_cost | dwMoney: lot.currency_code) }}</td>
                                <td class="num">{{ lot.purchase_value | dwMoney: lot.currency_code }}</td>
                                <td class="num">{{ lot.consumed_value | dwMoney: lot.currency_code }}</td>
                              }
                              <td class="dw-faint">{{ lot.delivery_note }}@if (lot.delivery_note && lot.invoice_reference) { · }{{ lot.invoice_reference }}</td>
                              <td class="act">@if (canManage) { <button pButton text size="small" type="button" (click)="receive(m, lot)">{{ t('common.edit') }}</button> }</td>
                            </tr>
                          }
                        </tbody>
                      </table>
                    </div>
                  }
                </p-tabpanel>

                <p-tabpanel [value]="2">
                  <p class="dw-muted intro">{{ t('production.material.historyIntro') }}</p>
                  @if ((m.consumptions ?? []).length === 0) {
                    <p class="empty">{{ t('production.material.historyEmpty') }}</p>
                  } @else {
                    <div class="scroll">
                      <table class="dw-table">
                        <thead><tr><th>{{ t('fields.date') }}</th><th>{{ t('production.of.one') }}</th><th>{{ t('production.articles.one') }}</th><th>{{ t('production.received.one') }}</th>
                          <th class="num">{{ t('fields.quantity') }}</th>@if (money) { <th class="num">{{ t('production.cost') }}</th> }</tr></thead>
                        <tbody>
                          @for (line of m.consumptions; track $index) {
                            <tr>
                              <td>{{ line.date | dwDate }}</td>
                              <td><a class="dw-mono" [routerLink]="['/production/manufacturing-orders', line.of]">{{ line.order_number }}</a> <dw-status kind="accreditation" [value]="tone(line.status)" [label]="t('production.ofStatus.' + line.status)" /></td>
                              <td>{{ line.article }}</td>
                              <td>@if (line.batch_number) { <span class="dw-mono">{{ line.batch_number }}</span> } @else { <span class="dw-faint">{{ t('production.of.anyBatch') }}</span> }</td>
                              <td class="num">{{ line.quantity | dwNumber: 1 }} {{ m.unit }}</td>
                              @if (money) { <td class="num">{{ line.cost === null ? '—' : (line.cost | dwMoney: currency) }}</td> }
                            </tr>
                          }
                        </tbody>
                      </table>
                    </div>
                  }
                </p-tabpanel>

                @if (money) {
                  <p-tabpanel [value]="3">
                    <p class="dw-muted intro">{{ t('production.material.pricesIntro') }}</p>
                    @if (priced().length === 0) {
                      <p class="empty">{{ t('production.material.pricesEmpty') }}</p>
                    } @else {
                      <div class="scroll">
                        <table class="dw-table">
                          <thead><tr><th>{{ t('production.received.receivedOn') }}</th><th>{{ t('production.received.supplier') }}</th><th>{{ t('production.received.one') }}</th>
                            <th class="num">{{ t('production.received.unitPrice', { unit: m.unit }) }}</th><th class="num">{{ t('production.received.unitCost') }}</th><th class="num">{{ t('production.material.versusAverage') }}</th></tr></thead>
                          <tbody>
                            @for (lot of priced(); track lot.ulid) {
                              <tr>
                                <td>{{ lot.received_on | dwDate }}</td><td>{{ lot.supplier?.name ?? '—' }}</td><td class="dw-mono">{{ lot.batch_number }}</td>
                                <td class="num">{{ lot.unit_price | dwMoney: lot.currency_code }}</td>
                                <td class="num">{{ lot.unit_cost | dwMoney: lot.currency_code }}</td>
                                <td class="num" [class.over]="gap(lot) > 0" [class.under]="gap(lot) < 0">{{ gap(lot) > 0 ? '+' : '' }}{{ gap(lot) | dwNumber: 1 }} %</td>
                              </tr>
                            }
                          </tbody>
                          <tfoot><tr><td colspan="4">{{ t('production.material.weightedAverage') }}</td><td class="num">{{ m.average_unit_cost | dwMoney: currency }}</td><td></td></tr></tfoot>
                        </table>
                      </div>
                    }
                  </p-tabpanel>
                }
              </p-tabpanels>
            </p-tabs>
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .kpis { margin-block-end: var(--dw-4); }
    .intro { margin: 0; padding: var(--dw-3) var(--dw-4); font-size: 13px; border-block-end: 1px solid var(--dw-line-soft); }
    .empty { margin: 0; padding: var(--dw-5) var(--dw-4); text-align: center; color: var(--dw-ink-3); }
    .scroll { overflow-x: auto; } table { inline-size: 100%; }
    .num { text-align: end; white-space: nowrap; } .act { text-align: end; }
    .over { color: var(--dw-danger); font-weight: 600; } .under { color: var(--dw-success); }
    .warn { font-size: 15px; vertical-align: -3px; margin-inline-start: 4px; }
    .chip { display: inline-block; margin-inline-end: 4px; padding: 1px 8px; border-radius: 999px; background: var(--dw-surface-2); font-size: 12px; }
  `,
})
export class MaterialDetailPage {
  readonly ulid = input.required<string>();

  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly session = inject(Session);

  protected readonly canManage = this.session.can('production.manage');
  protected readonly money = this.session.can('production.finance.view');
  protected readonly currency = this.session.company()?.currency_code ?? null;
  protected readonly tone = statusTone;
  protected readonly material = signal<RawMaterial | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  /** The lots that have a price, oldest first: the price of the material over time. */
  protected readonly priced = computed(() => (this.material()?.batches ?? []).filter((lot) => lot.unit_cost !== null).sort((a, b) => (a.received_on ?? '').localeCompare(b.received_on ?? '')));

  constructor() {
    queueMicrotask(() => this.load());
  }

  protected load(): void {
    this.loading.set(this.material() === null);
    this.error.set(null);
    this.api.get<RawMaterial>(`production/materials/${this.ulid()}`).subscribe({
      next: (material) => {
        this.material.set(material);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  /** How far the cost of a lot is from the average of the material, in percent. */
  protected gap(lot: MaterialBatch): number {
    const average = Number(this.material()?.average_unit_cost ?? 0);

    return average > 0 && lot.unit_cost !== null ? ((Number(lot.unit_cost) - average) / average) * 100 : 0;
  }

  protected edit(material: RawMaterial): void {
    this.dialog.open<MaterialDialog, RawMaterial | null, RawMaterial | boolean>(MaterialDialog, { data: material }).afterClosed().subscribe((saved) => saved && this.load());
  }

  protected receive(material: RawMaterial, batch: MaterialBatch | null): void {
    this.dialog.open<ReceivedLotDialog, ReceivedLotDialogData, RawMaterial>(ReceivedLotDialog, { data: { material, batch } }).afterClosed().subscribe((saved) => saved && this.material.set(saved));
  }
}
