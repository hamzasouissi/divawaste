import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface Page<T> {
  data: T[];
  meta: PageMeta;
}

export type Query = Record<string, string | number | boolean | null | undefined | readonly string[] | Record<string, unknown>>;

/**
 * Error returned by the API (RFC 9457 problem+json). `code` is stable and translatable, `errors` holds the
 * per-field messages of a 422, `requestId` is what support needs to find the request in the logs.
 */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    readonly detail: string | null,
    readonly errors: Record<string, string[]>,
    readonly requestId: string | null,
    /** Figures behind a refused business rule (the limits of an authorisation, each with its answer…). */
    readonly context: Record<string, unknown> | null = null,
  ) {
    super(detail ?? code);
  }

  static from(response: HttpErrorResponse): ApiError {
    const body = (response.error ?? {}) as { code?: string; detail?: string; errors?: Record<string, string[]>; request_id?: string; context?: Record<string, unknown> };

    return new ApiError(
      response.status,
      response.status === 0 ? 'NETWORK' : (body.code ?? 'SERVER_ERROR'),
      body.detail ?? null,
      body.errors ?? {},
      body.request_id ?? response.headers?.get('X-Request-Id') ?? null,
      body.context ?? null,
    );
  }

  get isValidation(): boolean {
    return this.status === 422;
  }
}

/** Flattens `{ filter: { status: ['a', 'b'], site: 'x' }, page: 2 }` into `filter[status][]=a&filter[status][]=b&filter[site]=x&page=2`. */
export function toParams(query: Query = {}): HttpParams {
  let params = new HttpParams();
  const append = (key: string, value: unknown): void => {
    if (value === null || value === undefined || value === '') {
      return;
    }
    if (Array.isArray(value)) {
      value.forEach((item) => (params = params.append(`${key}[]`, String(item))));
    } else if (typeof value === 'object') {
      Object.entries(value as Record<string, unknown>).forEach(([child, childValue]) => append(`${key}[${child}]`, childValue));
    } else {
      params = params.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    }
  };
  Object.entries(query).forEach(([key, value]) => append(key, value));

  return params;
}

/**
 * The only place that knows the API base path and the response envelope. Features call it with relative paths
 * and receive the unwrapped `data`.
 */
@Injectable({ providedIn: 'root' })
export class Api {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/v1';

  get<T>(path: string, query?: Query): Observable<T> {
    return this.http.get<{ data: T }>(this.url(path), { params: toParams(query) }).pipe(map((body) => body.data));
  }

  /** Lists: keeps `meta` (pagination) and any extra top-level keys next to `data`. */
  list<T, Extra = unknown>(path: string, query?: Query): Observable<Page<T> & Extra> {
    return this.http.get<Page<T> & Extra>(this.url(path), { params: toParams(query) });
  }

  /** Whole response body, for the few endpoints that return more than `data`. */
  raw<T>(path: string, query?: Query): Observable<T> {
    return this.http.get<T>(this.url(path), { params: toParams(query) });
  }

  post<T = void>(path: string, body: unknown = {}, query?: Query): Observable<T> {
    return this.http.post<{ data: T } | null>(this.url(path), body, { params: toParams(query) }).pipe(map((res) => (res?.data ?? undefined) as T));
  }

  put<T = void>(path: string, body: unknown = {}): Observable<T> {
    return this.http.put<{ data: T } | null>(this.url(path), body).pipe(map((res) => (res?.data ?? undefined) as T));
  }

  delete<T = void>(path: string, body?: unknown): Observable<T> {
    return this.http.delete<{ data: T } | null>(this.url(path), { body }).pipe(map((res) => (res?.data ?? undefined) as T));
  }

  blob(path: string, query?: Query): Observable<Blob> {
    return this.http.get(this.url(path), { params: toParams(query), responseType: 'blob' });
  }

  private url(path: string): string {
    return `${this.base}/${path.replace(/^\//, '')}`;
  }
}

/** Opens a generated or stored file (PDF label, export, certificate) in a new tab, or saves it under a name. */
export function openBlob(blob: Blob, filename?: string): void {
  const url = URL.createObjectURL(blob);
  if (filename) {
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
  } else {
    window.open(url, '_blank', 'noopener');
  }
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}
