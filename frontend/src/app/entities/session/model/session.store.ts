import { Injectable, computed, inject, signal } from '@angular/core';
import { AuthTokenStore } from '../../../shared/auth';
import { ApiClient, ApiError, ApiEnvelope } from '../../../shared/api';

export interface CurrentUser { ulid: string; first_name: string; last_name: string; email: string; locale: 'fr' | 'en' | 'ar' | string; two_factor_enabled: boolean; platform_roles: string[]; }
export interface CurrentCompany { ulid: string; company_type: 'industrial' | 'provider' | 'brand'; status: string; legal_name: string; trade_name: string | null; tax_id: string | null; currency_code: string; timezone: string; }
export type SessionStatus = 'loading' | 'authenticated' | 'unauthenticated' | 'tenant-unavailable' | 'error';

@Injectable({ providedIn: 'root' })
export class SessionStore {
  private readonly api = inject(ApiClient);
  private readonly authToken = inject(AuthTokenStore);
  readonly status = signal<SessionStatus>('loading');
  readonly user = signal<CurrentUser | null>(null);
  readonly company = signal<CurrentCompany | null>(null);
  readonly isReady = computed(() => this.status() !== 'loading');
  private bootstrapRequest: Promise<void> | null = null;

  bootstrap(): Promise<void> {
    if (this.bootstrapRequest !== null) return this.bootstrapRequest;
    if (this.status() !== 'loading') return Promise.resolve();
    return this.startBootstrap();
  }

  retry(): Promise<void> {
    if (this.bootstrapRequest !== null) return this.bootstrapRequest;
    this.status.set('loading');
    return this.startBootstrap();
  }

  private startBootstrap(): Promise<void> {
    const request = this.loadSession().finally(() => {
      if (this.bootstrapRequest === request) this.bootstrapRequest = null;
    });
    this.bootstrapRequest = request;
    return request;
  }

  private async loadSession(): Promise<void> {
    this.status.set('loading');
    try {
      const me = await this.api.get<ApiEnvelope<CurrentUser>>('/me');
      this.user.set(me.data);
      try {
        const company = await this.api.get<ApiEnvelope<CurrentCompany>>('/company');
        this.company.set(company.data);
        this.status.set('authenticated');
      } catch (error) {
        this.status.set(error instanceof ApiError && error.kind === 'forbidden' ? 'tenant-unavailable' : 'error');
      }
    } catch (error) {
      if (error instanceof ApiError && error.kind === 'unauthenticated') {
        this.authToken.clear();
        this.user.set(null);
        this.company.set(null);
        this.status.set('unauthenticated');
      } else {
        this.status.set('error');
      }
    }
  }
}
