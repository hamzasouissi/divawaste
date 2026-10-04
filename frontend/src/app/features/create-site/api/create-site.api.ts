import { Injectable, inject } from '@angular/core';
import { ApiClient, ApiEnvelope } from '../../../shared/api';
import { SiteDto } from '../../../entities/site';

export interface CreateSiteInput {
  code: string;
  name: string;
  country: string;
  address_line1: string | null;
  city: string | null;
  postal_code: string | null;
  region: string | null;
  timezone: string | null;
  regulatory_identifier: string | null;
  capacity_kg: string | null;
}

@Injectable({ providedIn: 'root' })
export class CreateSiteApi {
  private readonly api = inject(ApiClient);

  execute(input: CreateSiteInput): Promise<ApiEnvelope<SiteDto>> {
    return this.api.post<ApiEnvelope<SiteDto>>('/sites', input);
  }
}