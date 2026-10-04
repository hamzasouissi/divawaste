import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';

import { Audience, Session } from './session';

/** Signed-in users only; remembers where the visitor wanted to go. */
export const authGuard: CanActivateFn = async (_route, state) => {
  const session = inject(Session);
  await session.ensureLoaded();

  return session.isAuthenticated() ? true : inject(Router).createUrlTree(['/login'], { queryParams: { next: state.url } });
};

/** Public screens (sign-in, registration) send a signed-in user to their home. */
export const guestGuard: CanActivateFn = async () => {
  const session = inject(Session);
  await session.ensureLoaded();

  return session.isAuthenticated() ? inject(Router).parseUrl(session.home()) : true;
};

/** Each area belongs to one kind of account: industrial, provider or platform staff. */
export function audienceGuard(...allowed: Audience[]): CanActivateFn {
  return async () => {
    const session = inject(Session);
    await session.ensureLoaded();

    return allowed.includes(session.audience()) ? true : inject(Router).parseUrl(session.home());
  };
}

/**
 * Hides a screen the user has no permission for. This is navigation comfort only: the API refuses the
 * underlying calls regardless of what the client shows.
 */
export function permissionGuard(...anyOf: string[]): CanActivateFn {
  return async () => {
    const session = inject(Session);
    await session.ensureLoaded();

    return anyOf.some((permission) => session.can(permission) || session.canPlatform(permission)) ? true : inject(Router).parseUrl(session.home());
  };
}
