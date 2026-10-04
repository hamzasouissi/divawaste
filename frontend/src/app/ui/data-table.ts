import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, Directive, TemplateRef, computed, contentChildren, inject, input, model, output } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';
import { Paginator, PaginatorState } from 'primeng/paginator';
import { ProgressBar } from 'primeng/progressbar';
import { Skeleton } from 'primeng/skeleton';
import { TableModule } from 'primeng/table';

import { IconComponent } from './icon';
import { StateComponent } from './state';

export interface Column<T> {
  key: string;
  /** Translation key of the header. */
  header: string;
  align?: 'start' | 'end' | 'center';
  /** Sort field understood by the API; omit for a column that cannot be sorted. */
  sort?: string;
  width?: string;
  /** Plain text of the cell; use a `dwCell` template for anything richer. */
  value?: (row: T) => string | number | null | undefined;
  /** Hidden below this viewport width (px) so the essential columns stay readable on small screens. */
  hideBelow?: number;
}

export interface PageChange {
  pageIndex: number;
  pageSize: number;
}

/** `<ng-template dwCell="status" let-row>…</ng-template>` */
@Directive({ selector: 'ng-template[dwCell]' })
export class CellDirective {
  readonly dwCell = input.required<string>();
  readonly template = inject<TemplateRef<{ $implicit: unknown }>>(TemplateRef);
}

const PLACEHOLDERS = Array.from({ length: 8 }, (_, index) => ({ ulid: `placeholder-${index}` }));
const WIDTHS = ['62%', '78%', '48%', '70%', '56%', '84%', '40%'];

/**
 * The table of the product (PrimeNG Table and Paginator): dense, sorted and paginated by the server, with
 * skeleton rows on first load, a progress line on reload, and its error and empty states built in. Rows are
 * keyboard-activable when (rowClick) is bound; rows can be ticked when `selectable` is given.
 */
