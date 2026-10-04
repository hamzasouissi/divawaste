import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { Router } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';

import { Api, openBlob } from '../../core/api/api';
import { Company, CompanyStatus, Localized, ProviderAccreditation } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, LocalizedPipe, MoneyPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { StateComponent } from '../../ui/state';

const FILTERS = `
  .filters { display: flex; align-items: center; gap: var(--dw-2) var(--dw-3); flex-wrap: wrap; padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }
  .filters mat-form-field { width: 220px; }
`;

interface CompanyFilter {
  status?: CompanyStatus | null;
  type?: string | null;
  search?: string;
}

/** M0-06: registrations to review first, then every account. */
@Component({
  imports: [MatButtonModule, MatFormFieldModule, MatSelectModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, SearchComponent, DatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('platform.companies.title')" [subtitle]="t('platform.companies.subtitle')" />
      <div class="dw-panel">
        <div class="filters">
          <dw-search [placeholder]="t('fields.legalName')" (queryChange)="list.setFilter({ search: $event })" />
          <mat-form-field>
            <mat-label>{{ t('fields.status') }}</mat-label>
            <mat-select [value]="list.filter().status ?? ''" (selectionChange)="list.setFilter({ status: $event.value || null })">
              <mat-option value="">{{ t('common.all') }}</mat-option>
              @for (status of statuses; track status) { <mat-option [value]="status">{{ t('status.company.' + status) }}</mat-option> }
            </mat-select>
          </mat-form-field>
          <mat-form-field>
            <mat-label>{{ t('platform.companies.type') }}</mat-label>
            <mat-select [value]="list.filter().type ?? ''" (selectionChange)="list.setFilter({ type: $event.value || null })">
              <mat-option value="">{{ t('common.all') }}</mat-option>
              <mat-option value="industrial">{{ t('platform.companies.types.industrial') }}</mat-option>
              <mat-option value="provider">{{ t('platform.companies.types.provider') }}</mat-option>
            </mat-select>
          </mat-form-field>
          @if (list.filtered()) { <button mat-button type="button" (click)="list.resetFilter()">{{ t('common.resetFilters') }}</button> }
        </div>
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
export class PlatformCompaniesPage {
  private readonly api = inject(Api);
  private readonly router = inject(Router);

  protected readonly statuses: CompanyStatus[] = ['pending_approval', 'pending_verification', 'active', 'suspended', 'rejected', 'closed'];
  protected readonly list = new ListStore<Company, CompanyFilter>((query) => this.api.list<Company>('platform/companies', query));
  protected readonly columns: Column<Company>[] = [
    { key: 'name', header: 'fields.legalName' },
    { key: 'type', header: 'platform.companies.type' },
    { key: 'city', header: 'fields.city', value: (c) => [c.city, c.country].filter(Boolean).join(', '), hideBelow: 900 },
    { key: 'submitted', header: 'platform.companies.submittedAt', hideBelow: 640 },
    { key: 'status', header: 'fields.status' },
  ];

  protected open(company: Company): void {
    void this.router.navigate(['/platform/companies', company.ulid]);
  }
}

interface CompanyDetail extends Company {
  rejection_reason: string | null;
  suspension_reason: string | null;
  requested_plan: string | null;
  contact: { name: string; email: string; phone: string | null; email_verified: boolean } | null;
  counts: { sites: number; users: number; lots: number };
  accreditations: { ulid: string; type: string; name: Localized; reference_number: string; valid_from: string; expires_on: string; review_status: string; rejection_reason: string | null }[];
}

interface Plan {
  code: string;
  name: Localized;
  is_trial: boolean;
}

/** One registration or account: everything needed to decide, and the decision itself. */
@Component({
  imports: [ReactiveFormsModule, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent,
    DatePipe, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="!company() && !error()" [error]="error()" (retry)="load()">
        @if (company(); as c) {
          <dw-page-header [title]="c.legal_name" [subtitle]="t('platform.companies.types.' + c.company_type)" back="/platform/companies" [backLabel]="t('platform.companies.title')">
            <dw-status dwTitleBadge kind="company" [value]="c.status" />
            @if (canReview && c.status === 'pending_approval') {
              <button mat-button type="button" [disabled]="busy()" (click)="decide('reject')">{{ t('platform.companies.reject') }}</button>
              <button mat-flat-button type="button" [disabled]="busy()" (click)="decide('approve')"><mat-icon>check</mat-icon>{{ t('platform.companies.approve') }}</button>
            }
            @if (canSuspend && c.status === 'active') { <button mat-stroked-button type="button" [disabled]="busy()" (click)="decide('suspend')">{{ t('platform.companies.suspend') }}</button> }
            @if (canSuspend && c.status === 'suspended') { <button mat-flat-button type="button" [disabled]="busy()" (click)="decide('reactivate')">{{ t('platform.companies.reactivate') }}</button> }
          </dw-page-header>

          <div class="dw-split">
            <div class="dw-stack">
              <section class="dw-panel">
                <div class="dw-panel-body">
                  <dl class="dw-facts">
                    <dt>{{ t('fields.taxId') }}</dt><dd class="dw-mono">{{ c.tax_id ?? '—' }}</dd>
                    <dt>{{ t('fields.address') }}</dt><dd>{{ c.address_line1 }} {{ c.postal_code }} {{ c.city }} — {{ c.country }}</dd>
                    <dt>{{ t('fields.phone') }}</dt><dd>{{ c.phone ?? '—' }}</dd>
                    <dt>{{ t('platform.companies.submittedAt') }}</dt><dd>{{ c.submitted_at | dwDate: 'datetime' }}</dd>
                    <dt>{{ t('platform.companies.contact') }}</dt>
                    <dd>
                      @if (c.contact; as contact) {
                        {{ contact.name }} · {{ contact.email }} @if (contact.phone) { · {{ contact.phone }} }
                        <dw-status kind="alert" [value]="contact.email_verified ? 'ok' : 'warning'" [label]="t('platform.companies.emailVerified') + ' : ' + t(contact.email_verified ? 'common.yes' : 'common.no')" />
                      } @else { — }
                    </dd>
                    @if (c.activities.length > 0) { <dt>{{ t('auth.register.activities') }}</dt><dd>{{ c.activities.join(', ') }}</dd> }
                    @if (c.requested_plan) { <dt>{{ t('platform.companies.requestedPlan') }}</dt><dd>{{ c.requested_plan }}</dd> }
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
                        <div class="dw-spacer"><strong>{{ row.name | dwLocalized }}</strong> <span class="dw-mono dw-faint">{{ row.reference_number }}</span>
                          <div class="dw-muted">{{ row.valid_from | dwDate }} → {{ row.expires_on | dwDate }}</div></div>
                        <dw-status kind="accreditation" [value]="row.review_status" />
                        <button mat-icon-button type="button" (click)="file(row.ulid)" [attr.aria-label]="t('providers.viewFile')" [title]="t('providers.viewFile')"><mat-icon>description</mat-icon></button>
                      </li>
                    } @empty { <li class="dw-faint">{{ t('common.none') }}</li> }
                  </ul>
                </section>
              }
            </div>

            @if (canBill && c.company_type === 'industrial' && c.status === 'active') {
              <form class="dw-panel" [formGroup]="form" (ngSubmit)="activate()">
                <div class="dw-panel-body plan">
                  <h2 class="dw-h2">{{ t('platform.companies.activatePlan') }}</h2>
                  <mat-form-field>
                    <mat-label>{{ t('auth.register.plan') }}</mat-label>
                    <mat-select formControlName="plan">@for (plan of plans(); track plan.code) { <mat-option [value]="plan.code">{{ plan.name | dwLocalized }}</mat-option> }</mat-select>
                  </mat-form-field>
                  <mat-form-field>
                    <mat-label>{{ t('platform.companies.interval') }}</mat-label>
                    <mat-select formControlName="billing_interval">
                      <mat-option value="monthly">{{ t('admin.subscription.intervals.monthly') }}</mat-option><mat-option value="yearly">{{ t('admin.subscription.intervals.yearly') }}</mat-option>
                    </mat-select>
                  </mat-form-field>
                  <mat-form-field><mat-label>{{ t('admin.subscription.sitesWanted') }}</mat-label><input matInput type="number" min="1" max="500" formControlName="sites_quantity" /></mat-form-field>
                  <mat-form-field>
                    <mat-label>{{ t('platform.companies.unitAmount') }} ({{ c.currency_code }})</mat-label><input matInput inputmode="decimal" formControlName="unit_amount" />
                    @if (form.controls.unit_amount.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
                  </mat-form-field>
                  <div class="dw-form-actions"><button mat-flat-button type="submit" [disabled]="busy()">{{ t('platform.companies.activatePlan') }}</button></div>
                </div>
              </form>
            }
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .list { list-style: none; margin: 0; padding: 0; }
    .list li { display: flex; align-items: center; gap: var(--dw-3); padding: var(--dw-3) var(--dw-4); border-block-start: 1px solid var(--dw-line-soft); }
    .plan { display: grid; }
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
  protected readonly plans = signal<Plan[]>([]);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    plan: ['', Validators.required], billing_interval: ['monthly', Validators.required],
    sites_quantity: [1, [Validators.required, Validators.min(1), Validators.max(500)]], unit_amount: ['', [Validators.required, Validators.pattern(/^\d+([.,]\d{1,3})?$/)]],
  });

  ngOnInit(): void {
    this.load();
    this.api.get<{ plans: Plan[] }>('registration/options').subscribe({ next: (options) => this.plans.set(options.plans.filter((plan) => !plan.is_trial)), error: () => undefined });
  }

  protected load(): void {
    this.error.set(null);
    this.api.get<CompanyDetail>(`platform/companies/${this.ulid()}`).subscribe({
      next: (company) => {
        this.company.set(company);
        this.form.patchValue({ plan: company.requested_plan ?? '', sites_quantity: Math.max(1, company.counts.sites) });
      },
      error: (error: unknown) => this.error.set(error),
    });
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
  imports: [MatButtonModule, MatFormFieldModule, MatIconModule, MatSelectModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, DatePipe, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('platform.accreditations.title')" [subtitle]="t('platform.accreditations.subtitle')" />
      <div class="dw-panel">
        <div class="filters">
          <mat-form-field>
            <mat-label>{{ t('fields.status') }}</mat-label>
            <mat-select [value]="status()" (selectionChange)="setStatus($event.value)">
              @for (item of statuses; track item) { <mat-option [value]="item">{{ t('status.accreditation.' + item) }}</mat-option> }
            </mat-select>
          </mat-form-field>
        </div>
        <dw-data-table [columns]="columns" [rows]="rows()" [loading]="loading()" [error]="error()" emptyIcon="verified" [emptyTitle]="t('platform.accreditations.emptyTitle')" (retry)="load()">
          <ng-template dwCell="provider" let-a><strong>{{ a.legal_name }}</strong></ng-template>
          <ng-template dwCell="type" let-a>{{ a.name | dwLocalized }}<div class="dw-faint dw-mono">{{ a.reference_number }}</div></ng-template>
          <ng-template dwCell="validity" let-a>{{ a.valid_from | dwDate }} → {{ a.expires_on | dwDate }} @if (a.expired) { <dw-status kind="accreditation" value="expired" /> }</ng-template>
          <ng-template dwCell="actions" let-a>
            <div class="dw-row-tight">
              <button mat-stroked-button type="button" (click)="file(a)"><mat-icon>description</mat-icon>{{ t('providers.viewFile') }}</button>
              @if (a.review_status === 'pending') {
                <button mat-button type="button" [disabled]="busy()" (click)="decide(a, 'rejected')">{{ t('platform.accreditations.reject') }}</button>
                <button mat-flat-button type="button" [disabled]="busy()" (click)="decide(a, 'approved')">{{ t('platform.accreditations.approve') }}</button>
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
  imports: [MatButtonModule, MatFormFieldModule, MatSelectModule, TranslocoDirective, ...TABLE, PageHeaderComponent, DatePipe, MoneyPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('platform.payments.title')" [subtitle]="t('platform.payments.subtitle')" />
      <div class="dw-panel">
        <div class="filters">
          <mat-form-field>
            <mat-label>{{ t('fields.status') }}</mat-label>
            <mat-select [value]="status()" (selectionChange)="setStatus($event.value)">
              <mat-option value="pending">{{ t('status.accreditation.pending') }}</mat-option>
              <mat-option value="validated">{{ t('status.accreditation.approved') }}</mat-option>
              <mat-option value="rejected">{{ t('status.accreditation.rejected') }}</mat-option>
            </mat-select>
          </mat-form-field>
        </div>
        <dw-data-table [columns]="columns" [rows]="rows()" [loading]="loading()" [error]="error()" emptyIcon="account_balance" [emptyTitle]="t('platform.payments.emptyTitle')" (retry)="load()">
          <ng-template dwCell="company" let-p><strong>{{ p.legal_name }}</strong><div class="dw-faint dw-mono">{{ p.invoice_number }}</div></ng-template>
          <ng-template dwCell="amount" let-p>{{ p.amount | dwMoney: p.currency_code }}<div class="dw-faint">/ {{ p.total_amount | dwMoney: p.currency_code }}</div></ng-template>
          <ng-template dwCell="reference" let-p><span class="dw-mono">{{ p.bank_reference }}</span></ng-template>
          <ng-template dwCell="date" let-p>{{ p.declared_paid_on | dwDate }}</ng-template>
          <ng-template dwCell="actions" let-p>
            @if (p.status === 'pending') {
              <div class="dw-row-tight">
                <button mat-button type="button" [disabled]="busy()" (click)="decide(p, 'rejected')">{{ t('platform.payments.reject') }}</button>
                <button mat-flat-button type="button" [disabled]="busy()" (click)="decide(p, 'validated')">{{ t('platform.payments.validate') }}</button>
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
  imports: [ReactiveFormsModule, MatButtonModule, MatCheckboxModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent,
    LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('platform.catalog.title')" [subtitle]="t('platform.catalog.subtitle')">
        @if (!editing()) { <button mat-flat-button type="button" (click)="edit(null)"><mat-icon>add</mat-icon>{{ t('platform.catalog.new') }}</button> }
      </dw-page-header>
      <div class="dw-stack">
        @if (editing()) {
          <form class="dw-panel" [formGroup]="form" (ngSubmit)="save()">
            <div class="dw-panel-body">
              <div class="dw-form">
                <mat-form-field>
                  <mat-label>{{ t('fields.code') }}</mat-label><input matInput formControlName="code" maxlength="40" />
                  @if (form.controls.code.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
                </mat-form-field>
                <mat-form-field>
                  <mat-label>{{ t('wasteTypes.family') }}</mat-label>
                  <mat-select formControlName="waste_family">@for (family of meta()?.waste_families ?? []; track family.code) { <mat-option [value]="family.code">{{ family.name | dwLocalized }}</mat-option> }</mat-select>
                </mat-form-field>
                <mat-form-field><mat-label>{{ t('platform.catalog.nameFr') }}</mat-label><input matInput formControlName="name_fr" maxlength="150" /></mat-form-field>
                <mat-form-field><mat-label>{{ t('platform.catalog.nameEn') }}</mat-label><input matInput formControlName="name_en" maxlength="150" /></mat-form-field>
                <mat-form-field>
                  <mat-label>{{ t('lots.regulatoryCode') }}</mat-label>
                  <mat-select formControlName="regulatory_code">
                    <mat-option value="">—</mat-option>
                    @for (code of meta()?.regulatory_waste_codes ?? []; track code.code) { <mat-option [value]="code.code">{{ code.code }} — {{ code.label | dwLocalized }}</mat-option> }
                  </mat-select>
                </mat-form-field>
                <mat-form-field>
                  <mat-label>{{ t('wasteTypes.channel') }}</mat-label>
                  <mat-select formControlName="treatment_channel">
                    <mat-option value="">—</mat-option>
                    @for (channel of meta()?.treatment_channels ?? []; track channel.code) { <mat-option [value]="channel.code">{{ channel.name | dwLocalized }}</mat-option> }
                  </mat-select>
                </mat-form-field>
                <mat-checkbox formControlName="is_hazardous">{{ t('common.hazardous') }}</mat-checkbox>
                <mat-checkbox formControlName="is_active">{{ t('common.active') }}</mat-checkbox>
              </div>
              <div class="dw-form-actions">
                <button mat-button type="button" (click)="editing.set(false)">{{ t('common.cancel') }}</button>
                <button mat-flat-button type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
              </div>
            </div>
          </form>
        }
        <div class="dw-panel">
          <dw-data-table [columns]="columns" [rows]="rows()" [loading]="loading()" [error]="error()" [trackBy]="track" [clickable]="true" emptyIcon="category" [emptyTitle]="t('common.empty')"
            (rowClick)="edit($event)" (retry)="load()">
            <ng-template dwCell="name" let-i><strong>{{ i.name | dwLocalized }}</strong><div class="dw-faint dw-mono">{{ i.code }}</div></ng-template>
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
  protected readonly track = (item: CatalogItem): string => item.code;
  protected readonly form = inject(FormBuilder).nonNullable.group({
    code: ['', [Validators.required, Validators.pattern(/^[a-z][a-z0-9_]*$/)]], waste_family: ['', Validators.required], name_fr: ['', Validators.required], name_en: [''],
    regulatory_code: [''], treatment_channel: [''], is_hazardous: [false], is_active: [true],
  });
  protected readonly columns: Column<CatalogItem>[] = [
    { key: 'name', header: 'fields.name' },
    { key: 'family', header: 'wasteTypes.family', value: (i) => i.waste_family, hideBelow: 900 },
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
  imports: [FormsModule, MatButtonModule, MatCheckboxModule, MatFormFieldModule, MatSelectModule, TranslocoDirective, PageHeaderComponent, StateComponent, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('platform.roles.title')" [subtitle]="t('platform.roles.subtitle')" />
      <dw-state [loading]="roles().length === 0 && !error()" [error]="error()" (retry)="load()">
        <div class="dw-panel">
          <div class="head">
            <mat-form-field>
              <mat-label>{{ t('fields.role') }}</mat-label>
              <mat-select [ngModel]="selectedName()" (ngModelChange)="select($event)">
                @for (role of roles(); track role.name) { <mat-option [value]="role.name">{{ role.label | dwLocalized: role.name }} — {{ role.audience }}</mat-option> }
              </mat-select>
            </mat-form-field>
            <span class="dw-spacer"></span>
            <button mat-flat-button type="button" [disabled]="busy() || !dirty()" (click)="save()">{{ t('common.save') }}</button>
          </div>
          <div class="modules">
            @for (group of modules(); track group.module) {
              <fieldset>
                <legend>{{ group.module }}</legend>
                @for (permission of group.permissions; track permission.name) {
                  <mat-checkbox [checked]="draft().has(permission.name)" (change)="toggle(permission.name, $event.checked)"><span class="dw-mono">{{ permission.name }}</span></mat-checkbox>
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
    .head mat-form-field { width: 360px; max-width: 100%; }
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
