import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { StockThresholdDto, StockThresholdsApi } from '../../entities/stock-threshold';
import { LocaleService } from '../../shared/i18n/locale.service';
import { ResourceCollectionComponent, TableColumn } from '../../shared/ui/resource-collection';

@Component({
  imports: [ResourceCollectionComponent],
  template: `<app-resource-collection [title]="locale.t('stockThresholds')" [description]="locale.t('stockThresholdsDescription')" [columns]="columns()" [load]="load" />`,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StockThresholdsPage {
  private readonly api = inject(StockThresholdsApi);
  readonly locale = inject(LocaleService);
  readonly load = (page: number) => this.api.list(page);
  readonly columns = computed<TableColumn<StockThresholdDto>[]>(() => [
    { key: 'site', label: this.locale.t('siteUlid') },
    { key: 'zone', label: this.locale.t('zoneUlid') },
    { key: 'waste_type', label: this.locale.t('wasteTypeUlid') },
    { key: 'max_quantity_kg', label: this.locale.t('maxQuantityKg') },
    { key: 'warning_pct', label: this.locale.t('warningPercent') },
    { key: 'alert_level', label: this.locale.t('alertLevel') },
    { key: 'alerted_at', label: this.locale.t('alertedAt') },
    { key: 'is_active', label: this.locale.t('status'), format: (value) => this.locale.t(value ? 'active' : 'inactive') },
  ]);
}