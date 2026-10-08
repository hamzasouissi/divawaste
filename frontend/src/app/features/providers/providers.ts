import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';

import { Api, openBlob } from '../../core/api/api';
import { DirectoryProvider, ProviderAccreditation } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, LocalizedPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { StateComponent } from '../../ui/state';
import { CheckboxComponent } from '../../ui/checkbox';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';
import { FilterBarComponent } from '../../ui/filter-bar';
import { ExternalPartnersComponent } from './external-partners';

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
    TranslocoDirective, PageHeaderComponent, StatusComponent, SearchComponent, StateComponent, DatePipe, LocalizedPipe, IconComponent, ButtonDirective, ...SELECT, CheckboxComponent, FilterBarComponent, ExternalPartnersComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('providers.title')" [subtitle]="t('providers.subtitle')" />

      <dw-external-partners />
      <h2 class="dw-h2 directory">{{ t('providers.directory') }}</h2>

      <dw-filter-bar class="dw-panel standalone" [active]="activeFilters()" (cleared)="resetFilter()">
        <dw-search [placeholder]="t('common.search')" [query]="filter().search" (queryChange)="patch({ search: $event })" />
        <dw-select [placeholder]="t('providers.channels')" [value]="filter().channel" (valueChange)="patch({ channel: $event })">
          <dw-option value="" [label]="t('common.all')" />
          @for (channel of vocabulary()?.treatment_channels ?? []; track channel.code) { <dw-option [value]="channel.code" [label]="channel.name | dwLocalized" /> }
        </dw-select>
        <dw-checkbox [checked]="filter().partners_only" (checkedChange)="patch({ partners_only: $event })">{{ t('providers.partnersOnly') }}</dw-checkbox>
        <dw-checkbox [checked]="filter().hazardous" (checkedChange)="patch({ hazardous: $event })">{{ t('providers.hazardousOnly') }}</dw-checkbox>
      </dw-filter-bar>

      <dw-state [loading]="loading()" [error]="error()" [empty]="rows().length === 0" [keepContent]="rows().length > 0" icon="handshake"
        [emptyTitle]="t('providers.emptyTitle')" [emptyHint]="t('providers.emptyHint')" (retry)="load()">
        <div class="cards">
          @for (provider of rows(); track provider.ulid) {
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
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);

  protected readonly canManage = this.session.can('providers.partnerships.manage');
  protected readonly vocabulary = toSignal(inject(ReferenceData).vocabulary());
  protected readonly filter = signal<DirectoryFilter>({ search: '', channel: '', partners_only: false, hazardous: false });
  protected readonly rows = signal<DirectoryProvider[]>([]);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal<unknown>(null);
  private request = 0;

  constructor() {
    this.load();
  }

  protected channels(provider: DirectoryProvider): string {
    return provider.channels.map((channel) => this.toast.text(`channels.${channel}`)).join(', ') || '—';
  }

  protected readonly activeFilters = computed(() => Object.values(this.filter()).filter(Boolean).length);

  protected resetFilter(): void {
    this.filter.set({ search: '', channel: '', partners_only: false, hazardous: false });
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

  protected async end(provider: DirectoryProvider): Promise<void> {
    const partnership = provider.partnership;
    if (partnership && (await this.confirm.ask({ title: `${this.toast.text('providers.endPartner')} — ${provider.legal_name}`, danger: true }))) {
      await this.run(() => firstValueFrom(this.api.put(`provider-partnerships/${partnership.ulid}`, { status: 'ended' })), 'providers.unpartnered');
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
