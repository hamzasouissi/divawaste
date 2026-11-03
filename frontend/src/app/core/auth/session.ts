import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { Api, ApiError } from '../api/api';
import { Access, AccessSite, Company, CompanyModule, Me } from '../api/models';

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
    // A load that failed (server busy or out of reach) is tried again on the next navigation, not kept failed.
    this.loading ??= this.reload().catch((error: unknown) => {
      this.loading = null;
      throw error;
    });

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
      const me = await this.fetchMe();
      this.meState.set(me);
      this.accessState.set(me.memberships.length > 0 ? await firstValueFrom(this.api.get<Access>('me/access')).catch(() => null) : null);
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 401) {
        throw error;
      }
      this.clear();
    }
  }

  /** The signed-in user; a busy server (too many requests, a 502/503) is asked again twice, after 1 s then 3 s. */
  private async fetchMe(): Promise<Me> {
    for (const wait of [1000, 3000, 0]) {
      try {
        return await firstValueFrom(this.api.get<Me>('me'));
      } catch (error) {
        const busy = error instanceof ApiError && [429, 502, 503].includes(error.status);
        if (!busy || wait === 0) {
          throw error;
        }
        await new Promise((resolve) => setTimeout(resolve, wait));
      }
    }
    throw new Error('unreachable');
  }

  async login(credentials:{ email: string; password: string; code?: string; recovery_code?: string; company?: string }): Promise<LoginResult> {
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

  /**
   * G-02: a module the company switched off takes its rights with it in the interface — its buttons and fields
   * disappear everywhere, not only its menu. PRD-02: with an ERP, production data are read-only here until someone
   * chooses to correct them (the API still decides).
   */
  private moduleAllows(permission: string): boolean {
    if (permission.startsWith('production.finance')) {
      return this.uses('production') && this.uses('production_finance');
    }
    if (permission.startsWith('production.')) {
      return this.uses('production');
    }
    if (permission.startsWith('marketplace.')) {
      return this.uses('marketplace');
    }
    if (permission.startsWith('contracts.')) {
      return this.uses('contracts');
    }
    return true;
  }

  /** Production data of an ERP company opened for correction, for this visit only. */
  readonly erpUnlocked = signal(false);

  /** PRD-02: production records are edited here only without an ERP, or once the correction is opened. */
  canEditProduction(): boolean {
    return this.can('production.manage') && (!this.withErp() || this.erpUnlocked());
  }

  /** Company-wide permission, or a site permission on the given site (any site when none is given). */
  can(permission: string, siteUlid?: string | null): boolean {
    const access = this.accessState();
    if (!access || !this.moduleAllows(permission)) {
      return false;
    }
    if (access.company_permissions.includes(permission)) {
      return true;
    }

    return siteUlid ? (access.sites.find((site) => site.ulid === siteUlid)?.permissions.includes(permission) ?? false) : this.canAnywhere(permission);
  }

  canAnywhere(permission: string): boolean {
    return this.moduleAllows(permission) && (this.accessState()?.sites.some((site) => site.permissions.includes(permission)) ?? false);
  }

  /** Whether the company uses a part of the product (all of them until it chose). */
  uses(module: CompanyModule): boolean {
    return this.company()?.modules?.[module] ?? true;
  }

  /** Works with an ERP: production data come from it and are read here. */
  readonly withErp = computed(() => this.company()?.profile?.erp === true);
  /** Said it has no ERP (a company that never answered keeps the ways in of an ERP). */
  readonly noErp = computed(() => this.company()?.profile?.erp === false);

  /** After a change of the company (modules, identity): the new one, without reloading the session. */
  setCompany(company: Company): void {
    const access = this.accessState();
    if (access) {
      this.accessState.set({ ...access, company });
    }
  }

  canPlatform(permission: string): boolean {
    return this.meState()?.platform_permissions.includes(permission) ?? false;
  }

  /** Sites where the user holds a permission: what a site selector should offer for that action. */
  sitesFor(permission: string): AccessSite[] {
    return this.sites().filter((site) => site.is_active && site.permissions.includes(permission));
  }
}
