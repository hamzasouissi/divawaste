import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { Router } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';

import { Api } from '../../core/api/api';
import { Lot, LotStatus } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { DatePipe, KgPipe } from '../../core/format/pipes';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { CreateLotDialog, GroupLotsData, GroupLotsDialog } from './lot-dialogs';
import { CheckboxComponent } from '../../ui/checkbox';
import { Dialogs } from '../../ui/dialog';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';
import { FilterBarComponent } from '../../ui/filter-bar';
import { HelpComponent } from '../../ui/help';

interface LotFilter {
  status?: LotStatus[];
  site?: string | null;
  waste_type?: string | null;
  hazardous?: boolean | null;
  search?: string;
}

const STATUSES: LotStatus[] = ['created', 'stored', 'awaiting_pickup', 'collected', 'treated', 'closed'];

/**
 * Lot register. Filtering, sorting and paging are done by the server (the table can hold millions of rows).
 * Selecting lots of the same site, type and hazard status offers to group them (M2-06).
 */
@Component({
  imports: [
    HelpComponent,
    TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, SearchComponent, DatePipe, KgPipe, IconComponent, ButtonDirective, ...SELECT, CheckboxComponent, FilterBarComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('lots.title')" [subtitle]="t('lots.subtitle')">
        @if (groupable()) {
          <button pButton severity="secondary" outlined type="button" (click)="group()"><dw-icon>merge</dw-icon>{{ t('lots.groupSelected', { count: selection().length }) }}</button>
        }
        @if (canCreate) {
          <button pButton type="button" (click)="create()"><dw-icon>add</dw-icon>{{ t('lots.new') }}</button>
        }
      </dw-page-header>
      <dw-help topic="lots" />

      <div class="dw-panel">
        <dw-filter-bar [active]="list.activeCount()" (cleared)="list.resetFilter()">
          <dw-search [placeholder]="t('lots.searchPlaceholder')" [query]="list.filter().search ?? ''" (queryChange)="list.setFilter({ search: $event })" />
          <dw-select [placeholder]="t('fields.status')" multiple [value]="list.filter().status ?? []" (valueChange)="list.setFilter({ status: $event })">
            @for (status of statuses; track status) { <dw-option [value]="status" [label]="t('status.lot.' + status)" /> }
          </dw-select>
          @if (sites.length > 1) {
            <dw-select [placeholder]="t('fields.site')" [value]="list.filter().site ?? ''" (valueChange)="list.setFilter({ site: $event || null })">
              <dw-option value="" [label]="t('common.allSites')" />
              @for (site of sites; track site.ulid) { <dw-option [value]="site.ulid" [label]="site.name" /> }
            </dw-select>
          }
          <dw-select class="wide" [placeholder]="t('fields.wasteType')" [value]="list.filter().waste_type ?? ''" (valueChange)="list.setFilter({ waste_type: $event || null })">
            <dw-option value="" [label]="t('common.all')" />
            @for (type of types(); track type.ulid) { <dw-option [value]="type.ulid" [label]="type.name" /> }
          </dw-select>
          <dw-checkbox [checked]="list.filter().hazardous === true" (checkedChange)="list.setFilter({ hazardous: $event ? true : null })">{{ t('lots.hazardousOnly') }}</dw-checkbox>
        </dw-filter-bar>

        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1"
          [pageSize]="list.perPage()" [sort]="list.sort()" [clickable]="true" [selectable]="canGroup ? selectable : null" [(selection)]="selection"
          [emptyTitle]="list.filtered() ? t('common.noResult') : t('lots.emptyTitle')" [emptyHint]="list.filtered() ? '' : t('lots.emptyHint')" emptyIcon="inventory_2"
          (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (sortChange)="list.setSort($event)" (rowClick)="open($event)" (retry)="list.reload()">
          <ng-template dwCell="number" let-lot>
            <span class="dw-mono">{{ lot.lot_number }}</span>
            @if (lot.is_hazardous) { <dw-icon class="hazard" [attr.aria-label]="t('common.hazardous')" [title]="t('common.hazardous')">warning</dw-icon> }
          </ng-template>
          <ng-template dwCell="type" let-lot>{{ lot.waste_type?.name }}</ng-template>
          <ng-template dwCell="place" let-lot>{{ lot.site?.code }} · {{ lot.zone?.name }}</ng-template>
          <ng-template dwCell="weight" let-lot>{{ lot.net_weight_kg | dwKg }}</ng-template>
          <ng-template dwCell="date" let-lot>{{ lot.generated_at | dwDate: 'datetime' }}</ng-template>
          <ng-template dwCell="status" let-lot><dw-status kind="lot" [value]="lot.status" /></ng-template>
          @if (canCreate) {
            <button dwEmptyAction pButton type="button" (click)="create()"><dw-icon>add</dw-icon>{{ t('lots.new') }}</button>
          }
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: `
    .hazard { font-size: 15px; color: var(--dw-warning); vertical-align: -2px; margin-inline-start: 4px; }
  `,
})
export class LotsPage {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly dialog = inject(Dialogs);
  private readonly router = inject(Router);

  protected readonly statuses = STATUSES;
  protected readonly sites = this.session.sitesFor('lots.view');
  protected readonly canCreate = this.session.sitesFor('lots.create').length > 0;
  protected readonly types = toSignal(inject(ReferenceData).wasteTypes(), { initialValue: [] });
  protected readonly list = new ListStore<Lot, LotFilter>((query) => this.api.list<Lot>('lots', query));
  protected readonly selection = signal<Lot[]>([]);
  protected readonly canGroup = this.session.canAnywhere('lots.group') || this.session.can('lots.group');
  /** Only lots still on site can be grouped. */
  protected readonly selectable = (lot: Lot): boolean => lot.status === 'created' || lot.status === 'stored';

  /** Grouping needs at least two lots of the same site, type and hazard status, on a site where the user may group. */
  protected readonly groupable = computed(() => {
    const [first, ...others] = this.selection();

    return first !== undefined && others.length > 0 && this.session.can('lots.group', first.site?.ulid) &&
      others.every((lot) => lot.site?.ulid === first.site?.ulid && lot.waste_type?.ulid === first.waste_type?.ulid && lot.is_hazardous === first.is_hazardous);
  });

  protected readonly columns: Column<Lot>[] = [
    { key: 'number', header: 'lots.number', sort: 'lot_number' },
    { key: 'type', header: 'fields.wasteType' },
    { key: 'place', header: 'fields.zone', hideBelow: 900 },
    { key: 'weight', header: 'fields.weight', align: 'end', sort: 'net_weight_kg' },
    { key: 'date', header: 'lots.generatedAt', sort: 'generated_at', hideBelow: 640 },
    { key: 'status', header: 'fields.status' },
  ];



  protected open(lot: Lot): void {
    void this.router.navigate(['/lots', lot.ulid]);
  }

  protected create(): void {
    this.dialog.open(CreateLotDialog).afterClosed().subscribe((lot: Lot | undefined) => lot && this.open(lot));
  }

  protected group(): void {
    this.dialog.open<GroupLotsDialog, GroupLotsData, Lot>(GroupLotsDialog, { data: { lots: this.selection() } }).afterClosed().subscribe((lot) => {
      if (lot) {
        this.selection.set([]);
        this.open(lot);
      }
    });
  }
}
