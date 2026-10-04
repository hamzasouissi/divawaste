import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';

import { Audience, Session } from './session';

/*
 * Every guard takes what it needs from the injector BEFORE waiting for the session: `inject()` is only valid
 * synchronously, and a guard that redirects after an `await` would otherwise fail and leave a blank page.
 */

/** Signed-in users only; remembers where the visitor wanted to go. */
export const authGuard: CanActivateFn = async (_route, state) => {
  const session = inject(Session);
  const router = inject(Router);
  await session.ensureLoaded();

  return session.isAuthenticated() ? true : router.createUrlTree(['/login'], { queryParams: { next: state.url } });
};

/** Public screens (sign-in, registration) send a signed-in user to their home. */
export const guestGuard: CanActivateFn = async () => {
  const session = inject(Session);
  const router = inject(Router);
  await session.ensureLoaded();

  return session.isAuthenticated() ? router.parseUrl(session.home()) : true;
};

/** Each area belongs to one kind of account: industrial, provider or platform staff. */
export function audienceGuard(...allowed: Audience[]): CanActivateFn {
  return async () => {
    const session = inject(Session);
    const router = inject(Router);
    await session.ensureLoaded();

    return allowed.includes(session.audience()) ? true : router.parseUrl(session.home());
  };
}

/** An industrial user without desktop access (a workshop operator) belongs to the workshop application. */
export const desktopGuard: CanActivateFn = async () => {
  const session = inject(Session);
  const router = inject(Router);
  await session.ensureLoaded();

  return session.audience() !== 'industrial' || session.can('app.web.access') ? true : router.parseUrl('/m');
};

/**
 * Hides a screen the user has no permission for. This is navigation comfort only: the API refuses the
 * underlying calls regardless of what the client shows.
 */
export function permissionGuard(...anyOf: string[]): CanActivateFn {
  return async () => {
    const session = inject(Session);
    const router = inject(Router);
    await session.ensureLoaded();

    return anyOf.some((permission) => session.can(permission) || session.canPlatform(permission)) ? true : router.parseUrl(session.home());
  };
}
