import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, Directive, TemplateRef, computed, contentChildren, inject, input, output } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { TranslocoDirective } from '@jsverse/transloco';

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

/** `<ng-template dwCell="status" let-row>…</ng-template>` */
@Directive({ selector: 'ng-template[dwCell]' })
export class CellDirective {
  readonly dwCell = input.required<string>();
  readonly template = inject<TemplateRef<{ $implicit: unknown }>>(TemplateRef);
}

/**
 * The table of the product: dense, sortable by the server, paginated by the server, with its loading, error
 * and empty states built in. Rows are keyboard-activable when (rowClick) is bound.
 */
@Component({
  selector: 'dw-data-table',
  imports: [NgTemplateOutlet, MatIconModule, MatPaginatorModule, StateComponent, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="error()" [empty]="rows().length === 0" [keepContent]="rows().length > 0"
        [emptyTitle]="emptyTitle()" [emptyHint]="emptyHint()" [icon]="emptyIcon()" (retry)="retry.emit()">
        <ng-content select="[dwEmptyAction]" ngProjectAs="[dwEmptyAction]" />
        <div class="scroll" [class.busy]="loading()">
          <table>
            <thead>
              <tr>
                @for (column of columns(); track column.key) {
                  <th scope="col" [style.width]="column.width" [class]="'a-' + (column.align ?? 'start')" [attr.data-hide]="column.hideBelow"
                    [attr.aria-sort]="column.sort ? ariaSort(column.sort) : null">
                    @if (column.sort) {
                      <button type="button" class="sort" (click)="toggleSort(column.sort)">
                        {{ t(column.header) }}
                        <mat-icon aria-hidden="true">{{ sortIcon(column.sort) }}</mat-icon>
                      </button>
                    } @else {
                      {{ t(column.header) }}
                    }
                  </th>
                }
              </tr>
            </thead>
            <tbody>
              @for (row of rows(); track trackBy()(row)) {
                <tr [class.clickable]="clickable()" [attr.tabindex]="clickable() ? 0 : null" [attr.role]="clickable() ? 'link' : null"
                  (click)="clickable() && rowClick.emit(row)" (keydown.enter)="clickable() && rowClick.emit(row)">
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
            </tbody>
          </table>
        </div>
      </dw-state>
      @if (total() > pageSize() || pageIndex() > 0) {
        <mat-paginator [length]="total()" [pageIndex]="pageIndex()" [pageSize]="pageSize()" [pageSizeOptions]="[25, 50, 100]"
          [showFirstLastButtons]="true" (page)="page.emit($event)" [attr.aria-label]="t('common.pagination')" />
      } @else if (rows().length > 0) {
        <div class="count dw-faint">{{ t('common.rows', { count: total() || rows().length }) }}</div>
      }
    </ng-container>
  `,
  styles: `
    :host { display: block; }
    .scroll { overflow-x: auto; transition: opacity 120ms; }
    .scroll.busy { opacity: 0.55; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { position: sticky; inset-block-start: 0; background: var(--dw-surface-2); color: var(--dw-ink-2); font-size: 11px; font-weight: 600;
      letter-spacing: 0.04em; text-transform: uppercase; padding: 9px var(--dw-3); border-block-end: 1px solid var(--dw-line); white-space: nowrap; text-align: start; }
    td { padding: 8px var(--dw-3); border-block-end: 1px solid var(--dw-line-soft); vertical-align: middle; }
    tbody tr:last-child td { border-block-end: 0; }
    .a-end { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .a-center { text-align: center; }
    tr.clickable { cursor: pointer; }
    tr.clickable:hover td, tr.clickable:focus-visible td { background: var(--dw-primary-soft); }
    tr.clickable:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: -2px; }
    .sort { all: unset; cursor: pointer; display: inline-flex; align-items: center; gap: 2px; }
    .sort:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: 2px; border-radius: 3px; }
    .sort mat-icon { font-size: 14px; width: 14px; height: 14px; }
    .count { padding: var(--dw-2) var(--dw-3); font-size: 12px; text-align: end; border-block-start: 1px solid var(--dw-line-soft); }
    mat-paginator { border-block-start: 1px solid var(--dw-line-soft); background: transparent; }
    @media (max-width: 900px) { [data-hide='900'] { display: none; } }
    @media (max-width: 1200px) { [data-hide='1200'] { display: none; } }
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
  readonly trackBy = input<(row: T) => unknown>((row) => (row as { ulid?: unknown }).ulid ?? row);
  readonly clickable = input(false);
  readonly emptyTitle = input('');
  readonly emptyHint = input('');
  readonly emptyIcon = input('inbox');

  readonly page = output<PageEvent>();
  readonly sortChange = output<string | null>();
  readonly rowClick = output<T>();
  readonly retry = output<void>();

  private readonly cells = contentChildren(CellDirective);
  protected readonly templates = computed(() => Object.fromEntries(this.cells().map((cell) => [cell.dwCell(), cell.template])) as Record<string, TemplateRef<{ $implicit: unknown }> | undefined>);

  /** Ascending, then descending, then back to the default order. */
  protected toggleSort(field: string): void {
    const current = this.sort();
    this.sortChange.emit(current === field ? `-${field}` : current === `-${field}` ? null : field);
  }

  protected sortIcon(field: string): string {
    return this.sort() === field ? 'arrow_upward' : this.sort() === `-${field}` ? 'arrow_downward' : 'unfold_more';
  }

  protected ariaSort(field: string): string {
    return this.sort() === field ? 'ascending' : this.sort() === `-${field}` ? 'descending' : 'none';
  }
}

export const TABLE = [DataTableComponent, CellDirective] as const;
