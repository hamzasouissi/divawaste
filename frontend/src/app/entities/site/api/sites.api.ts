import { Injectable, inject } from '@angular/core';
import { ApiClient, PaginatedEnvelope } from '../../../shared/api';

export interface SiteDto extends Record<string, unknown> {
	ulid: string;
	code: string;
	name: string;
	site_kind: string;
	city: string | null;
	region: string | null;
	timezone: string | null;
	capacity_kg: string | null;
	is_active: boolean;
}

@Injectable({ providedIn: 'root' })
export class SitesApi {
	private readonly api = inject(ApiClient);

	list(page = 1): Promise<PaginatedEnvelope<SiteDto>> {
		return this.api.get<PaginatedEnvelope<SiteDto>>('/sites', { page, per_page: 25 });
	}
}
