import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { Api, ApiError } from '../api/api';
import { Access, AccessSite, Me } from '../api/models';

export type Audience = 'platform' | 'industrial' | 'provider' | 'none';

/** Per kind of account: [permission, page] in order of preference for the landing page. */
const LANDING: Record<'platform' | 'provider' | 'industrial', [string, string][]> = {
  platform: [
    ['platform.companies.review', '/platform/overview'], ['platform.providers.review', '/platform/accreditations'], ['platform.payments.validate', '/platform/payments'],
    ['platform.catalog.manage', '/platform/catalog'], ['platform.roles.manage', '/platform/roles'], ['platform.support.view', '/platform/activity'],
  ],
  provider: [
    ['provider.pickups.view', '/provider/pickups'], ['provider.profile.manage', '/provider/profile'], ['provider.accreditations.manage', '/provider/accreditations'], ['users.view', '/admin/users'],
  ],
  industrial: [
    ['dashboard.view', '/dashboard'], ['lots.view', '/lots'], ['stock.view', '/stock'], ['pickups.view', '/pickups'], ['documents.view', '/compliance/register'],
    ['reports.view', '/reports'], ['sites.view', '/sites'], ['users.view', '/admin/users'],
  ],
};

/** Set when the operator signed out without network: the server session is closed before anything else on the next load. */
const SIGN_OUT_PENDING = 'dw.sign-out-pending';

function signOutPending(value?: boolean): boolean {
  try {
    if (value === true) {
      localStorage.setItem(SIGN_OUT_PENDING, '1');
    } else if (value === false) {
      localStorage.removeItem(SIGN_OUT_PENDING);
    }

    return localStorage.getItem(SIGN_OUT_PENDING) === '1';
  } catch {
    return false;
  }
}

export interface LoginResult {
  requires_two_factor?: boolean;
  requires_company_selection?: boolean;
  companies?: { ulid: string; legal_name: string }[];
}

/**
 * Who is signed in and what they may do in the current company. The web application uses the Sanctum
 * cookie session (cahier §5): nothing secret is kept in the browser's storage.
 *
 * Permissions here only shape the interface (navigation, visible actions). The API enforces every one of
 * them again on each request.
 */
@Injectable({ providedIn: 'root' })
export class Session {
  private readonly api = inject(Api);
  private readonly http = inject(HttpClient);

  private readonly meState = signal<Me | null>(null);
  private readonly accessState = signal<Access | null>(null);
  private loading: Promise<void> | null = null;

  readonly me = this.meState.asReadonly();
  readonly access = this.accessState.asReadonly();
  readonly company = computed(() => this.accessState()?.company ?? null);
  readonly sites = computed<AccessSite[]>(() => this.accessState()?.sites ?? []);
  readonly isAuthenticated = computed(() => this.meState() !== null);

  readonly audience = computed<Audience>(() => {
    const access = this.accessState();
    if (access && access.company.status === 'active') {
      return access.company.company_type === 'provider' ? 'provider' : 'industrial';
    }

    return (this.meState()?.platform_permissions.length ?? 0) > 0 ? 'platform' : 'none';
  });

  constructor() {
    // A sign-out asked without network is finished as soon as the network is back, without waiting for a reload.
    addEventListener('online', () => {
      if (signOutPending()) {
        void this.closeServerSession();
      }
    });
  }

  /** Where this person starts: the workshop application for someone who only has mobile rights. */
  readonly home = computed(() => {
    switch (this.audience()) {
      // The landing page is the first screen the role may actually open: sending a user to a page his role
      // does not include would bounce him back here forever.
      case 'platform':
        return this.firstAllowed(LANDING.platform, (permission) => this.canPlatform(permission));
      case 'provider':
        return this.firstAllowed(LANDING.provider, (permission) => this.can(permission));
      case 'industrial':
        return this.can('app.web.access') ? this.firstAllowed(LANDING.industrial, (permission) => this.can(permission)) : '/m';
      default:
        return '/account-status';
    }
  });

  private firstAllowed(candidates: [string, string][], allowed: (permission: string) => boolean): string {
    return candidates.find(([permission]) => allowed(permission))?.[1] ?? '/account/profile';
  }

  /** Loads the session once per page load; later calls reuse the result. */
  ensureLoaded(): Promise<void> {
    this.loading ??= this.reload();

    return this.loading;
  }

  async reload(): Promise<void> {
    if (signOutPending()) {
      // A sign-out asked without network: finish it now, and stay signed out whatever happens.
      await this.closeServerSession();
      this.clear();

      return;
    }
    try {
      const me = await firstValueFrom(this.api.get<Me>('me'));
      this.meState.set(me);
      this.accessState.set(me.memberships.length > 0 ? await firstValueFrom(this.api.get<Access>('me/access')).catch(() => null) : null);
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 401) {
        throw error;
      }
      this.clear();
    }
  }

  async login(credentials: { email: string; password: string; code?: string; recovery_code?: string; company?: string }): Promise<LoginResult> {
    // The CSRF cookie must exist before the first state-changing request of a cookie session.
    await firstValueFrom(this.http.get('/sanctum/csrf-cookie', { responseType: 'text' }));
    // Signing in replaces the session: a sign-out that was still waiting for the network no longer applies.
    signOutPending(false);
    const result = await firstValueFrom(this.api.post<LoginResult>('auth/login', { ...credentials, mode: 'session' }));

    if (!result.requires_two_factor && !result.requires_company_selection) {
      this.loading = this.reload();
      await this.loading;
    }

    return result;
  }

  async switchCompany(company: string): Promise<void> {
    await firstValueFrom(this.api.post('auth/switch-company', { company }));
    this.loading = this.reload();
    await this.loading;
  }

  async logout(): Promise<void> {
    await this.closeServerSession();
    this.clear();
  }

  /** Closes the session on the server. If it cannot be reached, the sign-out is remembered and finished on the next load. */
  private async closeServerSession(): Promise<void> {
    try {
      await firstValueFrom(this.api.post('auth/logout'));
      signOutPending(false);
    } catch (error) {
      // 401: the session is already closed. Anything else: the server did not hear us.
      signOutPending(!(error instanceof ApiError && error.status === 401));
    }
  }

  clear(): void {
    this.meState.set(null);
    this.accessState.set(null);
    this.loading = Promise.resolve();
  }

  /** Company-wide permission, or a site permission on the given site (any site when none is given). */
  can(permission: string, siteUlid?: string | null): boolean {
    const access = this.accessState();
    if (!access) {
      return false;
    }
    if (access.company_permissions.includes(permission)) {
      return true;
    }

    return siteUlid ? (access.sites.find((site) => site.ulid === siteUlid)?.permissions.includes(permission) ?? false) : this.canAnywhere(permission);
  }

  canAnywhere(permission: string): boolean {
    return this.accessState()?.sites.some((site) => site.permissions.includes(permission)) ?? false;
  }

  canPlatform(permission: string): boolean {
    return this.meState()?.platform_permissions.includes(permission) ?? false;
  }

  /** Sites where the user holds a permission: what a site selector should offer for that action. */
  sitesFor(permission: string): AccessSite[] {
    return this.sites().filter((site) => site.is_active && site.permissions.includes(permission));
  }
}
