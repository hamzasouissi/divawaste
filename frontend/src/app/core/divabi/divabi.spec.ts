import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideZonelessChangeDetection } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';

import { Access, Me } from '../api/models';
import { apiErrorInterceptor } from '../auth/interceptors';
import { Session } from '../auth/session';
import { DivaBi } from './divabi';

interface Options { tokenProvider?: () => Promise<string> }

const me = (ulid: string, company: string): Me => ({
  ulid, first_name: 'Leila', last_name: 'Mansour', email: `${ulid}@capbon.test`, phone: null, locale: 'fr', email_verified: true, two_factor_enabled: false,
  platform_roles: [], platform_permissions: [], memberships: [{ company, legal_name: company, company_type: 'industrial', status: 'active', reason: null }],
});

const access = (company: string): Access => ({
  company: { ulid: company, company_type: 'industrial', status: 'active', legal_name: company } as Access['company'],
  roles: [], company_permissions: ['custom_views.view'], sites: [],
});

/**
 * Signing out, signing in as someone else or switching company does not reload the page: custom views must then use
 * a token of the new user and company, never the one DivaBI was given before.
 */
describe('DivaBi', () => {
  let session: Session;
  let divabi: DivaBi;
  let http: HttpTestingController;
  let configured: Options[];

  const signIn = async (user: string, company: string): Promise<void> => {
    const loading = session.reload();
    http.expectOne('/api/v1/me').flush({ data: me(user, company) });
    await new Promise((resolve) => setTimeout(resolve));
    http.expectOne('/api/v1/me/access').flush({ data: access(company) });
    await loading;
  };

  const start = async (token: string): Promise<boolean> => {
    const started = divabi.load();
    http.expectOne('/api/v1/custom-views/session').flush({ data: { token, expires_at: '2026-01-01T00:00:00Z', url: '/divabi', can_build: false } });

    return started;
  };

  /** The token DivaBI would send now: it asks its provider whenever it was given a new one. */
  const tokenDivabiUses = async (answer: string): Promise<string> => {
    const asking = configured[configured.length - 1].tokenProvider!();
    await new Promise((resolve) => setTimeout(resolve));
    http.match('/api/v1/custom-views/session').forEach((request) => request.flush({ data: { token: answer, expires_at: '2026-01-01T00:00:00Z', url: '/divabi', can_build: false } }));

    return asking;
  };

  beforeEach(() => {
    configured = [];
    (window as unknown as { DivaBI?: unknown }).DivaBI = { configure: (options: Options) => configured.push(options) };
    TestBed.configureTestingModule({
      providers: [provideZonelessChangeDetection(), provideRouter([]), provideHttpClient(withInterceptors([apiErrorInterceptor])), provideHttpClientTesting(),
        { provide: TranslocoService, useValue: { getActiveLang: () => 'fr' } }],
    });
    session = TestBed.inject(Session);
    divabi = TestBed.inject(DivaBi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    delete (window as unknown as { DivaBI?: unknown }).DivaBI;
  });

  it('starts once for the same user and company', async () => {
    await signIn('U1', 'C1');
    expect(await start('token-U1-C1')).toBe(true);
    expect(await tokenDivabiUses('unused')).toBe('token-U1-C1');

    // Another screen with custom views: nothing is asked again.
    expect(await divabi.load()).toBe(true);
    http.expectNone('/api/v1/custom-views/session');
  });

  it('starts again with a new token after a company switch', async () => {
    await signIn('U1', 'C1');
    await start('token-U1-C1');
    await tokenDivabiUses('unused');

    await signIn('U1', 'C2');
    const restarting = divabi.load();
    // The previous token is dropped at once, before DivaBI can use it again.
    expect(divabi.ready()).toBeNull();
    http.expectOne('/api/v1/custom-views/session').flush({ data: { token: 'token-U1-C2', expires_at: '2026-01-01T00:00:00Z', url: '/divabi', can_build: false } });
    expect(await restarting).toBe(true);

    expect(await tokenDivabiUses('unused')).toBe('token-U1-C2');
  });

  it('does not keep the previous user after a sign-out and another sign-in', async () => {
    await signIn('U1', 'C1');
    await start('token-U1-C1');

    session.clear();
    await signIn('U2', 'C1');
    await start('token-U2-C1');

    expect(await tokenDivabiUses('unused')).toBe('token-U2-C1');
  });
});
