import { Injectable, inject } from '@angular/core'; import { ApiClient, ApiEnvelope } from '../../../shared/api';
export interface PickupDto extends Record<string,unknown>{ulid:string;pickup_number:string;status:string;requested_date:string;confirmed_date:string|null;departure_weight_kg:string|null;received_weight_kg:string|null;variance_pct:string|null;variance_flag:boolean;lots:Record<string,unknown>[];}
@Injectable({providedIn:'root'}) export class PickupsApi {private readonly api=inject(ApiClient);get(ulid:string){return this.api.get<ApiEnvelope<PickupDto>>(`/pickups/${ulid}`);}}
