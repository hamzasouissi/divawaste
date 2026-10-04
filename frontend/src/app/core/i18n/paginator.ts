import { Injectable, inject } from '@angular/core';
import { MatPaginatorIntl } from '@angular/material/paginator';
import { TranslocoService } from '@jsverse/transloco';

/** Paginator texts from the translation files, like every other label of the product. */
@Injectable()
export class PaginatorLabels extends MatPaginatorIntl {
  private readonly transloco = inject(TranslocoService);

  constructor() {
    super();
    this.transloco.selectTranslation().subscribe(() => {
      this.itemsPerPageLabel = this.transloco.translate('paginator.perPage');
      this.nextPageLabel = this.transloco.translate('paginator.next');
      this.previousPageLabel = this.transloco.translate('paginator.previous');
      this.firstPageLabel = this.transloco.translate('paginator.first');
      this.lastPageLabel = this.transloco.translate('paginator.last');
      this.changes.next();
    });
  }

  override getRangeLabel = (page: number, pageSize: number, length: number): string => {
    if (length === 0 || pageSize === 0) {
      return this.transloco.translate('paginator.range', { from: 0, to: 0, total: length });
    }
    const from = page * pageSize + 1;

    return this.transloco.translate('paginator.range', { from, to: Math.min(from + pageSize - 1, length), total: length });
  };
}
