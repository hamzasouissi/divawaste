import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, KgPipe, LocalizedPipe, MoneyPipe, NumberPipe, QuantityPipe } from '../../core/format/pipes';
import { DateComponent } from '../../ui/date';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { SegmentedComponent } from '../../ui/more';
import { KpiComponent, PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SELECT } from '../../ui/select';
import { StateComponent } from '../../ui/state';
import { CreateLotDialog } from '../lots/lot-dialogs';
import { CuttingComponent } from './spreads';
import { OfSizesDialog, SizeRunComponent } from './sizes';
import { ManufacturingOrderDialog } from './orders';
import {
  AMOUNT, ConsumedMaterial, DECIMAL, EXPENSE_CATEGORIES, KIND_ICON, ManufacturingOrder, MaterialKind, OfExpense, OfWaste, ProducedLot, ProducedLotStatus, ProductionLists, blankToNull, decimal, statusTone,
} from './production-data';

/**
 * What an OF consumed, material by material of its article. One line per received lot: 12 kg of lot A and 8 kg of
 * lot B are two lines. A line without a lot is "lot non précisé" — it is kept as such, nothing is chosen in its place.
 * Only the article's materials are offered (and any the OF already consumed beside them, kept as they were).
 */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, LocalizedPipe, QuantityPipe, ButtonDirective, InputText, ...SELECT, ...DIALOG_PARTS, IconComponent, DateComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('production.consumed.edit') }} — {{ data.order_number }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <p class="hint">{{ t('production.consumed.editHint') }}</p>
          @for (m of blocks(); track m.material) {
            @let entered = totals()[m.material] || 0;
            <section class="block" [class.empty]="count(m.material) === 0">
              <header>
                <span class="ico"><dw-icon>{{ kindIcon(m.kind) }}</dw-icon></span>
                <span class="who"><b>{{ m.reference }}</b>@if (m.name !== m.reference) { <span>{{ m.name }}</span> }
                  @if (!m.in_article) { <span class="tag">{{ t('production.consumed.outsideArticle') }}</span> }</span>
                <span class="sums">
                  @if (m.planned !== null) { <span>{{ t('production.consumed.planned') }} <b>{{ m.planned | dwQty: m.unit }}</b></span> }
                  @if (count(m.material) > 0) { <span [class.over]="m.planned !== null && entered > +m.planned">{{ t('production.consumed.entered') }} <b>{{ entered | dwQty: m.unit }}</b></span> }
                </span>
              </header>
              @if (count(m.material) > 0) {
                <div class="cols" aria-hidden="true"><span>{{ t('production.received.one') }}</span><span class="end">{{ t('fields.quantity') }}</span><span>{{ t('production.of.step') }}</span><span>{{ t('fields.site') }}</span><span>{{ t('fields.date') }}</span><span></span></div>
                @for (line of lines.controls; track line; let i = $index) {
                  @if (line.value.material === m.material) {
                    <div class="line" [formGroup]="line">
                      <dw-select formControlName="batch" [ariaLabel]="t('production.received.one')">
                        <dw-option value="" [label]="t('production.of.anyBatch')" />
                        @for (batch of batchesOf(m.material); track batch.ulid) { <dw-option [value]="batch.ulid" [label]="batch.batch_number + (batch.supplier ? ' — ' + batch.supplier.name : '')" /> }
                      </dw-select>
                      <span class="qty" [style.--sfx]="unitOf(m.unit, t).length"><input pInputText formControlName="quantity" inputmode="decimal" required [attr.aria-label]="t('fields.quantity')" [placeholder]="t('fields.quantity')" /><span class="suffix">{{ unitOf(m.unit, t) }}</span></span>
                      <dw-select formControlName="step" [placeholder]="t('production.of.step')" [ariaLabel]="t('production.of.step')">
                        @for (step of stepOptions(); track step.code) { <dw-option [value]="step.code" [label]="step.name | dwLocalized" /> }
                      </dw-select>
                      <dw-select formControlName="site" [placeholder]="t('fields.site')" [ariaLabel]="t('fields.site')">
                        @for (site of sites; track site.ulid) { <dw-option [value]="site.ulid" [label]="site.name" /> }
                      </dw-select>
                      <dw-date formControlName="consumed_on" />
                      <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="lines.removeAt(i)" [attr.aria-label]="t('common.remove')" [title]="t('common.remove')"><dw-icon>close</dw-icon></button>
                    </div>
                  }
                }
              }
              <button pButton text size="small" type="button" class="add" (click)="add(m.material)"><dw-icon>add</dw-icon>{{ t(count(m.material) > 0 ? 'production.consumed.addLot' : 'production.consumed.enter') }}</button>
            </section>
          } @empty {
            <p class="none"><dw-icon>info</dw-icon><span>{{ t('production.consumed.noArticleMaterials') }}</span></p>
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
    .dw-dialog-body { inline-size: min(1080px, 94vw); display: flex; flex-direction: column; gap: var(--dw-3); }
    .hint { margin: 0; font-size: 13px; color: var(--dw-ink-2); }
    .block { flex: none; border: 1px solid var(--dw-line); border-radius: var(--dw-radius-lg, 12px); background: var(--dw-surface); }
    .block header { border-start-start-radius: inherit; border-start-end-radius: inherit; }
    header { display: flex; align-items: center; gap: var(--dw-3); padding: 12px 14px; background: var(--dw-surface-2); }
    .block.empty header { background: none; }
    .ico { display: grid; place-items: center; flex: none; inline-size: 34px; block-size: 34px; border-radius: 10px; background: var(--dw-primary-soft); color: var(--dw-primary); }
    .ico dw-icon { font-size: 19px; }
    .who { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 8px; min-inline-size: 0; flex: 1; font-size: 14px; } .who b { font-weight: 650; overflow-wrap: anywhere; }
    .who > span:not(.tag) { font-size: 13px; color: var(--dw-ink-3); }
    .tag { padding: 0 8px; border-radius: 999px; background: var(--dw-warning-soft); color: var(--dw-warning); font-size: 11.5px; line-height: 20px; }
    .sums { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 4px 16px; font-size: 13px; color: var(--dw-ink-2); font-variant-numeric: tabular-nums; }
    .sums b { color: var(--dw-ink); font-weight: 650; } .sums .over, .sums .over b { color: var(--dw-danger); }
    .cols, .line { display: grid; grid-template-columns: minmax(0, 2fr) 150px minmax(0, 1.2fr) minmax(0, 1.2fr) 150px 36px; gap: var(--dw-2); align-items: center; padding-inline: 14px; }
    .cols { padding-block: 10px 4px; font-size: 11.5px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--dw-ink-3); } .cols .end { text-align: end; }
    .line { padding-block: 4px; }
    .qty { position: relative; display: block; } .qty input { inline-size: 100%; padding-inline-end: calc(var(--sfx, 2) * 1ch + 18px); text-align: end; font-variant-numeric: tabular-nums; }
    .suffix { position: absolute; inset-inline-end: 10px; inset-block: 0; display: flex; align-items: center; font-size: 12.5px; color: var(--dw-ink-3); pointer-events: none; }
    .qty input.ng-invalid.ng-touched { border-color: var(--dw-danger); background: var(--dw-danger-soft); }
    .add { margin: 4px 6px 8px; }
    .block.empty .add { margin-block-start: 0; }
    .none { display: flex; gap: var(--dw-2); align-items: flex-start; margin: 0; padding: var(--dw-4); border-radius: var(--dw-radius); background: var(--dw-surface-2); font-size: 13.5px; }
    @media (max-width: 900px) {
      header { flex-wrap: wrap; } .sums { inline-size: 100%; justify-content: flex-start; padding-inline-start: 46px; }
      .cols { display: none; }
      .line { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 36px; grid-template-areas: 'batch batch del' 'qty step step' 'site date date'; padding-block: 10px; border-block-end: 1px solid var(--dw-line-soft); }
      .line > :nth-child(1) { grid-area: batch; } .line > :nth-child(2) { grid-area: qty; } .line > :nth-child(3) { grid-area: step; }
      .line > :nth-child(4) { grid-area: site; } .line > :nth-child(5) { grid-area: date; } .line > :nth-child(6) { grid-area: del; }
    }
  `,
})
export class ConsumptionsDialog {
  protected readonly data = dialogData<ManufacturingOrder>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  private readonly fb = inject(FormBuilder);
  private readonly lists = inject(ProductionLists);

  protected readonly sites = inject(Session).sitesFor('sites.view');
  private readonly materials = toSignal(this.lists.materials(), { initialValue: [] });
  protected readonly stepOptions = toSignal(this.lists.steps(), { initialValue: [] });
  protected readonly busy = signal(false);
  protected readonly lines = this.fb.array((this.data.consumptions ?? []).map((line) => this.line(line.material, line.batch ?? '', String(+line.quantity), line.step ?? '', line.site ?? '', line.consumed_on ?? '', line.notes ?? '')));
  protected readonly form = this.fb.nonNullable.group({ consumptions: this.lines });
  private readonly value = toSignal(this.lines.valueChanges, { initialValue: this.lines.getRawValue() });

  /** The article's materials, in its order; then any the OF consumed beside them. */
  protected readonly blocks = computed(() => (this.data.materials ?? []).filter((m) => m.in_article || m.lines.length > 0));
  /** What is typed so far for each material. */
  protected readonly totals = computed(() => {
    const totals: Record<string, number> = {};
    for (const line of this.value()) {
      const quantity = Number(String(line['quantity'] ?? '').replace(',', '.'));
      totals[line['material'] as string] = (totals[line['material'] as string] ?? 0) + (Number.isFinite(quantity) ? quantity : 0);
    }

    return totals;
  });

  private line(material: string, batch = '', quantity = '', step = '', site = '', consumedOn = '', notes = ''): FormGroup {
    return this.fb.nonNullable.group({ material: [material, Validators.required], batch: [batch], quantity: [quantity, [Validators.required, DECIMAL]], step: [step], site: [site], consumed_on: [consumedOn], notes: [notes] });
  }

  protected count(material: string): number {
    return this.value().filter((line) => line['material'] === material).length;
  }

  /** A new lot line starts where the last one of the material (or of the OF) was consumed: same step, site and date. */
  protected add(material: string): void {
    const all = this.lines.getRawValue();
    const last = [...all].reverse().find((line) => line['material'] === material) ?? all.at(-1);
    this.lines.push(this.line(material, '', '', (last?.['step'] as string) ?? '', (last?.['site'] as string) ?? '', (last?.['consumed_on'] as string) ?? ''));
  }

  protected batchesOf(material: string): { ulid: string; batch_number: string; supplier: { name: string } | null }[] {
    return this.materials().find((item) => item.ulid === material)?.batches ?? [];
  }

  protected kindIcon(kind: MaterialKind | null): string {
    return (kind && KIND_ICON[kind]) || 'inventory_2';
  }

  protected unitOf(unit: string, t: (key: string) => string): string {
    return t('units.' + unit);
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const consumptions = this.lines.getRawValue().map((line) => blankToNull({ ...line, quantity: decimal(line['quantity'] as string) }));
    try {
      this.ref.close(await firstValueFrom(this.api.put<ManufacturingOrder>(`production/manufacturing-orders/${this.data.ulid}/consumptions`, { consumptions })));
      this.toast.success('common.saved');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

export interface ProducedLotDialogData {
  of: ManufacturingOrder;
  lot: ProducedLot | null;
}

/** A produced lot: a batch of finished goods of this OF, for the client and the order of the OF. No supplier here. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, LocalizedPipe, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...DIALOG_PARTS, DateComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data.lot ? 'production.produced.edit' : 'production.produced.new') }} — {{ data.of.order_number }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <p class="dw-muted for">{{ t('production.produced.for', { article: data.of.article.reference, client: data.of.client?.name ?? '—', order: data.of.customer_order?.order_number ?? '—' }) }}</p>
          <div class="dw-form">
            <dw-field [label]="t('production.produced.number')"><input pInputText formControlName="lot_number" maxlength="60" required /></dw-field>
            <dw-field [label]="t('production.produced.quantity')"><input pInputText type="number" formControlName="quantity" min="1" required /></dw-field>
            <dw-field [label]="t('production.produced.producedOn')"><dw-date formControlName="produced_on" /></dw-field>
            <dw-field [label]="t('production.produced.producedUntil')"><dw-date formControlName="produced_until" /><small dwHint>{{ t('production.produced.untilHint') }}</small></dw-field>
            <dw-field [label]="t('production.of.step')">
              <dw-select formControlName="step"><dw-option value="" label="—" />@for (step of stepOptions(); track step.code) { <dw-option [value]="step.code" [label]="step.name | dwLocalized" /> }</dw-select>
            </dw-field>
            <dw-field [label]="t('fields.site')">
              <dw-select formControlName="site"><dw-option value="" label="—" />@for (site of sites; track site.ulid) { <dw-option [value]="site.ulid" [label]="site.name" /> }</dw-select>
            </dw-field>
            <dw-field [label]="t('fields.status')">
              <dw-select formControlName="status">@for (status of statuses; track status) { <dw-option [value]="status" [label]="t('production.producedStatus.' + status)" /> }</dw-select>
            </dw-field>
            @if (form.controls.status.value === 'delivered') { <dw-field [label]="t('production.produced.deliveredOn')"><dw-date formControlName="delivered_on" /></dw-field> }
            <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="1000"></textarea></dw-field>
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: '.dw-dialog-body { inline-size: min(640px, 88vw); } .for { margin: 0 0 var(--dw-3); font-size: 13px; }',
})
export class ProducedLotDialog {
  protected readonly data = dialogData<ProducedLotDialogData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly statuses: ProducedLotStatus[] = ['produced', 'delivered', 'cancelled'];
  protected readonly sites = inject(Session).sitesFor('sites.view');
  protected readonly stepOptions = toSignal(inject(ProductionLists).steps(), { initialValue: [] });
  protected readonly busy = signal(false);
  private readonly lot = this.data.lot;
  protected readonly form = inject(FormBuilder).nonNullable.group({
    lot_number: [this.lot?.lot_number ?? '', Validators.required],
    quantity: [this.lot?.quantity ?? (null as number | null), [Validators.required, Validators.min(1)]],
    produced_on: [this.lot?.produced_on ?? ''],
    produced_until: [this.lot?.produced_until ?? ''],
    step: [this.lot?.step ?? ''],
    site: [this.lot?.site ?? ''],
    status: [this.lot?.status ?? ('produced' as ProducedLotStatus)],
    delivered_on: [this.lot?.delivered_on ?? ''],
    notes: [this.lot?.notes ?? ''],
  });

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const body = blankToNull(this.form.getRawValue());
    try {
      this.ref.close(await firstValueFrom(this.lot
        ? this.api.put<ManufacturingOrder>(`production/produced-lots/${this.lot.ulid}`, body)
        : this.api.post<ManufacturingOrder>(`production/manufacturing-orders/${this.data.of.ulid}/produced-lots`, body)));
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

export interface ExpenseDialogData {
  of: ManufacturingOrder;
  expense: OfExpense | null;
}

/** A cost of the OF beside its materials. Nothing is assumed: labour counts only if it is entered. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...DIALOG_PARTS, DateComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data.expense ? 'production.expenses.edit' : 'production.expenses.new') }} — {{ data.of.order_number }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field [label]="t('production.expenses.category')">
              <dw-select formControlName="category" required>@for (category of categories; track category) { <dw-option [value]="category" [label]="t('production.expenseCategory.' + category)" /> }</dw-select>
            </dw-field>
            <dw-field [label]="t('production.expenses.amount')"><input pInputText formControlName="amount" inputmode="decimal" required /></dw-field>
            <dw-field class="dw-span-2" [label]="t('production.expenses.description')"><input pInputText formControlName="description" maxlength="191" required /></dw-field>
            <dw-field [label]="t('fields.date')"><dw-date formControlName="incurred_on" /></dw-field>
            <dw-field [label]="t('production.currency')"><input pInputText formControlName="currency_code" maxlength="3" [placeholder]="currency" class="dw-mono" /></dw-field>
            <dw-field [label]="t('production.expenses.payee')">
              <dw-select formControlName="supplier"><dw-option value="" label="—" />@for (supplier of suppliers(); track supplier.ulid) { <dw-option [value]="supplier.ulid" [label]="supplier.name" /> }</dw-select>
            </dw-field>
            <dw-field [label]="t('production.expenses.reference')"><input pInputText formControlName="reference" maxlength="80" /><small dwHint>{{ t('production.expenses.referenceHint') }}</small></dw-field>
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
  styles: '.dw-dialog-body { inline-size: min(620px, 88vw); }',
})
export class ExpenseDialog {
  protected readonly data = dialogData<ExpenseDialogData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly categories = EXPENSE_CATEGORIES;
  protected readonly currency = inject(Session).company()?.currency_code ?? '';
  protected readonly suppliers = toSignal(inject(ProductionLists).suppliers(), { initialValue: [] });
  protected readonly busy = signal(false);
  private readonly expense = this.data.expense;
  protected readonly form = inject(FormBuilder).nonNullable.group({
    category: [this.expense?.category ?? ('subcontracting' as string), Validators.required],
    description: [this.expense?.description ?? '', Validators.required],
    amount: [this.expense ? String(+this.expense.amount) : '', [Validators.required, AMOUNT]],
    currency_code: [this.expense?.currency_code ?? ''],
    incurred_on: [this.expense?.incurred_on ?? ''],
    supplier: [this.expense?.supplier?.ulid ?? ''],
    reference: [this.expense?.reference ?? ''],
    notes: [this.expense?.notes ?? ''],
  });

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const body = blankToNull({ ...value, amount: decimal(value.amount), currency_code: value.currency_code.trim().toUpperCase() });
    try {
      this.ref.close(await firstValueFrom(this.expense
        ? this.api.put<ManufacturingOrder>(`production/expenses/${this.expense.ulid}`, body)
        : this.api.post<ManufacturingOrder>(`production/manufacturing-orders/${this.data.of.ulid}/expenses`, body)));
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
 * One manufacturing order on one page: who it is for, what it had to make and made, what it consumed — material by
 * material, received lot by received lot, down to the supplier —, the lots it produced, the waste it left, and what all
 * that cost and is worth.
 */
@Component({
  imports: [SizeRunComponent, RouterLink, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, KpiComponent, ButtonDirective, IconComponent, DatePipe, NumberPipe, MoneyPipe, KgPipe, QuantityPipe, LocalizedPipe, SegmentedComponent, CuttingComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (of(); as o) {
          <dw-page-header [title]="t('production.of.detail', { number: o.order_number })" back="/production/manufacturing-orders" [backLabel]="t('production.of.title')">
            <dw-status dwTitleBadge kind="accreditation" [value]="tone(o.status)" [label]="t('production.ofStatus.' + o.status)" />
            @if (canManage) { <button pButton severity="secondary" outlined type="button" (click)="edit(o)"><dw-icon>edit</dw-icon>{{ t('common.edit') }}</button> }
          </dw-page-header>

          <!-- Who the order is for and what it makes: the chain, left to right. -->
          <nav class="chain" [attr.aria-label]="t('production.of.chain')">
            <span class="node"><small>{{ t('production.clients.one') }}</small><strong>{{ o.client?.name ?? '—' }}</strong></span>
            <dw-icon>chevron_right</dw-icon>
            <span class="node"><small>{{ t('production.orders.one') }}</small>
              @if (o.customer_order) { <a class="dw-mono" [routerLink]="['/production/orders', o.customer_order.ulid]">{{ o.customer_order.order_number }}</a> } @else { <strong>{{ t('production.of.noOrder') }}</strong> }
            </span>
            <dw-icon>chevron_right</dw-icon>
            <span class="node current"><small>{{ t('production.of.one') }}</small><strong class="dw-mono">{{ o.order_number }}</strong></span>
            <dw-icon>chevron_right</dw-icon>
            <span class="node"><small>{{ t('production.articles.one') }}</small><strong>{{ o.article.reference }}</strong> <span class="dw-faint">{{ o.article.name }}</span></span>
            <span class="node dates"><small>{{ t(o.finished_on ? 'production.of.period' : 'production.of.startedOn') }}</small><strong>{{ o.started_on | dwDate }}@if (o.finished_on) { → {{ o.finished_on | dwDate }} }</strong></span>
          </nav>

          <!-- The sizes it makes, for information: the cutting waste follows the fabric laid, not the sizes. -->
          @if (o.sizes.length > 0 || canCut) {
            <div class="sizes" [attr.aria-label]="t('production.sizes.sizes')">
              @if (o.sizes.length > 0) {
                <span class="sizes-label"><b>{{ t('production.sizes.sizes') }}</b>@if (o.size_grid) { <span class="dw-faint">{{ o.size_grid.name }}</span> }</span>
                <dw-size-run [sizes]="sizeRun().sizes" [quantities]="sizeRun().quantities" [totalLabel]="t('production.sizes.totalShort')" />
              } @else {
                <span class="sizes-label"><b>{{ t('production.sizes.sizes') }}</b><span class="dw-faint">{{ t('production.sizes.noneOnOf') }}</span></span>
              }
              @if (canCut) {
                <button pButton text size="small" type="button" class="size-edit" (click)="editSizes(o)"><dw-icon>{{ o.sizes.length > 0 ? 'edit' : 'straighten' }}</dw-icon>{{ t(o.sizes.length > 0 ? 'production.sizes.change' : 'production.sizes.assign') }}</button>
              }
            </div>
          }

          <div class="dw-grid-4 kpis">
            <dw-kpi [label]="t('production.of.producedPlanned')" [value]="(o.quantity_produced === null ? '—' : (o.quantity_produced | dwNumber)) + ' / ' + (o.quantity_planned | dwNumber)"
              [hint]="o.produced?.source === 'lots' ? t('production.of.fromLots', { count: liveLots() }) : t('production.of.typed')" />
            <dw-kpi [label]="t('production.of.wasteKpi')" [value]="o.waste?.kg | dwKg"
              [hint]="o.waste?.waste_rate_pct !== null && o.waste?.waste_rate_pct !== undefined ? t('production.of.wasteRate', { pct: (o.waste?.waste_rate_pct | dwNumber: 1) })
                : (o.waste?.unweighed_lots ?? 0) > 0 ? t('production.waste.notWeighed', { count: o.waste?.unweighed_lots })
                : t('production.of.wasteLots', { count: o.waste?.lots ?? 0 })" />
            @if (o.finance; as f) {
              <dw-kpi [label]="t('production.finance.totalCost')" [value]="!f.cost_complete && +f.total_cost === 0 ? '—' : (f.total_cost | dwMoney: f.currency)" [tone]="f.cost_complete ? 'neutral' : 'warning'"
                [hint]="f.cost_complete ? (f.unit_cost ? t('production.finance.perPiece', { amount: (f.unit_cost | dwMoney: f.currency) }) : '') : t('production.finance.incomplete')" />
              <dw-kpi [label]="t('production.finance.gain')" [value]="f.gain.amount === null ? t('production.finance.notCalculable') : (f.gain.amount | dwMoney: f.currency)"
                [tone]="f.gain.amount === null ? 'neutral' : (+f.gain.amount >= 0 ? 'success' : 'danger')"
                [hint]="f.gain.amount === null ? reasons(f.gain.missing) : t('production.finance.margin', { pct: (f.gain.margin_pct | dwNumber: 1) })" />
            } @else {
              <dw-kpi [label]="t('production.consumed.title')" [value]="(o.materials ?? []).length" [hint]="t('production.consumed.materialsHint')" />
              <dw-kpi [label]="t('production.produced.title')" [value]="(o.produced_lots ?? []).length" [hint]="t('production.produced.deliveredHint', { count: o.produced?.delivered ?? 0 })" />
            }
          </div>

          <!-- PRD-04: the OF in tabs; its waste first, since that is what DivaWaste is for. -->
          <dw-segmented class="tabs" [options]="tabOptions(t, !!o.finance)" [value]="tab()" (valueChange)="tab.set($any($event))" [ariaLabel]="t('production.of.one')" />
          <div class="dw-stack">
            @switch (tab()) {
            @case ('waste') {
            <!-- Waste lots: the existing waste workflow, seen from the OF. -->
            <section class="dw-panel">
              <div class="dw-panel-head">
                <h2 class="dw-h2">{{ t('production.waste.title') }}</h2>
                @if (canCreateLot) { <button pButton text size="small" type="button" (click)="waste(o)"><dw-icon>add</dw-icon>{{ t('production.waste.new') }}</button> }
              </div>
              <p class="dw-muted intro">{{ t('production.waste.intro') }}</p>
              <!-- Before anything is weighed: what the placements of the article are expected to leave. -->
              @if (o.waste?.estimated; as e) {
                <div class="estimate">
                  <p class="dw-muted">{{ t(e.source === 'cutting' ? 'production.waste.estimatedIntroCutting' : 'production.waste.estimatedIntro') }}</p>
                  <div class="scroll">
                    <table class="dw-table">
                      <thead><tr><th>{{ t('fields.wasteType') }}</th><th class="num">{{ t('production.waste.perPiece') }}</th>
                        <th class="num">{{ t(e.source === 'cutting' ? 'production.waste.forSpreads' : 'production.waste.forPlanned', { pieces: (e.planned_pieces | dwNumber) }) }}</th>
                        @if (e.produced_kg !== null) { <th class="num">{{ t(e.source === 'cutting' ? 'production.waste.forCut' : 'production.waste.forProduced', { pieces: (e.produced_pieces | dwNumber) }) }}</th> }
                        <th class="num">{{ t('production.waste.weighed') }}</th></tr></thead>
                      <tbody>
                        @for (row of e.by_type; track $index) {
                          <tr>
                            <td>{{ row.waste_type?.name ?? t('production.waste.typeUnknown') }}</td>
                            <td class="num">{{ row.per_piece_kg === null ? '—' : ((+row.per_piece_kg * 1000 | dwNumber: 1) + ' g') }}</td>
                            <td class="num">{{ +row.planned_kg | dwNumber: (+row.planned_kg < 1 ? 3 : 1) }} kg</td>
                            @if (e.produced_kg !== null) { <td class="num"><strong>@if (row.produced_kg === null) { — } @else { {{ +row.produced_kg | dwNumber: (+row.produced_kg < 1 ? 3 : 1) }} kg }</strong></td> }
                            <td class="num">
                              @if (row.lots === 0) { <span class="dw-faint">{{ t('production.waste.noLotOfType') }}</span> }
                              @else if (row.weighed_kg === null || +row.weighed_kg === 0) { <span class="dw-faint">{{ t('production.waste.notWeighed', { count: row.unweighed_lots }) }}</span> }
                              @else {
                                @let g = gap(row);
                                <strong [class.over]="g?.tone === 'over'" [class.under]="g?.tone === 'under'">{{ row.weighed_kg | dwKg }}</strong>
                                @if (g?.tone) {
                                  <div class="gap" [class.over]="g!.tone === 'over'" [class.under]="g!.tone === 'under'">
                                    <dw-icon>{{ g!.tone === 'over' ? 'arrow_upward' : 'arrow_downward' }}</dw-icon>{{ t(g!.tone === 'over' ? 'production.waste.aboveEstimate' : 'production.waste.belowEstimate',
                                      { kg: (g!.kg | dwKg), pct: (g!.pct | dwNumber: 0) }) }}
                                  </div>
                                } @else if (g?.tone === null && g?.kg === 0) { <div class="gap under">{{ t('production.waste.asEstimated') }}</div> }
                                @if (row.unweighed_lots > 0) { <div class="dw-faint">{{ t('production.waste.stillToWeigh', { count: row.unweighed_lots }) }}</div> }
                              }
                            </td>
                          </tr>
                        }
                      </tbody>
                    </table>
                  </div>
                </div>
              }
              @if ((o.waste?.lots ?? 0) === 0 && (o.waste?.rows ?? []).length === 0) {
                <p class="empty">{{ t('production.waste.empty') }}</p>
              } @else if (!o.waste?.can_view_lots) {
                <p class="empty">{{ t('production.waste.hidden', { count: o.waste?.lots ?? 0, kg: (o.waste?.kg | dwKg) }) }}</p>
              } @else {
                <div class="scroll">
                  <table class="dw-table">
                    <thead><tr><th>{{ t('lots.number') }}</th><th>{{ t('fields.wasteType') }}</th><th>{{ t('production.consumed.where') }}</th><th>{{ t('production.waste.source') }}</th><th>{{ t('fields.date') }}</th>
                      <th class="num">{{ t('fields.weight') }}</th><th>{{ t('fields.status') }}</th></tr></thead>
                    <tbody>
                      @for (lot of o.waste?.rows; track lot.ulid) {
                        <tr>
                          <td><a class="dw-mono" [routerLink]="['/lots', lot.ulid]">{{ lot.lot_number }}</a></td>
                          <td>{{ lot.waste_type }}</td>
                          <td>{{ lot.step_name ? (lot.step_name | dwLocalized) : '—' }}<span class="dw-faint"> · {{ lot.site_name }}</span></td>
                          <td>@if (lot.material) { <span class="dw-mono">{{ lot.material }}</span>@if (lot.batch_number) { · <span class="dw-mono">{{ lot.batch_number }}</span> } } @else { <span class="dw-faint">{{ t('production.waste.ofOnly') }}</span> }</td>
                          <td>{{ lot.generated_at | dwDate }}</td>
                          <td class="num">@if (lot.net_weight_kg === null) { <span class="dw-faint">{{ t('lots.toWeigh') }}</span> } @else { {{ lot.net_weight_kg | dwKg }} }</td>
                          <td><dw-status kind="lot" [value]="lot.status" /></td>
                        </tr>
                      }
                    </tbody>
                    <tfoot><tr><td colspan="5">{{ t('production.waste.total', { count: o.waste?.lots ?? 0 }) }}</td><td class="num">{{ o.waste?.kg | dwKg }}</td>
                      <td class="dw-faint">{{ o.waste?.waste_rate_pct !== null ? t('production.of.wasteRate', { pct: (o.waste?.waste_rate_pct | dwNumber: 1) })
                        : ((o.waste?.unweighed_lots ?? 0) > 0 ? t('production.waste.notWeighed', { count: o.waste?.unweighed_lots }) : t('production.waste.noRate')) }}</td></tr></tfoot>
                  </table>
                </div>
              }
            </section>

            }
            @case ('cut') {
              <dw-cutting [of]="o" [canManage]="canCut" (changed)="of.set($event)" />
            }
            @case ('make') {
            @if (o.steps.length > 0) {
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('production.of.steps') }}</h2></div>
                <div class="steps">
                  @for (step of o.steps; track step.step; let last = $last) {
                    <div class="step"><strong>{{ step.name | dwLocalized }}</strong><span class="dw-faint">{{ step.site_name }}</span>
                      <span>{{ step.pieces_done === null ? '—' : (step.pieces_done | dwNumber) }} {{ t('production.pieces') }}</span></div>
                    @if (!last) { <dw-icon class="dw-faint">arrow_forward</dw-icon> }
                  }
                </div>
              </section>
            }

            <!-- Materials consumed: one row per material of the article, its received lots underneath. -->
            <section class="dw-panel">
              <div class="dw-panel-head">
                <h2 class="dw-h2">{{ t('production.consumed.title') }}</h2>
                @if (canManage) { <button pButton text size="small" type="button" (click)="consume(o)"><dw-icon>edit_note</dw-icon>{{ t('production.consumed.edit') }}</button> }
              </div>
              <p class="dw-muted intro">{{ t('production.consumed.intro') }}</p>
              @if ((o.materials ?? []).length === 0) {
                <p class="empty">{{ t('production.consumed.empty') }}</p>
              } @else {
                <div class="scroll">
                  <table class="dw-table cons">
                    <thead><tr>
                      <th>{{ t('production.materials.one') }}</th>
                      <th class="num">{{ t('production.consumed.planned') }}</th>
                      <th class="num">{{ t('production.consumed.actual') }}</th>
                      <th class="prog">{{ t('production.consumed.progress') }}</th>
                      <th class="num var-col">{{ t('production.consumed.variance') }}</th>
                      @if (o.finance) { <th class="num money-col">{{ t('production.cost') }}</th> }
                      <th class="chev" [attr.aria-label]="t('production.consumed.lots')"></th>
                    </tr></thead>
                    @for (m of o.materials; track m.material) {
                      @let open = expanded().includes(m.material);
                      @let over = m.planned !== null && m.lines.length > 0 && +m.consumed > +m.planned;
                      <tbody [class.open]="open">
                        <tr class="main" [class.clickable]="m.lines.length > 0" (click)="m.lines.length > 0 && toggle(m.material)">
                          <td class="mcell">
                            <div class="mat">
                              <span class="ico" [class.off]="m.lines.length === 0"><dw-icon>{{ kindIcon(m.kind) }}</dw-icon></span>
                              <span class="who">
                                <a class="ref" [routerLink]="['/production/catalog', m.material]" (click)="$event.stopPropagation()">{{ m.reference }}</a>
                                <span class="nm">@if (m.name !== m.reference) { {{ m.name }} }@if (!m.in_article) { <span class="tag est">{{ t('production.consumed.outsideArticle') }}</span> }</span>
                              </span>
                            </div>
                          </td>
                          <td class="num"><small class="m-label">{{ t('production.consumed.planned') }}</small>@if (m.planned === null) { <span class="dw-faint">—</span> } @else { {{ m.planned | dwQty: m.unit }}
                            @if (m.per_piece) { <div class="sub">{{ +m.per_piece | dwNumber: 4 }} {{ t('production.articles.perPieceUnit.' + m.unit) }}</div> } }</td>
                          <td class="num"><small class="m-label">{{ t('production.consumed.actual') }}</small>@if (m.lines.length === 0) { <span class="pending">{{ t('production.consumed.notEntered') }}</span> } @else {
                            <strong>{{ m.consumed | dwQty: m.unit }}</strong><div class="sub">{{ t('production.consumed.linesCount', { count: m.lines.length }) }}</div> }</td>
                          <td class="prog">@if (m.planned !== null && +m.planned > 0 && m.lines.length > 0) {
                            <div class="meter"><div class="bar"><span [style.inline-size.%]="share(m)" [class.over]="over"></span></div><span class="pct" [class.over]="over">{{ ratio(m) | dwNumber: 0 }} %</span></div> }</td>
                          <td class="num var-col">@if (m.variance === null) { <span class="dw-faint">—</span> } @else {
                            <span class="delta" [class.over]="+m.variance > 0" [class.under]="+m.variance < 0">{{ m.variance | dwQty: m.unit : true }}</span>
                            @if (m.variance_pct !== null) { <div class="sub" [class.over]="m.variance_pct > 0" [class.under]="m.variance_pct < 0">{{ m.variance_pct > 0 ? '+' : '' }}{{ m.variance_pct | dwNumber: 1 }} %</div> } }</td>
                          @if (o.finance; as f) { <td class="num money-col">@if (m.lines.length === 0) { <span class="dw-faint">—</span> } @else if (m.cost === null) { <span class="tag missing" [title]="t('production.finance.materialUnknownHint')">{{ t('production.unknown') }}</span> } @else { {{ m.cost | dwMoney: f.currency }}
                            @if (+(m.cost_estimated ?? 0) > 0) { <div class="sub"><span class="tag est" [title]="t('production.consumed.averageHint')">{{ t('production.estimated') }}</span></div> } }</td> }
                          <td class="chev">@if (m.lines.length > 0) { <button type="button" class="fold" [attr.aria-expanded]="open" [attr.aria-label]="t('production.consumed.lots')" (click)="$event.stopPropagation(); toggle(m.material)"><dw-icon>{{ open ? 'expand_less' : 'expand_more' }}</dw-icon></button> }</td>
                        </tr>
                        @if (open) {
                          <tr class="detail"><td [attr.colspan]="o.finance ? 7 : 6">
                            <div class="lots">
                              <table class="dw-table">
                                <thead><tr><th>{{ t('production.received.one') }}</th><th>{{ t('production.received.supplier') }}</th><th>{{ t('production.consumed.where') }}</th><th>{{ t('fields.date') }}</th>
                                  <th class="num">{{ t('fields.quantity') }}</th>@if (o.finance) { <th class="num">{{ t('production.received.unitCost') }}</th><th class="num">{{ t('production.cost') }}</th> }</tr></thead>
                                <tbody>
                                  @for (line of m.lines; track $index) {
                                    <tr>
                                      <td>@if (line.batch_number) { <strong class="dw-mono">{{ line.batch_number }}</strong> } @else { <span class="tag unknown">{{ t('production.of.anyBatch') }}</span> }</td>
                                      <td>{{ line.supplier?.name ?? '—' }}</td>
                                      <td>{{ line.step_name ? (line.step_name | dwLocalized) : '—' }}@if (line.site_name) { <span class="dw-faint"> · {{ line.site_name }}</span> }</td>
                                      <td>{{ line.consumed_on | dwDate }}</td>
                                      <td class="num">{{ line.quantity | dwQty: m.unit }}</td>
                                      @if (o.finance; as f) {
                                        <td class="num">{{ line.unit_cost === null ? '—' : (line.unit_cost | dwMoney: f.currency) }}</td>
                                        <td class="num">
                                          @if (line.basis === 'unknown') { <span class="tag missing" [title]="t('production.costReason.' + (line.reason ?? 'no_price'))">{{ t('production.unknown') }}</span> }
                                          @else { {{ line.cost | dwMoney: f.currency }} @if (line.basis === 'average') { <span class="tag est" [title]="t('production.consumed.averageHint')">{{ t('production.estimated') }}</span> } }
                                        </td>
                                      }
                                    </tr>
                                  }
                                </tbody>
                              </table>
                              @if (+m.unallocated > 0) { <p class="note warn"><dw-icon>info</dw-icon>{{ +m.allocated > 0 ? t('production.consumed.unallocated', { quantity: (m.unallocated | dwNumber: 1), unit: unitShort(m.unit), known: (m.allocated | dwNumber: 1) }) : t('production.consumed.noLotNamed') }}</p> }
                            </div>
                          </td></tr>
                        }
                      </tbody>
                    }
                  </table>
                </div>
              }
            </section>

            }
            @case ('produced') {
            <!-- Produced lots: finished goods, for the client of the OF. -->
            <section class="dw-panel">
              <div class="dw-panel-head">
                <h2 class="dw-h2">{{ t('production.produced.title') }}</h2>
                @if (canManage) { <button pButton text size="small" type="button" (click)="produce(o, null)"><dw-icon>add</dw-icon>{{ t('production.produced.new') }}</button> }
              </div>
              <p class="dw-muted intro">{{ t('production.produced.intro', { client: o.client?.name ?? '—' }) }}</p>
              @if ((o.produced_lots ?? []).length === 0) {
                <p class="empty">{{ t('production.produced.empty') }}</p>
              } @else {
                <div class="scroll">
                  <table class="dw-table">
                    <thead><tr><th>{{ t('production.produced.number') }}</th><th>{{ t('production.produced.producedOn') }}</th><th>{{ t('production.consumed.where') }}</th><th class="num">{{ t('fields.quantity') }}</th>
                      @if (o.finance?.revenue?.net_unit_price) { <th class="num">{{ t('production.finance.value') }}</th> }<th>{{ t('fields.status') }}</th><th></th></tr></thead>
                    <tbody>
                      @for (lot of o.produced_lots; track lot.ulid) {
                        <tr [class.off]="lot.status === 'cancelled'">
                          <td><strong class="dw-mono">{{ lot.lot_number }}</strong></td>
                          <td>{{ lot.produced_on | dwDate }}@if (lot.produced_until) { → {{ lot.produced_until | dwDate }} }</td>
                          <td>{{ lot.step_name ? (lot.step_name | dwLocalized) : '—' }}@if (lot.site_name) { <span class="dw-faint"> · {{ lot.site_name }}</span> }</td>
                          <td class="num">{{ lot.quantity | dwNumber }} {{ t('production.pieces') }}</td>
                          @if (o.finance?.revenue?.net_unit_price; as price) { <td class="num">{{ lot.status === 'cancelled' ? '—' : (lot.quantity * +price | dwMoney: o.finance?.currency) }}</td> }
                          <td><dw-status kind="accreditation" [value]="tone(lot.status)" [label]="t('production.producedStatus.' + lot.status)" />@if (lot.delivered_on) { <span class="dw-faint"> {{ lot.delivered_on | dwDate }}</span> }</td>
                          <td class="act">@if (canManage) { <button pButton text size="small" type="button" (click)="produce(o, lot)">{{ t('common.edit') }}</button> }</td>
                        </tr>
                      }
                    </tbody>
                    <tfoot><tr><td colspan="3">{{ t('production.produced.total') }}</td><td class="num">{{ o.produced?.quantity ?? 0 | dwNumber }} {{ t('production.pieces') }}</td>
                      <td [attr.colspan]="o.finance?.revenue?.net_unit_price ? 3 : 2" class="dw-faint">{{ t('production.produced.deliveredHint', { count: o.produced?.delivered ?? 0 }) }}</td></tr></tfoot>
                  </table>
                </div>
              }
            </section>

            }
            @case ('money') {
            <!-- Costs, revenue and gain: every figure with what it is made of. -->
            @if (o.finance; as f) {
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('production.finance.title') }}</h2><span class="dw-faint">{{ t('production.finance.exclTax', { currency: f.currency }) }}</span></div>
                <div class="money">
                  <div>
                    <h3 class="dw-h3">{{ t('production.finance.costs') }}</h3>
                    <dl class="ledger">
                      <div><dt>{{ t('production.finance.plannedMaterial') }}<small>{{ t('production.finance.plannedMaterialHint') }}</small></dt><dd>{{ f.planned_material_cost === null ? t('production.unknown') : (f.planned_material_cost | dwMoney: f.currency) }}</dd></div>
                      <div><dt>{{ t('production.finance.materialKnown') }}<small>{{ t('production.finance.materialKnownHint') }}</small></dt><dd>{{ f.material_cost.known | dwMoney: f.currency }}</dd></div>
                      @if (+f.material_cost.estimated > 0) {
                        <div><dt>{{ t('production.finance.materialEstimated') }}<small>{{ t('production.finance.materialEstimatedHint') }}</small></dt><dd>{{ f.material_cost.estimated | dwMoney: f.currency }}</dd></div>
                      }
                      @if (f.material_cost.unknown_lines > 0) {
                        <div class="missing"><dt>{{ t('production.finance.materialUnknown', { count: f.material_cost.unknown_lines }) }}<small>{{ t('production.finance.materialUnknownHint') }}</small></dt><dd>{{ t('production.unknown') }}</dd></div>
                      }
                      <div><dt>{{ t('production.expenses.title') }}
                        @if (canManageMoney) { <button pButton text size="small" type="button" class="inline" (click)="expense(o, null)"><dw-icon>add</dw-icon>{{ t('common.add') }}</button> }</dt>
                        <dd>{{ f.expenses.total | dwMoney: f.currency }}</dd></div>
                      @for (item of o.expenses ?? []; track item.ulid) {
                        <div class="sub"><dt>{{ t('production.expenseCategory.' + item.category) }} — {{ item.description }}
                          <small>{{ item.incurred_on | dwDate }}@if (item.supplier) { · {{ item.supplier.name }} }@if (item.reference) { · {{ item.reference }} }</small></dt>
                          <dd>{{ item.amount | dwMoney: item.currency_code }}
                            @if (canManageMoney) {
                              <button pButton text rounded size="small" severity="secondary" class="dw-icon-btn" type="button" (click)="expense(o, item)" [attr.aria-label]="t('common.edit')" [title]="t('common.edit')"><dw-icon>edit</dw-icon></button>
                              <button pButton text rounded size="small" severity="secondary" class="dw-icon-btn" type="button" (click)="removeExpense(item)" [attr.aria-label]="t('common.delete')" [title]="t('common.delete')"><dw-icon>delete</dw-icon></button>
                            }</dd></div>
                      }
                      <div class="total"><dt>{{ t('production.finance.totalCost') }}@if (!f.cost_complete) { <small class="warn">{{ t('production.finance.incomplete') }}</small> }</dt><dd>{{ f.total_cost | dwMoney: f.currency }}</dd></div>
                      <div><dt>{{ t('production.finance.unitCost') }}</dt><dd>{{ f.unit_cost === null ? t('production.finance.notCalculable') : (f.unit_cost | dwMoney: f.currency) }}</dd></div>
                    </dl>
                  </div>
                  <div>
                    <h3 class="dw-h3">{{ t('production.finance.revenue') }}</h3>
                    <dl class="ledger">
                      <div><dt>{{ t('production.finance.unitPrice') }}<small>@if (f.revenue.order) { {{ t('production.finance.fromOrder') }} <a class="dw-mono" [routerLink]="['/production/orders', f.revenue.order.ulid]">{{ f.revenue.order.order_number }}</a> }
                        @if (f.revenue.net_unit_price && f.revenue.net_unit_price !== f.revenue.unit_price) { · {{ t('production.finance.afterDiscount', { amount: (f.revenue.net_unit_price | dwMoney: f.currency) }) }} }</small></dt>
                        <dd>{{ f.revenue.unit_price === null ? t('production.unknown') : (f.revenue.unit_price | dwMoney: f.currency) }}</dd></div>
                      <div><dt>{{ t('production.finance.plannedValue') }}<small>{{ t('production.finance.plannedValueHint') }}</small></dt><dd>{{ f.revenue.planned_value | dwMoney: f.currency }}</dd></div>
                      <div><dt>{{ t('production.finance.producedValue') }}<small>{{ t('production.finance.producedValueHint') }}</small></dt><dd>{{ f.revenue.produced_value | dwMoney: f.currency }}</dd></div>
                      @if (f.revenue.delivered_value !== null) { <div><dt>{{ t('production.finance.deliveredValue') }}</dt><dd>{{ f.revenue.delivered_value | dwMoney: f.currency }}</dd></div> }
                      <div><dt>{{ t('production.finance.invoiced') }}<small>{{ t(f.revenue.invoiced_scope === 'order' ? 'production.finance.invoicedOrderHint' : f.revenue.invoiced_scope === 'of' ? 'production.finance.invoicedOfHint' : 'production.finance.invoicedNoneHint') }}</small></dt>
                        <dd>{{ f.revenue.invoiced === null ? '—' : (f.revenue.invoiced | dwMoney: f.currency) }}</dd></div>
                      @if (f.revenue.uninvoiced !== null) { <div><dt>{{ t('production.finance.uninvoiced') }}</dt><dd>{{ f.revenue.uninvoiced | dwMoney: f.currency }}</dd></div> }
                    </dl>
                  </div>
                </div>
                <div class="gain" [class.good]="f.gain.amount !== null && +f.gain.amount >= 0" [class.bad]="f.gain.amount !== null && +f.gain.amount < 0">
                  <div>
                    <strong>{{ t('production.finance.gain') }}</strong>
                    <p>{{ t('production.finance.gainFormula') }}</p>
                    @if (f.gain.amount === null) { <p class="warn"><dw-icon>info</dw-icon>{{ t('production.finance.gainMissing') }} {{ reasons(f.gain.missing) }}</p> }
                    @else if (f.gain.estimated) { <p class="warn"><dw-icon>info</dw-icon>{{ t('production.finance.gainEstimated') }}</p> }
                  </div>
                  <div class="amount">
                    @if (f.gain.amount !== null) {
                      <span class="calc">{{ f.gain.revenue | dwMoney: f.currency }} − {{ f.gain.cost | dwMoney: f.currency }}</span>
                      <strong>{{ f.gain.amount | dwMoney: f.currency }}</strong><span>{{ t('production.finance.margin', { pct: (f.gain.margin_pct | dwNumber: 1) }) }}</span>
                    } @else { <strong>{{ t('production.finance.notCalculable') }}</strong> }
                  </div>
                </div>
              </section>
            }
            }
            }
            @if (o.notes) { <section class="dw-panel"><div class="dw-panel-body"><strong>{{ t('fields.notes') }}</strong><p class="notes">{{ o.notes }}</p></div></section> }
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .tabs { margin-block-end: 16px; max-inline-size: 100%; overflow-x: auto; scrollbar-width: none; }
    .chain { display: flex; align-items: center; flex-wrap: wrap; gap: var(--dw-2); margin-block-end: var(--dw-4); padding: var(--dw-3) var(--dw-4); background: var(--dw-surface); border: 1px solid var(--dw-line); border-radius: var(--dw-radius); }
    .chain > dw-icon { color: var(--dw-ink-3); }
    .node { display: grid; padding: 4px 10px; border-radius: var(--dw-radius); } .node small { font-size: 11px; color: var(--dw-ink-3); text-transform: uppercase; letter-spacing: 0.04em; }
    .node.current { background: var(--dw-primary-soft, var(--dw-surface-2)); } .node.dates { margin-inline-start: auto; text-align: end; } .node.dates strong { font-weight: 560; }
    .kpis { margin-block-end: var(--dw-4); }
    .sizes { display: flex; flex-wrap: wrap; align-items: center; gap: var(--dw-3); margin: 0 0 var(--dw-4); padding: 10px 14px 10px 18px; border-radius: var(--dw-radius-lg); background: var(--dw-surface); box-shadow: var(--dw-shadow); }
    .sizes-label { display: grid; font-size: 13px; line-height: 18px; } .sizes-label b { font-weight: 650; } .sizes-label span { font-size: 12px; }
    .sizes dw-size-run { min-inline-size: 0; max-inline-size: 100%; }
    .size-edit { margin-inline-start: auto; }
    .intro { margin: 0; padding: var(--dw-2) var(--dw-4) var(--dw-3); font-size: 13px; }
    .estimate { margin: 0 var(--dw-4) var(--dw-3); padding: var(--dw-2) var(--dw-3) var(--dw-3); border-radius: var(--dw-radius); background: var(--dw-surface-2); font-size: 13px; }
    .estimate p { margin: 0 0 var(--dw-2); } .estimate table { background: var(--dw-surface); }
    .estimate .over { color: var(--dw-danger); } .estimate .under { color: var(--dw-success); }
    .gap { display: flex; justify-content: flex-end; align-items: center; gap: 2px; margin-block-start: 2px; font-size: 12px; font-weight: 600; } .gap dw-icon { font-size: 14px; }
    .empty { margin: 0; padding: var(--dw-5) var(--dw-4); text-align: center; color: var(--dw-ink-3); }
    .steps { display: flex; align-items: center; flex-wrap: wrap; gap: var(--dw-3); padding: var(--dw-3) var(--dw-4); }
    .step { display: grid; gap: 1px; padding: 8px 12px; border: 1px solid var(--dw-line); border-radius: var(--dw-radius); font-size: 13px; }
    table.cons { min-inline-size: 760px; }
    table.cons > thead th { white-space: nowrap; }
    table.cons > tbody > tr.main > td { padding-block: 14px; vertical-align: middle; }
    table.cons > tbody + tbody { border-block-start: 0; }
    tr.main.clickable { cursor: pointer; } tr.main.clickable:hover > td { background: var(--dw-surface-2); }
    tbody.open > tr.main, tbody.open > tr.main > td { background: var(--dw-surface-2); border-block-end-color: transparent; }
    .mat { display: flex; align-items: center; gap: 12px; min-inline-size: 220px; }
    .ico { display: grid; place-items: center; flex: none; inline-size: 36px; block-size: 36px; border-radius: 10px; background: var(--dw-primary-soft); color: var(--dw-primary); }
    .ico.off { background: var(--dw-surface-2); color: var(--dw-ink-3); } .ico dw-icon { font-size: 20px; }
    .who { display: grid; min-inline-size: 0; } .ref { font-weight: 650; color: var(--dw-ink); text-decoration: none; overflow-wrap: anywhere; } .ref:hover { color: var(--dw-primary); text-decoration: underline; }
    .nm { font-size: 12.5px; color: var(--dw-ink-3); }
    .sub { margin-block-start: 2px; font-size: 12px; color: var(--dw-ink-3); font-weight: 400; }
    .pending { display: inline-block; padding: 1px 9px; border-radius: 999px; background: var(--dw-surface-2); color: var(--dw-ink-3); font-size: 12px; white-space: nowrap; }
    .prog { inline-size: 170px; }
    .meter { display: flex; align-items: center; gap: 8px; }
    .bar { flex: 1; block-size: 6px; border-radius: 3px; background: var(--dw-surface-3, var(--dw-line-soft)); overflow: hidden; }
    .bar span { display: block; block-size: 100%; border-radius: 3px; background: var(--dw-primary); } .bar span.over { background: var(--dw-danger); }
    .pct { inline-size: 42px; text-align: end; font-size: 12.5px; font-weight: 600; font-variant-numeric: tabular-nums; color: var(--dw-ink-2); }
    .delta { font-weight: 600; } .m-label { display: none; }
    .chev { inline-size: 44px; text-align: end; }
    .fold { display: inline-grid; place-items: center; inline-size: 30px; block-size: 30px; border: 0; border-radius: 8px; background: none; color: var(--dw-ink-3); cursor: pointer; }
    .fold:hover { background: var(--dw-surface-3, var(--dw-line-soft)); color: var(--dw-ink); }
    tr.detail > td { padding: 0 var(--dw-4) var(--dw-3) 64px !important; background: var(--dw-surface-2); }
    .lots { border: 1px solid var(--dw-line); border-radius: var(--dw-radius); background: var(--dw-surface); overflow: hidden; }
    .lots td { white-space: nowrap; } .lots .note { margin: 0; padding: 8px 12px; border-block-start: 1px solid var(--dw-line-soft); }
    @media (max-width: 1100px) { .money-col { display: none; } }
    .dw-stack > * { min-inline-size: 0; }
    @media (max-width: 760px) {
      /* A phone reads one card per material: who it is, then planned and consumed side by side. */
      table.cons, table.cons > tbody, tr.detail, tr.detail > td { display: block; } table.cons { min-inline-size: 0; } table.cons > thead { display: none; }
      table.cons > tbody { border-block-end: 1px solid var(--dw-line-soft); }
      tr.main { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 40px; align-items: start; }
      table.cons > tbody > tr.main > td { padding: 2px 16px 12px; border: 0; text-align: start; }
      table.cons > tbody > tr.main > td.mcell { grid-column: 1 / -1; padding-block: 14px 8px; }
      table.cons > tbody > tr.main > td.chev { grid-column: 3; grid-row: 2; padding-inline: 0 8px; }
      .prog, table.cons .var-col, table.cons .money-col { display: none; }
      .m-label { display: block; font-size: 11px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--dw-ink-3); }
      .mat { min-inline-size: 0; gap: 10px; } .ico { inline-size: 32px; block-size: 32px; }
      tr.detail > td { padding: 0 var(--dw-3) var(--dw-3) !important; } .lots { overflow-x: auto; }
    }
    .scroll { overflow-x: auto; } table { inline-size: 100%; }
    .num { text-align: end; white-space: nowrap; } .act { text-align: end; }
    .over { color: var(--dw-danger); } .under { color: var(--dw-success); } tr.off td { color: var(--dw-ink-3); text-decoration: line-through; } tr.off td:last-child, tr.off td:nth-last-child(2) { text-decoration: none; }
    .tag { display: inline-block; padding: 0 7px; border-radius: 999px; font-size: 11.5px; line-height: 18px; white-space: nowrap; }
    .tag.unknown { background: var(--dw-neutral-soft, var(--dw-surface-2)); color: var(--dw-ink-2); } .tag.est { background: var(--dw-warning-soft, var(--dw-surface-2)); color: var(--dw-warning); }
    .tag.missing { background: var(--dw-danger-soft, var(--dw-surface-2)); color: var(--dw-danger); }
    .note { display: flex; align-items: center; gap: 6px; margin: var(--dw-2) var(--dw-4) 0; font-size: 12.5px; } .warn { color: var(--dw-warning); } .note dw-icon, p.warn dw-icon { font-size: 16px; }
    .money { display: grid; gap: var(--dw-5); grid-template-columns: repeat(2, minmax(0, 1fr)); padding: var(--dw-4); }
    .ledger { margin: var(--dw-2) 0 0; } .ledger > div { display: flex; justify-content: space-between; align-items: baseline; gap: var(--dw-3); padding-block: 7px; border-block-end: 1px solid var(--dw-line-soft); }
    .ledger dt { font-size: 13px; } .ledger dt small { display: block; font-size: 11.5px; color: var(--dw-ink-3); font-weight: 400; } .ledger dt small.warn { color: var(--dw-warning); }
    .ledger dd { margin: 0; font-variant-numeric: tabular-nums; white-space: nowrap; display: flex; align-items: center; gap: 2px; }
    .ledger .sub dt { padding-inline-start: var(--dw-4); color: var(--dw-ink-2); } .ledger .total { font-weight: 650; border-block-end-color: var(--dw-line); } .ledger .missing dd { color: var(--dw-danger); }
    .inline { margin-inline-start: 6px; vertical-align: baseline; }
    .gain { display: flex; justify-content: space-between; align-items: center; gap: var(--dw-4); flex-wrap: wrap; margin: 0 var(--dw-4) var(--dw-4); padding: var(--dw-3) var(--dw-4); border-radius: var(--dw-radius); background: var(--dw-surface-2); border: 1px solid var(--dw-line); }
    .gain.good { border-color: var(--dw-success); } .gain.bad { border-color: var(--dw-danger); }
    .gain p { margin: 2px 0 0; font-size: 12.5px; color: var(--dw-ink-2); max-inline-size: 70ch; display: flex; gap: 6px; align-items: flex-start; } .gain p.warn { color: var(--dw-warning); }
    .amount { display: grid; justify-items: end; font-variant-numeric: tabular-nums; } .amount strong { font-size: 20px; } .amount span { font-size: 12.5px; color: var(--dw-ink-2); }
    .gain.good .amount strong { color: var(--dw-success); } .gain.bad .amount strong { color: var(--dw-danger); }
    .notes { margin: 4px 0 0; white-space: pre-line; }
    @media (max-width: 900px) { .money { grid-template-columns: minmax(0, 1fr); } .node.dates { margin-inline-start: 0; text-align: start; } }
  `,
})
export class OfDetailPage implements OnInit {
  protected readonly tab = signal<'waste' | 'cut' | 'make' | 'produced' | 'money'>('waste');

