import { ChangeDetectionStrategy, Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { Textarea } from 'primeng/textarea';

import { Api } from '../../core/api/api';
import { AuthorizationCheck, DirectoryProvider, ExternalPartner, Lot, Pickup, ProviderRequirements } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { LimitText } from '../../core/format/limit-text';
import { DatePipe, KnownWeightPipe, LotMeasurePipe } from '../../core/format/pipes';
import { LimitsComponent, failingLimits } from '../../ui/limits';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { RequirementsComponent } from '../../ui/requirements';
import { StateComponent } from '../../ui/state';
import { CheckboxComponent } from '../../ui/checkbox';
import { DateComponent } from '../../ui/date';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';

function today(): string {
  const now = new Date();

  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

/**
 * M4-03: a pickup request in one screen — the lots on the left, the receiver, the transporter and the date on the
 * right. The receiver is a provider of the platform or a partner without an account; the transporter is the receiver
 * itself unless another one is chosen.
 * A provider that cannot be chosen (no valid accreditation, not a partner, or not approved for hazardous waste
 * when the selection needs it) is shown with the reason instead of being hidden (M4-02).
 */
@Component({
  imports: [
    FormsModule, RouterLink, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, DatePipe, KnownWeightPipe, LotMeasurePipe, IconComponent, ButtonDirective, ...FIELD, ...SELECT, CheckboxComponent, DateComponent, LimitsComponent, RequirementsComponent, Textarea,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('pickups.create.title')" back="/pickups" [backLabel]="t('pickups.title')" />

      <div class="dw-split">
        <section class="dw-panel">
          <div class="dw-panel-head">
            <h2 class="dw-h2">{{ t('pickups.create.site') }}</h2><span class="dw-spacer"></span>
            <dw-select class="site" [ariaLabel]="t('fields.site')" [placeholder]="t('fields.site')" [ngModel]="site()" (ngModelChange)="selectSite($event)">
              @for (item of sites; track item.ulid) { <dw-option [value]="item.ulid" [label]="item.name" /> }
            </dw-select>
          </div>
          <dw-state [loading]="loadingLots()" [error]="lotsError()" [empty]="lots().length === 0" [emptyTitle]="t('pickups.create.noLots')" icon="inventory_2" (retry)="loadLots()">
            <table class="dw-table pick">
              <thead>
                <tr>
                  <th><dw-checkbox [checked]="allSelected()" [indeterminate]="selected().length > 0 && !allSelected()" (checkedChange)="toggleAll($event)" [ariaLabel]="t('pickups.create.selectLots')" /></th>
                  <th>{{ t('lots.number') }}</th><th>{{ t('fields.wasteType') }}</th><th>{{ t('fields.zone') }}</th><th>{{ t('lots.generatedAt') }}</th><th class="dw-num">{{ t('fields.weight') }}</th>
                </tr>
              </thead>
              <tbody>
                @for (lot of lots(); track lot.ulid) {
                  <tr (click)="toggle(lot.ulid)" [class.on]="selected().includes(lot.ulid)">
                    <td><dw-checkbox [checked]="selected().includes(lot.ulid)" (checkedChange)="toggle(lot.ulid)" (click)="$event.stopPropagation()" [ariaLabel]="lot.lot_number" /></td>
                    <td class="dw-mono">{{ lot.lot_number }} @if (lot.is_hazardous) { <dw-icon class="hazard" [title]="t('common.hazardous')" [attr.aria-label]="t('common.hazardous')">warning</dw-icon> }</td>
                    <td>{{ lot.waste_type?.name }}</td><td>{{ lot.zone?.name }}</td><td>{{ lot.generated_at | dwDate }}</td><td class="dw-num">{{ lot | dwLotMeasure }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </dw-state>
        </section>

        <section class="dw-panel side">
          <div class="dw-panel-head"><h2 class="dw-h2">{{ t('pickups.create.provider') }}</h2></div>
          <div class="dw-panel-body dw-stack-sm">
            <p class="summary" role="status">{{ t('pickups.create.selected', { count: selected().length, kg: (selectedKg() | dwKgKnown: unweighed()) }) }}</p>
            @if (hazardous()) { <p class="warn"><dw-icon aria-hidden="true">warning</dw-icon>{{ t('pickups.create.hazardousWarning') }}</p> }
            @for (item of authorizations(); track item.waste_type) {
              <p class="cover" [class.bad]="item.reason" role="status">
                <dw-icon aria-hidden="true">{{ item.reason ? 'block' : 'verified' }}</dw-icon>
                <span>
                  <strong>{{ item.waste_type }}</strong> —
                  @if (item.reason === 'missing') { {{ t('pickups.create.authorization.missing') }} }
                  @else if (item.reason === 'packaging') { {{ t('pickups.create.authorization.packaging', { reference: item.reference, lots: (item.uncounted ?? []).join(', ') }) }} }
                  @else if (item.reason === 'unverifiable') { {{ t('pickups.create.authorization.unverifiable', { reference: item.reference, lots: (item.unweighed ?? []).join(', ') }) }} }
                  @else if (item.reason) { {{ t('pickups.create.authorization.' + item.reason, { reference: item.reference }) }} }
                  @else { {{ t('pickups.create.authorization.covered', { reference: item.reference }) }} }
                </span>
              </p>
              @if (item.constraints.length > 0) {
                <div class="cover-limits" [class.bad]="item.reason">
                  <dw-limits [limits]="item.constraints" />
                  @if (item.reason) { <p class="result">{{ t('pickups.create.authorization.blocked', { limits: failing(item) }) }}</p> }
                </div>
              }
            }

            <div class="providers" role="radiogroup" [attr.aria-label]="t('pickups.provider')">
              @for (item of providers(); track item.ulid) {
                <button type="button" role="radio" class="provider" [attr.aria-checked]="provider() === 'c:' + item.ulid" [class.on]="provider() === 'c:' + item.ulid" [disabled]="!choosable(item)" (click)="choose('c:' + item.ulid)">
                  <span class="name">{{ item.legal_name }}</span>
                  <span class="dw-faint">{{ item.city }} · {{ item.channels.length > 0 ? channelNames(item, t) : '—' }}</span>
                  @if (!choosable(item)) {
                    <dw-status kind="accreditation" value="expired" [label]="item.blocked_reason ? t('pickups.create.notSelectable.' + item.blocked_reason) : t('providers.hazardousOnly')" />
                  } @else if (item.accreditations[0]?.state === 'expiring') {
                    <dw-status kind="accreditation" value="expiring" [label]="t('providers.daysLeft', { days: item.accreditations[0].days_left })" />
                  }
                </button>
              }
              @for (item of receivers(); track item.ulid) {
                <button type="button" role="radio" class="provider" [attr.aria-checked]="provider() === 'x:' + item.ulid" [class.on]="provider() === 'x:' + item.ulid" [disabled]="blocked(item) !== null" (click)="choose('x:' + item.ulid)">
                  <span class="name">{{ item.legal_name }} <dw-status kind="member" value="suspended" tone="neutral" [label]="t('partners.noAccount')" /></span>
                  <span class="dw-faint">{{ item.city }} · @for (role of item.roles; track role; let last = $last) { {{ t('providers.role.' + role) }}@if (!last) { , } }</span>
                  @if (blocked(item); as reason) { <dw-status kind="accreditation" value="expired" [label]="t('pickups.create.notSelectable.' + reason)" /> }
                </button>
              }
              @if (providers().length === 0 && receivers().length === 0) {
                <p class="dw-muted">{{ t('pickups.create.noReceiver') }} <a routerLink="/providers">{{ t('nav.providers') }}</a></p>
              }
            </div>

            @if (requirements(); as asked) {
              @if (asked.constraints.length > 0) {
                <div class="asked" [class.bad]="asked.blocked" [class.fine]="asked.outcome === 'respected'" role="status">
                  <p class="asked-title"><dw-icon aria-hidden="true">{{ asked.blocked ? 'block' : asked.outcome === 'respected' ? 'verified' : 'warning' }}</dw-icon>{{ t('requirements.title', { provider: receiverName() }) }}</p>
                  <dw-requirements [requirements]="asked.constraints" />
                  <p class="verdict">{{ t(asked.blocked ? (asked.may_override ? (withoutAccount() ? 'requirements.blockedOverridableInternal' : 'requirements.blockedOverridable') : 'requirements.blockedNoOverride') : asked.outcome === 'respected' ? 'requirements.allMet' : withoutAccount() ? 'requirements.canProceedInternal' : 'requirements.canProceed') }}</p>
                  @if (asked.blocked && asked.may_override) {
                    <dw-field [label]="t('requirements.overrideReason')">
                      <textarea pTextarea rows="2" maxlength="1000" [ngModel]="overrideReason()" (ngModelChange)="overrideReason.set($event)"></textarea>
                      <small dwHint>{{ t('requirements.overrideHint') }}</small>
                    </dw-field>
                  }
                </div>
              }
            }

            <dw-field [label]="t('pickups.transporter')">
              <dw-select [ngModel]="transporter()" (ngModelChange)="transporter.set($event)">
                <dw-option value="" [label]="t('pickups.receiverTransports')" />
                @for (item of transporters(); track item.key) { <dw-option [value]="item.key" [label]="item.label" /> }
              </dw-select>
              @if (transporterRequired()) { <small dwError>{{ t('pickups.create.transporterRequired') }}</small> }
            </dw-field>

            <dw-field [label]="t('pickups.plannedChannel')">
              <dw-select [ngModel]="channel()" (ngModelChange)="channel.set($event)">
                <dw-option value="" [label]="t('pickups.plannedChannelDefault')" />
                @for (code of channels; track code) { <dw-option [value]="code" [label]="t('channels.' + code)" /> }
              </dw-select>
              <small dwHint>{{ t('pickups.plannedChannelHint') }}</small>
            </dw-field>

            <dw-field [label]="t('pickups.requestedDate')">
              <dw-date [ngModel]="date()" (ngModelChange)="date.set($event)" [min]="minDate" required />
            </dw-field>

            <div class="dw-form-actions">
              <button pButton severity="secondary" outlined type="button" [disabled]="!ready() || busy()" (click)="save(false)">{{ t('pickups.create.saveDraft') }}</button>
              <button pButton type="button" [disabled]="!sendable() || busy()" (click)="save(true)"><dw-icon>send</dw-icon>{{ t('pickups.create.send') }}</button>
            </div>
          </div>
        </section>
      </div>
    </ng-container>
  `,
  styles: `
    .site { width: 220px; }
    .dw-form-actions { flex-wrap: wrap; } .dw-form-actions > * { flex: 1 1 auto; }
    .pick tbody tr { cursor: pointer; } .pick tbody tr:hover td { background: var(--dw-surface-2); } .pick tr.on td, .pick tr.on:hover td { background: var(--dw-primary-soft); }
    .pick th:first-child, .pick td:first-child { width: 40px; padding-inline-end: 0; }
    .hazard { font-size: 15px; width: 15px; height: 15px; color: var(--dw-warning); vertical-align: -2px; }
    .side { position: sticky; inset-block-start: calc(var(--dw-shell-bar) + var(--dw-4)); }
    .summary { font-weight: 600; }
    .warn { display: flex; gap: 8px; padding: 8px 10px; border-radius: var(--dw-radius); background: var(--dw-warning-soft); color: var(--dw-warning); font-size: 13px; }
    .warn dw-icon { flex: none; font-size: 18px; }
    .cover { display: flex; gap: 8px; padding: 8px 10px; border-radius: var(--dw-radius); background: var(--dw-success-soft); color: var(--dw-success); font-size: 13px; }
    .cover.bad { background: var(--dw-danger-soft); color: var(--dw-danger); } .cover dw-icon { flex: none; font-size: 18px; }
    .cover-limits { margin: calc(var(--dw-2) * -1) 0 0; padding: 8px 10px 8px 36px; border-inline-start: 3px solid var(--dw-success); font-size: 13px; }
    .cover-limits.bad { border-color: var(--dw-danger); } .result { margin: var(--dw-2) 0 0; font-weight: 600; color: var(--dw-danger); }
    .asked { display: grid; gap: var(--dw-2); padding: 10px 12px; border-radius: var(--dw-radius); border-inline-start: 3px solid var(--dw-warning); background: var(--dw-warning-soft); }
    .asked.bad { border-color: var(--dw-danger); background: var(--dw-danger-soft); } .asked.fine { border-color: var(--dw-success); background: var(--dw-success-soft); }
    .asked-title { display: flex; gap: 8px; margin: 0; font-weight: 600; font-size: 13px; } .asked-title dw-icon { flex: none; font-size: 18px; } .verdict { margin: 0; font-size: 13px; font-weight: 600; }
    .providers { display: grid; gap: 8px; margin-block: var(--dw-2); }
    .provider { all: unset; cursor: pointer; display: grid; gap: 2px; padding: 10px 12px; border: 1px solid var(--dw-line); border-radius: var(--dw-radius); }
    .provider .name { font-weight: 600; } .provider.on { border-color: var(--dw-primary); background: var(--dw-primary-soft); }
    .provider:disabled { cursor: not-allowed; opacity: 0.7; } .provider:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: 2px; }
  `,
})
export class PickupCreatePage {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly router = inject(Router);

  protected readonly sites = inject(Session).sitesFor('pickups.request');
  protected readonly minDate = today();
  protected readonly site = signal(this.sites[0]?.ulid ?? '');
  protected readonly lots = signal<Lot[]>([]);
  protected readonly loadingLots = signal(false);
  protected readonly lotsError = signal<unknown>(null);
  protected readonly selected = signal<string[]>([]);
  protected readonly providers = signal<DirectoryProvider[]>([]);
  private readonly externals = signal<ExternalPartner[]>([]);
  /** Partners without an account that may receive waste. */
  protected readonly receivers = computed(() => this.externals().filter((partner) => partner.roles.some((role) => role !== 'transporter')));
  /** For the waste types that only leave under an authorisation: what covers the selection on the chosen date. */
  protected readonly authorizations = signal<AuthorizationCheck[]>([]);
  private readonly limitText = inject(LimitText);
  private check = 0;
  /** What the chosen provider asks of a collection of the selected lots, each requirement answered on its own. */
  protected readonly requirements = signal<ProviderRequirements | null>(null);
  /** Why the request is sent although a blocking requirement fails; asked only from someone entitled to decide it. */
  protected readonly overrideReason = signal('');
  private asked = 0;
  /** The chosen receiver: `c:` + a provider of the platform, or `x:` + a partner without an account. */
  protected readonly provider = signal('');
  /** The chosen transporter, written the same way; empty = the receiver transports. */
  protected readonly transporter = signal('');
  protected readonly date = signal(today());
  /** What the receiver is to do with the waste, when it is not what each waste type plans: it selects the tolerance at reception. */
  protected readonly channel = signal('');
  protected readonly channels = ['reuse', 'recycling', 'energy_recovery', 'incineration', 'other_treatment', 'landfill'];
  protected readonly busy = signal(false);

  private readonly chosen = computed(() => this.lots().filter((lot) => this.selected().includes(lot.ulid)));
  protected readonly selectedKg = computed(() => this.chosen().reduce((sum, lot) => sum + Number(lot.net_weight_kg ?? 0), 0));
  /** Selected lots nobody weighed: said beside the total, never added to it as zeros. */
  protected readonly unweighed = computed(() => this.chosen().filter((lot) => lot.net_weight_kg === null).length);
  protected readonly hazardous = computed(() => this.chosen().some((lot) => lot.is_hazardous));
  protected readonly allSelected = computed(() => this.lots().length > 0 && this.selected().length === this.lots().length);
  /** Whoever may carry the waste, other than the receiver already chosen. */
  protected readonly transporters = computed(() => [
    ...this.providers().filter((item) => item.roles.includes('transporter') && this.choosable(item)).map((item) => ({ key: `c:${item.ulid}`, label: item.legal_name })),
    ...this.externals().filter((item) => item.roles.includes('transporter') && this.blocked(item, false) === null)
      .map((item) => ({ key: `x:${item.ulid}`, label: `${item.legal_name} — ${this.toast.text('partners.noAccount')}` })),
  ].filter((item) => item.key !== this.provider()));
  /** A partner that only recycles or eliminates cannot come and fetch the waste. */
  protected readonly transporterRequired = computed(() => {
    const receiver = this.externals().find((item) => `x:${item.ulid}` === this.provider());

    return receiver !== undefined && !receiver.roles.includes('transporter') && this.transporter() === '';
  });
  protected readonly ready = computed(() => {
    const provider = this.providers().find((item) => `c:${item.ulid}` === this.provider());
    const partner = this.receivers().find((item) => `x:${item.ulid}` === this.provider());
    const receiver = provider !== undefined ? this.choosable(provider) : partner !== undefined && this.blocked(partner) === null;

    return this.selected().length > 0 && receiver && !this.transporterRequired() && this.date() >= this.minDate && !this.authorizations().some((item) => item.reason !== null);
  });

  /** The request may be saved as a draft whatever the provider asks; it is sent only if nothing blocks, or with a reason. */
  protected readonly sendable = computed(() => {
    const asked = this.requirements();

    return this.ready() && (!asked?.blocked || (asked.may_override === true && this.overrideReason().trim() !== ''));
  });

  constructor() {
    this.loadLots();
    this.api.get<DirectoryProvider[]>('providers/directory', { filter: { partners_only: true } }).subscribe({ next: (providers) => this.providers.set(providers), error: (error: unknown) => this.toast.error(error) });
    // A partner that has opened an account is chosen among the providers of the platform from then on.
    this.api.get<ExternalPartner[]>('external-partners').subscribe({ next: (partners) => this.externals.set(partners.filter((partner) => !partner.linked_company)), error: (error: unknown) => this.toast.error(error) });
    // The API refuses a request that no authorisation covers; asking first lets the form say so before sending.
    effect(() => {
      const lots = this.selected();
      const date = this.date();
      const request = ++this.check;
      if (lots.length === 0 || !date) {
        untracked(() => this.authorizations.set([]));

        return;
      }
      this.api.post<AuthorizationCheck[]>('waste-authorizations/check', { lots, date }).subscribe({
        next: (items) => request === this.check && this.authorizations.set(items),
        error: () => request === this.check && this.authorizations.set([]),
      });
    });
  }

  /** The receiver chosen is a partner without an account: nobody on its side reads the request on the platform. */
  protected withoutAccount(): boolean {
    return this.provider().startsWith('x:');
  }

  protected receiverName(): string {
    return this.providers().find((item) => `c:${item.ulid}` === this.provider())?.legal_name ?? this.receivers().find((item) => `x:${item.ulid}` === this.provider())?.legal_name ?? '';
  }

  /** Asked again whenever the lots, the provider or the treatment change: the answer is about the whole collection. */
  private readonly askRequirements = effect(() => {
    const lots = this.selected();
    const provider = this.provider();
    const channel = this.channel();
    const request = ++this.asked;
    if (lots.length === 0 || !provider) {
      untracked(() => this.requirements.set(null));

      return;
    }
    // A provider of the platform says what it asks itself; for a partner without an account, it is what the company wrote down.
    this.api.post<ProviderRequirements>('provider-requirements/check', { [provider.startsWith('c:') ? 'provider' : 'external_provider']: provider.slice(2), lots, treatment_channel: channel || null }).subscribe({
      next: (result) => request === this.asked && this.requirements.set(result),
      error: () => request === this.asked && this.requirements.set(null),
    });
  });

  /** The limits that refuse the request, by name: "Colis, Poids". */
  protected failing(item: AuthorizationCheck): string {
    return failingLimits(item.constraints).map((limit) => this.limitText.name(limit)).join(', ');
  }

  protected choose(key: string): void {
    this.provider.set(key);
    if (this.transporter() === key) {
      this.transporter.set('');
    }
  }

  /**
   * Why a partner without an account cannot be chosen, or null. Nobody reviews its authorisation on the platform:
   * for hazardous waste the company must at least have recorded one, valid today.
   */
  protected blocked(partner: ExternalPartner, asReceiver = true): 'no_valid_accreditation' | 'not_a_receiver' | null {
    if (asReceiver && !partner.roles.some((role) => role !== 'transporter')) {
      return 'not_a_receiver';
    }
    const expired = partner.accreditation_expires_on !== null && partner.accreditation_expires_on < this.minDate;

    return expired || (this.hazardous() && !(partner.accreditation_reference && partner.covers_hazardous)) ? 'no_valid_accreditation' : null;
  }

  protected selectSite(site: string): void {
    this.site.set(site);
    this.selected.set([]);
    this.loadLots();
  }

  /** Lots still on the site and not yet promised to another pickup. */
  protected loadLots(): void {
    if (!this.site()) {
      return;
    }
    this.loadingLots.set(true);
    this.lotsError.set(null);
    this.api.list<Lot>('lots', { filter: { site: this.site(), status: ['created', 'stored'] }, sort: 'generated_at', per_page: 100 }).subscribe({
      next: (page) => {
        this.lots.set(page.data);
        this.loadingLots.set(false);
      },
      error: (error: unknown) => {
        this.lotsError.set(error);
        this.loadingLots.set(false);
      },
    });
  }

  protected toggle(ulid: string): void {
    this.selected.update((items) => (items.includes(ulid) ? items.filter((item) => item !== ulid) : [...items, ulid]));
  }

  protected toggleAll(checked: boolean): void {
    this.selected.set(checked ? this.lots().map((lot) => lot.ulid) : []);
  }

  protected choosable(provider: DirectoryProvider): boolean {
    return provider.selectable && (!this.hazardous() || provider.accepts_hazardous);
  }

  protected channelNames(provider: DirectoryProvider, t: (key: string) => string): string {
    return provider.channels.map((channel) => t(`channels.${channel}`)).join(', ');
  }

  protected async save(send: boolean): Promise<void> {
    this.busy.set(true);
    try {
      const party = (key: string, kind: 'c' | 'x'): string | null => (key.startsWith(`${kind}:`) ? key.slice(2) : null);
      const pickup = await firstValueFrom(this.api.post<Pickup>('pickups', {
        site: this.site(), requested_date: this.date(), lots: this.selected(),
        provider: party(this.provider(), 'c'), external_provider: party(this.provider(), 'x'),
        transporter: party(this.transporter(), 'c'), external_transporter: party(this.transporter(), 'x'),
        treatment_channel: this.channel() || null,
      }));
      if (send) {
        // If sending fails (provider no longer eligible…), the draft exists: the user lands on it with the reason.
        const reason = this.requirements()?.blocked ? this.overrideReason().trim() : '';
        await firstValueFrom(this.api.post(`pickups/${pickup.ulid}/submit`, reason ? { requirement_override_reason: reason } : {})).then(() => this.toast.success('pickups.done.submitted'), (error: unknown) => this.toast.error(error));
      }
      await this.router.navigate(['/pickups', pickup.ulid]);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
