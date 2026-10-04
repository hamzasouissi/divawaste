import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { Router, RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';

import { ApiError } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';

type Step = 'credentials' | 'two_factor' | 'company';

/** Sign-in: credentials, then the second factor when it is enabled, then the company when the user has several. */
@Component({
  imports: [ReactiveFormsModule, RouterLink, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h1 class="dw-h1">{{ t('auth.login.title') }}</h1>
      <p class="dw-muted lead">{{ t('auth.login.lead') }}</p>

      @if (expired()) {
        <p class="notice" role="status">{{ t('auth.login.expired') }}</p>
      }
      @if (failure()) {
        <p class="notice danger" role="alert">{{ failure() }}</p>
      }

      <form [formGroup]="form" (ngSubmit)="submit()" class="dw-stack-sm" novalidate>
        @switch (step()) {
          @case ('credentials') {
            <mat-form-field>
              <mat-label>{{ t('auth.email') }}</mat-label>
              <input matInput type="email" formControlName="email" autocomplete="username" required />
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ t('auth.password') }}</mat-label>
              <input matInput [type]="reveal() ? 'text' : 'password'" formControlName="password" autocomplete="current-password" required />
              <button mat-icon-button matSuffix type="button" (click)="reveal.set(!reveal())" [attr.aria-label]="t('auth.togglePassword')" [attr.aria-pressed]="reveal()">
                <mat-icon>{{ reveal() ? 'visibility_off' : 'visibility' }}</mat-icon>
              </button>
            </mat-form-field>
          }
          @case ('two_factor') {
            <p class="dw-muted">{{ recovery() ? t('auth.login.recoveryHint') : t('auth.login.codeHint') }}</p>
            @if (recovery()) {
              <mat-form-field>
                <mat-label>{{ t('auth.login.recoveryCode') }}</mat-label>
                <input matInput formControlName="recovery_code" autocomplete="off" />
              </mat-form-field>
            } @else {
              <mat-form-field>
                <mat-label>{{ t('auth.login.code') }}</mat-label>
                <input matInput formControlName="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" />
              </mat-form-field>
            }
            <button mat-button type="button" (click)="recovery.set(!recovery())">{{ recovery() ? t('auth.login.useCode') : t('auth.login.useRecovery') }}</button>
          }
          @case ('company') {
            <p class="dw-muted">{{ t('auth.login.chooseCompany') }}</p>
            <div class="companies" role="radiogroup" [attr.aria-label]="t('auth.login.chooseCompany')">
              @for (company of companies(); track company.ulid) {
                <button type="button" class="company" role="radio" [attr.aria-checked]="form.controls.company.value === company.ulid"
                  [class.selected]="form.controls.company.value === company.ulid" (click)="form.controls.company.setValue(company.ulid)">
                  {{ company.legal_name }}
                </button>
              }
            </div>
          }
        }

        <button mat-flat-button type="submit" class="submit" [disabled]="busy()">{{ busy() ? t('common.wait') : t('auth.login.submit') }}</button>
      </form>

      @if (step() === 'credentials') {
        <div class="links">
          <a routerLink="/forgot-password">{{ t('auth.login.forgot') }}</a>
          <span class="dw-spacer"></span>
          <a routerLink="/register">{{ t('auth.login.register') }}</a>
        </div>
      } @else {
        <button mat-button type="button" (click)="restart()">{{ t('auth.login.restart') }}</button>
      }
    </ng-container>
  `,
  styles: `
    .lead { margin-block: 4px var(--dw-5); }
    .submit { height: 44px; margin-block-start: var(--dw-2); }
    .links { display: flex; margin-block-start: var(--dw-4); font-size: 13px; }
    .notice { padding: 10px 12px; border-radius: var(--dw-radius); background: var(--dw-info-soft); color: var(--dw-info); margin-block-end: var(--dw-3); font-size: 13px; }
    .notice.danger { background: var(--dw-danger-soft); color: var(--dw-danger); }
    .companies { display: grid; gap: 8px; }
    .company { all: unset; cursor: pointer; padding: 12px 14px; border: 1px solid var(--dw-line); border-radius: var(--dw-radius); font-weight: 500; }
    .company.selected { border-color: var(--dw-primary); background: var(--dw-primary-soft); }
    .company:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: 2px; }
  `,
})
export class LoginPage {
  private readonly session = inject(Session);
  private readonly router = inject(Router);
  private readonly toast = inject(Toast);

  /** Query parameters, bound by the router. */
  readonly next = input<string>();
  readonly expired = input<string>();

  protected readonly form = inject(FormBuilder).nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', Validators.required],
    code: [''],
    recovery_code: [''],
    company: [''],
  });
  protected readonly step = signal<Step>('credentials');
  protected readonly recovery = signal(false);
  protected readonly reveal = signal(false);
  protected readonly busy = signal(false);
  protected readonly failure = signal('');
  protected readonly companies = signal<{ ulid: string; legal_name: string }[]>([]);

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    this.failure.set('');
    const value = this.form.getRawValue();

    try {
      const result = await this.session.login({
        email: value.email,
        password: value.password,
        code: !this.recovery() && value.code ? value.code : undefined,
        recovery_code: this.recovery() && value.recovery_code ? value.recovery_code : undefined,
        company: value.company || undefined,
      });

      if (result.requires_two_factor) {
        this.step.set('two_factor');
      } else if (result.requires_company_selection) {
        this.companies.set(result.companies ?? []);
        this.step.set('company');
      } else {
        const next = this.next();
        await this.router.navigateByUrl(next && next.startsWith('/') && !next.startsWith('//') ? next : this.session.home());
      }
    } catch (error) {
      // The API answers the same 401 for a wrong password, an unknown address and a locked account, on purpose.
      this.failure.set(error instanceof ApiError && error.status === 401 ? this.toast.describe(new ApiError(401, this.step() === 'two_factor' ? 'INVALID_CODE' : 'INVALID_CREDENTIALS', null, {}, null)) : this.toast.describe(error));
    } finally {
      this.busy.set(false);
    }
  }

  protected restart(): void {
    this.form.patchValue({ code: '', recovery_code: '', company: '' });
    this.step.set('credentials');
    this.failure.set('');
  }
}
