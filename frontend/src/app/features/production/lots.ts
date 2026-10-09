import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { Router, RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { Tab, TabList, TabPanel, TabPanels, Tabs } from 'primeng/tabs';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { DatePipe, MoneyPipe, NumberPipe } from '../../core/format/pipes';
import { Column, TABLE } from '../../ui/data-table';
import { FilterBarComponent } from '../../ui/filter-bar';
import { HelpComponent } from '../../ui/help';
import { IconComponent } from '../../ui/icon';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { SELECT } from '../../ui/select';
import { ProducedLot, ProducedLotStatus, ProductionLists, ReceivedLot, statusTone } from './production-data';

/**
 * The two kinds of lot of production, side by side so they are never confused: received lots (raw material, from a
 * supplier) and produced lots (finished goods, for a client). Waste lots have their own page in Operations.
 */
@Component({
  imports: [
    RouterLink, HelpComponent, TranslocoDirective, PageHeaderComponent, ...TABLE, StatusComponent, SearchComponent, FilterBarComponent, IconComponent, DatePipe, NumberPipe, MoneyPipe, ...SELECT,
    Tabs, TabList, Tab, TabPanels, TabPanel,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('production.lots.title')" [subtitle]="t('production.lots.subtitle')" />
      <dw-help topic="productionLots" />
      <div class="dw-panel">
        <p-tabs [value]="0">
          <p-tablist>
            <p-tab [value]="0"><dw-icon>south_west</dw-icon> {{ t('production.received.title') }}</p-tab>
            <p-tab [value]="1"><dw-icon>north_east</dw-icon> {{ t('production.produced.title') }}</p-tab>
          </p-tablist>
          <p-tabpanels>
            <p-tabpanel [value]="0">
              <dw-filter-bar [active]="(receivedSearch() ? 1 : 0) + (supplier() ? 1 : 0) + (priced() ? 1 : 0)" (cleared)="receivedSearch.set(''); supplier.set(''); priced.set(''); received.reload()">
                <dw-search [placeholder]="t('production.received.search')" [query]="receivedSearch()" (queryChange)="receivedSearch.set($event); received.reload()" />
                <dw-select [placeholder]="t('production.suppliers.one')" [value]="supplier()" (valueChange)="pick(supplier, $event, received)">
                  <dw-option value="" [label]="t('common.all')" />
                  @for (item of suppliers(); track item.ulid) { <dw-option [value]="item.ulid" [label]="item.name" /> }
                </dw-select>
                @if (money) {
                  <dw-select [placeholder]="t('production.received.price')" [value]="priced()" (valueChange)="pick(priced, $event, received)">
                    <dw-option value="" [label]="t('common.all')" /><dw-option value="yes" [label]="t('production.received.withPrice')" /><dw-option value="no" [label]="t('production.received.withoutPrice')" />
                  </dw-select>
                }
              </dw-filter-bar>
              <dw-data-table [columns]="receivedColumns" [rows]="received.rows()" [loading]="received.loading()" [error]="received.error()" [total]="received.total()" [pageIndex]="received.page() - 1"
                [pageSize]="received.perPage()" [clickable]="true" [emptyTitle]="t('production.received.empty')" [emptyHint]="t('production.received.emptyHint')" emptyIcon="inventory_2"
                (page)="received.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="openMaterial($event)" (retry)="received.reload()">
                <ng-template dwCell="number" let-lot><strong class="dw-mono">{{ lot.batch_number }}</strong>@if (lot.supplier_batch_reference) { <div class="dw-faint dw-mono">{{ lot.supplier_batch_reference }}</div> }</ng-template>
                <ng-template dwCell="material" let-lot><span class="dw-mono">{{ lot.material.reference }}</span><div class="dw-faint">{{ lot.material.name }}</div></ng-template>
                <ng-template dwCell="received" let-lot>{{ lot.received_on | dwDate }}</ng-template>
                <ng-template dwCell="quantity" let-lot>{{ lot.quantity === null ? '—' : (lot.quantity | dwNumber: 1) + ' ' + lot.material.unit }}</ng-template>
                <ng-template dwCell="consumed" let-lot><span [class.over]="lot.over_consumed" [title]="lot.over_consumed ? t('production.received.overConsumed') : ''">{{ lot.consumed | dwNumber: 1 }} {{ lot.material.unit }}</span></ng-template>
                <ng-template dwCell="unitCost" let-lot>@if (lot.unit_cost === null) { <span class="missing">{{ t('production.unknown') }}</span> } @else { {{ lot.unit_cost | dwMoney: lot.currency_code }} }</ng-template>
                <ng-template dwCell="value" let-lot>{{ lot.purchase_value | dwMoney: lot.currency_code }}</ng-template>
              </dw-data-table>
            </p-tabpanel>
            <p-tabpanel [value]="1">
              <dw-filter-bar [active]="(producedSearch() ? 1 : 0) + (client() ? 1 : 0) + (status() ? 1 : 0)" (cleared)="producedSearch.set(''); client.set(''); status.set(''); produced.reload()">
                <dw-search [placeholder]="t('production.produced.search')" [query]="producedSearch()" (queryChange)="producedSearch.set($event); produced.reload()" />
                <dw-select [placeholder]="t('production.clients.one')" [value]="client()" (valueChange)="pick(client, $event, produced)">
                  <dw-option value="" [label]="t('common.all')" />
                  @for (item of clients(); track item.ulid) { <dw-option [value]="item.ulid" [label]="item.name" /> }
                </dw-select>
                <dw-select [placeholder]="t('fields.status')" [value]="status()" (valueChange)="pick(status, $event, produced)">
                  <dw-option value="" [label]="t('common.all')" />
                  @for (item of statuses; track item) { <dw-option [value]="item" [label]="t('production.producedStatus.' + item)" /> }
                </dw-select>
              </dw-filter-bar>
              <dw-data-table [columns]="producedColumns" [rows]="produced.rows()" [loading]="produced.loading()" [error]="produced.error()" [total]="produced.total()" [pageIndex]="produced.page() - 1"
                [pageSize]="produced.perPage()" [clickable]="true" [emptyTitle]="t('production.produced.emptyList')" [emptyHint]="t('production.produced.emptyHint')" emptyIcon="package_2"
                (page)="produced.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="openOf($event)" (retry)="produced.reload()">
                <ng-template dwCell="number" let-lot><strong class="dw-mono">{{ lot.lot_number }}</strong></ng-template>
                <ng-template dwCell="of" let-lot><a class="dw-mono" [routerLink]="['/production/manufacturing-orders', lot.manufacturing_order.ulid]" (click)="$event.stopPropagation()">{{ lot.manufacturing_order.order_number }}</a>
                  @if (lot.customer_order) { <div class="dw-faint dw-mono">{{ lot.customer_order.order_number }}</div> }</ng-template>
                <ng-template dwCell="article" let-lot>{{ lot.article.reference }}<div class="dw-faint">{{ lot.article.name }}</div></ng-template>
                <ng-template dwCell="produced" let-lot>{{ lot.produced_on | dwDate }}</ng-template>
                <ng-template dwCell="quantity" let-lot>{{ lot.quantity | dwNumber }}</ng-template>
                <ng-template dwCell="status" let-lot><dw-status kind="accreditation" [value]="tone(lot.status)" [label]="t('production.producedStatus.' + lot.status)" />@if (lot.delivered_on) { <span class="dw-faint"> {{ lot.delivered_on | dwDate }}</span> }</ng-template>
              </dw-data-table>
            </p-tabpanel>
          </p-tabpanels>
        </p-tabs>
      </div>
    </ng-container>
  `,
  styles: '.over { color: var(--dw-danger); font-weight: 600; } .missing { color: var(--dw-warning); } p-tab dw-icon { font-size: 16px; vertical-align: -3px; }',
})
export class ProductionLotsPage {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly lists = inject(ProductionLists);

  protected readonly money = inject(Session).can('production.finance.view');
  protected readonly tone = statusTone;
  protected readonly statuses: ProducedLotStatus[] = ['produced', 'delivered', 'cancelled'];
  protected readonly suppliers = toSignal(this.lists.suppliers(), { initialValue: [] });
  protected readonly clients = toSignal(this.lists.clients(), { initialValue: [] });
  protected readonly receivedSearch = signal('');
  protected readonly supplier = signal('');
  protected readonly priced = signal('');
  protected readonly producedSearch = signal('');
  protected readonly client = signal('');
  protected readonly status = signal('');
  protected readonly received = new ListStore<ReceivedLot>((query) => this.api.list<ReceivedLot>('production/received-lots', { ...query, search: this.receivedSearch(), supplier: this.supplier(), priced: this.priced() }));
  protected readonly produced = new ListStore<ProducedLot>((query) => this.api.list<ProducedLot>('production/produced-lots', { ...query, search: this.producedSearch(), client: this.client(), status: this.status() }));
  protected readonly receivedColumns: Column<ReceivedLot>[] = [
    { key: 'number', header: 'production.received.number', width: '170px' },
    { key: 'material', header: 'production.materials.one' },
    { key: 'supplier', header: 'production.received.supplier', value: (lot) => lot.supplier?.name ?? '—', hideBelow: 800 },
    { key: 'received', header: 'production.received.receivedOn', hideBelow: 1000 },
    { key: 'quantity', header: 'production.received.received', align: 'end', hideBelow: 700 },
    { key: 'consumed', header: 'production.received.consumed', align: 'end', hideBelow: 1000 },
    ...(this.money ? [{ key: 'unitCost', header: 'production.received.unitCost', align: 'end' as const }, { key: 'value', header: 'production.received.purchaseValue', align: 'end' as const, hideBelow: 1100 }] : []),
  ];
  protected readonly producedColumns: Column<ProducedLot>[] = [
    { key: 'number', header: 'production.produced.number', width: '180px' },
    { key: 'of', header: 'production.of.one' },
    { key: 'article', header: 'production.articles.one', hideBelow: 800 },
    { key: 'client', header: 'production.clients.one', value: (lot) => lot.client?.name ?? '—', hideBelow: 700 },
    { key: 'produced', header: 'production.produced.producedOn', hideBelow: 1000 },
    { key: 'quantity', header: 'fields.quantity', align: 'end' },
    { key: 'status', header: 'fields.status' },
  ];

  /** The selector also announces the value it starts with: only a real change reloads. */
  protected pick(target: { (): string; set(value: string): void }, value: string, list: { reload(): void }): void {
    if (value !== target()) {
      target.set(value);
      list.reload();
    }
  }

  protected openMaterial(lot: ReceivedLot): void {
    void this.router.navigate(['/production/catalog', lot.material.ulid]);
  }

  protected openOf(lot: ProducedLot): void {
    void this.router.navigate(['/production/manufacturing-orders', lot.manufacturing_order.ulid]);
  }
}
