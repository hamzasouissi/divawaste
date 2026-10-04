import { Injectable, inject } from '@angular/core'; import { ApiClient, PaginatedEnvelope } from '../../../shared/api';
export interface ReportDto extends Record<string,unknown>{ulid:string;report_type:string;format:string;status:string;started_at:string|null;completed_at:string|null;expires_at:string|null;created_at:string;}
@Injectable({providedIn:'root'}) export class ReportsApi {private readonly api=inject(ApiClient);list(page=1){return this.api.get<PaginatedEnvelope<ReportDto>>('/reports',{page,per_page:25});}}
