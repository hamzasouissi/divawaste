import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';

import { Api, openBlob } from '../../core/api/api';
import { DirectoryProvider, LimitResult, ProviderAccreditation } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { LimitText } from '../../core/format/limit-text';
import { CodePipe, DatePipe, LocalizedPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { StateComponent } from '../../ui/state';
import { CheckboxComponent } from '../../ui/checkbox';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';
import { FilterBarComponent } from '../../ui/filter-bar';
import { ExternalPartnersComponent } from './external-partners';
import { HelpComponent } from '../../ui/help';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Dialogs } from '../../ui/dialog';
import { SegmentedComponent } from '../../ui/more';
import { Contract, ContractDialog } from '../contracts/contracts';
import { PartyContracts } from '../contracts/party-contracts';

interface DirectoryFilter {
  search: string;
  channel: string;
  partners_only: boolean;
  hazardous: boolean;
}

/**
 * M4-01 / M4-02: the directory of published providers. The state of each accreditation is shown with its
 * expiry date; a provider that cannot be chosen for a pickup says why, here as in the pickup form.
 */
@Component({
  imports: [
    HelpComponent, RouterLink,
    TranslocoDirective, PageHeaderComponent, StatusComponent, SearchComponent, StateComponent, CodePipe, DatePipe, LocalizedPipe, IconComponent, ButtonDirective, ...SELECT, CheckboxComponent, FilterBarComponent, ExternalPartnersComponent, SegmentedComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('providers.title')" [subtitle]="t('providers.subtitle')">
        <!-- PR-01: my providers (with and without an account) apart from the directory of everyone else. -->
        <dw-segmented [options]="[{ value: 'mine', label: t('providers.tabs.mine') }, { value: 'directory', label: t('providers.tabs.directory') }]"
          [value]="view()" (valueChange)="setView($any($event))" [ariaLabel]="t('providers.title')" />
      </dw-page-header>
      <dw-help topic="providers" />

      @if (view() === 'mine') {
        <dw-external-partners (linked)="load()" (contract)="contract($event)" />
        <h2 class="dw-h2 directory">{{ t('providers.platformPartners') }}</h2>
      }

      <dw-filter-bar class="dw-panel standalone" [active]="activeFilters()" (cleared)="resetFilter()">
        <dw-search [placeholder]="t('common.search')" [query]="filter().search" (queryChange)="patch({ search: $event })" />
        <dw-select [placeholder]="t('providers.channels')" [value]="filter().channel" (valueChange)="patch({ channel: $event })">
          <dw-option value="" [label]="t('common.all')" />
          @for (channel of vocabulary()?.treatment_channels ?? []; track channel.code) { <dw-option [value]="channel.code" [label]="channel.name | dwLocalized" /> }
        </dw-select>
        <dw-checkbox [checked]="filter().hazardous" (checkedChange)="patch({ hazardous: $event })">{{ t('providers.hazardousOnly') }}</dw-checkbox>
      </dw-filter-bar>

      <dw-state [loading]="loading()" [error]="error()" [empty]="rows().length === 0" [keepContent]="rows().length > 0" icon="handshake"
        [emptyTitle]="t('providers.emptyTitle')" [emptyHint]="t('providers.emptyHint')" (retry)="load()">
        <div class="cards">
          @for (provider of shown(); track provider.ulid) {
            <article class="dw-panel card">
              <header>
                <div class="dw-spacer">
                  <h2 class="dw-h2">{{ provider.legal_name }}</h2>
                  <p class="dw-muted">
                    @for (role of provider.roles; track role; let last = $last) { {{ t('providers.role.' + role) }}@if (!last) { · } }
                    @if (provider.city) { — {{ provider.city }} }
                  </p>
                </div>
                @if (provider.partnership?.status === 'active') { <dw-status kind="accreditation" value="approved" [label]="t('providers.partner')" /> }
              </header>

              @if (provider.blocked_reason === 'no_valid_accreditation') {
                <p class="blocked" role="note"><dw-icon aria-hidden="true">block</dw-icon>{{ t('providers.notSelectable') }} — {{ t('pickups.create.notSelectable.no_valid_accreditation') }}</p>
              }
              @if (provider.description) { <p>{{ provider.description }}</p> }

              <dl class="dw-facts">
                @if (provider.service_area) { <dt>{{ t('providers.serviceArea') }}</dt><dd>{{ provider.service_area }}</dd> }
                <dt>{{ t('providers.channels') }}</dt>
                <dd>{{ channels(provider) }}</dd>
                <dt>{{ t('providers.contact') }}</dt>
                <dd>
                  {{ provider.contact.name ?? '—' }}
                  @if (provider.contact.phone) { · <a [href]="'tel:' + provider.contact.phone">{{ provider.contact.phone }}</a> }
                  @if (provider.contact.email) { · <a [href]="'mailto:' + provider.contact.email">{{ provider.contact.email }}</a> }
                </dd>
              </dl>

              @if (asked(provider); as rules) {
                @if (rules.length > 0) {
                  <div>
                    <h3 class="dw-label">{{ t('providers.requirements') }}</h3>
                    <ul class="asked">
                      @for (rule of rules; track $index) {
                        <li>
                          <span class="dw-spacer"><strong>{{ rule.item ? (rule.item | dwCode: 'catalog') : (rule.family | dwCode: 'family') }}</strong>@if (rule.item) { <span class="dw-faint"> ({{ t(rule.adds ? 'requirements.scope.typeAdds' : 'requirements.scope.type') }})</span> } · {{ t('channels.' + rule.channel) }} —
                            @if (rule.minimum) { {{ t('requirements.atLeast', { min: rule.minimum }) }} }
                            @if (rule.minimum && rule.packaging) { ; }
                            @if (rule.packaging) { {{ t('requirements.inPackaging', { packaging: rule.packaging }) }} }
                          </span>
                          <span class="mode" [class.blocking]="rule.blocking">{{ t(rule.blocking ? 'requirements.blocking' : 'requirements.warningOnly') }}</span>
                        </li>
                      }
                    </ul>
                  </div>
                }
              }

              <div>
                <h3 class="dw-label">{{ t('providers.accreditations') }}</h3>
                <ul class="accreditations">
                  @for (accreditation of provider.accreditations; track accreditation.ulid) {
                    <li>
                      <span class="dw-spacer">
                        {{ accreditation.name | dwLocalized }} <span class="dw-faint dw-mono">{{ accreditation.reference_number }}</span>
                        <span class="dw-faint"> — {{ t('providers.expiresOn') }} {{ accreditation.expires_on | dwDate }}</span>
                      </span>
                      <dw-status kind="accreditation" [value]="accreditation.state ?? 'valid'" />
                      <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="file(accreditation)" [attr.aria-label]="t('providers.viewFile')" [title]="t('providers.viewFile')"><dw-icon>description</dw-icon></button>
                    </li>
                  } @empty {
                    <li class="dw-faint">{{ t('common.none') }}</li>
                  }
                </ul>
              </div>

              @if (canManage) {
                <footer>
                  @if (provider.partnership?.status === 'active') {
                    <button pButton text type="button" [disabled]="busy()" (click)="end(provider)">{{ t('providers.endPartner') }}</button>
                    <!-- PR-05: a contract with this partner, its name and role already chosen; one already there is shown instead. -->
                    @if (contracts.byParty().get(provider.ulid); as current) {
                      <a pButton severity="secondary" outlined [routerLink]="['/contracts', current.ulid]"><dw-icon>description</dw-icon>{{ t('providers.viewContract', { number: current.contract_number }) }}</a>
                      @if (session.can('contracts.manage')) {
                        <button pButton text type="button" (click)="contract({ party: 'p:' + provider.ulid, role: provider.roles.includes('transporter') && provider.roles.length === 1 ? 'transporter' : 'receiver' })"><dw-icon>add</dw-icon>{{ t('providers.anotherContract') }}</button>
                      }
                    } @else if (session.uses('contracts') && session.can('contracts.manage')) {
                      <button pButton severity="secondary" outlined type="button" (click)="contract({ party: 'p:' + provider.ulid, role: provider.roles.includes('transporter') && provider.roles.length === 1 ? 'transporter' : 'receiver' })"><dw-icon>handshake</dw-icon>{{ t('providers.makeContract') }}</button>
                    }
                  } @else {
                    <button pButton type="button" [disabled]="busy()" (click)="add(provider)"><dw-icon>add</dw-icon>{{ t('providers.addPartner') }}</button>
                  }
                </footer>
              }
            </article>
          }
        </div>
      </dw-state>
    </ng-container>
  `,
  styles: `
    .directory { margin-block-end: var(--dw-3); }
    .asked { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; font-size: 13px; } .asked li { display: flex; gap: var(--dw-2); align-items: baseline; }
    .mode { font-size: 12px; color: var(--dw-ink-2); white-space: nowrap; } .mode.blocking { font-weight: 600; color: var(--dw-ink); }
    .cards { display: grid; gap: var(--dw-4); grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); align-items: start; }
    .card { display: grid; gap: var(--dw-3); padding: var(--dw-4); }
    .card header { display: flex; align-items: flex-start; gap: var(--dw-3); }
    .blocked { display: flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: var(--dw-radius); background: var(--dw-danger-soft); color: var(--dw-danger); font-size: 12.5px; font-weight: 600; }
    .blocked dw-icon { font-size: 16px; }
    .accreditations { list-style: none; margin: 4px 0 0; padding: 0; display: grid; gap: 2px; font-size: 13px; }
    .accreditations li { display: flex; align-items: center; gap: var(--dw-2); }
    footer { display: flex; justify-content: flex-end; border-block-start: 1px solid var(--dw-line-soft); padding-block-start: var(--dw-3); }
    @media (max-width: 640px) { .cards { grid-template-columns: minmax(0, 1fr); } }
  `,
})
export class ProvidersPage {
  private readonly api = inject(Api);
  protected readonly session = inject(Session);
  private readonly dialog = inject(Dialogs);
  private readonly router = inject(Router);
  protected readonly contracts = inject(PartyContracts);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);
  private readonly limitText = inject(LimitText);
  private readonly code = new CodePipe();

  protected readonly canManage = this.session.can('providers.partnerships.manage');
  protected readonly vocabulary = toSignal(inject(ReferenceData).vocabulary());
  protected readonly view = signal<'mine' | 'directory'>('mine');
  protected readonly filter = signal<DirectoryFilter>({ search: '', channel: '', partners_only: true, hazardous: false });
  protected readonly rows = signal<DirectoryProvider[]>([]);
  /** The directory shows everyone who is not already a partner. */
  protected readonly shown = computed(() => (this.view() === 'directory' ? this.rows().filter((provider) => provider.partnership?.status !== 'active') : this.rows()));
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal<unknown>(null);
  private request = 0;

  constructor() {
    // ?view=directory (DAVI's "choose your providers") opens the directory; the list of partners otherwise
    inject(ActivatedRoute).queryParamMap.pipe(takeUntilDestroyed()).subscribe((params) => {
      const view = params.get('view') === 'directory' ? 'directory' : 'mine';
      if (view !== this.view()) {
        this.view.set(view);
        this.filter.update((filter) => ({ ...filter, partners_only: view === 'mine' }));
      }
      this.load();
    });
    this.contracts.load();
  }

  /** What the provider asks of a collection, per waste and treatment: a minimum with its unit, a packaging. */
  protected asked(provider: DirectoryProvider): { family: string | null; item: string | null; adds: boolean; channel: string; minimum: string | null; packaging: string | null; blocking: boolean }[] {
    return provider.accepted_wastes.filter((rule) => rule.min_quantity !== null || rule.required_packaging_type !== null).map((rule) => ({
      family: rule.family, item: rule.catalog_item, adds: rule.adds_to_family ?? false, channel: rule.channel, blocking: rule.is_blocking,
      minimum: rule.min_quantity === null ? null : this.limitText.quantity({ unit: rule.min_unit ?? 'kg', packaging_type: rule.min_packaging_type ?? undefined } as LimitResult, rule.min_quantity),
      packaging: rule.required_packaging_type ? this.code.transform(rule.required_packaging_type, 'packaging') : null,
    }));
  }

  protected channels(provider: DirectoryProvider): string {
    return provider.channels.map((channel) => this.toast.text(`channels.${channel}`)).join(', ') || '—';
  }

  protected readonly activeFilters = computed(() => [this.filter().search, this.filter().channel, this.filter().hazardous].filter(Boolean).length);

  protected setView(view: 'mine' | 'directory'): void {
    this.view.set(view);
    this.patch({ partners_only: view === 'mine' });
  }

  protected contract(prefill: { party: string; role: 'receiver' | 'transporter' }): void {
    this.dialog.open<ContractDialog, { prefill: typeof prefill }, Contract>(ContractDialog, { data: { prefill } }).afterClosed()
      .subscribe((created) => {
        if (created) {
          this.contracts.load();
          void this.router.navigate(['/contracts', created.ulid]);
        }
      });
  }

  protected resetFilter(): void {
    this.filter.set({ search: '', channel: '', partners_only: this.view() === 'mine', hazardous: false });
    this.load();
  }

  protected patch(change: Partial<DirectoryFilter>): void {
    this.filter.update((filter) => ({ ...filter, ...change }));
    this.load();
  }

  protected load(): void {
    const request = ++this.request;
    const { search, channel, partners_only, hazardous } = this.filter();
    this.loading.set(true);
    this.error.set(null);
    this.api.get<DirectoryProvider[]>('providers/directory', { filter: { search, channel, partners_only: partners_only || null, hazardous: hazardous || null } }).subscribe({
      next: (rows) => {
        // A slower, older answer must not replace a newer one.
        if (request === this.request) {
          this.rows.set(rows);
          this.loading.set(false);
        }
      },
      error: (error: unknown) => {
        if (request === this.request) {
          this.error.set(error);
          this.loading.set(false);
        }
      },
    });
  }

  protected async add(provider: DirectoryProvider): Promise<void> {
    await this.run(() => firstValueFrom(this.api.post('provider-partnerships', { provider: provider.ulid })), 'providers.partnered');
  }

  /** G-09: ended at once; the toast offers to take the partner back. */
  protected async end(provider: DirectoryProvider): Promise<void> {
    const partnership = provider.partnership;
    if (!partnership) {
      return;
    }
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.put(`provider-partnerships/${partnership.ulid}`, { status: 'ended' }));
      this.toast.undoable('providers.unpartnered', () => void this.add(provider));
      this.load();
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected async file(accreditation: ProviderAccreditation): Promise<void> {
    try {
      openBlob(await firstValueFrom(this.api.blob(`providers/accreditations/${accreditation.ulid}/file`)));
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
