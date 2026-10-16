import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of } from 'rxjs';
import { Router, RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';

import { Api } from '../../core/api/api';
import { Pickup, PickupStatus } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { DatePipe, KgPipe, KnownWeightPipe } from '../../core/format/pipes';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { CheckboxComponent } from '../../ui/checkbox';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';
import { FilterBarComponent } from '../../ui/filter-bar';
import { HelpComponent } from '../../ui/help';
import { ToleranceSummary } from './tolerance';

interface PickupFilter {
  status?: PickupStatus[];
  site?: string | null;
  variance_flagged?: boolean | null;
  /** Pickups whose waste left and whose slips are not all signed, closed or not. */
  documents_outstanding?: boolean | null;
  search?: string;
}

/**
 * Pickups, seen from either side. An industrial sees its requests by site and provider; a provider sees the
 * requests addressed to it by client. Same list, same statuses: both parties talk about the same object.
 */
@Component({
  imports: [
    HelpComponent, ToleranceSummary,
    RouterLink, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, SearchComponent, DatePipe, KgPipe, KnownWeightPipe, IconComponent, ButtonDirective, ...SELECT, CheckboxComponent, FilterBarComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t(provider ? 'provider.pickups.title' : 'pickups.title')" [subtitle]="t(provider ? 'provider.pickups.subtitle' : 'pickups.subtitle')">
        @if (!provider && canRequest) { <a pButton routerLink="/pickups/new"><dw-icon>add</dw-icon>{{ t('pickups.new') }}</a> }
      </dw-page-header>
      <dw-help topic="pickups" />

      <div class="dw-panel">
        <dw-filter-bar [active]="list.activeCount()" (cleared)="list.resetFilter()">
          <dw-search [placeholder]="t('pickups.number')" [query]="list.filter().search ?? ''" (queryChange)="list.setFilter({ search: $event })" />
          <dw-select [placeholder]="t('fields.status')" multiple [value]="list.filter().status ?? []" (valueChange)="list.setFilter({ status: $event })">
            @for (status of statuses; track status) { <dw-option [value]="status" [label]="t('status.pickup.' + status)" /> }
          </dw-select>
          @if (!provider && sites.length > 1) {
            <dw-select [placeholder]="t('fields.site')" [value]="list.filter().site ?? ''" (valueChange)="list.setFilter({ site: $event || null })">
              <dw-option value="" [label]="t('common.allSites')" />
              @for (site of sites; track site.ulid) { <dw-option [value]="site.ulid" [label]="site.name" /> }
            </dw-select>
          }
          <dw-checkbox [checked]="list.filter().variance_flagged === true" (checkedChange)="list.setFilter({ variance_flagged: $event ? true : null })">{{ t('pickups.varianceOnly') }}</dw-checkbox>
          <dw-checkbox [checked]="list.filter().documents_outstanding === true" (checkedChange)="list.setFilter({ documents_outstanding: $event ? true : null })">{{ t('pickups.compliance.outstandingOnly') }}</dw-checkbox>
        </dw-filter-bar>

        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1"
          [pageSize]="list.perPage()" [sort]="list.sort()" [clickable]="true" emptyIcon="local_shipping"
          [emptyTitle]="list.filtered() ? t('common.noResult') : t(provider ? 'provider.pickups.emptyTitle' : 'pickups.emptyTitle')"
          [emptyHint]="list.filtered() ? '' : t(provider ? 'provider.pickups.emptyHint' : 'pickups.emptyHint')"
          (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (sortChange)="list.setSort($event)" (rowClick)="open($event)" (retry)="list.reload()">
          <ng-template dwCell="number" let-pickup><span class="dw-mono">{{ pickup.pickup_number }}</span></ng-template>
          <ng-template dwCell="party" let-pickup>{{ provider ? pickup.company?.legal_name : pickup.provider?.legal_name }}</ng-template>
          <ng-template dwCell="site" let-pickup>{{ pickup.site?.name }}@if (provider && pickup.site?.city) { <span class="dw-faint"> · {{ pickup.site.city }}</span> }</ng-template>
          <ng-template dwCell="date" let-pickup>{{ pickup.confirmed_date ?? pickup.requested_date | dwDate }}</ng-template>
          <ng-template dwCell="lots" let-pickup>{{ pickup.lots_count }}</ng-template>
          <ng-template dwCell="weight" let-pickup>
            @if (pickup.received_at) { {{ pickup.received_weight_kg | dwKgKnown: pickup.received_unweighed_lots }} }
            @else if (pickup.collected_at) { {{ pickup.departure_weight_kg | dwKgKnown: pickup.departure_unweighed_lots }} }
            @else { {{ pickup.declared_weight_kg | dwKg }} }
          </ng-template>
          <ng-template dwCell="status" let-pickup>
            <dw-status kind="pickup" [value]="pickup.status" />
            @if (pickup.awaiting === 'transporter') { <div class="dw-faint">{{ t('pickups.waitingTransporter') }}</div> }
            @if (pickup.variance_flagged && !pickup.variance_acknowledged) { <dw-icon class="flag" [title]="t('pickups.varianceFlagged')" [attr.aria-label]="t('pickups.varianceFlagged')">scale</dw-icon> }
          </ng-template>
          <!-- Closed and documented are two things: the slips of a closed pickup may still wait for a signature. -->
          <ng-template dwCell="documents" let-pickup>
            @if (pickup.compliance_state && pickup.compliance_state !== 'not_due' && pickup.compliance_state !== 'not_applicable') { <dw-status kind="compliance" [value]="pickup.compliance_state" /> } @else { <span class="dw-faint">—</span> }
          </ng-template>
        </dw-data-table>
      </div>
      @if (!provider) { <dw-tolerance-summary [rows]="summary()" /> }
    </ng-container>
  `,
  styles: `
    .flag { font-size: 16px; width: 16px; height: 16px; color: var(--dw-danger); vertical-align: -3px; margin-inline-start: 6px; }
  `,
})
export class PickupsPage {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly router = inject(Router);

  protected readonly provider = this.session.audience() === 'provider';
  protected readonly sites = this.session.sitesFor('pickups.view');
  protected readonly canRequest = this.session.can('pickups.request');
  // A provider never receives drafts, so it is not offered that filter.
  protected readonly statuses: PickupStatus[] = [...(this.provider ? [] : (['draft'] as PickupStatus[])), 'requested', 'confirmed', 'collected', 'received', 'completed', 'refused', 'cancelled'];
  protected readonly list = new ListStore<Pickup, PickupFilter>((query) => this.api.list<Pickup>('pickups', query));

  /** Receptions judged against a tolerance, per waste type. Shown under the list when there are any; a failure to load it never hides the list. */
  protected readonly summary = toSignal(
    this.provider ? of([]) : this.api.get<{ waste_type: { ulid: string; code: string; name: string }; unit: string; received: number; within: number; warning: number; blocking: number; no_reference: number; not_judged: number }[]>('pickups/tolerance-summary')
      .pipe(map((rows) => rows.filter((row) => row.received > row.not_judged)), catchError(() => of([]))),
    { initialValue: [] },
  );

  protected readonly columns: Column<Pickup>[] = [
    { key: 'number', header: 'pickups.number', sort: 'pickup_number' },
    { key: 'party', header: this.provider ? 'pickups.client' : 'pickups.provider' },
    { key: 'site', header: 'fields.site', hideBelow: 900 },
    { key: 'date', header: 'pickups.date', sort: 'requested_date' },
    { key: 'lots', header: 'pickups.lots', align: 'end', hideBelow: 640 },
    { key: 'weight', header: 'fields.weight', align: 'end' },
    { key: 'status', header: 'fields.status' },
    { key: 'documents', header: 'pickups.compliance.column', hideBelow: 720 },
  ];

  protected open(pickup: Pickup): void {
    void this.router.navigate([this.provider ? '/provider/pickups' : '/pickups', pickup.ulid]);
  }
}
