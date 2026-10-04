import { HttpErrorResponse } from '@angular/common/http';

export type ApiErrorKind =
  | 'unauthenticated'
  | 'forbidden'
  | 'not-found'
  | 'validation'
  | 'conflict'
  | 'network'
  | 'server'
  | 'unexpected';

export interface ProblemDetails {
  title?: string;
  status?: number;
  code?: string;
  detail?: string;
  errors?: Record<string, string[]>;
  request_id?: string;
}

export class ApiError extends Error {
  constructor(
    readonly kind: ApiErrorKind,
    readonly status: number,
    readonly problem: ProblemDetails,
    readonly requestId: string | null,
  ) {
    super(problem.title ?? 'Request failed');
  }

  static fromHttp(error: HttpErrorResponse): ApiError {
    const problem = typeof error.error === 'object' && error.error !== null ? error.error as ProblemDetails : {};
    const status = error.status;
    const kind: ApiErrorKind = status === 0 ? 'network' : status === 401 ? 'unauthenticated' : status === 403 ? 'forbidden' : status === 404 ? 'not-found' : status === 422 ? 'validation' : status === 409 ? 'conflict' : status >= 500 ? 'server' : 'unexpected';
    return new ApiError(kind, status, problem, error.headers.get('X-Request-Id') ?? problem.request_id ?? null);
  }
}
