import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';

import { Api, openBlob } from '../../core/api/api';
import { Company, CompanyStatus, Localized, ProviderAccreditation } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { CountryPipe, DatePipe, LocalizedPipe, MoneyPipe, TermPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { StateComponent } from '../../ui/state';
import { CheckboxComponent } from '../../ui/checkbox';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';
import { FilterBarComponent } from '../../ui/filter-bar';
import { PlatformPlan } from './back-office';

const FILTERS = `
  .how { margin-block-end: var(--dw-3); }
  .filters dw-field { width: 220px; }
`;

interface CompanyFilter {
  plan_requested?: boolean | null;
  status?: CompanyStatus | null;
  type?: string | null;
  search?: string;
}

/** M0-06: registrations to review first, then every account. */
@Component({
  imports: [TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, SearchComponent, DatePipe, IconComponent, ...SELECT, CheckboxComponent, FilterBarComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('platform.companies.title')" [subtitle]="t('platform.companies.subtitle')" />
      <p class="dw-notice info how"><dw-icon>info</dw-icon><span>{{ t('platform.companies.selfRegistration') }}</span></p>
      <div class="dw-panel">
        <dw-filter-bar [active]="list.activeCount()" (cleared)="list.resetFilter()">
          <dw-search [placeholder]="t('fields.legalName')" [query]="list.filter().search ?? ''" (queryChange)="list.setFilter({ search: $event })" />
          <dw-select [placeholder]="t('fields.status')" [value]="list.filter().status ?? ''" (valueChange)="list.setFilter({ status: $any($event) || null })">
            <dw-option value="" [label]="t('common.all')" />
            @for (status of statuses; track status) { <dw-option [value]="status" [label]="t('status.company.' + status)" /> }
          </dw-select>
          <dw-select [placeholder]="t('platform.companies.type')" [value]="list.filter().type ?? ''" (valueChange)="list.setFilter({ type: $event || null })">
            <dw-option value="" [label]="t('common.all')" />
            <dw-option value="industrial" [label]="t('platform.companies.types.industrial')" />
            <dw-option value="provider" [label]="t('platform.companies.types.provider')" />
          </dw-select>
          <dw-checkbox [checked]="list.filter().plan_requested === true" (checkedChange)="list.setFilter({ plan_requested: $event ? true : null })">{{ t('platform.companies.planRequested') }}</dw-checkbox>
        </dw-filter-bar>
        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1"
          [pageSize]="list.perPage()" [clickable]="true" emptyIcon="domain" [emptyTitle]="t('platform.companies.emptyTitle')"
          (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="open($event)" (retry)="list.reload()">
          <ng-template dwCell="name" let-c><strong>{{ c.legal_name }}</strong><div class="dw-faint dw-mono">{{ c.tax_id }}</div></ng-template>
          <ng-template dwCell="type" let-c>{{ t('platform.companies.types.' + c.company_type) }}</ng-template>
          <ng-template dwCell="submitted" let-c>{{ c.submitted_at | dwDate: 'datetime' }}</ng-template>
          <ng-template dwCell="status" let-c><dw-status kind="company" [value]="c.status" /></ng-template>
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: FILTERS,
})
export class PlatformCompaniesPage implements OnInit {
  private readonly api = inject(Api);
  private readonly router = inject(Router);

  /** Query parameter: opens the list on one status (link of the overview). */
  readonly status = input<CompanyStatus>();
  /** Query parameter set by the overview: only clients waiting for a plan to be activated. */
  readonly requests = input<string>();

  protected readonly statuses: CompanyStatus[] = ['pending_approval', 'pending_verification', 'active', 'suspended', 'rejected', 'closed'];
  protected readonly list = new ListStore<Company, CompanyFilter>((query) => this.api.list<Company>('platform/companies', query));
  protected readonly columns: Column<Company>[] = [
    { key: 'name', header: 'fields.legalName' },
    { key: 'type', header: 'platform.companies.type' },
    { key: 'city', header: 'fields.city', value: (c) => c.city, hideBelow: 900 },
    { key: 'submitted', header: 'platform.companies.submittedAt', hideBelow: 640 },
    { key: 'status', header: 'fields.status' },
  ];

  ngOnInit(): void {
    const status = this.status();
    if (status && this.statuses.includes(status)) {
      this.list.setFilter({ status });
    }
    if (this.requests()) {
      this.list.setFilter({ plan_requested: true });
    }
  }

  protected open(company: Company): void {
    void this.router.navigate(['/platform/companies', company.ulid]);
  }
}

interface CompanyDetail extends Company {
  rejection_reason: string | null;
  suspension_reason: string | null;
  requested_plan: string | null;
  requested_sites: number | null;
  country_name: Localized | null;
  activity_names: Localized[];
  sites: { code: string; name: string; city: string | null; is_active: boolean }[];
  users: { name: string; email: string; status: string; last_login_at: string | null; roles: string[] }[];
  invoices: { ulid: string; invoice_number: string; status: string; issue_date: string; due_date: string; currency_code: string; total_amount: string; amount_paid: string }[];
  activity: { event: string; occurred_at: string; actor: string | null; reason: string | null }[];
  subscription: {
    plan: string; name: Localized; status: string; billing_interval: 'monthly' | 'yearly' | null; sites_quantity: number | null; currency_code: string; unit_amount: string | null;
    trial_ends_at: string | null; current_period_end: string | null; cancel_at_period_end: boolean;
  } | null;
  contact: { name: string; email: string; phone: string | null; email_verified: boolean } | null;
  counts: { sites: number; users: number; lots: number };
  accreditations: { ulid: string; type: string; name: Localized; reference_number: string; valid_from: string; expires_on: string; review_status: string; rejection_reason: string | null }[];
}


/** One registration or account: everything needed to decide, and the decision itself. */
@Component({
  imports: [
    CountryPipe, TermPipe, ReactiveFormsModule, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, DatePipe, LocalizedPipe, MoneyPipe, IconComponent, ButtonDirective, InputText, ...FIELD, ...SELECT,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="!company() && !error()" [error]="error()" (retry)="load()">
        @if (company(); as c) {
          <dw-page-header [title]="c.legal_name" [subtitle]="t('platform.companies.types.' + c.company_type)" back="/platform/companies" [backLabel]="t('platform.companies.title')">
            <dw-status dwTitleBadge kind="company" [value]="c.status" />
            @if (canReview && c.status === 'pending_approval') {
              <button pButton text type="button" [disabled]="busy()" (click)="decide('reject')">{{ t('platform.companies.reject') }}</button>
              <button pButton type="button" [disabled]="busy()" (click)="decide('approve')"><dw-icon>check</dw-icon>{{ t('platform.companies.approve') }}</button>
            }
            @if (canSuspend && c.status === 'active') { <button pButton severity="secondary" outlined type="button" [disabled]="busy()" (click)="decide('suspend')">{{ t('platform.companies.suspend') }}</button> }
            @if (canSuspend && c.status === 'suspended') { <button pButton type="button" [disabled]="busy()" (click)="decide('reactivate')">{{ t('platform.companies.reactivate') }}</button> }
          </dw-page-header>

          <div class="dw-split">
            <div class="dw-stack">
              <section class="dw-panel">
                <div class="dw-panel-body">
                  <dl class="dw-facts">
                    <dt>{{ t('fields.taxId') }}</dt><dd class="dw-mono">{{ c.tax_id ?? '—' }}</dd>
                    <dt>{{ t('fields.address') }}</dt><dd>{{ c.address_line1 }} {{ c.postal_code }} {{ c.city }} — {{ c.country | dwCountry }}</dd>
                    <dt>{{ t('fields.phone') }}</dt><dd>{{ c.phone ?? '—' }}</dd>
                    <dt>{{ t('platform.companies.submittedAt') }}</dt><dd>{{ c.submitted_at | dwDate: 'datetime' }}</dd>
                    <dt>{{ t('platform.companies.contact') }}</dt>
                    <dd>
                      @if (c.contact; as contact) {
                        {{ contact.name }} · {{ contact.email }} @if (contact.phone) { · {{ contact.phone }} }
                        <dw-status kind="alert" [value]="contact.email_verified ? 'ok' : 'warning'" [label]="t('platform.companies.emailVerified') + ' : ' + t(contact.email_verified ? 'common.yes' : 'common.no')" />
                      } @else { — }
                    </dd>
                    @if (c.activities.length > 0) { <dt>{{ t('auth.register.activities') }}</dt><dd>@for (activity of c.activity_names; track $index; let last = $last) { {{ activity | dwLocalized }}{{ last ? '' : ', ' }} }</dd> }
                    @if (c.requested_plan) { <dt>{{ t('platform.companies.requestedPlan') }}</dt><dd>{{ planName(c.requested_plan) }}@if (c.requested_sites) { · {{ t('platform.overview.sites', { count: c.requested_sites }) }} }</dd> }
                    <dt>{{ t('platform.companies.usage') }}</dt><dd>{{ t('platform.companies.usageDetail', c.counts) }}</dd>
                    @if (c.rejection_reason) { <dt>{{ t('fields.reason') }}</dt><dd>{{ c.rejection_reason }}</dd> }
                    @if (c.suspension_reason) { <dt>{{ t('fields.reason') }}</dt><dd>{{ c.suspension_reason }}</dd> }
                  </dl>
                </div>
              </section>

              @if (c.company_type === 'provider') {
                <section class="dw-panel">
                  <header class="dw-panel-head"><h2 class="dw-h2">{{ t('providers.accreditations') }}</h2></header>
                  <ul class="list">
                    @for (row of c.accreditations; track row.ulid) {
                      <li>
                        <div class="dw-spacer"><strong>{{ row.name | dwLocalized }}</strong>
                          <div class="dw-muted">{{ row.reference_number }} · {{ row.valid_from | dwDate }} → {{ row.expires_on | dwDate }}</div></div>
                        <dw-status kind="accreditation" [value]="row.review_status" />
                        <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="file(row.ulid)" [attr.aria-label]="t('providers.viewFile')" [title]="t('providers.viewFile')"><dw-icon>description</dw-icon></button>
                      </li>
                    } @empty { <li class="dw-faint">{{ t('common.none') }}</li> }
                  </ul>
                </section>
              }
              @if (c.company_type === 'industrial') {
                <section class="dw-panel">
                  <header class="dw-panel-head"><h2 class="dw-h2">{{ t('platform.companies.sites') }}</h2><span class="count">{{ c.sites.length }}</span></header>
                  @if (c.sites.length > 0) {
                    <table class="dw-table">
                      <tbody>
                        @for (site of c.sites; track site.code) {
                          <tr><td class="dw-mono">{{ site.code }}</td><td>{{ site.name }}</td><td>{{ site.city ?? '—' }}</td>
                            <td class="num"><dw-status kind="member" [value]="site.is_active ? 'active' : 'suspended'" [label]="t(site.is_active ? 'common.active' : 'platform.companies.inactiveSite')" [tone]="site.is_active ? 'success' : 'neutral'" /></td></tr>
                        }
                      </tbody>
                    </table>
                  } @else { <p class="none">{{ t('platform.companies.noSites') }}</p> }
                </section>
              }

              <section class="dw-panel">
                <header class="dw-panel-head"><h2 class="dw-h2">{{ t('platform.companies.users') }}</h2><span class="count">{{ c.users.length }}</span></header>
                @if (c.users.length > 0) {
                  <table class="dw-table">
                    <thead><tr><th>{{ t('fields.name') }}</th><th>{{ t('fields.role') }}</th><th>{{ t('platform.companies.lastLogin') }}</th><th></th></tr></thead>
                    <tbody>
                      @for (user of c.users; track user.email) {
                        <tr><td><strong>{{ user.name }}</strong><div class="dw-faint">{{ user.email }}</div></td><td>{{ user.roles.join(', ') || '—' }}</td>
                          <td>{{ user.last_login_at ? (user.last_login_at | dwDate: 'datetime') : t('platform.companies.neverLoggedIn') }}</td>
                          <td class="num"><dw-status kind="member" [value]="user.status" /></td></tr>
                      }
                    </tbody>
                  </table>
                } @else { <p class="none">{{ t('platform.companies.noUsers') }}</p> }
              </section>

              @if (c.company_type === 'industrial') {
                <section class="dw-panel">
                  <header class="dw-panel-head"><h2 class="dw-h2">{{ t('platform.companies.invoices') }}</h2><span class="count">{{ c.invoices.length }}</span></header>
                  @if (c.invoices.length > 0) {
                    <table class="dw-table">
                      <thead><tr><th>{{ t('admin.subscription.invoiceNumber') }}</th><th>{{ t('admin.subscription.issueDate') }}</th><th>{{ t('admin.subscription.dueDate') }}</th>
                        <th class="num">{{ t('admin.subscription.total') }}</th><th class="num">{{ t('admin.subscription.paid') }}</th><th>{{ t('fields.status') }}</th></tr></thead>
                      <tbody>
                        @for (invoice of c.invoices; track invoice.ulid) {
                          <tr><td class="dw-mono">{{ invoice.invoice_number }}</td><td>{{ invoice.issue_date | dwDate }}</td><td>{{ invoice.due_date | dwDate }}</td>
                            <td class="num">{{ invoice.total_amount | dwMoney: invoice.currency_code }}</td><td class="num">{{ invoice.amount_paid | dwMoney: invoice.currency_code }}</td>
                            <td><dw-status kind="invoice" [value]="invoice.status" /></td></tr>
                        }
                      </tbody>
                    </table>
                  } @else { <p class="none">{{ t('platform.companies.noInvoices') }}</p> }
                </section>
              }

              <section class="dw-panel">
                <header class="dw-panel-head"><h2 class="dw-h2">{{ t('platform.companies.history') }}</h2></header>
                <ul class="list">
                  @for (entry of c.activity; track $index) {
                    <li>
                      <div class="dw-spacer"><strong>{{ entry.event | dwTerm: 'terms.events' }}</strong>@if (entry.reason) { <div class="dw-muted">{{ entry.reason }}</div> }</div>
                      <span class="dw-faint">{{ entry.actor ?? t('admin.audit.system') }} · {{ entry.occurred_at | dwDate: 'datetime' }}</span>
                    </li>
                  } @empty { <li class="dw-faint">{{ t('platform.companies.noHistory') }}</li> }
                </ul>
              </section>
            </div>

            @if (c.company_type === 'industrial' && (c.subscription || (canBill && c.status === 'active'))) {
            <div class="dw-stack">
              <section class="dw-panel">
                <header class="dw-panel-head"><h2 class="dw-h2">{{ t('platform.companies.subscription') }}</h2>
                  @if (c.subscription; as sub) { <dw-status kind="subscription" [value]="sub.status" /> }
                </header>
                <div class="dw-panel-body">
                  @if (c.subscription; as sub) {
                    <dl class="dw-facts">
                      <dt>{{ t('platform.companies.currentPlan') }}</dt><dd><strong>{{ sub.name | dwLocalized }}</strong></dd>
                      @if (sub.unit_amount) {
                        <dt>{{ t('platform.companies.price') }}</dt>
                        <dd>{{ sub.unit_amount | dwMoney: sub.currency_code }}@if (sub.billing_interval) { / {{ t('admin.subscription.intervals.' + sub.billing_interval) }} }</dd>
                      }
                      @if (sub.sites_quantity) { <dt>{{ t('admin.subscription.sitesWanted') }}</dt><dd>{{ sub.sites_quantity }}</dd> }
                      @if (sub.status === 'trialing' && sub.trial_ends_at) { <dt>{{ t('platform.companies.trialEnd') }}</dt><dd>{{ sub.trial_ends_at | dwDate }}</dd> }
                      @if (sub.current_period_end) { <dt>{{ t('platform.companies.periodEnd') }}</dt><dd>{{ sub.current_period_end | dwDate }}</dd> }
                    </dl>
                    @if (sub.cancel_at_period_end) { <p class="dw-notice warning cancelling"><dw-icon>event_busy</dw-icon><span>{{ t('platform.companies.cancelling') }}</span></p> }
                  } @else { <p class="dw-faint">{{ t('platform.companies.noSubscription') }}</p> }
                </div>
              </section>
            @if (canBill && c.status === 'active') {
              <form class="dw-panel" [formGroup]="form" (ngSubmit)="activate()">
                <header class="dw-panel-head"><h2 class="dw-h2">{{ t('platform.companies.activatePlan') }}</h2></header>
                <div class="dw-panel-body plan">
                  <dw-field [label]="t('auth.register.plan')">
                    <dw-select formControlName="plan">@for (plan of plans(); track plan.code) { <dw-option [value]="plan.code" [label]="plan.name | dwLocalized" /> }</dw-select>
                  </dw-field>
                  <dw-field [label]="t('platform.companies.interval')">
                    <dw-select formControlName="billing_interval">
                      <dw-option value="monthly" [label]="t('admin.subscription.intervals.monthly')" /><dw-option value="yearly" [label]="t('admin.subscription.intervals.yearly')" />
                    </dw-select>
                  </dw-field>
                  <dw-field [label]="t('admin.subscription.sitesWanted')"><input pInputText type="number" min="1" max="500" formControlName="sites_quantity" /></dw-field>
                  <dw-field>
                    <span dwLabel>{{ t('platform.companies.unitAmount') }} ({{ c.currency_code }})</span><input pInputText inputmode="decimal" formControlName="unit_amount" />
                    <small dwHint>{{ reference() ? t('platform.companies.referencePrice', { amount: (reference() | dwMoney: c.currency_code) }) : t('platform.companies.noReferencePrice') }}</small>
                  </dw-field>
                  <div class="dw-form-actions"><button pButton type="submit" [disabled]="busy()">{{ t('platform.companies.activatePlan') }}</button></div>
                </div>
              </form>
            }
            </div>
            }
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .list { list-style: none; margin: 0; padding: 0; }
    .list li { display: flex; align-items: center; gap: var(--dw-3); padding: var(--dw-3) var(--dw-4); border-block-start: 1px solid var(--dw-line-soft); }
    .plan { display: grid; gap: var(--dw-3); }
    .none { padding: var(--dw-3) var(--dw-4); color: var(--dw-ink-3); }
    .count { display: inline-grid; place-items: center; min-width: 20px; height: 18px; padding-inline: 6px; border-radius: 9px; background: var(--dw-neutral-soft); color: var(--dw-ink-2); font-size: 11px; font-weight: 700; }
    .list li:first-child { border-block-start: 0; }
    .cancelling { margin-block-start: var(--dw-3); }
  `,
})
export class PlatformCompanyPage implements OnInit {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);

  readonly ulid = input.required<string>();
  protected readonly canReview = this.session.canPlatform('platform.companies.review');
  protected readonly canSuspend = this.session.canPlatform('platform.companies.suspend');
  protected readonly canBill = this.session.canPlatform('platform.invoices.manage');
  protected readonly company = signal<CompanyDetail | null>(null);
  protected readonly error = signal<unknown>(null);
  protected readonly busy = signal(false);
  protected readonly plans = signal<PlatformPlan[]>([]);
  /** Reference price of the chosen plan and interval, in the currency of the company; proposed, never imposed. */
  protected readonly reference = signal<string | null>(null);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    plan: ['', Validators.required], billing_interval: ['monthly', Validators.required],
    sites_quantity: [1, [Validators.required, Validators.min(1), Validators.max(500)]], unit_amount: ['', [Validators.required, Validators.pattern(/^\d+([.,]\d{1,3})?$/)]],
  });

  ngOnInit(): void {
    this.load();
    this.api.get<PlatformPlan[]>('platform/plans').subscribe({
      next: (plans) => {
        this.plans.set(plans.filter((plan) => !plan.is_trial && plan.is_active));
        this.propose();
      },
      error: () => undefined,
    });
    this.form.controls.plan.valueChanges.subscribe(() => this.propose());
    this.form.controls.billing_interval.valueChanges.subscribe(() => this.propose());
  }

  protected load(): void {
    this.error.set(null);
    this.api.get<CompanyDetail>(`platform/companies/${this.ulid()}`).subscribe({
      next: (company) => {
        this.company.set(company);
        this.form.patchValue({ plan: company.requested_plan && company.requested_plan !== 'trial' ? company.requested_plan : '', sites_quantity: Math.max(1, company.requested_sites ?? company.counts.sites) });
        this.propose();
      },
      error: (error: unknown) => this.error.set(error),
    });
  }

  protected planName(code: string): string {
    return this.plans().find((plan) => plan.code === code)?.name['fr'] ?? code;
  }

  /** Fills the price with the reference price as long as nobody typed one. */
  private propose(): void {
    const { plan, billing_interval } = this.form.getRawValue();
    const currency = this.company()?.currency_code;
    const price = this.plans().find((item) => item.code === plan)?.prices.find((item) => item.billing_interval === billing_interval && item.currency_code === currency)?.unit_amount ?? null;
    this.reference.set(price);
    if (price !== null && !this.form.controls.unit_amount.dirty) {
      this.form.controls.unit_amount.setValue(String(Number(price)));
    }
  }

  protected async decide(action: 'approve' | 'reject' | 'suspend' | 'reactivate'): Promise<void> {
    const name = this.company()?.legal_name ?? '';
    const needsReason = action === 'reject' || action === 'suspend';
    const answer = await this.confirm.ask({
      title: `${this.toast.text('platform.companies.' + action)} — ${name}`, danger: needsReason,
      reasonLabel: needsReason ? this.toast.text(`platform.companies.${action}Reason`) : undefined,
    });
    if (answer === null) {
      return;
    }
    const done = { approve: 'approved', reject: 'rejected', suspend: 'suspended', reactivate: 'reactivated' }[action];
    await this.run(() => firstValueFrom(this.api.post(`platform/companies/${this.ulid()}/${action}`, needsReason ? { reason: answer } : {})), `platform.companies.${done}`);
  }

  protected async activate(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    const value = this.form.getRawValue();
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.post(`platform/companies/${this.ulid()}/subscription`, { ...value, unit_amount: value.unit_amount.replace(',', '.') }));
      this.toast.success('platform.companies.planActivated');
      this.load();
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }

  protected async file(accreditation: string): Promise<void> {
    try {
      openBlob(await firstValueFrom(this.api.blob(`platform/accreditations/${accreditation}/file`)));
    } catch (error) {
      this.toast.error(error);
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

/** Loading, error and reload shared by the two review queues below. */
abstract class ReviewQueue<T> {
  protected readonly api = inject(Api);
  protected readonly toast = inject(Toast);
  protected readonly confirm = inject(Confirm);

  protected readonly rows = signal<T[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly busy = signal(false);
  protected readonly status = signal('pending');
  protected abstract readonly path: string;
  protected abstract readonly filterKey: string;

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get<T[]>(this.path, { filter: { [this.filterKey]: this.status() } }).subscribe({
      next: (rows) => {
        this.rows.set(rows);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected setStatus(status: string): void {
    this.status.set(status);
    this.load();
  }

  protected async review(path: string, decision: string, reasonLabel: string | null, title: string, done: string): Promise<void> {
    const answer = await this.confirm.ask({ title, reasonLabel: reasonLabel ?? undefined, danger: reasonLabel !== null });
    if (answer === null) {
      return;
    }
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.post(path, { decision, reason: typeof answer === 'string' ? answer : null }));
      this.toast.success(done);
      this.load();
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

type ReviewAccreditation = ProviderAccreditation & { company: string; legal_name: string };

/** M0-05 / M0-06: the accreditation document is read before any decision. */
@Component({
  imports: [TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, DatePipe, LocalizedPipe, IconComponent, ButtonDirective, ...SELECT, FilterBarComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('platform.accreditations.title')" [subtitle]="t('platform.accreditations.subtitle')" />
      <div class="dw-panel">
        <dw-filter-bar>
          <dw-select [placeholder]="t('fields.status')" [value]="status()" (valueChange)="setStatus($event)">
            @for (item of statuses; track item) { <dw-option [value]="item" [label]="t('status.accreditation.' + item)" /> }
          </dw-select>
        </dw-filter-bar>
        <dw-data-table [columns]="columns" [rows]="rows()" [loading]="loading()" [error]="error()" emptyIcon="verified" [emptyTitle]="t('platform.accreditations.emptyTitle')" (retry)="load()">
          <ng-template dwCell="provider" let-a><strong>{{ a.legal_name }}</strong></ng-template>
          <ng-template dwCell="type" let-a>{{ a.name | dwLocalized }}<div class="dw-faint dw-mono">{{ a.reference_number }}</div></ng-template>
          <ng-template dwCell="validity" let-a>{{ a.valid_from | dwDate }} → {{ a.expires_on | dwDate }} @if (a.expired) { <dw-status kind="accreditation" value="expired" /> }</ng-template>
          <ng-template dwCell="actions" let-a>
            <div class="dw-row-tight">
              <button pButton severity="secondary" outlined type="button" (click)="file(a)"><dw-icon>description</dw-icon>{{ t('providers.viewFile') }}</button>
              @if (a.review_status === 'pending') {
                <button pButton text type="button" [disabled]="busy()" (click)="decide(a, 'rejected')">{{ t('platform.accreditations.reject') }}</button>
                <button pButton type="button" [disabled]="busy()" (click)="decide(a, 'approved')">{{ t('platform.accreditations.approve') }}</button>
              }
            </div>
          </ng-template>
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: FILTERS,
})
export class PlatformAccreditationsPage extends ReviewQueue<ReviewAccreditation> {
  protected readonly path = 'platform/accreditations';
  protected readonly filterKey = 'review_status';
  protected readonly statuses = ['pending', 'approved', 'rejected', 'revoked'];
  protected readonly columns: Column<ReviewAccreditation>[] = [
    { key: 'provider', header: 'pickups.provider' },
    { key: 'type', header: 'providers.accreditationType' },
    { key: 'authority', header: 'providers.authority', value: (a) => a.issuing_authority, hideBelow: 1200 },
    { key: 'validity', header: 'providers.expiresOn', hideBelow: 640 },
    { key: 'actions', header: 'common.actions' },
  ];

  constructor() {
    super();
    this.load();
  }

  protected async decide(accreditation: ReviewAccreditation, decision: 'approved' | 'rejected'): Promise<void> {
    await this.review(`platform/accreditations/${accreditation.ulid}/review`, decision, decision === 'rejected' ? this.toast.text('platform.accreditations.rejectReason') : null,
      `${this.toast.text(decision === 'approved' ? 'platform.accreditations.approve' : 'platform.accreditations.reject')} — ${accreditation.legal_name} (${accreditation.reference_number})`,
      'platform.accreditations.reviewed');
  }

  protected async file(accreditation: ReviewAccreditation): Promise<void> {
    try {
      openBlob(await firstValueFrom(this.api.blob(`platform/accreditations/${accreditation.ulid}/file`)));
    } catch (error) {
      this.toast.error(error);
    }
  }
}

interface DeclaredPayment {
  ulid: string;
  status: string;
  amount: string;
  currency_code: string;
  bank_reference: string;
  declared_paid_on: string;
  rejection_reason: string | null;
  invoice_number: string;
  total_amount: string;
  amount_paid: string;
  company: string;
  legal_name: string;
}

/** M0-04: bank transfers declared by clients, to match against the bank statement. */
@Component({
  imports: [TranslocoDirective, ...TABLE, PageHeaderComponent, DatePipe, MoneyPipe, ButtonDirective, ...SELECT, FilterBarComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('platform.payments.title')" [subtitle]="t('platform.payments.subtitle')" />
      <div class="dw-panel">
        <dw-filter-bar>
          <dw-select [placeholder]="t('fields.status')" [value]="status()" (valueChange)="setStatus($event)">
            <dw-option value="pending" [label]="t('status.accreditation.pending')" />
            <dw-option value="validated" [label]="t('status.accreditation.approved')" />
            <dw-option value="rejected" [label]="t('status.accreditation.rejected')" />
          </dw-select>
        </dw-filter-bar>
        <dw-data-table [columns]="columns" [rows]="rows()" [loading]="loading()" [error]="error()" emptyIcon="account_balance" [emptyTitle]="t('platform.payments.emptyTitle')" (retry)="load()">
          <ng-template dwCell="company" let-p><strong>{{ p.legal_name }}</strong><div class="dw-faint dw-mono">{{ p.invoice_number }}</div></ng-template>
          <ng-template dwCell="amount" let-p>{{ p.amount | dwMoney: p.currency_code }}<div class="dw-faint">/ {{ p.total_amount | dwMoney: p.currency_code }}</div></ng-template>
          <ng-template dwCell="reference" let-p><span class="dw-mono">{{ p.bank_reference }}</span></ng-template>
          <ng-template dwCell="date" let-p>{{ p.declared_paid_on | dwDate }}</ng-template>
          <ng-template dwCell="actions" let-p>
            @if (p.status === 'pending') {
              <div class="dw-row-tight">
                <button pButton text type="button" [disabled]="busy()" (click)="decide(p, 'rejected')">{{ t('platform.payments.reject') }}</button>
                <button pButton type="button" [disabled]="busy()" (click)="decide(p, 'validated')">{{ t('platform.payments.validate') }}</button>
              </div>
            } @else { {{ p.rejection_reason ?? '' }} }
          </ng-template>
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: FILTERS,
})
export class PlatformPaymentsPage extends ReviewQueue<DeclaredPayment> {
  protected readonly path = 'platform/payments';
  protected readonly filterKey = 'status';
  protected readonly columns: Column<DeclaredPayment>[] = [
    { key: 'company', header: 'shell.company' },
    { key: 'amount', header: 'admin.subscription.amount', align: 'end' },
    { key: 'reference', header: 'platform.payments.reference', hideBelow: 640 },
    { key: 'date', header: 'admin.subscription.paidOn', hideBelow: 900 },
    { key: 'actions', header: 'common.actions' },
  ];

  constructor() {
    super();
    this.load();
  }

  protected async decide(payment: DeclaredPayment, decision: 'validated' | 'rejected'): Promise<void> {
    await this.review(`platform/payments/${payment.ulid}/review`, decision, decision === 'rejected' ? this.toast.text('platform.payments.rejectReason') : null,
      `${this.toast.text(decision === 'validated' ? 'platform.payments.validate' : 'platform.payments.reject')} — ${payment.legal_name} (${payment.invoice_number})`, 'platform.payments.reviewed');
  }
}

interface CatalogItem {
  code: string;
  name: Localized;
  waste_family: string | null;
  regulatory_code: string | null;
  is_hazardous: boolean;
  treatment_channel: string | null;
  is_active: boolean;
  used_by: number;
}

interface CatalogPayload {
  data: CatalogItem[];
  meta: {
    waste_families: { code: string; name: Localized }[];
    treatment_channels: { code: string; name: Localized }[];
    regulatory_waste_codes: { code: string; label: Localized; is_hazardous: boolean }[];
  };
}

/** M1-02: the reference catalogue every client starts from. An item in use is deactivated, never removed. */
@Component({
  imports: [
    ReactiveFormsModule, TranslocoDirective, SearchComponent, ...TABLE, PageHeaderComponent, StatusComponent, LocalizedPipe, IconComponent, ButtonDirective, InputText, ...FIELD, ...SELECT, CheckboxComponent, FilterBarComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('platform.catalog.title')" [subtitle]="t('platform.catalog.subtitle')">
        @if (!editing()) { <button pButton type="button" (click)="edit(null)"><dw-icon>add</dw-icon>{{ t('platform.catalog.new') }}</button> }
      </dw-page-header>
      <div class="dw-stack">
        @if (editing()) {
          <form class="dw-panel" [formGroup]="form" (ngSubmit)="save()">
            <div class="dw-panel-body">
              <div class="dw-form">
                <dw-field [label]="t('fields.code')">
                  <input pInputText formControlName="code" maxlength="40" />
                </dw-field>
                <dw-field [label]="t('wasteTypes.family')">
                  <dw-select formControlName="waste_family">@for (family of meta()?.waste_families ?? []; track family.code) { <dw-option [value]="family.code" [label]="family.name | dwLocalized" /> }</dw-select>
                </dw-field>
                <dw-field [label]="t('platform.catalog.nameFr')"><input pInputText formControlName="name_fr" maxlength="150" /></dw-field>
                <dw-field [label]="t('platform.catalog.nameEn')"><input pInputText formControlName="name_en" maxlength="150" /></dw-field>
                <dw-field [label]="t('lots.regulatoryCode')">
                  <dw-select formControlName="regulatory_code">
                    <dw-option value="" label="—" />
                    @for (code of meta()?.regulatory_waste_codes ?? []; track code.code) { <dw-option [value]="code.code" [label]="code.code + ' — ' + (code.label | dwLocalized)" /> }
                  </dw-select>
                </dw-field>
                <dw-field [label]="t('wasteTypes.channel')">
                  <dw-select formControlName="treatment_channel">
                    <dw-option value="" label="—" />
                    @for (channel of meta()?.treatment_channels ?? []; track channel.code) { <dw-option [value]="channel.code" [label]="channel.name | dwLocalized" /> }
                  </dw-select>
                </dw-field>
                <dw-checkbox formControlName="is_hazardous">{{ t('common.hazardous') }}</dw-checkbox>
                <dw-checkbox formControlName="is_active">{{ t('common.active') }}</dw-checkbox>
              </div>
              <div class="dw-form-actions">
                <button pButton text type="button" (click)="editing.set(false)">{{ t('common.cancel') }}</button>
                <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
              </div>
            </div>
          </form>
        }
        <div class="dw-panel">
          <dw-filter-bar [active]="(query() ? 1 : 0) + (family() ? 1 : 0) + (hazardous() ? 1 : 0)" (cleared)="query.set(''); family.set(''); hazardous.set(false)">
            <dw-search [placeholder]="t('platform.catalog.search')" [query]="query()" (queryChange)="query.set($event)" />
            <dw-select class="wide" [placeholder]="t('wasteTypes.family')" [value]="family()" (valueChange)="family.set($event)">
              <dw-option value="" [label]="t('common.all')" />
              @for (item of meta()?.waste_families ?? []; track item.code) { <dw-option [value]="item.code" [label]="item.name | dwLocalized" /> }
            </dw-select>
            <dw-checkbox [checked]="hazardous()" (checkedChange)="hazardous.set($event)">{{ t('lots.hazardousOnly') }}</dw-checkbox>
          </dw-filter-bar>
          <dw-data-table [columns]="columns" [rows]="visible()" [loading]="loading()" [error]="error()" [trackBy]="track" [clickable]="true" emptyIcon="category"
            [emptyTitle]="rows().length > 0 ? t('common.noResult') : t('common.empty')" (rowClick)="edit($event)" (retry)="load()">
            <ng-template dwCell="family" let-i>{{ familyName(i.waste_family) }}</ng-template>
            <ng-template dwCell="name" let-i><strong>{{ i.name | dwLocalized }}</strong><div class="dw-faint dw-code">{{ i.code }}</div></ng-template>
            <ng-template dwCell="hazard" let-i>@if (i.is_hazardous) { <dw-status kind="alert" value="warning" [label]="t('common.hazardous')" /> }</ng-template>
            <ng-template dwCell="channel" let-i>{{ i.treatment_channel ? t('channels.' + i.treatment_channel) : '—' }}</ng-template>
            <ng-template dwCell="state" let-i><dw-status kind="member" [value]="i.is_active ? 'active' : 'suspended'" [label]="t(i.is_active ? 'common.active' : 'common.inactive')" [tone]="i.is_active ? 'success' : 'neutral'" /></ng-template>
          </dw-data-table>
        </div>
      </div>
    </ng-container>
  `,
})
export class PlatformCatalogPage {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);

  protected readonly rows = signal<CatalogItem[]>([]);
  protected readonly meta = signal<CatalogPayload['meta'] | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly busy = signal(false);
  protected readonly editing = signal(false);
  private current: string | null = null;
  protected readonly query = signal('');
  protected readonly family = signal('');
  protected readonly hazardous = signal(false);
  /** The catalogue is small and loaded whole: it is filtered here, as the user types. */
  protected readonly visible = computed(() => {
    const needle = this.query().toLowerCase();

    return this.rows().filter((item) => (!this.family() || item.waste_family === this.family()) && (!this.hazardous() || item.is_hazardous)
      && (needle === '' || [item.code, item.regulatory_code ?? '', ...Object.values(item.name)].some((text) => text.toLowerCase().includes(needle))));
  });
  protected readonly track = (item: CatalogItem): string => item.code;

  protected familyName(code: string): string {
    return this.meta()?.waste_families.find((family) => family.code === code)?.name['fr'] ?? code;
  }

  protected readonly form = inject(FormBuilder).nonNullable.group({
    code: ['', [Validators.required, Validators.pattern(/^[a-z][a-z0-9_]*$/)]], waste_family: ['', Validators.required], name_fr: ['', Validators.required], name_en: [''],
    regulatory_code: [''], treatment_channel: [''], is_hazardous: [false], is_active: [true],
  });
  protected readonly columns: Column<CatalogItem>[] = [
    { key: 'name', header: 'fields.name' },
    { key: 'family', header: 'wasteTypes.family', hideBelow: 900 },
    { key: 'code', header: 'lots.regulatoryCode', value: (i) => i.regulatory_code, hideBelow: 640 },
    { key: 'hazard', header: 'common.hazardous', hideBelow: 640 },
    { key: 'channel', header: 'wasteTypes.channel', hideBelow: 900 },
    { key: 'used', header: 'platform.catalog.usedBy', align: 'end', value: (i) => i.used_by },
    { key: 'state', header: 'fields.status' },
  ];

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.raw<CatalogPayload>('platform/waste-catalog').subscribe({
      next: (payload) => {
        this.rows.set(payload.data);
        this.meta.set(payload.meta);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected edit(item: CatalogItem | null): void {
    this.current = item?.code ?? null;
    this.form.reset({
      code: item?.code ?? '', waste_family: item?.waste_family ?? '', name_fr: item?.name['fr'] ?? '', name_en: item?.name['en'] ?? '',
      regulatory_code: item?.regulatory_code ?? '', treatment_channel: item?.treatment_channel ?? '', is_hazardous: item?.is_hazardous ?? false, is_active: item?.is_active ?? true,
    });
    // The code is the stable identifier clients' types point to: it cannot change once created.
    this.form.controls.code[item ? 'disable' : 'enable']();
    this.editing.set(true);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  protected async save(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    const value = this.form.getRawValue();
    const body = { ...value, name_en: value.name_en || null, regulatory_code: value.regulatory_code || null, treatment_channel: value.treatment_channel || null };
    this.busy.set(true);
    try {
      await firstValueFrom(this.current ? this.api.put(`platform/waste-catalog/${this.current}`, body) : this.api.post('platform/waste-catalog', body));
      this.toast.success('platform.catalog.saved');
      this.editing.set(false);
      this.load();
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

interface PlatformPermission {
  name: string;
  module: string;
  scope: string;
  audience: string;
}

interface PlatformRole {
  name: string;
  label: Localized | null;
  audience: string;
  permissions: string[];
}

/** Cahier §2: the matrix of the standard roles, editable without touching the code. A role only receives permissions of its own audience. */
@Component({
  imports: [TermPipe, FormsModule, TranslocoDirective, PageHeaderComponent, StateComponent, LocalizedPipe, ButtonDirective, ...FIELD, ...SELECT, CheckboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('platform.roles.title')" [subtitle]="t('platform.roles.subtitle')" />
      <dw-state [loading]="roles().length === 0 && !error()" [error]="error()" (retry)="load()">
        <div class="dw-panel">
          <div class="head">
            <dw-field [label]="t('fields.role')">
              <dw-select [ngModel]="selectedName()" (ngModelChange)="select($event)">
                @for (role of roles(); track role.name) { <dw-option [value]="role.name" [label]="(role.label | dwLocalized: role.name) + ' — ' + (role.audience | dwTerm: 'terms.audiences')" /> }
              </dw-select>
            </dw-field>
            <span class="dw-spacer"></span>
            <button pButton type="button" [disabled]="busy() || !dirty()" (click)="save()">{{ t('common.save') }}</button>
          </div>
          <div class="modules">
            @for (group of modules(); track group.module) {
              <fieldset>
                <legend>{{ group.module | dwTerm: 'terms.modules' }}</legend>
                @for (permission of group.permissions; track permission.name) {
                  <dw-checkbox [checked]="draft().has(permission.name)" (checkedChange)="toggle(permission.name, $event)">{{ permission.name | dwTerm: 'permissions' }}</dw-checkbox>
                }
              </fieldset>
            }
          </div>
        </div>
      </dw-state>
    </ng-container>
  `,
  styles: `
    .head { display: flex; align-items: center; gap: var(--dw-3); padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }
    .head dw-field { width: 360px; max-width: 100%; }
    .modules { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: var(--dw-3); padding: var(--dw-4); }
    fieldset { border: 1px solid var(--dw-line-soft); border-radius: var(--dw-radius); padding: 4px 8px 8px; margin: 0; display: grid; }
    legend { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dw-ink-2); padding-inline: 4px; }
  `,
})
export class PlatformRolesPage {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);

  protected readonly roles = signal<PlatformRole[]>([]);
  private readonly permissions = signal<PlatformPermission[]>([]);
  protected readonly error = signal<unknown>(null);
  protected readonly busy = signal(false);
  protected readonly selectedName = signal('');
  protected readonly draft = signal<Set<string>>(new Set());
  private readonly selected = computed(() => this.roles().find((role) => role.name === this.selectedName()) ?? null);
  protected readonly dirty = computed(() => {
    const saved = new Set(this.selected()?.permissions ?? []);

    return saved.size !== this.draft().size || [...this.draft()].some((name) => !saved.has(name));
  });
  // Same rule as the API: platform roles get platform permissions, client roles the ones of their audience.
  protected readonly modules = computed(() => {
    const audience = this.selected()?.audience;
    const allowed = this.permissions().filter((permission) => (audience === 'platform' ? permission.audience === 'platform' : permission.scope !== 'platform' && ['any', audience].includes(permission.audience)));
    const groups = new Map<string, PlatformPermission[]>();
    for (const permission of allowed) {
      groups.set(permission.module, [...(groups.get(permission.module) ?? []), permission]);
    }

    return [...groups.entries()].map(([module, permissions]) => ({ module, permissions }));
  });

  constructor() {
    this.load();
  }

  protected load(): void {
    this.error.set(null);
    this.api.get<{ roles: PlatformRole[]; permissions: PlatformPermission[] }>('platform/roles').subscribe({
      next: (payload) => {
        this.roles.set(payload.roles);
        this.permissions.set(payload.permissions);
        this.select(this.selectedName() || payload.roles[0]?.name || '');
      },
      error: (error: unknown) => this.error.set(error),
    });
  }

  protected select(name: string): void {
    this.selectedName.set(name);
    this.draft.set(new Set(this.selected()?.permissions ?? []));
  }

  protected toggle(permission: string, checked: boolean): void {
    this.draft.update((current) => {
      const next = new Set(current);
      if (checked) {
        next.add(permission);
      } else {
        next.delete(permission);
      }

      return next;
    });
  }

  protected async save(): Promise<void> {
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.put(`platform/roles/${this.selectedName()}`, { permissions: [...this.draft()] }));
      this.toast.success('platform.roles.saved');
      this.load();
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
