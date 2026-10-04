import { Injectable, inject } from '@angular/core';
import { ApiClient, ApiEnvelope } from '../../../shared/api';

export interface LoginCompanyOption {
  ulid: string;
  legal_name: string;
}

export interface LoginInput {
  email: string;
  password: string;
  company?: string;
}

export type LoginData =
  | { requires_company_selection: true; companies: LoginCompanyOption[] }
  | {
      requires_company_selection: false;
      access_token: string;
      token_type: 'Bearer';
      company: LoginCompanyOption | null;
      user: { ulid: string; email: string; first_name: string; last_name: string };
    };

@Injectable({ providedIn: 'root' })
export class LoginApi {
  private readonly api = inject(ApiClient);

  execute(input: LoginInput): Promise<ApiEnvelope<LoginData>> {
    return this.api.post<ApiEnvelope<LoginData>>('/auth/login', input);
  }
}