@Component({
  selector: 'dw-data-table',
  imports: [NgTemplateOutlet, TableModule, Paginator, ProgressBar, Skeleton, IconComponent, StateComponent, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (loading()) {
        <p-progressbar mode="indeterminate" class="bar" [attr.aria-label]="t('common.loading')" />
      }
      @if (error()) {
        <dw-state [error]="error()" (retry)="retry.emit()" />
      } @else {
        <p-table [value]="$any(first() ? placeholders : rows())" [dataKey]="dataKey()" [rowHover]="clickable()" [selection]="selection()" (selectionChange)="selection.set($event)"
          [rowSelectable]="rowSelectable" [class.busy]="loading() && !first()" [attr.aria-busy]="loading()">
          <ng-template #header>
            <tr>
              @if (selectable()) {
                <th scope="col" class="check"><p-tableHeaderCheckbox [ariaLabel]="t('common.selectAll')" /></th>
              }
              @for (column of columns(); track column.key) {
                <th scope="col" [style.width]="column.width" [class]="'a-' + (column.align ?? 'start')" [class.sorted]="column.sort && sorted(column.sort)" [attr.data-hide]="column.hideBelow"
                  [attr.aria-sort]="column.sort ? ariaSort(column.sort) : null">
                  @if (column.sort) {
                    <button type="button" class="sort" (click)="toggleSort(column.sort)">
                      <span>{{ column.header ? t(column.header) : '' }}</span>
                      <dw-icon>{{ sortIcon(column.sort) }}</dw-icon>
                    </button>
                  } @else {
                    {{ column.header ? t(column.header) : '' }}
                  }
                </th>
              }
            </tr>
          </ng-template>
          <ng-template #body let-row let-index="rowIndex">
            @if (first()) {
              <tr aria-hidden="true">
                @if (selectable()) { <td class="check"></td> }
                @for (column of columns(); track column.key; let position = $index) {
                  <td [attr.data-hide]="column.hideBelow"><p-skeleton height="10px" [width]="width(index + position)" /></td>
                }
              </tr>
            } @else {
              <tr [class.clickable]="clickable()" [attr.tabindex]="clickable() ? 0 : null" [attr.role]="clickable() ? 'link' : null"
                (click)="clickable() && rowClick.emit(row)" (keydown.enter)="clickable() && rowClick.emit(row)">
                @if (selectable(); as allowed) {
                  <td class="check" (click)="$event.stopPropagation()" (keydown.enter)="$event.stopPropagation()">
                    @if (allowed(row)) { <p-tableCheckbox [value]="row" /> }
                  </td>
                }
                @for (column of columns(); track column.key) {
                  <td [class]="'a-' + (column.align ?? 'start')" [attr.data-hide]="column.hideBelow">
                    @if (templates()[column.key]; as template) {
                      <ng-container [ngTemplateOutlet]="template" [ngTemplateOutletContext]="{ $implicit: row }" />
                    } @else {
                      {{ column.value ? (column.value(row) ?? '—') : '' }}
                    }
                  </td>
                }
              </tr>
            }
          </ng-template>
          <ng-template #emptymessage>
            <tr class="none">
              <td [attr.colspan]="columns().length + (selectable() ? 1 : 0)">
                <dw-state [empty]="true" [emptyTitle]="emptyTitle()" [emptyHint]="emptyHint()" [icon]="emptyIcon()">
                  <ng-content select="[dwEmptyAction]" ngProjectAs="[dwEmptyAction]" />
                </dw-state>
              </td>
            </tr>
          </ng-template>
        </p-table>

        @if (rows().length > 0) {
          <div class="foot">
            <span class="range">
              @if (paged()) {
                {{ t('paginator.range', { from: pageIndex() * pageSize() + 1, to: pageIndex() * pageSize() + rows().length, total: total() }) }}
              } @else {
                {{ t('common.rows', { count: total() || rows().length }) }}
              }
              @if (selection().length > 0) { <span class="picked">· {{ t('common.selected', { count: selection().length }) }}</span> }
            </span>
            @if (paged()) {
              <p-paginator [first]="pageIndex() * pageSize()" [rows]="pageSize()" [totalRecords]="total()" [rowsPerPageOptions]="[25, 50, 100]" [pageLinkSize]="5"
                (onPageChange)="change($event)" [attr.aria-label]="t('common.pagination')" />
            }
          </div>
        }
      }
    </ng-container>
  `,
  styles: `
    :host { display: block; position: relative; min-width: 0; }
    .bar { position: absolute; inset-block-start: 0; inset-inline: 0; z-index: 3; }
    p-table { display: block; transition: opacity 120ms; }
    p-table.busy { opacity: 0.6; }
    th.check, td.check { width: 40px; padding-inline-end: 0; }
    .a-end { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .a-center { text-align: center; }
    th.a-end .sort { flex-direction: row-reverse; }
    tr.clickable { cursor: pointer; }
    tr.clickable:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: -2px; }
    tr.none td { padding: 0; border: 0; }
    .sort { all: unset; cursor: pointer; display: inline-flex; align-items: center; gap: 2px; border-radius: 3px; }
    .sort:hover { color: var(--dw-ink); }
    .sort:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .sort dw-icon { font-size: 14px; opacity: 0.45; }
    th.sorted { color: var(--dw-ink); }
    th.sorted .sort dw-icon { opacity: 1; color: var(--dw-primary); }
    .foot { display: flex; align-items: center; justify-content: space-between; gap: var(--dw-3); flex-wrap: wrap; min-height: 40px; padding: 4px var(--dw-3);
      border-block-start: 1px solid var(--dw-line-soft); font-size: 12px; color: var(--dw-ink-2); }
    .range { font-variant-numeric: tabular-nums; }
    .picked { color: var(--dw-primary); font-weight: 600; }
    @media (max-width: 1200px) { [data-hide='1200'] { display: none; } }
    @media (max-width: 900px) { [data-hide='900'] { display: none; } }
    @media (max-width: 640px) { [data-hide='640'], [data-hide='900'], [data-hide='1200'] { display: none; } }
  `,
})
export class DataTableComponent<T> {
  readonly columns = input.required<Column<T>[]>();
  readonly rows = input.required<T[]>();
  readonly loading = input(false);
  readonly error = input<unknown>(null);
  readonly total = input(0);
  readonly pageIndex = input(0);
  readonly pageSize = input(25);
  readonly sort = input<string | null>(null);
  /** Property that identifies a row (selection, rendering). */
  readonly dataKey = input('ulid');
  readonly clickable = input(false);
  /** Adds a tick column; the function says which rows may be ticked. */
  readonly selectable = input<((row: T) => boolean) | null>(null);
  readonly selection = model<T[]>([]);
  readonly emptyTitle = input('');
  readonly emptyHint = input('');
  readonly emptyIcon = input('inbox');

  readonly page = output<PageChange>();
  readonly sortChange = output<string | null>();
  readonly rowClick = output<T>();
  readonly retry = output<void>();

  private readonly cells = contentChildren(CellDirective);
  protected readonly templates = computed(() => Object.fromEntries(this.cells().map((cell) => [cell.dwCell(), cell.template])) as Record<string, TemplateRef<{ $implicit: unknown }> | undefined>);

  protected readonly placeholders = PLACEHOLDERS;
  /** First load: nothing to show yet, so skeleton rows stand in for the data. */
  protected readonly first = computed(() => this.loading() && this.rows().length === 0);
  protected readonly paged = computed(() => this.total() > this.pageSize() || this.pageIndex() > 0);
  protected readonly rowSelectable = (event: { data: T }): boolean => this.selectable()?.(event.data) ?? false;

  protected width(index: number): string {
    return WIDTHS[index % WIDTHS.length];
  }

  protected change(state: PaginatorState): void {
    this.page.emit({ pageIndex: state.page ?? 0, pageSize: state.rows ?? this.pageSize() });
  }

  /** Ascending, then descending, then back to the default order. */
  protected toggleSort(field: string): void {
    const current = this.sort();
    this.sortChange.emit(current === field ? `-${field}` : current === `-${field}` ? null : field);
  }

  protected sorted(field: string): boolean {
    return this.sort() === field || this.sort() === `-${field}`;
  }

  protected sortIcon(field: string): string {
    return this.sort() === field ? 'arrow_upward' : this.sort() === `-${field}` ? 'arrow_downward' : 'unfold_more';
  }

  protected ariaSort(field: string): string {
    return this.sort() === field ? 'ascending' : this.sort() === `-${field}` ? 'descending' : 'none';
  }
}

export const TABLE = [DataTableComponent, CellDirective] as const;