  protected tabOptions(t: (key: string) => string, money: boolean): { value: string; label: string }[] {
    return [
      { value: 'waste', label: t('production.waste.title') },
      { value: 'cut', label: t('production.cutting.tab') },
      { value: 'make', label: t('production.of.tabMake') },
      { value: 'produced', label: t('production.produced.title') },
      ...(money ? [{ value: 'money', label: t('production.of.tabMoney') }] : []),
    ];
  }

  readonly ulid = input.required<string>();

  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly toast = inject(Toast);
  private readonly transloco = inject(TranslocoService);
  private readonly session = inject(Session);

  protected get canManage(): boolean {
    return this.session.canEditProduction();
  }

  /** Sizes and spreads are DivaWaste's own (an ERP never sends them): entered here even with an ERP. */
  protected get canCut(): boolean {
    return this.session.can('production.manage');
  }
  protected get canManageMoney(): boolean {
    return this.canManage && this.session.can('production.finance.view');
  }
  protected readonly canCreateLot = this.session.sitesFor('lots.create').length > 0;
  protected readonly tone = statusTone;
  protected readonly of = signal<ManufacturingOrder | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly liveLots = computed(() => (this.of()?.produced_lots ?? []).filter((lot) => lot.status !== 'cancelled').length);

  ngOnInit(): void {
    this.load();
  }

