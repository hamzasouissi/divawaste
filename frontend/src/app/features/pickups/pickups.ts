import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatSelectModule } from '@angular/material/select';
import { Router, RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';

import { Api } from '../../core/api/api';
import { Pickup, PickupStatus } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { DatePipe, KgPipe } from '../../core/format/pipes';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';

interface PickupFilter {
  status?: PickupStatus[];
  site?: string | null;
  variance_flagged?: boolean | null;
  search?: string;
}

/**
 * Pickups, seen from either side. An industrial sees its requests by site and provider; a provider sees the
 * requests addressed to it by client. Same list, same statuses: both parties talk about the same object.
 */
@Component({
  imports: [RouterLink, MatButtonModule, MatCheckboxModule, MatFormFieldModule, MatIconModule, MatSelectModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, SearchComponent,
    DatePipe, KgPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t(provider ? 'provider.pickups.title' : 'pickups.title')" [subtitle]="t(provider ? 'provider.pickups.subtitle' : 'pickups.subtitle')">
        @if (!provider && canRequest) { <a mat-flat-button routerLink="/pickups/new"><mat-icon>add</mat-icon>{{ t('pickups.new') }}</a> }
      </dw-page-header>

      <div class="dw-panel">
        <div class="filters">
          <dw-search [placeholder]="t('pickups.number')" (queryChange)="list.setFilter({ search: $event })" />
          <mat-form-field class="f-status">
            <mat-label>{{ t('fields.status') }}</mat-label>
            <mat-select multiple [value]="list.filter().status ?? []" (selectionChange)="list.setFilter({ status: $event.value })">
              @for (status of statuses; track status) { <mat-option [value]="status">{{ t('status.pickup.' + status) }}</mat-option> }
            </mat-select>
          </mat-form-field>
          @if (!provider && sites.length > 1) {
            <mat-form-field class="f-site">
              <mat-label>{{ t('fields.site') }}</mat-label>
              <mat-select [value]="list.filter().site ?? ''" (selectionChange)="list.setFilter({ site: $event.value || null })">
                <mat-option value="">{{ t('common.allSites') }}</mat-option>
                @for (site of sites; track site.ulid) { <mat-option [value]="site.ulid">{{ site.name }}</mat-option> }
              </mat-select>
            </mat-form-field>
          }
          <mat-checkbox [checked]="list.filter().variance_flagged === true" (change)="list.setFilter({ variance_flagged: $event.checked ? true : null })">{{ t('pickups.varianceOnly') }}</mat-checkbox>
          @if (list.filtered()) { <button mat-button type="button" (click)="list.resetFilter()">{{ t('common.resetFilters') }}</button> }
        </div>

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
          <ng-template dwCell="weight" let-pickup>{{ pickup.received_weight_kg ?? pickup.departure_weight_kg ?? pickup.declared_weight_kg | dwKg }}</ng-template>
          <ng-template dwCell="status" let-pickup>
            <dw-status kind="pickup" [value]="pickup.status" />
            @if (pickup.variance_flagged && !pickup.variance_acknowledged) { <mat-icon class="flag" [title]="t('pickups.varianceFlagged')" [attr.aria-label]="t('pickups.varianceFlagged')">scale</mat-icon> }
          </ng-template>
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: `
    .filters { display: flex; align-items: center; gap: var(--dw-2) var(--dw-3); flex-wrap: wrap; padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }
    .f-status { width: 210px; } .f-site { width: 200px; }
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

  protected readonly columns: Column<Pickup>[] = [
    { key: 'number', header: 'pickups.number', sort: 'pickup_number' },
    { key: 'party', header: this.provider ? 'pickups.client' : 'pickups.provider' },
    { key: 'site', header: 'fields.site', hideBelow: 900 },
    { key: 'date', header: 'pickups.date', sort: 'requested_date' },
    { key: 'lots', header: 'pickups.lots', align: 'end', hideBelow: 640 },
    { key: 'weight', header: 'fields.weight', align: 'end' },
    { key: 'status', header: 'fields.status' },
  ];

  protected open(pickup: Pickup): void {
    void this.router.navigate([this.provider ? '/provider/pickups' : '/pickups', pickup.ulid]);
  }
}
