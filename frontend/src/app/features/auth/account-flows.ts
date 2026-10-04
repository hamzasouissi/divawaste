import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { Router, RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';

import { Api } from '../../core/api/api';
import { Localized } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, LocalizedPipe } from '../../core/format/pipes';
import { StatusComponent } from '../../ui/page';

const FORM = [ReactiveFormsModule, MatButtonModule, MatFormFieldModule, MatInputModule, TranslocoDirective] as const;
const RESULT_STYLES = `
  .result { display: grid; gap: var(--dw-3); justify-items: start; }
  .result mat-icon { font-size: 40px; width: 40px; height: 40px; }
  .ok { color: var(--dw-success); } .ko { color: var(--dw-danger); }
  .lead { margin-block: 4px var(--dw-5); }
  button[type=submit] { height: 44px; }
`;

/** M0-02: landing page of the confirmation link sent by email. */
@Component({
  imports: [RouterLink, MatButtonModule, MatIconModule, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="result" *transloco="let t" aria-live="polite">
      @switch (state()) {
        @case ('pending') { <p class="dw-muted">{{ t('auth.verify.pending') }}</p> }
        @case ('done') {
          <mat-icon class="ok" aria-hidden="true">check_circle</mat-icon>
          <h1 class="dw-h1">{{ t('auth.verify.doneTitle') }}</h1>
          <p class="dw-muted">{{ t('auth.verify.doneBody') }}</p>
          <a mat-flat-button routerLink="/login">{{ t('auth.login.submit') }}</a>
        }
        @default {
          <mat-icon class="ko" aria-hidden="true">error</mat-icon>
          <h1 class="dw-h1">{{ t('auth.verify.failedTitle') }}</h1>
          <p class="dw-muted">{{ t('auth.verify.failedBody') }}</p>
          <a mat-stroked-button routerLink="/login">{{ t('auth.login.submit') }}</a>
        }
      }
    </div>
  `,
  styles: RESULT_STYLES,
})
export class VerifyEmailPage implements OnInit {
  private readonly api = inject(Api);

  readonly user = input<string>();
  readonly expires = input<string>();
  readonly signature = input<string>();
  protected readonly state = signal<'pending' | 'done' | 'failed'>('pending');

  async ngOnInit(): Promise<void> {
    try {
      await firstValueFrom(this.api.post('auth/email/verify', { user: this.user(), expires: Number(this.expires()), signature: this.signature() }));
      this.state.set('done');
    } catch {
      this.state.set('failed');
    }
  }
}

@Component({
  imports: [...FORM, RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h1 class="dw-h1">{{ t('auth.forgot.title') }}</h1>
      @if (sent()) {
        <p class="dw-muted lead" role="status">{{ t('auth.forgot.sent') }}</p>
        <a mat-stroked-button routerLink="/login">{{ t('auth.backToLogin') }}</a>
      } @else {
        <p class="dw-muted lead">{{ t('auth.forgot.lead') }}</p>
        <form [formGroup]="form" (ngSubmit)="submit()" class="dw-stack-sm" novalidate>
          <mat-form-field>
            <mat-label>{{ t('auth.email') }}</mat-label>
            <input matInput type="email" formControlName="email" autocomplete="username" required />
          </mat-form-field>
          <button mat-flat-button type="submit" [disabled]="busy()">{{ t('auth.forgot.submit') }}</button>
          <a routerLink="/login">{{ t('auth.backToLogin') }}</a>
        </form>
      }
    </ng-container>
  `,
  styles: RESULT_STYLES,
})
export class ForgotPasswordPage {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);

  protected readonly form = inject(FormBuilder).nonNullable.group({ email: ['', [Validators.required, Validators.email]] });
  protected readonly busy = signal(false);
  protected readonly sent = signal(false);

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    try {
      // The API answers the same way whether the address has an account or not.
      await firstValueFrom(this.api.post('auth/forgot-password', this.form.getRawValue()));
      this.sent.set(true);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

@Component({
  imports: [...FORM, RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h1 class="dw-h1">{{ t('auth.reset.title') }}</h1>
      <p class="dw-muted lead">{{ t('auth.passwordRule') }}</p>
      <form [formGroup]="form" (ngSubmit)="submit()" class="dw-stack-sm" novalidate>
        <mat-form-field>
          <mat-label>{{ t('auth.newPassword') }}</mat-label>
          <input matInput type="password" formControlName="password" autocomplete="new-password" required />
          @if (form.controls.password.errors; as errors) {
            <mat-error>{{ errors['server'] || t('auth.passwordRule') }}</mat-error>
          }
        </mat-form-field>
        @if (form.controls.token.errors?.['server']; as message) {
          <p class="ko" role="alert">{{ message }}</p>
        }
        <button mat-flat-button type="submit" [disabled]="busy()">{{ t('auth.reset.submit') }}</button>
        <a routerLink="/login">{{ t('auth.backToLogin') }}</a>
      </form>
    </ng-container>
  `,
  styles: RESULT_STYLES,
})
export class ResetPasswordPage implements OnInit {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly router = inject(Router);

  readonly token = input<string>();
  readonly email = input<string>();
  protected readonly form = inject(FormBuilder).nonNullable.group({
    email: [''],
    token: [''],
    password: ['', [Validators.required, Validators.minLength(12)]],
  });
  protected readonly busy = signal(false);

  ngOnInit(): void {
    this.form.patchValue({ email: this.email() ?? '', token: this.token() ?? '' });
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.post('auth/reset-password', this.form.getRawValue()));
      this.toast.success('auth.reset.done');
      await this.router.navigate(['/login']);
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

interface InvitationPreview {
  email: string;
  first_name: string | null;
  last_name: string | null;
  company: string;
  role: Localized | null;
  expires_at: string;
  has_account: boolean;
  legal_documents: { type: string; version: string; title: string }[];
}

/** M0-08: the invited person joins the company, creating an account if they have none. */
@Component({
  imports: [...FORM, RouterLink, MatCheckboxModule, MatIconModule, DatePipe, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (invitation(); as invite) {
        <h1 class="dw-h1">{{ t('auth.invitation.title', { company: invite.company }) }}</h1>
        <p class="dw-muted lead">{{ t('auth.invitation.lead', { role: invite.role | dwLocalized, email: invite.email }) }} {{ t('auth.invitation.expires', { date: invite.expires_at | dwDate }) }}</p>
        <form [formGroup]="form" (ngSubmit)="submit()" class="dw-stack-sm" novalidate>
          @if (invite.has_account) {
            <p class="dw-muted">{{ t('auth.invitation.existing') }}</p>
          } @else {
            <div class="dw-form">
              <mat-form-field><mat-label>{{ t('fields.firstName') }}</mat-label><input matInput formControlName="first_name" autocomplete="given-name" required /></mat-form-field>
              <mat-form-field><mat-label>{{ t('fields.lastName') }}</mat-label><input matInput formControlName="last_name" autocomplete="family-name" required /></mat-form-field>
              <mat-form-field class="dw-span-2">
                <mat-label>{{ t('auth.newPassword') }}</mat-label>
                <input matInput type="password" formControlName="password" autocomplete="new-password" required />
                <mat-hint>{{ t('auth.passwordRule') }}</mat-hint>
                @if (form.controls.password.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
              </mat-form-field>
            </div>
          }
          <mat-checkbox formControlName="accept">{{ t('auth.acceptLegal', { documents: legalTitles() }) }}</mat-checkbox>
          <button mat-flat-button type="submit" [disabled]="busy() || !form.controls.accept.value">{{ t('auth.invitation.submit') }}</button>
        </form>
      } @else if (invalid()) {
        <div class="result">
          <mat-icon class="ko" aria-hidden="true">error</mat-icon>
          <h1 class="dw-h1">{{ t('auth.invitation.invalidTitle') }}</h1>
          <p class="dw-muted">{{ t('auth.invitation.invalidBody') }}</p>
          <a mat-stroked-button routerLink="/login">{{ t('auth.login.submit') }}</a>
        </div>
      }
    </ng-container>
  `,
  styles: RESULT_STYLES,
})
export class InvitationPage implements OnInit {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly router = inject(Router);

  readonly token = input<string>();
  protected readonly invitation = signal<InvitationPreview | null>(null);
  protected readonly invalid = signal(false);
  protected readonly busy = signal(false);
  protected readonly legalTitles = computed(() => (this.invitation()?.legal_documents ?? []).map((doc) => doc.title).join(', '));
  protected readonly form = inject(FormBuilder).nonNullable.group({ first_name: [''], last_name: [''], password: [''], accept: [false] });

  async ngOnInit(): Promise<void> {
    try {
      const invite = await firstValueFrom(this.api.get<InvitationPreview>(`auth/invitations/${this.token() ?? ''}`));
      this.invitation.set(invite);
      this.form.patchValue({ first_name: invite.first_name ?? '', last_name: invite.last_name ?? '' });
    } catch {
      this.invalid.set(true);
    }
  }

  protected async submit(): Promise<void> {
    this.busy.set(true);
    const { accept, ...value } = this.form.getRawValue();
    try {
      await firstValueFrom(this.api.post(`auth/invitations/${this.token() ?? ''}/accept`, { ...value, accept_terms: accept, accept_privacy: accept }));
      this.toast.success('auth.invitation.done');
      await this.router.navigate(['/login']);
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

/** Signed in, but no usable company yet: explains why (email to confirm, review pending, rejected, suspended). */
@Component({
  imports: [MatButtonModule, MatIconModule, TranslocoDirective, StatusComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="wrap" *transloco="let t">
      <div class="dw-panel dw-panel-body dw-stack">
        <h1 class="dw-h1">{{ t('auth.status.title') }}</h1>
        @for (membership of session.me()?.memberships ?? []; track membership.company) {
          <div class="dw-stack-sm">
            <div class="dw-row"><strong>{{ membership.legal_name }}</strong><dw-status kind="company" [value]="membership.status" /></div>
            <p class="dw-muted">{{ t('auth.status.' + membership.status) }}</p>
            @if (membership.reason) { <p>{{ t('auth.status.reason', { reason: membership.reason }) }}</p> }
          </div>
        } @empty {
          <p class="dw-muted">{{ t('auth.status.none') }}</p>
        }
        @if (session.me() && !session.me()!.email_verified) {
          <div class="dw-row">
            <span class="dw-muted">{{ t('auth.status.emailUnverified', { email: session.me()!.email }) }}</span>
            <button mat-stroked-button type="button" (click)="resend()" [disabled]="resent()">{{ resent() ? t('auth.status.resent') : t('auth.status.resend') }}</button>
          </div>
        }
        <div class="dw-row">
          <button mat-stroked-button type="button" (click)="refresh()"><mat-icon>refresh</mat-icon>{{ t('auth.status.refresh') }}</button>
          <button mat-button type="button" (click)="logout()">{{ t('shell.logout') }}</button>
        </div>
      </div>
    </div>
  `,
  styles: `.wrap { min-height: 100dvh; display: grid; place-items: center; padding: var(--dw-4); } .dw-panel { width: min(100%, 560px); }`,
})
export class AccountStatusPage {
  protected readonly session = inject(Session);
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly toast = inject(Toast);

  protected readonly resent = signal(false);

  protected async resend(): Promise<void> {
    try {
      await firstValueFrom(this.api.post('auth/email/resend'));
      this.resent.set(true);
    } catch (error) {
      this.toast.error(error);
    }
  }

  protected async refresh(): Promise<void> {
    await this.session.reload();
    if (this.session.audience() !== 'none') {
      await this.router.navigateByUrl(this.session.home());
    }
  }

  protected async logout(): Promise<void> {
    await this.session.logout();
    await this.router.navigate(['/login']);
  }
}
