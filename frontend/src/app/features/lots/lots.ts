import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatChipsModule } from '@angular/material/chips';
import { MatDialog } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatSelectModule } from '@angular/material/select';
import { Router } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';

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
  imports: [MatButtonModule, MatCheckboxModule, MatChipsModule, MatFormFieldModule, MatIconModule, MatSelectModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent,
    SearchComponent, DatePipe, KgPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('lots.title')" [subtitle]="t('lots.subtitle')">
        @if (groupable()) {
          <button mat-stroked-button type="button" (click)="group()"><mat-icon>merge</mat-icon>{{ t('lots.groupSelected', { count: selection().length }) }}</button>
        }
        @if (canCreate) {
          <button mat-flat-button type="button" (click)="create()"><mat-icon>add</mat-icon>{{ t('lots.new') }}</button>
        }
      </dw-page-header>

      <div class="dw-panel">
        <div class="filters">
          <dw-search [placeholder]="t('lots.searchPlaceholder')" (queryChange)="list.setFilter({ search: $event })" />
          <mat-form-field class="f-status">
            <mat-label>{{ t('fields.status') }}</mat-label>
            <mat-select multiple [value]="list.filter().status ?? []" (selectionChange)="list.setFilter({ status: $event.value })">
              @for (status of statuses; track status) { <mat-option [value]="status">{{ t('status.lot.' + status) }}</mat-option> }
            </mat-select>
          </mat-form-field>
          @if (sites.length > 1) {
            <mat-form-field class="f-site">
              <mat-label>{{ t('fields.site') }}</mat-label>
              <mat-select [value]="list.filter().site ?? ''" (selectionChange)="list.setFilter({ site: $event.value || null })">
                <mat-option value="">{{ t('common.allSites') }}</mat-option>
                @for (site of sites; track site.ulid) { <mat-option [value]="site.ulid">{{ site.name }}</mat-option> }
              </mat-select>
            </mat-form-field>
          }
          <mat-form-field class="f-type">
            <mat-label>{{ t('fields.wasteType') }}</mat-label>
            <mat-select [value]="list.filter().waste_type ?? ''" (selectionChange)="list.setFilter({ waste_type: $event.value || null })">
              <mat-option value="">{{ t('common.all') }}</mat-option>
              @for (type of types(); track type.ulid) { <mat-option [value]="type.ulid">{{ type.name }}</mat-option> }
            </mat-select>
          </mat-form-field>
          <mat-checkbox [checked]="list.filter().hazardous === true" (change)="list.setFilter({ hazardous: $event.checked ? true : null })">{{ t('lots.hazardousOnly') }}</mat-checkbox>
          @if (list.filtered()) {
            <button mat-button type="button" (click)="reset()">{{ t('common.resetFilters') }}</button>
          }
        </div>

        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1"
          [pageSize]="list.perPage()" [sort]="list.sort()" [clickable]="true"
          [emptyTitle]="list.filtered() ? t('common.noResult') : t('lots.emptyTitle')" [emptyHint]="list.filtered() ? '' : t('lots.emptyHint')" emptyIcon="inventory_2"
          (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (sortChange)="list.setSort($event)" (rowClick)="open($event)" (retry)="list.reload()">
          <ng-template dwCell="select" let-lot>
            @if (lot.status === 'created' || lot.status === 'stored') {
              <mat-checkbox [checked]="isSelected(lot)" (change)="toggle(lot)" (click)="$event.stopPropagation()" [attr.aria-label]="lot.lot_number" />
            }
          </ng-template>
          <ng-template dwCell="number" let-lot>
            <span class="dw-mono">{{ lot.lot_number }}</span>
            @if (lot.is_hazardous) { <mat-icon class="hazard" [attr.aria-label]="t('common.hazardous')" [title]="t('common.hazardous')">warning</mat-icon> }
          </ng-template>
          <ng-template dwCell="type" let-lot>{{ lot.waste_type?.name }}</ng-template>
          <ng-template dwCell="place" let-lot>{{ lot.site?.code }} · {{ lot.zone?.name }}</ng-template>
          <ng-template dwCell="weight" let-lot>{{ lot.net_weight_kg | dwKg }}</ng-template>
          <ng-template dwCell="date" let-lot>{{ lot.generated_at | dwDate: 'datetime' }}</ng-template>
          <ng-template dwCell="status" let-lot><dw-status kind="lot" [value]="lot.status" /></ng-template>
          @if (canCreate) {
            <button dwEmptyAction mat-flat-button type="button" (click)="create()"><mat-icon>add</mat-icon>{{ t('lots.new') }}</button>
          }
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: `
    .filters { display: flex; align-items: center; gap: var(--dw-2) var(--dw-3); flex-wrap: wrap; padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }
    .f-status { width: 210px; } .f-site { width: 200px; } .f-type { width: 220px; }
    .hazard { font-size: 15px; width: 15px; height: 15px; color: var(--dw-warning); vertical-align: -2px; margin-inline-start: 4px; }
  `,
})
export class LotsPage {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly dialog = inject(MatDialog);
  private readonly router = inject(Router);

  protected readonly statuses = STATUSES;
  protected readonly sites = this.session.sitesFor('lots.view');
  protected readonly canCreate = this.session.sitesFor('lots.create').length > 0;
  protected readonly types = toSignal(inject(ReferenceData).wasteTypes(), { initialValue: [] });
  protected readonly list = new ListStore<Lot, LotFilter>((query) => this.api.list<Lot>('lots', query));
  protected readonly selection = signal<Lot[]>([]);

  /** Grouping needs at least two lots of the same site, type and hazard status, on a site where the user may group. */
  protected readonly groupable = computed(() => {
    const [first, ...others] = this.selection();

    return first !== undefined && others.length > 0 && this.session.can('lots.group', first.site?.ulid) &&
      others.every((lot) => lot.site?.ulid === first.site?.ulid && lot.waste_type?.ulid === first.waste_type?.ulid && lot.is_hazardous === first.is_hazardous);
  });

  protected readonly columns: Column<Lot>[] = [
    ...(this.session.canAnywhere('lots.group') || this.session.can('lots.group') ? [{ key: 'select', header: '', width: '44px' } as Column<Lot>] : []),
    { key: 'number', header: 'lots.number', sort: 'lot_number' },
    { key: 'type', header: 'fields.wasteType' },
    { key: 'place', header: 'fields.zone', hideBelow: 900 },
    { key: 'weight', header: 'fields.weight', align: 'end', sort: 'net_weight_kg' },
    { key: 'date', header: 'lots.generatedAt', sort: 'generated_at', hideBelow: 640 },
    { key: 'status', header: 'fields.status' },
  ];

  protected isSelected(lot: Lot): boolean {
    return this.selection().some((item) => item.ulid === lot.ulid);
  }

  protected toggle(lot: Lot): void {
    this.selection.update((items) => (items.some((item) => item.ulid === lot.ulid) ? items.filter((item) => item.ulid !== lot.ulid) : [...items, lot]));
  }

  protected reset(): void {
    this.list.resetFilter();
  }

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
