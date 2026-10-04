import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { Router, RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';

import { Api } from '../../core/api/api';
import { DirectoryProvider, Lot, Pickup } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, KgPipe } from '../../core/format/pipes';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { StateComponent } from '../../ui/state';

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
  imports: [FormsModule, RouterLink, MatButtonModule, MatCheckboxModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, TranslocoDirective, PageHeaderComponent, StatusComponent,
    StateComponent, DatePipe, KgPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('pickups.create.title')" back="/pickups" [backLabel]="t('pickups.title')" />

      <div class="dw-split">
        <section class="dw-panel">
          <div class="dw-panel-head">
            <h2 class="dw-h2">{{ t('pickups.create.site') }}</h2><span class="dw-spacer"></span>
            <mat-form-field class="site">
              <mat-label>{{ t('fields.site') }}</mat-label>
              <mat-select [ngModel]="site()" (ngModelChange)="selectSite($event)">
                @for (item of sites; track item.ulid) { <mat-option [value]="item.ulid">{{ item.name }}</mat-option> }
              </mat-select>
            </mat-form-field>
          </div>
          <dw-state [loading]="loadingLots()" [error]="lotsError()" [empty]="lots().length === 0" [emptyTitle]="t('pickups.create.noLots')" icon="inventory_2" (retry)="loadLots()">
            <table>
              <thead>
                <tr>
                  <th><mat-checkbox [checked]="allSelected()" [indeterminate]="selected().length > 0 && !allSelected()" (change)="toggleAll($event.checked)" [attr.aria-label]="t('pickups.create.selectLots')" /></th>
                  <th>{{ t('lots.number') }}</th><th>{{ t('fields.wasteType') }}</th><th>{{ t('fields.zone') }}</th><th>{{ t('lots.generatedAt') }}</th><th class="dw-num">{{ t('fields.weight') }}</th>
                </tr>
              </thead>
              <tbody>
                @for (lot of lots(); track lot.ulid) {
                  <tr (click)="toggle(lot.ulid)" [class.on]="selected().includes(lot.ulid)">
                    <td><mat-checkbox [checked]="selected().includes(lot.ulid)" (change)="toggle(lot.ulid)" (click)="$event.stopPropagation()" [attr.aria-label]="lot.lot_number" /></td>
                    <td class="dw-mono">{{ lot.lot_number }} @if (lot.is_hazardous) { <mat-icon class="hazard" [title]="t('common.hazardous')" [attr.aria-label]="t('common.hazardous')">warning</mat-icon> }</td>
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
            @if (hazardous()) { <p class="warn"><mat-icon aria-hidden="true">warning</mat-icon>{{ t('pickups.create.hazardousWarning') }}</p> }

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

            <mat-form-field>
              <mat-label>{{ t('pickups.requestedDate') }}</mat-label>
              <input matInput type="date" [ngModel]="date()" (ngModelChange)="date.set($event)" [min]="minDate" required />
            </mat-form-field>

            <div class="dw-form-actions">
              <button mat-stroked-button type="button" [disabled]="!ready() || busy()" (click)="save(false)">{{ t('pickups.create.saveDraft') }}</button>
              <button mat-flat-button type="button" [disabled]="!ready() || busy()" (click)="save(true)"><mat-icon>send</mat-icon>{{ t('pickups.create.send') }}</button>
            </div>
          </div>
        </section>
      </div>
    </ng-container>
  `,
  styles: `
    .site { width: 240px; margin-block: 4px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { text-align: start; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dw-ink-3); padding: 4px 10px; border-block-end: 1px solid var(--dw-line); }
    td { padding: 2px 10px; border-block-end: 1px solid var(--dw-line-soft); } tbody tr { cursor: pointer; } tr.on td { background: var(--dw-primary-soft); }
    .hazard { font-size: 15px; width: 15px; height: 15px; color: var(--dw-warning); vertical-align: -2px; }
    .side { position: sticky; inset-block-start: calc(var(--dw-shell-bar) + var(--dw-4)); }
    .summary { font-weight: 600; }
    .warn { display: flex; gap: 8px; padding: 8px 10px; border-radius: var(--dw-radius); background: var(--dw-warning-soft); color: var(--dw-warning); font-size: 13px; }
    .warn mat-icon { flex: none; font-size: 18px; width: 18px; height: 18px; }
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
