import { Injectable, inject } from '@angular/core'; import { ApiClient, PaginatedEnvelope } from '../../../shared/api';
export interface WasteTypeDto extends Record<string, unknown> { ulid:string;code:string;name:string;description:string|null;is_hazardous:boolean;is_active:boolean; }
@Injectable({providedIn:'root'}) export class WasteTypesApi { private readonly api=inject(ApiClient); list(page=1,activeOnly=true){return this.api.get<PaginatedEnvelope<WasteTypeDto>>('/waste-types',{page,per_page:25,active_only:activeOnly});} }
