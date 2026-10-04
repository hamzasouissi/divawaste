import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';

export interface ApiEnvelope<T> { data: T; }
export interface PaginationMeta { current_page: number; last_page: number; per_page: number; total: number; }
export interface PaginatedEnvelope<T> extends ApiEnvelope<T[]> { links: Record<string, string | null>; meta: PaginationMeta; }

@Injectable({ providedIn: 'root' })
export class ApiClient {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = '/api/v1';

  get<T>(path: string, params?: Record<string, string | number | boolean | undefined>): Promise<T> {
    const clean: Record<string, string | number | boolean> = {};
    for (const [key, value] of Object.entries(params ?? {})) if (value !== undefined) clean[key] = value;
    return firstValueFrom<T>(this.http.get<T>(`${this.baseUrl}${path}`, { params: clean, withCredentials: true }));
  }

  post<T>(path: string, body?: unknown): Promise<T> {
    return firstValueFrom(this.http.post<T>(`${this.baseUrl}${path}`, body ?? {}, { withCredentials: true }));
  }

  put<T>(path: string, body: unknown): Promise<T> {
    return firstValueFrom(this.http.put<T>(`${this.baseUrl}${path}`, body, { withCredentials: true }));
  }
}
