import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { catchError, throwError } from 'rxjs';

import { ApiError } from '../api/api';
import { Session } from './session';

/** Every API call is a JSON call of the cookie session, in the user's language. */
export const apiRequestInterceptor: HttpInterceptorFn = (request, next) => {
  if (!request.url.startsWith('/api/') && !request.url.startsWith('/sanctum/')) {
    return next(request);
  }

  return next(
    request.clone({
      withCredentials: true,
      setHeaders: { Accept: 'application/json', 'Accept-Language': inject(TranslocoService).getActiveLang() },
    }),
  );
};

/**
 * Turns HTTP failures into ApiError. An expired session sends the user back to the sign-in screen with the
 * page they were on, except for the calls that are expected to answer 401 (probing the session, signing in).
 */
export const apiErrorInterceptor: HttpInterceptorFn = (request, next) => {
  const router = inject(Router);
  const session = inject(Session);

  return next(request).pipe(
    catchError((response: unknown) => {
      if (!(response instanceof HttpErrorResponse) || !request.url.startsWith('/api/')) {
        return throwError(() => response);
      }

      const error = ApiError.from(response);
      const isAuthCall = /\/api\/v1\/(me$|auth\/)/.test(request.url);

      if (error.status === 401 && !isAuthCall && session.isAuthenticated()) {
        session.clear();
        void router.navigate(['/login'], { queryParams: { next: router.url, expired: 1 } });
      }

      return throwError(() => error);
    }),
  );
};