  /**
   * What was weighed of a type against what was expected (for the pieces produced, else those planned): above is
   * red, below green. Below while lots are still to weigh says nothing yet — their weight is still to come.
   */
  protected gap(row: NonNullable<OfWaste['estimated']>['by_type'][number]): { kg: number; pct: number; tone: 'over' | 'under' | null } | null {
    const expected = Number(row.produced_kg ?? row.planned_kg);
    if (row.weighed_kg === null || !(expected > 0)) {
      return null;
    }
    const kg = Math.round((Number(row.weighed_kg) - expected) * 1000) / 1000;
    const tone = kg > 0 ? 'over' : kg < 0 && row.unweighed_lots === 0 ? 'under' : null;

    return { kg: Math.abs(kg), pct: (Math.abs(kg) / expected) * 100, tone };
  }

  /** The sizes of the OF and the pieces of each, as its size run shows them. */
  protected readonly sizeRun = computed(() => {
    const lines = this.of()?.sizes ?? [];

    return { sizes: lines.map((line) => line.size), quantities: Object.fromEntries(lines.map((line) => [line.size, line.quantity])) as Record<string, number> };
  });

  /** The sizes of the OF and the pieces of each, from a grid or picked one by one. */
  protected editSizes(o: ManufacturingOrder): void {
    this.dialog.open<OfSizesDialog, ManufacturingOrder, ManufacturingOrder>(OfSizesDialog, { data: o }).afterClosed().subscribe((saved) => saved && this.of.set(saved));
  }

