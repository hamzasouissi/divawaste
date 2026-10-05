import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';
import { Drawer } from 'primeng/drawer';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';
import { ToggleSwitch } from 'primeng/toggleswitch';
import { firstValueFrom } from 'rxjs';

import { Api } from '../../core/api/api';
import { Localized } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, LocalizedPipe, MoneyPipe, TermPipe } from '../../core/format/pipes';
import { Column, TABLE } from '../../ui/data-table';
import { DateComponent } from '../../ui/date';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { FilterBarComponent } from '../../ui/filter-bar';
import { IconComponent } from '../../ui/icon';
import { ListStore } from '../../ui/list-store';
import { KpiComponent, PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { SELECT } from '../../ui/select';
import { StateComponent } from '../../ui/state';

interface Overview {
  companies: { total: number; industrial: number; provider: number; active: number; pending_approval: number; pending_verification: number; suspended: number; rejected: number };
  accreditations: { pending: number; expiring: number; expired: number };
  subscriptions: { trialing: number; active: number; past_due: number; suspended: number; plan_requests: number };
  billing: { payments_pending: number; invoices_open: number; invoices_overdue: number };
  pending_companies: { ulid: string; legal_name: string; company_type: string; city: string | null; submitted_at: string | null }[];
  plan_requests: { ulid: string; legal_name: string; plan: string | null; sites: number | null }[];
  recent_activity: { event: string; entity: string; occurred_at: string; company: string | null; actor: string | null }[];
}

/** Events the platform trail knows by name; anything else keeps its technical name. */
const EVENTS = ['company_approved', 'company_rejected', 'company_suspended', 'company_reactivated', 'subscription_activated', 'plan_updated', 'accreditation_approved',
  'accreditation_rejected', 'payment_validated', 'payment_rejected', 'created', 'updated', 'deleted'];
const eventLabel = (event: string, t: (key: string) => string): string => (EVENTS.includes(event) ? t(`platform.activity.events.${event}`) : event.replace(/_/g, ' ').replace(/^./, (letter) => letter.toUpperCase()));

/**
 * First screen of the back office: what is waiting for Diva Software (registrations, accreditations, bank
 * transfers, plan requests), then the size of the customer base and what was decided recently.
 */
@Component({
  imports: [RouterLink, TranslocoDirective, PageHeaderComponent, KpiComponent, StateComponent, DatePipe, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('platform.overview.title')" [subtitle]="t('platform.overview.subtitle')" />
      <dw-state [loading]="!data() && !error()" [error]="error()" (retry)="load()">
        @if (data(); as d) {
          <div class="dw-stack">
            <div class="todo">
              <a class="dw-panel task" routerLink="/platform/companies" [queryParams]="{ status: 'pending_approval' }" [class.due]="d.companies.pending_approval > 0">
                <span class="mark"><dw-icon>how_to_reg</dw-icon></span>
                <span class="text"><strong>{{ d.companies.pending_approval }}</strong>{{ t('platform.overview.pendingCompanies') }}</span>
                <dw-icon class="go">chevron_right</dw-icon>
              </a>
              <a class="dw-panel task" routerLink="/platform/accreditations" [class.due]="d.accreditations.pending > 0">
                <span class="mark"><dw-icon>verified</dw-icon></span>
                <span class="text"><strong>{{ d.accreditations.pending }}</strong>{{ t('platform.overview.pendingAccreditations') }}</span>
                <dw-icon class="go">chevron_right</dw-icon>
              </a>
              <a class="dw-panel task" routerLink="/platform/payments" [class.due]="d.billing.payments_pending > 0">
                <span class="mark"><dw-icon>account_balance</dw-icon></span>
                <span class="text"><strong>{{ d.billing.payments_pending }}</strong>{{ t('platform.overview.pendingPayments') }}</span>
                <dw-icon class="go">chevron_right</dw-icon>
              </a>
              <a class="dw-panel task" routerLink="/platform/companies" [queryParams]="{ requests: 1 }" [class.due]="d.subscriptions.plan_requests > 0">
                <span class="mark"><dw-icon>sell</dw-icon></span>
                <span class="text"><strong>{{ d.subscriptions.plan_requests }}</strong>{{ t('platform.overview.planRequests') }}</span>
                <dw-icon class="go">chevron_right</dw-icon>
              </a>
            </div>

            <div class="dw-stats">
              <dw-kpi [label]="t('platform.overview.activeCompanies')" [value]="d.companies.active" [hint]="t('platform.overview.split', { industrial: d.companies.industrial, provider: d.companies.provider })" />
              <dw-kpi [label]="t('platform.overview.trials')" [value]="d.subscriptions.trialing" [hint]="t('platform.overview.trialsHint')" />
              <dw-kpi [label]="t('platform.overview.paying')" [value]="d.subscriptions.active" [hint]="d.subscriptions.past_due > 0 ? t('platform.overview.pastDue', { count: d.subscriptions.past_due }) : ''"
                [tone]="d.subscriptions.past_due > 0 ? 'warning' : 'neutral'" />
              <dw-kpi [label]="t('platform.overview.openInvoices')" [value]="d.billing.invoices_open"
                [hint]="d.billing.invoices_overdue > 0 ? t('platform.overview.overdue', { count: d.billing.invoices_overdue }) : ''" [tone]="d.billing.invoices_overdue > 0 ? 'danger' : 'neutral'" />
              <dw-kpi [label]="t('platform.overview.suspended')" [value]="d.companies.suspended" [tone]="d.companies.suspended > 0 ? 'warning' : 'neutral'" />
              <dw-kpi [label]="t('platform.overview.unverified')" [value]="d.companies.pending_verification" [hint]="t('platform.overview.unverifiedHint')" />
              <dw-kpi [label]="t('platform.overview.expiring')" [value]="d.accreditations.expiring" [tone]="d.accreditations.expiring > 0 ? 'warning' : 'neutral'" [hint]="t('platform.overview.expiringHint')" />
              <dw-kpi [label]="t('platform.overview.expired')" [value]="d.accreditations.expired" [tone]="d.accreditations.expired > 0 ? 'danger' : 'neutral'" />
            </div>

            <div class="dw-grid-2">
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('platform.overview.queue') }}</h2></div>
                <ul class="rows">
                  @for (company of d.pending_companies; track company.ulid) {
                    <li><a [routerLink]="['/platform/companies', company.ulid]">
                      <span class="dw-spacer"><strong>{{ company.legal_name }}</strong><small>{{ t('platform.companies.types.' + company.company_type) }}@if (company.city) { · {{ company.city }} }</small></span>
                      <small>{{ company.submitted_at | dwDate }}</small><dw-icon class="go">chevron_right</dw-icon>
                    </a></li>
                  } @empty { <li class="none">{{ t('platform.overview.queueEmpty') }}</li> }
                </ul>
              </section>
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('platform.overview.requests') }}</h2></div>
                <ul class="rows">
                  @for (request of d.plan_requests; track request.ulid) {
                    <li><a [routerLink]="['/platform/companies', request.ulid]">
                      <span class="dw-spacer"><strong>{{ request.legal_name }}</strong><small>{{ request.plan ? t('platform.plans.models.' + request.plan) : '' }}@if (request.sites) { · {{ t('platform.overview.sites', { count: request.sites }) }} }</small></span>
                      <dw-icon class="go">chevron_right</dw-icon>
                    </a></li>
                  } @empty { <li class="none">{{ t('platform.overview.requestsEmpty') }}</li> }
                </ul>
              </section>
            </div>

            <section class="dw-panel">
              <div class="dw-panel-head"><h2 class="dw-h2">{{ t('platform.overview.recent') }}</h2><span class="dw-spacer"></span><a class="more" routerLink="/platform/activity">{{ t('common.seeAll') }}<dw-icon>chevron_right</dw-icon></a></div>
              <ul class="rows">
                @for (entry of d.recent_activity; track $index) {
                  <li><div class="line">
                    <span class="dw-spacer"><strong>{{ label(entry.event, t) }}</strong><small>{{ entry.company ?? '—' }}</small></span>
                    <small>{{ entry.actor ?? t('admin.audit.system') }} · {{ entry.occurred_at | dwDate: 'datetime' }}</small>
                  </div></li>
                } @empty { <li class="none">{{ t('platform.overview.recentEmpty') }}</li> }
              </ul>
            </section>
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .todo { display: grid; gap: var(--dw-3); grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .task { display: flex; align-items: center; gap: var(--dw-3); padding: var(--dw-3) var(--dw-3) var(--dw-3) var(--dw-4); color: var(--dw-ink); }
    .task:hover { text-decoration: none; border-color: var(--dw-line-strong); }
    .mark { flex: none; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 8px; background: var(--dw-neutral-soft); color: var(--dw-ink-3); }
    .task.due { border-color: #ecd9a8; background: #fffaf0; }
    .task.due .mark { background: var(--dw-warning-soft); color: var(--dw-warning); }
    .text { flex: 1; display: flex; flex-direction: column; font-size: 12.5px; color: var(--dw-ink-2); min-width: 0; }
    .text strong { font-size: 20px; line-height: 24px; font-weight: 650; color: var(--dw-ink); font-variant-numeric: tabular-nums; }
    .go { color: var(--dw-ink-3); font-size: 16px; }
    .rows { list-style: none; margin: 0; padding: 0; }
    .rows li + li { border-block-start: 1px solid var(--dw-line-soft); }
    .rows a, .rows .line { display: flex; align-items: center; gap: var(--dw-3); min-height: 44px; padding: 6px var(--dw-3) 6px var(--dw-4); color: var(--dw-ink); }
    .rows a:hover { background: var(--dw-surface-2); text-decoration: none; }
    .rows .dw-spacer { display: flex; flex-direction: column; min-width: 0; }
    .rows small { font-size: 12px; color: var(--dw-ink-3); }
    .none { padding: var(--dw-4); color: var(--dw-ink-3); }
    .more { display: inline-flex; align-items: center; font-size: 12.5px; font-weight: 550; }
    .more dw-icon { font-size: 16px; }
    @media (max-width: 1100px) { .todo { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 560px) { .todo { grid-template-columns: minmax(0, 1fr); } }
  `,
})
export class PlatformOverviewPage {
  private readonly api = inject(Api);

  protected readonly data = signal<Overview | null>(null);
  protected readonly error = signal<unknown>(null);
  protected readonly label = eventLabel;

  constructor() {
    this.load();
  }

  protected load(): void {
    this.error.set(null);
    this.api.get<Overview>('platform/overview').subscribe({ next: (data) => this.data.set(data), error: (error: unknown) => this.error.set(error) });
  }
}

export interface PlatformPlan {
  code: string;
  name: Localized;
  description: Localized | null;
  billing_model: 'trial' | 'per_site' | 'per_volume' | 'hybrid';
  is_trial: boolean;
  trial_days: number | null;
  is_public: boolean;
  is_active: boolean;
  subscribers: number;
  prices: { currency_code: string; billing_interval: 'monthly' | 'yearly'; component: string; unit_amount: string }[];
}

interface PlanDialogData {
  plan: PlatformPlan;
  currencies: string[];
}

const AMOUNT = Validators.pattern(/^\d+([.,]\d{1,3})?$/);

/** Name, visibility and reference price of a plan. The price really invoiced is confirmed for each client at activation. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...DIALOG_PARTS, ToggleSwitch, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('platform.plans.edit') }} — {{ data.plan.name | dwLocalized }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field class="dw-span-2" [label]="t('fields.name')"><input pInputText formControlName="name" maxlength="100" /></dw-field>
            <dw-field class="dw-span-2" [label]="t('fields.description')"><textarea pTextarea formControlName="description" rows="2" maxlength="500"></textarea></dw-field>
            @if (data.plan.is_trial) {
              <dw-field [label]="t('platform.plans.trialDays')"><input pInputText type="number" min="1" max="365" formControlName="trial_days" /></dw-field>
            } @else {
              <dw-field [label]="t('platform.plans.currency')">
                <dw-select formControlName="currency_code">@for (currency of data.currencies; track currency) { <dw-option [value]="currency" [label]="currency" /> }</dw-select>
              </dw-field>
              <span></span>
              <dw-field [label]="t('platform.plans.monthly') + ' — ' + t('platform.plans.unit.' + data.plan.billing_model)">
                <input pInputText inputmode="decimal" formControlName="monthly_amount" />
              </dw-field>
              <dw-field [label]="t('platform.plans.yearly') + ' — ' + t('platform.plans.unit.' + data.plan.billing_model)">
                <input pInputText inputmode="decimal" formControlName="yearly_amount" />
              </dw-field>
              <p class="dw-span-2 dw-faint">{{ t('platform.plans.priceHint') }}</p>
            }
            <div class="dw-switch"><p-toggleswitch formControlName="is_public" inputId="plan-public" /><label for="plan-public">{{ t('platform.plans.public') }}</label></div>
            <div class="dw-switch"><p-toggleswitch formControlName="is_active" inputId="plan-active" /><label for="plan-active">{{ t('platform.plans.active') }}</label></div>
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: '.dw-dialog-body { width: min(560px, 86vw); }',
})
export class PlanDialog {
  protected readonly data = dialogData<PlanDialogData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  private readonly currency = this.data.plan.prices[0]?.currency_code ?? this.data.currencies[0] ?? 'TND';
  private readonly price = (interval: string): string => this.data.plan.prices.find((price) => price.billing_interval === interval && price.currency_code === this.currency)?.unit_amount ?? '';

  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    name: [this.data.plan.name['fr'] ?? '', Validators.required],
    description: [this.data.plan.description?.['fr'] ?? ''],
    trial_days: [this.data.plan.trial_days ?? 30, [Validators.min(1), Validators.max(365)]],
    currency_code: [this.currency],
    monthly_amount: [this.price('monthly'), AMOUNT],
    yearly_amount: [this.price('yearly'), AMOUNT],
    is_public: [this.data.plan.is_public],
    is_active: [this.data.plan.is_active],
  });

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    const value = this.form.getRawValue();
    const amount = (text: string): string | null => (text.trim() === '' ? null : text.replace(',', '.'));
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.put(`platform/plans/${this.data.plan.code}`, {
        name: value.name, description: value.description.trim() || null, is_public: value.is_public, is_active: value.is_active,
        ...(this.data.plan.is_trial ? { trial_days: value.trial_days } : { currency_code: value.currency_code, monthly_amount: amount(value.monthly_amount), yearly_amount: amount(value.yearly_amount) }),
      }));
      this.toast.success('common.saved');
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

/** M0-03: the formulas offered to clients, who uses them and at which reference price. */
@Component({
  imports: [TranslocoDirective, PageHeaderComponent, StateComponent, StatusComponent, LocalizedPipe, MoneyPipe, IconComponent, ButtonDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('platform.plans.title')" [subtitle]="t('platform.plans.subtitle')" />
      <div class="dw-panel">
        <dw-state [loading]="loading()" [error]="error()" [keepContent]="plans().length > 0" (retry)="load()">
          <div class="scroll">
            <table class="dw-table">
              <thead><tr>
                <th>{{ t('platform.plans.plan') }}</th><th>{{ t('platform.plans.model') }}</th><th class="num">{{ t('platform.plans.monthly') }}</th><th class="num">{{ t('platform.plans.yearly') }}</th>
                <th class="num">{{ t('platform.plans.subscribers') }}</th><th>{{ t('platform.plans.visibility') }}</th><th></th>
              </tr></thead>
              <tbody>
                @for (plan of plans(); track plan.code) {
                  <tr>
                    <td><strong>{{ plan.name | dwLocalized }}</strong>@if (plan.description) { <div class="dw-faint">{{ plan.description | dwLocalized }}</div> }</td>
                    <td>{{ t('platform.plans.models.' + plan.billing_model) }}@if (plan.is_trial) { <span class="dw-faint"> · {{ t('platform.plans.days', { count: plan.trial_days }) }}</span> }</td>
                    <td class="num">@if (price(plan, 'monthly'); as p) { {{ p.unit_amount | dwMoney: p.currency_code }} } @else { <span class="dw-faint">{{ plan.is_trial ? t('platform.plans.free') : t('platform.plans.onQuote') }}</span> }</td>
                    <td class="num">@if (price(plan, 'yearly'); as p) { {{ p.unit_amount | dwMoney: p.currency_code }} } @else { <span class="dw-faint">{{ plan.is_trial ? t('platform.plans.free') : t('platform.plans.onQuote') }}</span> }</td>
                    <td class="num">{{ plan.subscribers }}</td>
                    <td>
                      <dw-status kind="alert" [value]="plan.is_active ? 'ok' : 'exceeded'" [label]="t(plan.is_active ? 'common.active' : 'common.inactive')" [tone]="plan.is_active ? 'success' : 'neutral'" />
                      @if (plan.is_public) { <dw-status kind="alert" value="ok" [label]="t('platform.plans.publicShort')" tone="info" /> }
                    </td>
                    <td class="num">@if (canManage) { <button pButton text type="button" (click)="edit(plan)"><dw-icon>edit</dw-icon>{{ t('common.edit') }}</button> }</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        </dw-state>
      </div>
      <p class="note dw-notice info"><dw-icon>info</dw-icon><span>{{ t('platform.plans.note') }}</span></p>
    </ng-container>
  `,
  styles: '.scroll { overflow-x: auto; } .note { margin-block-start: var(--dw-4); max-width: 920px; } td dw-status + dw-status { margin-inline-start: 6px; }',
})
export class PlatformPlansPage {
  private readonly api = inject(Api);
  private readonly dialogs = inject(Dialogs);

  protected readonly canManage = inject(Session).canPlatform('platform.plans.manage');
  protected readonly plans = signal<PlatformPlan[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  private currencies: string[] = [];

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.raw<{ data: PlatformPlan[]; meta: { currencies: string[] } }>('platform/plans').subscribe({
      next: (page) => {
        this.plans.set(page.data);
        this.currencies = page.meta.currencies;
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected price(plan: PlatformPlan, interval: string): PlatformPlan['prices'][number] | undefined {
    return plan.prices.find((price) => price.billing_interval === interval);
  }

  protected edit(plan: PlatformPlan): void {
    this.dialogs.open<PlanDialog, PlanDialogData, boolean>(PlanDialog, { data: { plan, currencies: this.currencies } }).afterClosed().subscribe((saved) => saved && this.load());
  }
}

interface PlatformInvoice {
  ulid: string;
  invoice_number: string;
  status: string;
  issue_date: string;
  due_date: string;
  period_start: string | null;
  period_end: string | null;
  currency_code: string;
  total_amount: string;
  amount_paid: string;
  company: string;
  legal_name: string;
  pending_payment: boolean;
  is_late: boolean;
}

const INVOICE_STATUSES = ['issued', 'partially_paid', 'paid', 'overdue', 'cancelled'];

/** Billing follow-up: the invoices of every client and what is still due. Payments are validated in "Virements déclarés". */
@Component({
  imports: [RouterLink, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, SearchComponent, DatePipe, MoneyPipe, ...SELECT, FilterBarComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('platform.invoices.title')" [subtitle]="t('platform.invoices.subtitle')" />
      <div class="dw-panel">
        <dw-filter-bar [active]="list.activeCount()" (cleared)="list.resetFilter()">
          <dw-search [placeholder]="t('platform.invoices.search')" [query]="list.filter().search ?? ''" (queryChange)="list.setFilter({ search: $event })" />
          <dw-select [placeholder]="t('fields.status')" [value]="list.filter().status ?? ''" (valueChange)="list.setFilter({ status: $event || null })">
            <dw-option value="" [label]="t('common.all')" />
            @for (status of statuses; track status) { <dw-option [value]="status" [label]="t('status.invoice.' + status)" /> }
          </dw-select>
        </dw-filter-bar>
        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1" [pageSize]="list.perPage()"
          emptyIcon="receipt_long" [emptyTitle]="list.filtered() ? t('common.noResult') : t('platform.invoices.emptyTitle')" [emptyHint]="list.filtered() ? '' : t('platform.invoices.emptyHint')"
          (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (retry)="list.reload()">
          <ng-template dwCell="number" let-i><span class="dw-mono">{{ i.invoice_number }}</span></ng-template>
          <ng-template dwCell="company" let-i><a [routerLink]="['/platform/companies', i.company]">{{ i.legal_name }}</a></ng-template>
          <ng-template dwCell="issued" let-i>{{ i.issue_date | dwDate }}</ng-template>
          <ng-template dwCell="due" let-i><span [class.late]="i.is_late">{{ i.due_date | dwDate }}</span></ng-template>
          <ng-template dwCell="total" let-i>{{ i.total_amount | dwMoney: i.currency_code }}</ng-template>
          <ng-template dwCell="paid" let-i>{{ i.amount_paid | dwMoney: i.currency_code }}</ng-template>
          <ng-template dwCell="status" let-i>
            <dw-status kind="invoice" [value]="i.is_late && i.status !== 'overdue' ? 'overdue' : i.status" />
            @if (i.pending_payment) { <a routerLink="/platform/payments" class="pending">{{ t('platform.invoices.transferDeclared') }}</a> }
          </ng-template>
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: '.late { color: var(--dw-danger); font-weight: 600; } .pending { margin-inline-start: 8px; font-size: 12px; font-weight: 550; }',
})
export class PlatformInvoicesPage {
  private readonly api = inject(Api);

  protected readonly statuses = INVOICE_STATUSES;
  protected readonly list = new ListStore<PlatformInvoice, { status?: string | null; search?: string }>((query) => this.api.list<PlatformInvoice>('platform/invoices', query));
  protected readonly columns: Column<PlatformInvoice>[] = [
    { key: 'number', header: 'admin.subscription.invoiceNumber' },
    { key: 'company', header: 'platform.invoices.company' },
    { key: 'issued', header: 'admin.subscription.issueDate', hideBelow: 900 },
    { key: 'due', header: 'admin.subscription.dueDate' },
    { key: 'total', header: 'admin.subscription.total', align: 'end' },
    { key: 'paid', header: 'admin.subscription.paid', align: 'end', hideBelow: 640 },
    { key: 'status', header: 'fields.status' },
  ];
}

interface ActivityEntry {
  event: string;
  entity: string;
  object: string | null;
  actor: { ulid: string; name: string } | null;
  source: string;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  ip_address: string | null;
  occurred_at: string;
  company: { ulid: string; legal_name: string } | null;
}

interface ActivityFilter {
  entity?: string | null;
  from?: string | null;
  to?: string | null;
}

const ENTITIES = ['companies', 'provider_accreditations', 'company_subscriptions', 'invoices', 'payments', 'waste_catalog_items', 'subscription_plans', 'roles'];

/**
 * M7-02 on the platform side: registrations decided, accounts suspended, accreditations reviewed, plans
 * activated, payments validated, reference data changed — with who did it. Operational data of the clients
 * (lots, pickups, documents) is not in this trail: it belongs to each company's own audit journal.
 */
@Component({
  imports: [TermPipe, FormsModule, RouterLink, TranslocoDirective, ...TABLE, PageHeaderComponent, DatePipe, ...SELECT, DateComponent, FilterBarComponent, Drawer],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('platform.activity.title')" [subtitle]="t('platform.activity.subtitle')" />
      <div class="dw-panel">
        <dw-filter-bar [active]="list.activeCount()" (cleared)="list.resetFilter()">
          <dw-select class="wide" [placeholder]="t('platform.activity.entity')" [value]="list.filter().entity ?? ''" (valueChange)="list.setFilter({ entity: $event || null })">
            <dw-option value="" [label]="t('common.all')" />
            @for (entity of entities; track entity) { <dw-option [value]="entity" [label]="t('platform.activity.entities.' + entity)" /> }
          </dw-select>
          <dw-date [placeholder]="t('period.from')" [ngModel]="list.filter().from ?? ''" (ngModelChange)="list.setFilter({ from: $event || null })" />
          <dw-date [placeholder]="t('period.to')" [ngModel]="list.filter().to ?? ''" (ngModelChange)="list.setFilter({ to: $event || null })" />
        </dw-filter-bar>
        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1" [pageSize]="list.perPage()"
          [trackBy]="track" [clickable]="true" emptyIcon="history" [emptyTitle]="t('platform.activity.emptyTitle')"
          (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="selected.set($event)" (retry)="list.reload()">
          <ng-template dwCell="when" let-e>{{ e.occurred_at | dwDate: 'datetime' }}</ng-template>
          <ng-template dwCell="event" let-e><strong>{{ label(e.event, t) }}</strong></ng-template>
          <ng-template dwCell="entity" let-e>{{ t('platform.activity.entities.' + e.entity) }}</ng-template>
          <ng-template dwCell="company" let-e>{{ e.company?.legal_name ?? '—' }}</ng-template>
          <ng-template dwCell="actor" let-e>{{ e.actor?.name ?? t('admin.audit.system') }}</ng-template>
        </dw-data-table>
      </div>

      <p-drawer [visible]="selected() !== null" (visibleChange)="$event || selected.set(null)" position="right" styleClass="dw-drawer" [header]="t('common.details')">
        @if (selected(); as e) {
          <dl class="dw-facts">
            <dt>{{ t('admin.audit.when') }}</dt><dd>{{ e.occurred_at | dwDate: 'datetime' }}</dd>
            <dt>{{ t('admin.audit.event') }}</dt><dd>{{ label(e.event, t) }}</dd>
            <dt>{{ t('admin.audit.entity') }}</dt><dd>{{ t('platform.activity.entities.' + e.entity) }}</dd>
            <dt>{{ t('platform.invoices.company') }}</dt>
            <dd>@if (e.company; as company) { <a [routerLink]="['/platform/companies', company.ulid]">{{ company.legal_name }}</a> } @else { — }</dd>
            <dt>{{ t('admin.audit.actor') }}</dt><dd>{{ e.actor?.name ?? t('admin.audit.system') }}</dd>
            <dt>{{ t('admin.audit.source') }}</dt><dd>{{ e.source | dwTerm: 'terms.sources' }}@if (e.ip_address) { · {{ e.ip_address }} }</dd>
          </dl>
          <h3 class="dw-label">{{ t('admin.audit.changes') }}</h3>
          @if (changes(e).length > 0) {
            <table class="dw-table diff">
              <thead><tr><th></th><th>{{ t('admin.audit.before') }}</th><th>{{ t('admin.audit.after') }}</th></tr></thead>
              <tbody>@for (change of changes(e); track change.key) { <tr><th scope="row">{{ change.key | dwTerm: 'terms.fields' }}</th><td>{{ change.before }}</td><td>{{ change.after }}</td></tr> }</tbody>
            </table>
          } @else { <p class="dw-faint">—</p> }
        }
      </p-drawer>
    </ng-container>
  `,
  styles: `
    h3 { margin-block: var(--dw-5) var(--dw-2); }
    .diff { border: 1px solid var(--dw-line); border-radius: var(--dw-radius); border-collapse: separate; border-spacing: 0; overflow: hidden; font-size: 12.5px; }
    .diff th[scope='row'] { text-align: start; font-weight: 550; padding: 6px var(--dw-3); border-block-end: 1px solid var(--dw-line-soft); background: var(--dw-surface-2); white-space: nowrap; }
    .diff td { overflow-wrap: anywhere; vertical-align: top; }
    .diff tr:last-child th { border-block-end: 0; }
  `,
})
export class PlatformActivityPage {
  private readonly api = inject(Api);

  protected readonly entities = ENTITIES;
  protected readonly label = eventLabel;
  protected readonly selected = signal<ActivityEntry | null>(null);
  protected readonly list = new ListStore<ActivityEntry, ActivityFilter>((query) => this.api.list<ActivityEntry>('platform/activity', query));
  protected readonly track = (entry: ActivityEntry): string => `${entry.occurred_at}${entry.entity}${entry.object}${entry.event}`;
  protected readonly columns: Column<ActivityEntry>[] = [
    { key: 'when', header: 'admin.audit.when' },
    { key: 'event', header: 'admin.audit.event' },
    { key: 'entity', header: 'admin.audit.entity', hideBelow: 900 },
    { key: 'company', header: 'platform.invoices.company' },
    { key: 'actor', header: 'admin.audit.actor', hideBelow: 640 },
  ];

  protected changes(entry: ActivityEntry): { key: string; before: string; after: string }[] {
    const show = (value: unknown): string => (value === null || value === undefined ? '—' : typeof value === 'object' ? JSON.stringify(value) : String(value));
    const keys = new Set([...Object.keys(entry.old_values ?? {}), ...Object.keys(entry.new_values ?? {})]);

    return [...keys].map((key) => ({ key, before: show(entry.old_values?.[key]), after: show(entry.new_values?.[key]) }));
  }
}
