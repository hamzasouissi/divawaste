import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialog, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom, of } from 'rxjs';

import { Api, openBlob } from '../../core/api/api';
import { AuditEntry, Company, Invoice, Subscription } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, LocalizedPipe, MoneyPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { StateComponent } from '../../ui/state';

interface AuditFilter {
  entity?: string | null;
  event?: string | null;
  from?: string | null;
  to?: string | null;
}

/** M7-02: who did what, when, from where. Read-only by nature; the row opens to show the values before and after. */
@Component({
  imports: [FormsModule, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, TranslocoDirective, ...TABLE, PageHeaderComponent, DatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('admin.audit.title')" [subtitle]="t('admin.audit.subtitle')" />
      <div class="dw-panel">
        <div class="filters">
          <mat-form-field>
            <mat-label>{{ t('admin.audit.event') }}</mat-label>
            <mat-select [value]="list.filter().event ?? ''" (selectionChange)="list.setFilter({ event: $event.value || null })">
              <mat-option value="">{{ t('common.all') }}</mat-option>
              @for (event of events; track event) { <mat-option [value]="event">{{ t('admin.audit.events.' + event) }}</mat-option> }
            </mat-select>
          </mat-form-field>
          <mat-form-field>
            <mat-label>{{ t('admin.audit.entity') }}</mat-label>
            <mat-select [value]="list.filter().entity ?? ''" (selectionChange)="list.setFilter({ entity: $event.value || null })">
              <mat-option value="">{{ t('common.all') }}</mat-option>
              @for (entity of entities; track entity) { <mat-option [value]="entity">{{ entity }}</mat-option> }
            </mat-select>
          </mat-form-field>
          <mat-form-field class="date"><mat-label>{{ t('period.from') }}</mat-label><input matInput type="date" [ngModel]="list.filter().from ?? ''" (ngModelChange)="list.setFilter({ from: $event || null })" /></mat-form-field>
          <mat-form-field class="date"><mat-label>{{ t('period.to') }}</mat-label><input matInput type="date" [ngModel]="list.filter().to ?? ''" (ngModelChange)="list.setFilter({ to: $event || null })" /></mat-form-field>
          @if (list.filtered()) { <button mat-button type="button" (click)="list.resetFilter()">{{ t('common.resetFilters') }}</button> }
        </div>
        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1"
          [pageSize]="list.perPage()" [trackBy]="track" emptyIcon="history" [emptyTitle]="t('admin.audit.emptyTitle')"
          (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (retry)="list.reload()">
          <ng-template dwCell="when" let-e><span class="dw-nowrap">{{ e.occurred_at | dwDate: 'datetime' }}</span></ng-template>
          <ng-template dwCell="actor" let-e>{{ e.actor?.name ?? t('admin.audit.system') }}<div class="dw-faint">{{ e.source }}@if (e.ip_address) { · {{ e.ip_address }} }</div></ng-template>
          <ng-template dwCell="event" let-e>{{ eventLabel(e.event, t) }}</ng-template>
          <ng-template dwCell="entity" let-e>{{ e.entity }}<div class="dw-faint dw-mono">{{ e.object }}</div></ng-template>
          <ng-template dwCell="changes" let-e>
            @if (changes(e).length > 0) {
              <details>
                <summary>{{ changes(e).length }} — {{ summary(e) }}</summary>
                <table class="diff">
                  <thead><tr><th></th><th>{{ t('admin.audit.before') }}</th><th>{{ t('admin.audit.after') }}</th></tr></thead>
                  <tbody>@for (change of changes(e); track change.key) { <tr><th scope="row">{{ change.key }}</th><td>{{ change.before }}</td><td>{{ change.after }}</td></tr> }</tbody>
                </table>
              </details>
            } @else { — }
          </ng-template>
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: `
    .filters { display: flex; align-items: center; gap: var(--dw-2) var(--dw-3); flex-wrap: wrap; padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }
    .filters mat-form-field { width: 200px; } .filters .date { width: 160px; }
    summary { cursor: pointer; color: var(--dw-ink-2); max-width: 420px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .diff { margin-block: 6px; font-size: 12px; border-collapse: collapse; }
    .diff th, .diff td { padding: 3px 8px; border: 1px solid var(--dw-line-soft); text-align: start; vertical-align: top; max-width: 280px; overflow-wrap: anywhere; }
    .diff th { background: var(--dw-surface-2); font-weight: 600; }
  `,
})
export class AuditPage {
  private readonly api = inject(Api);

  protected readonly events = ['created', 'updated', 'deleted', 'restored'];
  protected readonly entities = ['waste_lots', 'pickups', 'sites', 'zones', 'waste_types', 'stock_thresholds', 'documents', 'hazardous_waste_manifests', 'annual_declarations', 'users', 'companies',
    'provider_accreditations', 'provider_partnerships', 'company_subscriptions', 'invoices', 'payments'];
  protected readonly list = new ListStore<AuditEntry, AuditFilter>((query) => this.api.list<AuditEntry>('audit-logs', query));
  protected readonly track = (entry: AuditEntry): string => `${entry.occurred_at}${entry.entity}${entry.object}${entry.event}`;

  protected readonly columns: Column<AuditEntry>[] = [
    { key: 'when', header: 'admin.audit.when' },
    { key: 'actor', header: 'admin.audit.actor' },
    { key: 'event', header: 'admin.audit.event' },
    { key: 'entity', header: 'admin.audit.entity' },
    { key: 'changes', header: 'admin.audit.changes', hideBelow: 900 },
  ];

  /** Standard events are translated; business events (accreditation_approved…) keep their technical name. */
  protected eventLabel(event: string, t: (key: string) => string): string {
    return this.events.includes(event) ? t(`admin.audit.events.${event}`) : event;
  }

  protected changes(entry: AuditEntry): { key: string; before: string; after: string }[] {
    const show = (value: unknown): string => (value === null || value === undefined ? '—' : typeof value === 'object' ? JSON.stringify(value) : String(value));
    const keys = new Set([...Object.keys(entry.old_values ?? {}), ...Object.keys(entry.new_values ?? {})]);

    return [...keys].map((key) => ({ key, before: show(entry.old_values?.[key]), after: show(entry.new_values?.[key]) }));
  }

  protected summary(entry: AuditEntry): string {
    return this.changes(entry).map((change) => change.key).join(', ');
  }
}

interface DeclareData {
  invoice: Invoice;
}

/** M0-04: the client declares its bank transfer; the platform validates it against the bank statement. */
@Component({
  imports: [ReactiveFormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatInputModule, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ t('admin.subscription.declareTitle', { number: data.invoice.invoice_number }) }}</h2>
      <form [formGroup]="form" (ngSubmit)="save()">
        <mat-dialog-content>
          <mat-form-field>
            <mat-label>{{ t('admin.subscription.amount') }} ({{ data.invoice.currency_code }})</mat-label>
            <input matInput inputmode="decimal" formControlName="amount" />
            @if (form.controls.amount.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
          </mat-form-field>
          <mat-form-field>
            <mat-label>{{ t('admin.subscription.bankReference') }}</mat-label>
            <input matInput formControlName="bank_reference" maxlength="100" />
            @if (form.controls.bank_reference.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
          </mat-form-field>
          <mat-form-field>
            <mat-label>{{ t('admin.subscription.paidOn') }}</mat-label>
            <input matInput type="date" formControlName="declared_paid_on" [max]="today" />
            @if (form.controls.declared_paid_on.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
          </mat-form-field>
        </mat-dialog-content>
        <mat-dialog-actions align="end">
          <button mat-button type="button" mat-dialog-close>{{ t('common.cancel') }}</button>
          <button mat-flat-button type="submit" [disabled]="busy()">{{ t('admin.subscription.declare') }}</button>
        </mat-dialog-actions>
      </form>
    </ng-container>
  `,
  styles: 'mat-dialog-content { display: grid; min-width: min(420px, 80vw); }',
})
export class DeclarePaymentDialog {
  protected readonly data = inject<DeclareData>(MAT_DIALOG_DATA);
  private readonly ref = inject(MatDialogRef<DeclarePaymentDialog, boolean>);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);

  protected readonly today = new Date().toISOString().slice(0, 10);
  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    amount: [(Number(this.data.invoice.total_amount) - Number(this.data.invoice.amount_paid)).toFixed(3), [Validators.required, Validators.pattern(/^\d+([.,]\d{1,3})?$/)]],
    bank_reference: ['', Validators.required],
    declared_paid_on: [this.today, Validators.required],
  });

  protected async save(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    try {
      const value = this.form.getRawValue();
      await firstValueFrom(this.api.post(`invoices/${this.data.invoice.ulid}/payments`, { ...value, amount: value.amount.replace(',', '.') }));
      this.toast.success('admin.subscription.declared');
      this.ref.close(true);
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

/** M0-11: the plan, its invoices and the payments. Prices are agreed with Diva Software: a change is a request. */
@Component({
  imports: [FormsModule, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, StateComponent,
    DatePipe, LocalizedPipe, MoneyPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('admin.subscription.title')" [subtitle]="t('admin.subscription.subtitle')" />
      <div class="dw-stack">
        <dw-state [loading]="!subscription() && !error()" [error]="error()" (retry)="load()">
          @if (subscription(); as s) {
            <section class="dw-panel">
              <header class="dw-panel-head">
                <h2 class="dw-h2 dw-spacer">{{ t('admin.subscription.plan') }}</h2>
                @if (s.current; as current) { <dw-status kind="subscription" [value]="current.status" /> }
              </header>
              <div class="dw-panel-body dw-stack-sm">
                @if (s.current; as current) {
                  @if (lapsed()) { <p class="lapsed" role="alert">{{ t('admin.subscription.lapsed') }}</p> }
                  <p class="plan">{{ planName(current.plan) | dwLocalized: (current.plan ?? '—') }}</p>
                  @if (current.status === 'trialing' && current.trial_ends_at) { <p>{{ t('admin.subscription.trialEnds', { date: (current.trial_ends_at | dwDate) }) }}</p> }
                  @if (current.current_period_start && current.current_period_end) {
                    <p>{{ t('admin.subscription.period', { from: (current.current_period_start | dwDate), to: (current.current_period_end | dwDate) }) }}</p>
                  }
                  <p>{{ t('admin.subscription.sites', { active: s.active_sites, quantity: current.sites_quantity }) }}</p>
                  @if (current.unit_amount) {
                    <p>{{ t('admin.subscription.unitPrice', { amount: (current.unit_amount | dwMoney: current.currency_code), interval: t('admin.subscription.intervals.' + current.billing_interval) }) }}</p>
                  }
                  @if (current.cancel_at_period_end) { <p class="dw-muted">{{ t('admin.subscription.cancelScheduled') }}</p> }
                }
                @if (s.requested_plan) {
                  <p class="requested">{{ t('admin.subscription.requested', { plan: (planName(s.requested_plan) | dwLocalized: s.requested_plan), sites: s.requested_sites }) }}</p>
                }

                @if (canManage) {
                  <div class="change">
                    <h3 class="dw-h3">{{ t('admin.subscription.change') }}</h3>
                    <div class="dw-row">
                      <mat-form-field>
                        <mat-label>{{ t('auth.register.plan') }}</mat-label>
                        <mat-select [ngModel]="plan()" (ngModelChange)="plan.set($event)">
                          @for (item of paidPlans(); track item.code) { <mat-option [value]="item.code">{{ item.name | dwLocalized }}</mat-option> }
                        </mat-select>
                      </mat-form-field>
                      <mat-form-field class="sites">
                        <mat-label>{{ t('admin.subscription.sitesWanted') }}</mat-label>
                        <input matInput type="number" min="1" max="500" [ngModel]="sites()" (ngModelChange)="sites.set($event)" />
                      </mat-form-field>
                      <button mat-flat-button type="button" [disabled]="busy() || !plan() || !(sites() >= 1)" (click)="request()">{{ t('admin.subscription.changeTitle') }}</button>
                      <span class="dw-spacer"></span>
                      @if (s.current?.cancel_at_period_end) {
                        <button mat-stroked-button type="button" [disabled]="busy()" (click)="resume()">{{ t('admin.subscription.resume') }}</button>
                      } @else if (s.current && s.current.status === 'active') {
                        <button mat-button type="button" [disabled]="busy()" (click)="cancel()">{{ t('admin.subscription.cancel') }}</button>
                      }
                    </div>
                  </div>
                }
              </div>
            </section>
          }
        </dw-state>

        @if (canInvoices) {
          <section class="dw-panel">
            <header class="dw-panel-head"><h2 class="dw-h2">{{ t('admin.subscription.invoices') }}</h2></header>
            <dw-data-table [columns]="columns" [rows]="invoices.rows()" [loading]="invoices.loading()" [error]="invoices.error()" [total]="invoices.total()" [pageIndex]="invoices.page() - 1"
              [pageSize]="invoices.perPage()" emptyIcon="receipt_long" [emptyTitle]="t('admin.subscription.noInvoice')"
              (page)="invoices.setPage($event.pageIndex + 1, $event.pageSize)" (retry)="invoices.reload()">
              <ng-template dwCell="number" let-i><span class="dw-mono">{{ i.invoice_number }}</span></ng-template>
              <ng-template dwCell="issue" let-i>{{ i.issue_date | dwDate }}</ng-template>
              <ng-template dwCell="due" let-i>{{ i.due_date | dwDate }}</ng-template>
              <ng-template dwCell="total" let-i>{{ i.total_amount | dwMoney: i.currency_code }}</ng-template>
              <ng-template dwCell="paid" let-i>{{ i.amount_paid | dwMoney: i.currency_code }}</ng-template>
              <ng-template dwCell="status" let-i>
                <dw-status kind="invoice" [value]="i.status" />
                @if (i.pending_payment) { <div class="dw-faint">{{ t('admin.subscription.pendingPayment') }}</div> }
              </ng-template>
              <ng-template dwCell="actions" let-i>
                <div class="dw-row-tight">
                  <button mat-stroked-button type="button" (click)="pdf(i)"><mat-icon>picture_as_pdf</mat-icon>PDF</button>
                  @if (canManage && !i.pending_payment && (i.status === 'issued' || i.status === 'partially_paid' || i.status === 'overdue')) {
                    <button mat-flat-button type="button" (click)="declare(i)">{{ t('admin.subscription.declare') }}</button>
                  }
                </div>
              </ng-template>
            </dw-data-table>
          </section>
        }
      </div>
    </ng-container>
  `,
  styles: `
    .plan { font-size: 20px; font-weight: 600; }
    .lapsed { padding: 8px 12px; border-radius: var(--dw-radius); background: var(--dw-danger-soft); color: var(--dw-danger); font-weight: 600; }
    .requested { padding: 8px 12px; border-radius: var(--dw-radius); background: var(--dw-info-soft); color: var(--dw-info); }
    .change { border-block-start: 1px solid var(--dw-line-soft); margin-block-start: var(--dw-3); padding-block-start: var(--dw-3); display: grid; gap: var(--dw-2); }
    .change mat-form-field { width: 260px; } .change .sites { width: 150px; }
  `,
})
export class SubscriptionPage {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);
  private readonly dialog = inject(MatDialog);

  protected readonly canManage = this.session.can('subscription.manage');
  protected readonly canInvoices = this.session.can('invoices.view');
  protected readonly subscription = signal<Subscription | null>(null);
  protected readonly error = signal<unknown>(null);
  protected readonly busy = signal(false);
  protected readonly plan = signal('');
  protected readonly sites = signal(1);
  protected readonly paidPlans = computed(() => (this.subscription()?.plans ?? []).filter((plan) => !plan.is_trial));
  protected readonly lapsed = computed(() => ['expired', 'suspended', 'cancelled'].includes(this.subscription()?.current?.status ?? ''));
  // Without the right to see invoices the section is not shown and nothing is requested.
  protected readonly invoices = new ListStore<Invoice>((query) =>
    this.canInvoices ? this.api.list<Invoice>('invoices', query) : of({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }));

  protected readonly columns: Column<Invoice>[] = [
    { key: 'number', header: 'admin.subscription.invoiceNumber' },
    { key: 'issue', header: 'admin.subscription.issueDate', hideBelow: 640 },
    { key: 'due', header: 'admin.subscription.dueDate', hideBelow: 900 },
    { key: 'total', header: 'admin.subscription.total', align: 'end' },
    { key: 'paid', header: 'admin.subscription.paid', align: 'end', hideBelow: 900 },
    { key: 'status', header: 'fields.status' },
    { key: 'actions', header: 'common.actions' },
  ];

  constructor() {
    this.load();
  }

  protected planName(code: string | null): Subscription['plans'][number]['name'] | null {
    return this.subscription()?.plans.find((plan) => plan.code === code)?.name ?? null;
  }

  protected load(): void {
    this.error.set(null);
    this.api.get<Subscription>('subscription').subscribe({
      next: (subscription) => {
        this.subscription.set(subscription);
        this.plan.set(subscription.requested_plan ?? (subscription.current?.plan && !subscription.plans.find((plan) => plan.code === subscription.current?.plan)?.is_trial ? subscription.current.plan : ''));
        this.sites.set(subscription.requested_sites ?? Math.max(1, subscription.active_sites));
      },
      error: (error: unknown) => this.error.set(error),
    });
  }

  protected async request(): Promise<void> {
    await this.run(() => firstValueFrom(this.api.post('subscription/change-request', { plan: this.plan(), sites: Number(this.sites()) })), 'admin.subscription.changeSent');
  }

  protected async cancel(): Promise<void> {
    const reason = await this.confirm.ask({
      title: this.toast.text('admin.subscription.cancelConfirm'), message: this.toast.text('admin.subscription.cancelHint'),
      reasonLabel: this.toast.text('admin.subscription.cancelReason'), confirmLabel: this.toast.text('admin.subscription.cancel'), danger: true,
    });
    if (typeof reason === 'string') {
      await this.run(() => firstValueFrom(this.api.post('subscription/cancel', { reason })), 'admin.subscription.cancelScheduled');
    }
  }

  protected async resume(): Promise<void> {
    await this.run(() => firstValueFrom(this.api.post('subscription/resume')), 'common.saved');
  }

  protected async pdf(invoice: Invoice): Promise<void> {
    try {
      openBlob(await firstValueFrom(this.api.blob(`invoices/${invoice.ulid}/pdf`)));
    } catch (error) {
      this.toast.error(error);
    }
  }

  protected async declare(invoice: Invoice): Promise<void> {
    if (await firstValueFrom(this.dialog.open(DeclarePaymentDialog, { data: { invoice } }).afterClosed())) {
      this.invoices.reload();
    }
  }

  private async run(action: () => Promise<unknown>, done: string): Promise<void> {
    this.busy.set(true);
    try {
      await action();
      this.toast.success(done);
      this.load();
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

/** Company details. What identifies the contracting party (legal name, tax ID, country) is shown but not editable. */
@Component({
  imports: [ReactiveFormsModule, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, TranslocoDirective, PageHeaderComponent, StateComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('admin.company.title')" [subtitle]="t('admin.company.subtitle')" />
      <dw-state [loading]="!company() && !error()" [error]="error()" (retry)="load()">
        @if (company(); as c) {
          <div class="dw-split">
            <form class="dw-panel" [formGroup]="form" (ngSubmit)="save()">
              <div class="dw-panel-body">
                <h2 class="dw-h2">{{ t('admin.company.identity') }}</h2>
                <dl class="dw-facts">
                  <dt>{{ t('fields.legalName') }}</dt><dd>{{ c.legal_name }}</dd>
                  <dt>{{ t('fields.taxId') }}</dt><dd class="dw-mono">{{ c.tax_id ?? '—' }}</dd>
                  <dt>{{ t('fields.country') }}</dt><dd>{{ c.country }} · {{ c.currency_code }}</dd>
                </dl>
                <p class="dw-faint">{{ t('admin.company.identityHint') }}</p>

                <div class="dw-form">
                  <mat-form-field><mat-label>{{ t('fields.tradeName') }}</mat-label><input matInput formControlName="trade_name" /></mat-form-field>
                  <mat-form-field><mat-label>{{ t('fields.vatNumber') }}</mat-label><input matInput formControlName="vat_number" /></mat-form-field>
                  <mat-form-field class="dw-span-2"><mat-label>{{ t('fields.address') }}</mat-label><input matInput formControlName="address_line1" /></mat-form-field>
                  <mat-form-field><mat-label>{{ t('fields.postalCode') }}</mat-label><input matInput formControlName="postal_code" /></mat-form-field>
                  <mat-form-field><mat-label>{{ t('fields.city') }}</mat-label><input matInput formControlName="city" /></mat-form-field>
                  <mat-form-field><mat-label>{{ t('fields.region') }}</mat-label><input matInput formControlName="region" /></mat-form-field>
                  <mat-form-field><mat-label>{{ t('fields.phone') }}</mat-label><input matInput type="tel" formControlName="phone" /></mat-form-field>
                  <mat-form-field>
                    <mat-label>{{ t('fields.email') }}</mat-label><input matInput type="email" formControlName="email" />
                    @if (form.controls.email.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
                  </mat-form-field>
                  <mat-form-field>
                    <mat-label>{{ t('admin.company.billingEmail') }}</mat-label><input matInput type="email" formControlName="billing_email" />
                    @if (form.controls.billing_email.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
                  </mat-form-field>
                  @if (industrial) {
                    <mat-form-field class="dw-span-2">
                      <mat-label>{{ t('admin.company.varianceThreshold') }}</mat-label>
                      <input matInput inputmode="decimal" formControlName="weight_variance_threshold_pct" />
                      <mat-hint>{{ t('admin.company.varianceHint') }}</mat-hint>
                      @if (form.controls.weight_variance_threshold_pct.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
                    </mat-form-field>
                  }
                </div>
                <div class="dw-form-actions"><button mat-flat-button type="submit" [disabled]="busy()">{{ t('common.save') }}</button></div>
              </div>
            </form>

            <section class="dw-panel">
              <div class="dw-panel-body dw-stack-sm">
                <h2 class="dw-h2">{{ t('admin.company.logo') }}</h2>
                @if (logo(); as url) { <img [src]="url" alt="" class="logo" /> } @else { <div class="logo empty" aria-hidden="true"><mat-icon>image</mat-icon></div> }
                <p class="dw-faint">{{ t('admin.company.logoHint') }}</p>
                <input #file type="file" accept="image/png,image/jpeg" hidden (change)="upload(file)" />
                <button mat-stroked-button type="button" [disabled]="busy()" (click)="file.click()"><mat-icon>upload</mat-icon>{{ t('admin.company.uploadLogo') }}</button>
              </div>
            </section>
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .dw-form { margin-block-start: var(--dw-4); }
    .logo { max-width: 100%; max-height: 120px; object-fit: contain; border: 1px solid var(--dw-line-soft); border-radius: var(--dw-radius); padding: var(--dw-2); background: #fff; }
    .logo.empty { display: grid; place-items: center; height: 96px; color: var(--dw-ink-3); }
  `,
})
export class CompanyPage {
  private readonly api = inject(Api);
  private readonly http = inject(HttpClient);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);

  protected readonly industrial = this.session.audience() === 'industrial';
  protected readonly company = signal<Company | null>(null);
  protected readonly error = signal<unknown>(null);
  protected readonly busy = signal(false);
  protected readonly logo = signal<string | null>(null);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    trade_name: [''], vat_number: [''], address_line1: [''], postal_code: [''], city: [''], region: [''], phone: [''],
    email: ['', Validators.email], billing_email: ['', Validators.email], weight_variance_threshold_pct: ['', Validators.pattern(/^\d{1,3}([.,]\d{1,2})?$/)],
  });

  constructor() {
    this.load();
    inject(DestroyRef).onDestroy(() => this.releaseLogo());
  }

  protected load(): void {
    this.error.set(null);
    this.api.get<Company>('company').subscribe({
      next: (company) => this.show(company),
      error: (error: unknown) => this.error.set(error),
    });
  }

  protected async save(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    try {
      const value = this.form.getRawValue();
      const body = Object.fromEntries(Object.entries(value).map(([key, text]) => [key, text.trim() === '' ? null : text.trim()]));
      body['weight_variance_threshold_pct'] = this.industrial && value.weight_variance_threshold_pct ? value.weight_variance_threshold_pct.replace(',', '.') : null;
      this.show(await firstValueFrom(this.api.put<Company>('company', body)));
      await this.session.reload();
      this.toast.success('common.saved');
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }

  protected async upload(input: HTMLInputElement): Promise<void> {
    const file = input.files?.[0];
    input.value = '';
    if (!file) {
      return;
    }
    const body = new FormData();
    body.append('file', file);
    this.busy.set(true);
    try {
      this.show((await firstValueFrom(this.http.post<{ data: Company }>('/api/v1/company/logo', body))).data);
      this.toast.success('common.saved');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  private show(company: Company): void {
    this.company.set(company);
    this.form.reset({
      trade_name: company.trade_name ?? '', vat_number: company.vat_number ?? '', address_line1: company.address_line1 ?? '', postal_code: company.postal_code ?? '',
      city: company.city ?? '', region: company.region ?? '', phone: company.phone ?? '', email: company.email ?? '', billing_email: company.billing_email ?? '',
      weight_variance_threshold_pct: company.weight_variance_threshold_pct ?? '',
    });
    if (company.has_logo) {
      this.api.blob('company/logo', { v: Date.now() }).subscribe({
        next: (blob) => {
          this.releaseLogo();
          this.logo.set(URL.createObjectURL(blob));
        },
      });
    }
  }

  private releaseLogo(): void {
    const url = this.logo();
    if (url) {
      URL.revokeObjectURL(url);
    }
  }
}
