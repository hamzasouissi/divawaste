import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, MoneyPipe, NumberPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { DateComponent } from '../../ui/date';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { KpiComponent, PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SELECT } from '../../ui/select';
import { StateComponent } from '../../ui/state';
import { OrderDialog } from './orders';
import { AMOUNT, CustomerOrder, OrderInvoice, blankToNull, decimal, statusTone } from './production-data';

export interface InvoiceDialogData {
  order: CustomerOrder;
  invoice: OrderInvoice | null;
}

/**
 * An invoice or a credit note issued for the order. It is issued elsewhere: its number, date and amount are recorded
 * here so that "ordered" and "really invoiced" can be told apart.
 */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...DIALOG_PARTS, DateComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data.invoice ? 'production.invoices.edit' : 'production.invoices.new') }} — {{ data.order.order_number }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field [label]="t('production.invoices.kind')">
              <dw-select formControlName="kind"><dw-option value="invoice" [label]="t('production.invoiceKind.invoice')" /><dw-option value="credit_note" [label]="t('production.invoiceKind.credit_note')" /></dw-select>
            </dw-field>
            <dw-field [label]="t('production.invoices.number')"><input pInputText formControlName="number" maxlength="60" required /></dw-field>
            <dw-field [label]="t('production.invoices.amount')"><input pInputText formControlName="amount" inputmode="decimal" required /><small dwHint>{{ t('production.invoices.amountHint') }}</small></dw-field>
            <dw-field [label]="t('fields.date')"><dw-date formControlName="issued_on" /></dw-field>
            <dw-field class="dw-span-2" [label]="t('production.invoices.of')">
              <dw-select formControlName="manufacturing_order">
                <dw-option value="" [label]="t('production.invoices.wholeOrder')" />
                @for (of of data.order.orders ?? []; track of.ulid) { <dw-option [value]="of.ulid" [label]="of.order_number + ' — ' + of.article" /> }
              </dw-select>
              <small dwHint>{{ t('production.invoices.ofHint') }}</small>
            </dw-field>
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
  styles: '.dw-dialog-body { inline-size: min(600px, 88vw); }',
})
export class InvoiceDialog {
  protected readonly data = dialogData<InvoiceDialogData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly busy = signal(false);
  private readonly invoice = this.data.invoice;
  protected readonly form = inject(FormBuilder).nonNullable.group({
    kind: [this.invoice?.kind ?? ('invoice' as string)],
    number: [this.invoice?.number ?? '', Validators.required],
    amount: [this.invoice ? String(+this.invoice.amount) : '', [Validators.required, AMOUNT]],
    issued_on: [this.invoice?.issued_on ?? ''],
    manufacturing_order: [this.invoice?.manufacturing_order?.ulid ?? ''],
    notes: [this.invoice?.notes ?? ''],
  });

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const body = blankToNull({ ...value, amount: decimal(value.amount) });
    try {
      this.ref.close(await firstValueFrom(this.invoice
        ? this.api.put<CustomerOrder>(`production/invoices/${this.invoice.ulid}`, body)
        : this.api.post<CustomerOrder>(`production/orders/${this.data.order.ulid}/invoices`, body)));
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

/** An order of a client: what it asks for and at what price, the OFs that make it, and what was invoiced for it. */
@Component({
  imports: [RouterLink, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, KpiComponent, ButtonDirective, IconComponent, DatePipe, NumberPipe, MoneyPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (order(); as o) {
          <dw-page-header [title]="t('production.orders.detail', { number: o.order_number })" [subtitle]="o.client?.name ?? ''" back="/production/orders" [backLabel]="t('production.orders.title')">
            <dw-status dwTitleBadge kind="accreditation" [value]="tone(o.status)" [label]="t('production.orderStatus.' + o.status)" />
            @if (canManage) { <button pButton severity="secondary" outlined type="button" (click)="edit(o)"><dw-icon>edit</dw-icon>{{ t('common.edit') }}</button> }
          </dw-page-header>

          <div class="dw-grid-4 kpis">
            <dw-kpi [label]="t('production.orders.quantity')" [value]="o.quantity | dwNumber" [hint]="(o.ordered_on | dwDate) + (o.due_on ? ' → ' + (o.due_on | dwDate) : '')" />
            @if (money) {
              <dw-kpi [label]="t('production.finance.orderValue')" [value]="o.order_value === null ? t('production.unknown') : (o.order_value | dwMoney: o.currency_code)"
                [hint]="o.order_value === null ? t('production.orders.priceMissing') : (o.discount_amount ? t('production.orders.discounted', { amount: (o.discount_amount | dwMoney: o.currency_code) }) : t('production.finance.orderValueHint'))"
                [tone]="o.order_value === null ? 'warning' : 'neutral'" />
              <dw-kpi [label]="t('production.finance.invoiced')" [value]="o.invoiced | dwMoney: o.currency_code" [hint]="t('production.invoices.count', { count: (o.invoices ?? []).length })" />
              <dw-kpi [label]="t('production.finance.uninvoiced')" [value]="o.uninvoiced === null ? '—' : (o.uninvoiced | dwMoney: o.currency_code)" [hint]="t('production.finance.uninvoicedHint')" />
            } @else {
              <dw-kpi [label]="t('production.of.title')" [value]="(o.orders ?? []).length" hint="" />
            }
          </div>

          <div class="dw-stack">
            <section class="dw-panel">
              <div class="dw-panel-head"><h2 class="dw-h2">{{ t('production.orders.lines') }}</h2></div>
              <div class="scroll">
                <table class="dw-table">
                  <thead><tr><th>{{ t('production.articles.one') }}</th><th class="num">{{ t('fields.quantity') }}</th>@if (money) { <th class="num">{{ t('production.finance.unitPrice') }}</th><th class="num">{{ t('production.finance.amount') }}</th> }</tr></thead>
                  <tbody>
                    @for (line of o.lines; track line.article) {
                      <tr><td><strong class="dw-mono">{{ line.reference }}</strong> {{ line.name }}</td><td class="num">{{ line.quantity | dwNumber }}</td>
                        @if (money) { <td class="num">{{ line.unit_price === null ? t('production.unknown') : (line.unit_price | dwMoney: o.currency_code) }}</td><td class="num">{{ line.amount | dwMoney: o.currency_code }}</td> }</tr>
                    }
                  </tbody>
                  @if (money && o.order_value !== null) {
                    <tfoot>
                      @if (o.discount_amount) { <tr><td colspan="3">{{ t('production.orders.discount') }}</td><td class="num">− {{ o.discount_amount | dwMoney: o.currency_code }}</td></tr> }
                      <tr><td colspan="3">{{ t('production.finance.orderValue') }}</td><td class="num">{{ o.order_value | dwMoney: o.currency_code }}</td></tr>
                    </tfoot>
                  }
                </table>
              </div>
            </section>

            <section class="dw-panel">
              <div class="dw-panel-head"><h2 class="dw-h2">{{ t('production.of.title') }}</h2></div>
              @if ((o.orders ?? []).length === 0) {
                <p class="empty">{{ t('production.orders.noOf') }}</p>
              } @else {
                <div class="scroll">
                  <table class="dw-table">
                    <thead><tr><th>{{ t('production.of.one') }}</th><th>{{ t('production.articles.one') }}</th><th class="num">{{ t('production.of.producedPlanned') }}</th>
                      @if (money) { <th class="num">{{ t('production.finance.totalCost') }}</th><th class="num">{{ t('production.finance.producedValue') }}</th><th class="num">{{ t('production.finance.gain') }}</th> }<th>{{ t('fields.status') }}</th></tr></thead>
                    <tbody>
                      @for (of of o.orders; track of.ulid) {
                        <tr>
                          <td><a class="dw-mono" [routerLink]="['/production/manufacturing-orders', of.ulid]">{{ of.order_number }}</a></td><td>{{ of.article }}</td>
                          <td class="num">{{ of.quantity_produced === null ? '—' : (of.quantity_produced | dwNumber) }} / {{ of.quantity_planned | dwNumber }}</td>
                          @if (money) {
                            <td class="num">{{ of.total_cost | dwMoney: o.currency_code }}@if (of.cost_complete === false) { <span class="dw-faint" [title]="t('production.finance.incomplete')"> *</span> }</td>
                            <td class="num">{{ of.produced_value | dwMoney: o.currency_code }}</td>
                            <td class="num">{{ of.gain === null ? t('production.finance.notCalculable') : (of.gain | dwMoney: o.currency_code) }}</td>
                          }
                          <td><dw-status kind="accreditation" [value]="tone(of.status)" [label]="t('production.ofStatus.' + of.status)" /></td>
                        </tr>
                      }
                    </tbody>
                  </table>
                </div>
              }
            </section>

            @if (money) {
              <section class="dw-panel">
                <div class="dw-panel-head">
                  <h2 class="dw-h2">{{ t('production.invoices.title') }}</h2>
                  @if (canManage) { <button pButton text size="small" type="button" (click)="invoice(o, null)"><dw-icon>add</dw-icon>{{ t('production.invoices.new') }}</button> }
                </div>
                <p class="dw-muted intro">{{ t('production.invoices.intro') }}</p>
                @if ((o.invoices ?? []).length === 0) {
                  <p class="empty">{{ t('production.invoices.empty') }}</p>
                } @else {
                  <div class="scroll">
                    <table class="dw-table">
                      <thead><tr><th>{{ t('production.invoices.number') }}</th><th>{{ t('production.invoices.kind') }}</th><th>{{ t('fields.date') }}</th><th>{{ t('production.invoices.of') }}</th><th class="num">{{ t('production.finance.amount') }}</th><th></th></tr></thead>
                      <tbody>
                        @for (item of o.invoices; track item.ulid) {
                          <tr>
                            <td><strong class="dw-mono">{{ item.number }}</strong></td><td>{{ t('production.invoiceKind.' + item.kind) }}</td><td>{{ item.issued_on | dwDate }}</td>
                            <td>@if (item.manufacturing_order) { <a class="dw-mono" [routerLink]="['/production/manufacturing-orders', item.manufacturing_order.ulid]">{{ item.manufacturing_order.order_number }}</a> } @else { <span class="dw-faint">{{ t('production.invoices.wholeOrder') }}</span> }</td>
                            <td class="num">{{ item.kind === 'credit_note' ? '− ' : '' }}{{ item.amount | dwMoney: item.currency_code }}</td>
                            <td class="act">@if (canManage) {
                              <button pButton text size="small" type="button" (click)="invoice(o, item)">{{ t('common.edit') }}</button>
                              <button pButton text rounded size="small" severity="secondary" class="dw-icon-btn" type="button" (click)="remove(item)" [attr.aria-label]="t('common.delete')"><dw-icon>delete</dw-icon></button>
                            }</td>
                          </tr>
                        }
                      </tbody>
                      <tfoot><tr><td colspan="4">{{ t('production.finance.invoiced') }}</td><td class="num">{{ o.invoiced | dwMoney: o.currency_code }}</td><td></td></tr></tfoot>
                    </table>
                  </div>
                }
              </section>
            }
            @if (o.notes) { <section class="dw-panel"><div class="dw-panel-body"><strong>{{ t('fields.notes') }}</strong><p class="notes">{{ o.notes }}</p></div></section> }
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .kpis { margin-block-end: var(--dw-4); } .scroll { overflow-x: auto; } table { inline-size: 100%; }
    .num { text-align: end; white-space: nowrap; } .act { text-align: end; white-space: nowrap; }
    .intro { margin: 0; padding: var(--dw-2) var(--dw-4) var(--dw-3); font-size: 13px; }
    .empty { margin: 0; padding: var(--dw-5) var(--dw-4); text-align: center; color: var(--dw-ink-3); } .notes { margin: 4px 0 0; white-space: pre-line; }
  `,
})
export class OrderDetailPage implements OnInit {
  readonly ulid = input.required<string>();

  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);
  private readonly transloco = inject(TranslocoService);
  private readonly session = inject(Session);

  protected readonly money = this.session.can('production.finance.view');
  protected readonly canManage = this.session.can('production.manage');
  protected readonly tone = statusTone;
  protected readonly order = signal<CustomerOrder | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(this.order() === null);
    this.error.set(null);
    this.api.get<CustomerOrder>(`production/orders/${this.ulid()}`).subscribe({
      next: (order) => {
        this.order.set(order);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected edit(order: CustomerOrder): void {
    this.dialog.open<OrderDialog, CustomerOrder | null, CustomerOrder>(OrderDialog, { data: order }).afterClosed().subscribe((saved) => saved && this.load());
  }

  protected invoice(order: CustomerOrder, invoice: OrderInvoice | null): void {
    this.dialog.open<InvoiceDialog, InvoiceDialogData, CustomerOrder>(InvoiceDialog, { data: { order, invoice } }).afterClosed().subscribe((saved) => saved && this.order.set(saved));
  }

  protected async remove(invoice: OrderInvoice): Promise<void> {
    if (!(await this.confirm.ask({ title: this.transloco.translate('production.invoices.deleteTitle', { number: invoice.number }), confirmLabel: this.transloco.translate('common.delete'), danger: true }))) {
      return;
    }
    try {
      this.order.set(await firstValueFrom(this.api.delete<CustomerOrder>(`production/invoices/${invoice.ulid}`)));
    } catch (error) {
      this.toast.error(error);
    }
  }
}
