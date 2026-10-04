import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { SessionStore } from '../../../entities/session';
import { ApiError } from '../../../shared/api';
import { AuthTokenStore } from '../../../shared/auth';
import { LocaleService } from '../../../shared/i18n/locale.service';
import { LoginApi, LoginCompanyOption } from '../api/login.api';

@Component({
  selector: 'app-sign-in-form',
  imports: [ReactiveFormsModule],
  template: `
    <form class="sign-in-form" [formGroup]="form" (ngSubmit)="submit()" novalidate>
      @if (errorKey()) {
        <p class="form-error" role="alert">{{ locale.t(errorKey()!) }}</p>
      }

      <label>
        <span>{{ locale.t('emailAddress') }}</span>
        <input formControlName="email" type="email" inputmode="email" autocomplete="username" required [attr.aria-invalid]="showFieldError('email')" aria-describedby="sign-in-email-error" />
        @if (showFieldError('email')) { <span id="sign-in-email-error" class="field-error">{{ locale.t(form.controls.email.hasError('required') ? 'emailRequired' : 'emailInvalid') }}</span> }
      </label>

      <label>
        <span>{{ locale.t('password') }}</span>
        <input formControlName="password" type="password" autocomplete="current-password" required [attr.aria-invalid]="showFieldError('password')" aria-describedby="sign-in-password-error" />
        @if (showFieldError('password')) { <span id="sign-in-password-error" class="field-error">{{ locale.t('passwordRequired') }}</span> }
      </label>

      @if (companies().length > 0) {
        <label>
          <span>{{ locale.t('chooseCompany') }}</span>
          <select [value]="selectedCompany()" (change)="selectedCompany.set($any($event.target).value)" required>
            <option value="">{{ locale.t('chooseCompanyPlaceholder') }}</option>
            @for (company of companies(); track company.ulid) {
              <option [value]="company.ulid">{{ company.legal_name }}</option>
            }
          </select>
        </label>
      }

      <button class="button sign-in-submit" type="submit" [disabled]="submitting() || form.invalid || (companies().length > 0 && !selectedCompany())">
        {{ locale.t(submitting() ? 'signingIn' : 'signIn') }}
      </button>
    </form>
  `,
  styles: `
    .sign-in-form { display: grid; gap: 1rem; }
    label { display: grid; gap: .45rem; color: var(--text); font-size: .88rem; font-weight: 650; }
    input, select { width: 100%; min-height: 47px; padding: .7rem .8rem; border: 1px solid var(--border-strong); border-radius: 5px; background: var(--surface); color: var(--text); font: inherit; font-weight: 400; }
    input[aria-invalid="true"] { border-color: var(--danger); }
    .field-error, .form-error { margin: 0; color: var(--danger); font-size: .85rem; font-weight: 500; }
    .form-error { padding: .75rem; border-inline-start: 3px solid var(--danger); background: var(--danger-soft); }
    .sign-in-submit { width: 100%; margin-top: .25rem; }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SignInFormComponent {
  private readonly formBuilder = inject(FormBuilder);
  private readonly loginApi = inject(LoginApi);
  private readonly authToken = inject(AuthTokenStore);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);
  readonly locale = inject(LocaleService);
  readonly returnUrl = input.required<string>();
  readonly submitting = signal(false);
  readonly errorKey = signal<string | null>(null);
  readonly companies = signal<LoginCompanyOption[]>([]);
  readonly selectedCompany = signal('');
  readonly form = this.formBuilder.nonNullable.group({
    email: ['', [Validators.required, Validators.email, Validators.maxLength(255)]],
    password: ['', [Validators.required]],
  });

  showFieldError(field: 'email' | 'password'): boolean {
    const control = this.form.controls[field];
    return (control.dirty || control.touched) && control.invalid;
  }

  async submit(): Promise<void> {
    this.form.markAllAsTouched();
    if (this.form.invalid || this.submitting() || (this.companies().length > 0 && !this.selectedCompany())) return;

    this.submitting.set(true);
    this.errorKey.set(null);
    try {
      const response = await this.loginApi.execute({
        email: this.form.controls.email.value.trim(),
        password: this.form.controls.password.value,
        ...(this.selectedCompany() ? { company: this.selectedCompany() } : {}),
      });

      if (response.data.requires_company_selection) {
        this.companies.set(response.data.companies);
        this.errorKey.set('companySelectionRequired');
        return;
      }

      this.companies.set([]);
      this.authToken.set(response.data.access_token);
      await this.session.retry();
      if (this.session.status() === 'authenticated') {
        this.form.controls.password.setValue('');
        await this.router.navigateByUrl(this.returnUrl());
      } else if (this.session.status() === 'unauthenticated') {
        this.authToken.clear();
        this.errorKey.set('loginSessionFailed');
      }
    } catch (error) {
      if (error instanceof ApiError && error.kind === 'unauthenticated') this.errorKey.set('invalidCredentials');
      else if (error instanceof ApiError && error.kind === 'forbidden') this.errorKey.set('companyUnavailable');
      else if (error instanceof ApiError && error.kind === 'validation') this.errorKey.set('loginValidationError');
      else this.errorKey.set('loginUnexpectedError');
    } finally {
      this.submitting.set(false);
    }
  }
}