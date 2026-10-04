import { TestBed } from '@angular/core/testing';
import { ActivatedRouteSnapshot, Router, RouterStateSnapshot, UrlTree, provideRouter } from '@angular/router';
import { signal } from '@angular/core';
import { SessionStore, SessionStatus } from '../../entities/session';
import { sessionGuard } from './session.guard';

describe('sessionGuard', () => {
  function setup(status: SessionStatus): Router {
    const session = { status: signal(status), bootstrap: jasmine.createSpy('bootstrap').and.resolveTo() };
    TestBed.configureTestingModule({
      providers: [{ provide: SessionStore, useValue: session }, provideRouter([])],
    });
    return TestBed.inject(Router);
  }

  it('redirects unauthenticated users to login with a safe return path', async () => {
    const router = setup('unauthenticated');
    const result = await TestBed.runInInjectionContext(() => sessionGuard({} as ActivatedRouteSnapshot, { url: '/sites' } as RouterStateSnapshot));

    expect(router.serializeUrl(result as UrlTree)).toBe('/login?returnUrl=%2Fsites');
  });

  it('keeps tenant access failures out of the login flow', async () => {
    const router = setup('tenant-unavailable');
    const result = await TestBed.runInInjectionContext(() => sessionGuard({} as ActivatedRouteSnapshot, { url: '/sites' } as RouterStateSnapshot));

    expect(router.serializeUrl(result as UrlTree)).toBe('/access-unavailable');
  });
});