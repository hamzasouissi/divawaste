import { Injectable, inject } from '@angular/core'; import { ApiClient, ApiEnvelope } from '../../../shared/api';
export interface ProviderDto extends Record<string, unknown>{ulid:string;legal_name:string;is_collector:boolean;is_transporter:boolean;is_recycler:boolean;is_eliminator:boolean;service_area:string|null;}
@Injectable({providedIn:'root'}) export class ProvidersApi {private readonly api=inject(ApiClient); list(){return this.api.get<ApiEnvelope<ProviderDto[]>>('/providers/directory');}}
