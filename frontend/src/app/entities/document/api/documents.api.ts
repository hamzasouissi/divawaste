import { Injectable, inject } from '@angular/core'; import { ApiClient, PaginatedEnvelope } from '../../../shared/api';
export interface DocumentDto extends Record<string,unknown>{ulid:string;type:string;title:string;reference:string|null;status:string;is_regulatory:boolean;issued_at:string|null;created_at:string;}
@Injectable({providedIn:'root'}) export class DocumentsApi {private readonly api=inject(ApiClient);list(page=1){return this.api.get<PaginatedEnvelope<DocumentDto>>('/documents',{page,per_page:25});}}
