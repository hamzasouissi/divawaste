import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';

import { Api, openBlob } from '../../core/api/api';
import { Lot, LotStatus } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { DatePipe, LotMeasurePipe } from '../../core/format/pipes';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { CreateLotDialog, GroupLotsData, GroupLotsDialog, MoveLotsData, MoveLotsDialog } from './lot-dialogs';
import { CreateListingData, CreateListingDialog } from '../marketplace/listing-dialogs';
import { SegmentedComponent } from '../../ui/more';
import { Toast } from '../../core/feedback/toast';
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
  unweighed?: boolean | null;
  search?: string;
}

const STATUSES: LotStatus[] = ['created', 'stored', 'awaiting_pickup', 'collected', 'treated', 'closed'];
/** On site: created, in stock, or waiting for the pickup that will take it. */
const ON_SITE: LotStatus[] = ['created', 'stored', 'awaiting_pickup'];
const GONE: LotStatus[] = ['collected', 'treated', 'closed'];

/**
 * Lot register. Filtering, sorting and paging are done by the server (the table can hold millions of rows).
 * Selecting lots of the same site, type and hazard status offers to group them (M2-06).
 */
@Component({
  imports: [
    HelpComponent, RouterLink,
    TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, SearchComponent, DatePipe, LotMeasurePipe, IconComponent, ButtonDirective, ...SELECT, CheckboxComponent, FilterBarComponent, SegmentedComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('lots.title')" [subtitle]="t('lots.subtitle')">
        <!-- L-05: open on what is on site; the history is one click away. -->
        <dw-segmented [options]="[{ value: 'onsite', label: t('lots.views.onsite') }, { value: 'gone', label: t('lots.views.gone') }, { value: 'all', label: t('lots.views.all') }]"
          [value]="view()" (valueChange)="setView($any($event))" [ariaLabel]="t('lots.views.label')" />
        @if (canCreate) {
          <a pButton outlined severity="secondary" routerLink="/lots/import"><dw-icon>upload_file</dw-icon>{{ t('lots.importButton') }}</a>
          <button pButton type="button" (click)="create()"><dw-icon>add</dw-icon>{{ t('lots.new') }}</button>
        }
      </dw-page-header>
      <dw-help topic="lots" />

      <!-- L-04: what can be done with the selected lots, in one bar. -->
      @if (selection().length > 0) {
        <div class="selbar" role="region" [attr.aria-label]="t('lots.selection', { count: selection().length })">
          <span class="selcount"><strong>{{ selection().length }}</strong> {{ t('lots.selectedLabel', { count: selection().length }) }}</span>
          <span class="dw-spacer"></span>
          @if (sameSite()) {
            @if (session.can('pickups.request', sameSite())) {
              <button pButton type="button" (click)="requestPickup()"><dw-icon>local_shipping</dw-icon>{{ t('pickups.new') }}</button>
            }
            @if (session.uses('marketplace') && session.can('marketplace.manage', sameSite())) {
              <button pButton severity="secondary" outlined type="button" (click)="sell()"><dw-icon>storefront</dw-icon>{{ t('marketplace.sellLot') }}</button>
            }
            @if (session.can('lots.move', sameSite())) {
              <button pButton severity="secondary" outlined type="button" (click)="move()"><dw-icon>move_down</dw-icon>{{ t('lots.move') }}</button>
            }
          } @else {
            <span class="why">{{ t('lots.differentSites') }}</span>
          }
          @if (groupable()) {
            <button pButton severity="secondary" outlined type="button" (click)="group()"><dw-icon>merge</dw-icon>{{ t('lots.group') }}</button>
          } @else if (canGroup && selection().length > 1 && sameSite()) {
            <span class="why" [title]="t('lots.cannotGroup')"><dw-icon>info</dw-icon>{{ t('lots.cannotGroupShort') }}</span>
          }
          @if (session.canAnywhere('lots.print_label')) {
            <button pButton severity="secondary" outlined type="button" (click)="printLabels()"><dw-icon>print</dw-icon>{{ t('lots.printLabels') }}</button>
          }
          <button pButton text severity="secondary" type="button" (click)="selection.set([])">{{ t('common.clearSelection') }}</button>
        </div>
      }

      <div class="dw-panel">
        <dw-filter-bar [active]="list.activeCount() - (view() === 'onsite' || view() === 'gone' ? 1 : 0)" (cleared)="setView('onsite')">
          <dw-search [placeholder]="t('lots.searchPlaceholder')" [query]="list.filter().search ?? ''" (queryChange)="list.setFilter({ search: $event })" />
          <dw-select [placeholder]="t('fields.status')" multiple [value]="view() === 'custom' ? (list.filter().status ?? []) : []" (valueChange)="list.setFilter({ status: $event }); view.set($event.length ? 'custom' : 'all')">
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
          <dw-checkbox [checked]="list.filter().unweighed === true" (checkedChange)="list.setFilter({ unweighed: $event ? true : null })">{{ t('lots.unweighedOnly') }}</dw-checkbox>
        </dw-filter-bar>

        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1"
          [pageSize]="list.perPage()" [sort]="list.sort()" [clickable]="true" [selectable]="canSelect ? selectable : null" [(selection)]="selection"
          [emptyTitle]="list.filtered() ? t('common.noResult') : t('lots.emptyTitle')" [emptyHint]="list.filtered() ? '' : t('lots.emptyHint')" emptyIcon="inventory_2"
          (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (sortChange)="list.setSort($event)" (rowClick)="open($event)" (retry)="list.reload()">
          <ng-template dwCell="number" let-lot>
            <span class="dw-mono">{{ lot.lot_number }}</span>
            @if (lot.is_hazardous) { <dw-icon class="hazard" [attr.aria-label]="t('common.hazardous')" [title]="t('common.hazardous')">warning</dw-icon> }
          </ng-template>
          <ng-template dwCell="type" let-lot>{{ lot.waste_type?.name }}</ng-template>
          <ng-template dwCell="place" let-lot>{{ lot.site?.code }} · {{ lot.zone?.name }}</ng-template>
          <ng-template dwCell="weight" let-lot>{{ lot | dwLotMeasure }}</ng-template>
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
    .selbar { position: sticky; inset-block-start: calc(var(--dw-shell-bar) + 56px); z-index: 4; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-block-end: 14px;
      padding: 10px 12px 10px 18px; border-radius: var(--dw-radius-lg); background: var(--dw-ink); color: #fff; box-shadow: var(--dw-shadow-pop); animation: dw-rise 160ms var(--dw-ease); }
    .selcount { font-size: 13.5px; color: rgb(255 255 255 / 78%); } .selcount strong { color: #fff; font-size: 15px; margin-inline-end: 2px; }
    .selbar .p-button-outlined { background: rgb(255 255 255 / 6%); border-color: rgb(255 255 255 / 22%); color: #fff; }
    .selbar .p-button-outlined:not(:disabled):hover { background: rgb(255 255 255 / 14%); border-color: rgb(255 255 255 / 34%); color: #fff; }
    .selbar .p-button-text { color: rgb(255 255 255 / 78%); }
    .why { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; color: rgb(255 255 255 / 66%); } .why dw-icon { font-size: 15px; }
  `,
})
export class LotsPage {
  private readonly api = inject(Api);
  protected readonly session = inject(Session);
  private readonly dialog = inject(Dialogs);
  private readonly router = inject(Router);
  private readonly toast = inject(Toast);

  protected readonly statuses = STATUSES;
  protected readonly sites = this.session.sitesFor('lots.view');
  protected readonly canCreate = this.session.sitesFor('lots.create').length > 0;
  protected readonly types = toSignal(inject(ReferenceData).wasteTypes(), { initialValue: [] });
  protected readonly list = new ListStore<Lot, LotFilter>((query) => this.api.list<Lot>('lots', query), { filter: { status: ON_SITE } });
  protected readonly view = signal<'onsite' | 'gone' | 'all' | 'custom'>('onsite');
  protected readonly selection = signal<Lot[]>([]);
  protected readonly canGroup = this.session.canAnywhere('lots.group') || this.session.can('lots.group');
  /** Lots can be selected by whoever may do something with several at once. */
  protected readonly canSelect = this.canGroup || ['pickups.request', 'lots.move', 'lots.print_label', 'marketplace.manage'].some((permission) => this.session.canAnywhere(permission));
  /** The site of the selection when it is a single one: pickups, sales and moves are made per site. */
  protected readonly sameSite = computed(() => {
    const sites = new Set(this.selection().map((lot) => lot.site?.ulid ?? ''));
    return sites.size === 1 ? [...sites][0] : null;
  });
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



  constructor() {
    const query = inject(ActivatedRoute).snapshot.queryParamMap;
    // The dashboard's "Nouveau lot" lands here with ?new=1.
    if (query.get('new') && this.canCreate) {
      queueMicrotask(() => this.create());
    }
    // "À traiter" and DAVI lead here to the lots of one check: not weighed, or never placed in a zone.
    if (query.get('unweighed')) {
      this.list.setFilter({ unweighed: true });
    }
    if (query.get('status') === 'created') {
      this.list.setFilter({ status: ['created'] });
    }
  }

  protected setView(view: 'onsite' | 'gone' | 'all'): void {
    this.view.set(view);
    if (view === 'onsite' && this.list.activeCount() > 1) {
      // "Réinitialiser": every filter goes, back to what is on site.
      this.list.resetFilter();
    }
    this.list.setFilter({ status: view === 'onsite' ? ON_SITE : view === 'gone' ? GONE : [] });
  }

  protected requestPickup(): void {
    void this.router.navigate(['/pickups/new'], { queryParams: { site: this.sameSite(), lots: this.selection().map((lot) => lot.ulid).join(',') } });
  }

  protected sell(): void {
    const lots = this.selection();
    const types = new Set(lots.map((lot) => lot.waste_type?.ulid));
    this.dialog.open<CreateListingDialog, CreateListingData, { ulid: string }>(CreateListingDialog, {
      data: { site: this.sameSite() ?? '', lots: lots.map((lot) => lot.ulid), wasteType: types.size === 1 ? (lots[0].waste_type?.ulid ?? null) : null },
    }).afterClosed().subscribe((listing) => listing && void this.router.navigate(['/marketplace', listing.ulid]));
  }

  protected move(): void {
    this.dialog.open<MoveLotsDialog, MoveLotsData, number>(MoveLotsDialog, { data: { lots: this.selection() } }).afterClosed().subscribe((moved) => {
      if (moved) {
        this.selection.set([]);
        this.list.reload();
      }
    });
  }

  protected printLabels(): void {
    for (const lot of this.selection()) {
      this.api.blob(`lots/${lot.ulid}/label`).subscribe({ next: (blob) => openBlob(blob), error: (error: unknown) => this.toast.error(error) });
    }
  }

  protected open(lot: Lot): void {
    void this.router.navigate(['/lots', lot.ulid]);
  }

  protected create(): void {
    // Closed after "Créer et un autre": the new lots are in the list.
    this.dialog.open(CreateLotDialog).afterClosed().subscribe((lot: Lot | undefined) => (lot ? this.open(lot) : this.list.reload()));
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
