import { Injectable, inject } from '@angular/core';
import { ApiClient, PaginatedEnvelope } from '../../../shared/api';

export interface StockThresholdDto extends Record<string, unknown> {
  ulid: string;
  site: string | null;
  zone: string | null;
  waste_type: string | null;
  max_quantity_kg: string | number;
  warning_pct: number | null;
  alert_level: string | null;
  alerted_at: string | null;
  is_active: boolean;
}

@Injectable({ providedIn: 'root' })
export class StockThresholdsApi {
  private readonly api = inject(ApiClient);

  list(page = 1): Promise<PaginatedEnvelope<StockThresholdDto>> {
    return this.api.get<PaginatedEnvelope<StockThresholdDto>>('/stock-thresholds', { page, per_page: 25 });
  }
}