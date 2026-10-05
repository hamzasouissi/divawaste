import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideZonelessChangeDetection } from '@angular/core';
import { TestBed } from '@angular/core/testing';

import { navigationFor } from '../../layout/navigation';
import { Access, Me } from '../api/models';
import { apiErrorInterceptor } from './interceptors';
import { Session } from './session';

const me = (overrides: Partial<Me> = {}): Me => ({
  ulid: 'U1', first_name: 'Leila', last_name: 'Mansour', email: 'leila@capbon.test', phone: null, locale: 'fr', email_verified: true, two_factor_enabled: false,
  platform_roles: [], platform_permissions: [], memberships: [{ company: 'C1', legal_name: 'Textile du Cap Bon', company_type: 'industrial', status: 'active', reason: null }],
  ...overrides,
});

const access = (overrides: Partial<Access> = {}): Access => ({
  company: { ulid: 'C1', company_type: 'industrial', status: 'active', legal_name: 'Textile du Cap Bon' } as Access['company'],
  roles: [],
  company_permissions: [],
  sites: [
    { ulid: 'NAB', code: 'NAB', name: 'Usine de Nabeul', is_active: true, permissions: ['app.mobile.access', 'lots.create', 'lots.view', 'pickups.load'] },
    { ulid: 'KOR', code: 'KOR', name: 'Atelier de Korba', is_active: true, permissions: [] },
  ],
  ...overrides,
});

describe('Session', () => {
  let session: Session;
  let http: HttpTestingController;

  const load = async (user: Me, rights: Access | null): Promise<void> => {
    const loading = session.reload();
    http.expectOne('/api/v1/me').flush({ data: user });
    await Promise.resolve();
    if (user.memberships.length > 0) {
      const request = http.expectOne('/api/v1/me/access');
      if (rights) {
        request.flush({ data: rights });
      } else {
        request.flush({ code: 'FORBIDDEN' }, { status: 403, statusText: 'Forbidden' });
      }
    }
    await loading;
  };

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideZonelessChangeDetection(), provideHttpClient(withInterceptors([apiErrorInterceptor])), provideHttpClientTesting()] });
    session = TestBed.inject(Session);
    http = TestBed.inject(HttpTestingController);
  });

  it('grants a site permission on that site only', async () => {
    await load(me(), access());

    expect(session.can('lots.create', 'NAB')).toBe(true);
    expect(session.can('lots.create', 'KOR')).toBe(false);
    expect(session.can('lots.create')).toBe(true);
    expect(session.can('pickups.request')).toBe(false);
    expect(session.sitesFor('lots.create').map((site) => site.ulid)).toEqual(['NAB']);
  });

  it('sends an operator without desktop access to the workshop application', async () => {
    await load(me(), access());

    expect(session.audience()).toBe('industrial');
    expect(session.home()).toBe('/m');
    expect(navigationFor(session).flatMap((group) => group.items.map((item) => item.path))).toEqual(['/lots', '/m']);
  });

  it('sends a desktop user to the dashboard and shows only what the role allows', async () => {
    await load(me(), access({ company_permissions: ['app.web.access', 'dashboard.view', 'reports.view'] }));

    expect(session.home()).toBe('/dashboard');
    const paths = navigationFor(session).flatMap((group) => group.items.map((item) => item.path));
    expect(paths).toContain('/dashboard');
    expect(paths).toContain('/reports');
    expect(paths).not.toContain('/admin/users');
    expect(paths).not.toContain('/platform/companies');
  });

  it('routes a provider and a platform user to their own area', async () => {
    await load(me(), access({ company: { ulid: 'P1', company_type: 'provider', status: 'active', legal_name: 'Recyclage du Sahel' } as Access['company'], company_permissions: ['provider.pickups.view'], sites: [] }));
    expect(session.home()).toBe('/provider/pickups');
    expect(navigationFor(session).flatMap((group) => group.items.map((item) => item.path))).toContain('/provider/pickups');

    await load(me({ memberships: [], platform_permissions: ['platform.companies.review'] }), null);
    expect(session.audience()).toBe('platform');
    expect(session.home()).toBe('/platform/overview');
    expect(session.can('lots.view')).toBe(false);
  });

  it('keeps a company that is not active yet out of the workspace', async () => {
    await load(me(), access({ company: { ulid: 'C1', company_type: 'industrial', status: 'pending_approval', legal_name: 'Nouvelle société' } as Access['company'] }));

    expect(session.audience()).toBe('none');
    expect(session.home()).toBe('/account-status');
    expect(navigationFor(session)).toEqual([]);
  });

  it('is signed out when the API answers 401', async () => {
    const loading = session.reload();
    http.expectOne('/api/v1/me').flush({ code: 'UNAUTHENTICATED' }, { status: 401, statusText: 'Unauthorized' });
    await loading;

    expect(session.isAuthenticated()).toBe(false);
    expect(session.can('lots.view')).toBe(false);
  });

  it('signs out on the server, and when the server cannot be reached finishes the sign-out on the next load', async () => {
    localStorage.clear();
    await load(me(), access());

    // No network: the screen is signed out at once, the server session is still open.
    const leaving = session.logout();
    http.expectOne('/api/v1/auth/logout').error(new ProgressEvent('error'));
    await leaving;
    expect(session.isAuthenticated()).toBe(false);

    // Next load: the session is closed on the server before anything is read, so the operator is not signed in again.
    const reopening = session.reload();
    http.expectOne('/api/v1/auth/logout').flush(null, { status: 204, statusText: 'No Content' });
    await reopening;
    http.expectNone('/api/v1/me');
    expect(session.isAuthenticated()).toBe(false);

    // Once done, loading reads the session normally again.
    await load(me(), access());
    expect(session.isAuthenticated()).toBe(true);

    const online = session.logout();
    http.expectOne('/api/v1/auth/logout').flush(null, { status: 204, statusText: 'No Content' });
    await online;
    const after = session.reload();
    http.expectOne('/api/v1/me').flush({ code: 'UNAUTHENTICATED' }, { status: 401, statusText: 'Unauthorized' });
    await after;
    expect(session.isAuthenticated()).toBe(false);
  });
});
