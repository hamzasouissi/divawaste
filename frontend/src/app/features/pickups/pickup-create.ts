import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';

import { Api } from '../../core/api/api';
import { DirectoryProvider, Lot, Pickup } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, KgPipe } from '../../core/format/pipes';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
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
 * M4-03: a pickup request in one screen — the lots on the left, the provider and the date on the right.
 * A provider that cannot be chosen (no valid accreditation, not a partner, or not approved for hazardous waste
 * when the selection needs it) is shown with the reason instead of being hidden (M4-02).
 */
@Component({
  imports: [
    FormsModule, RouterLink, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, DatePipe, KgPipe, IconComponent, ButtonDirective, ...FIELD, ...SELECT, CheckboxComponent, DateComponent,
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
                    <td>{{ lot.waste_type?.name }}</td><td>{{ lot.zone?.name }}</td><td>{{ lot.generated_at | dwDate }}</td><td class="dw-num">{{ lot.net_weight_kg | dwKg }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </dw-state>
        </section>

        <section class="dw-panel side">
          <div class="dw-panel-head"><h2 class="dw-h2">{{ t('pickups.create.provider') }}</h2></div>
          <div class="dw-panel-body dw-stack-sm">
            <p class="summary" role="status">{{ t('pickups.create.selected', { count: selected().length, kg: (selectedKg() | dwKg) }) }}</p>
            @if (hazardous()) { <p class="warn"><dw-icon aria-hidden="true">warning</dw-icon>{{ t('pickups.create.hazardousWarning') }}</p> }

            <div class="providers" role="radiogroup" [attr.aria-label]="t('pickups.provider')">
              @for (item of providers(); track item.ulid) {
                <button type="button" role="radio" class="provider" [attr.aria-checked]="provider() === item.ulid" [class.on]="provider() === item.ulid" [disabled]="!choosable(item)" (click)="provider.set(item.ulid)">
                  <span class="name">{{ item.legal_name }}</span>
                  <span class="dw-faint">{{ item.city }} · {{ item.channels.length > 0 ? channelNames(item, t) : '—' }}</span>
                  @if (!choosable(item)) {
                    <dw-status kind="accreditation" value="expired" [label]="item.blocked_reason ? t('pickups.create.notSelectable.' + item.blocked_reason) : t('providers.hazardousOnly')" />
                  } @else if (item.accreditations[0]?.state === 'expiring') {
                    <dw-status kind="accreditation" value="expiring" [label]="t('providers.daysLeft', { days: item.accreditations[0].days_left })" />
                  }
                </button>
              } @empty {
                <p class="dw-muted">{{ t('providers.emptyHint') }} <a routerLink="/providers">{{ t('nav.providers') }}</a></p>
              }
            </div>

            <dw-field [label]="t('pickups.requestedDate')">
              <dw-date [ngModel]="date()" (ngModelChange)="date.set($event)" [min]="minDate" required />
            </dw-field>

            <div class="dw-form-actions">
              <button pButton severity="secondary" outlined type="button" [disabled]="!ready() || busy()" (click)="save(false)">{{ t('pickups.create.saveDraft') }}</button>
              <button pButton type="button" [disabled]="!ready() || busy()" (click)="save(true)"><dw-icon>send</dw-icon>{{ t('pickups.create.send') }}</button>
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
  protected readonly provider = signal('');
  protected readonly date = signal(today());
  protected readonly busy = signal(false);

  private readonly chosen = computed(() => this.lots().filter((lot) => this.selected().includes(lot.ulid)));
  protected readonly selectedKg = computed(() => this.chosen().reduce((sum, lot) => sum + Number(lot.net_weight_kg), 0));
  protected readonly hazardous = computed(() => this.chosen().some((lot) => lot.is_hazardous));
  protected readonly allSelected = computed(() => this.lots().length > 0 && this.selected().length === this.lots().length);
  protected readonly ready = computed(() => {
    const provider = this.providers().find((item) => item.ulid === this.provider());

    return this.selected().length > 0 && provider !== undefined && this.choosable(provider) && this.date() >= this.minDate;
  });

  constructor() {
    this.loadLots();
    this.api.get<DirectoryProvider[]>('providers/directory', { filter: { partners_only: true } }).subscribe({ next: (providers) => this.providers.set(providers), error: (error: unknown) => this.toast.error(error) });
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
      const pickup = await firstValueFrom(this.api.post<Pickup>('pickups', { site: this.site(), provider: this.provider(), requested_date: this.date(), lots: this.selected() }));
      if (send) {
        // If sending fails (provider no longer eligible…), the draft exists: the user lands on it with the reason.
        await firstValueFrom(this.api.post(`pickups/${pickup.ulid}/submit`)).then(() => this.toast.success('pickups.done.submitted'), (error: unknown) => this.toast.error(error));
      }
      await this.router.navigate(['/pickups', pickup.ulid]);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
