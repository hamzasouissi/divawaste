import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { Api, ApiError } from '../api/api';
import { Access, AccessSite, Me } from '../api/models';

export type Audience = 'platform' | 'industrial' | 'provider' | 'none';

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

  /** Where this person starts: the workshop application for someone who only has mobile rights. */
  readonly home = computed(() => {
    switch (this.audience()) {
      case 'platform':
        return '/platform';
      case 'provider':
        return '/provider';
      case 'industrial':
        return this.can('app.web.access') ? '/dashboard' : '/m';
      default:
        return '/account-status';
    }
  });

  /** Loads the session once per page load; later calls reuse the result. */
  ensureLoaded(): Promise<void> {
    this.loading ??= this.reload();

    return this.loading;
  }

  async reload(): Promise<void> {
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
    await firstValueFrom(this.api.post('auth/logout')).catch(() => undefined);
    this.clear();
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