  protected load(): void {
    this.loading.set(this.of() === null);
    this.error.set(null);
    this.api.get<ManufacturingOrder>(`production/manufacturing-orders/${this.ulid()}`).subscribe({
      next: (of) => {
        this.of.set(of);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  /** The materials whose received lots are shown. */
  protected readonly expanded = signal<string[]>([]);

  protected toggle(material: string): void {
    this.expanded.update((open) => (open.includes(material) ? open.filter((item) => item !== material) : [...open, material]));
  }

  protected kindIcon(kind: MaterialKind | null): string {
    return (kind && KIND_ICON[kind]) || 'inventory_2';
  }

  protected unitShort(unit: string): string {
    return this.transloco.translate('units.' + unit);
  }

  /** Consumed against planned, in %, uncapped. */
  protected ratio(material: ConsumedMaterial): number {
    const planned = Number(material.planned ?? 0);

    return planned > 0 ? (Number(material.consumed) / planned) * 100 : 0;
  }

  /** Consumed as a share of planned, capped: the bar shows "how far", the figures say by how much it is over. */
  protected share(material: ConsumedMaterial): number {
    const planned = Number(material.planned ?? 0);

    return planned > 0 ? Math.min(100, (Number(material.consumed) / planned) * 100) : 0;
  }

  /** Why a figure cannot be given, in words. */
  protected reasons(missing: string[]): string {
    return missing.map((reason) => this.transloco.translate('production.missing.' + reason)).join(' · ');
  }

  private saved = (of: ManufacturingOrder | undefined): void => {
    if (of) {
      this.of.set(of);
    }
  };

  protected edit(of: ManufacturingOrder): void {
    this.dialog.open<ManufacturingOrderDialog, ManufacturingOrder | null, ManufacturingOrder>(ManufacturingOrderDialog, { data: of }).afterClosed().subscribe(this.saved);
  }

  protected consume(of: ManufacturingOrder): void {
    this.dialog.open<ConsumptionsDialog, ManufacturingOrder, ManufacturingOrder>(ConsumptionsDialog, { data: of }).afterClosed().subscribe(this.saved);
  }

  protected produce(of: ManufacturingOrder, lot: ProducedLot | null): void {
    this.dialog.open<ProducedLotDialog, ProducedLotDialogData, ManufacturingOrder>(ProducedLotDialog, { data: { of, lot } }).afterClosed().subscribe(this.saved);
  }

  protected expense(of: ManufacturingOrder, expense: OfExpense | null): void {
    this.dialog.open<ExpenseDialog, ExpenseDialogData, ManufacturingOrder>(ExpenseDialog, { data: { of, expense } }).afterClosed().subscribe(this.saved);
  }

  /** The ordinary creation of a waste lot, with this OF already chosen: the waste register and its rules are the same. */
  protected waste(of: ManufacturingOrder): void {
    this.dialog.open(CreateLotDialog, { data: { production_order: of.ulid } }).afterClosed().subscribe((lot: unknown) => lot && this.load());
  }

  /** G-09: deleted at once; "Annuler" records the same expense again. */
  protected async removeExpense(expense: OfExpense): Promise<void> {
    const of = this.of();
    try {
      this.of.set(await firstValueFrom(this.api.delete<ManufacturingOrder>(`production/expenses/${expense.ulid}`)));
      if (of) {
        this.toast.undoable('production.expenses.deleted', () => void this.restoreExpense(of.ulid, expense), { description: expense.description });
      }
    } catch (error) {
      this.toast.error(error);
    }
  }

  private async restoreExpense(of: string, expense: OfExpense): Promise<void> {
    const { category, description, amount, currency_code, incurred_on, reference, notes, supplier } = expense;
    try {
      this.of.set(await firstValueFrom(this.api.post<ManufacturingOrder>(`production/manufacturing-orders/${of}/expenses`,
        { category, description, amount, currency_code, incurred_on, reference, notes, supplier: supplier?.ulid ?? null })));
    } catch (error) {
      this.toast.error(error);
    }
  }
}
