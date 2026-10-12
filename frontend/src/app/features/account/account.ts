import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { Router, RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';

import { Api } from '../../core/api/api';
import { AppNotification, OnboardingState } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Locale } from '../../core/i18n/i18n';
import { Toast } from '../../core/feedback/toast';
import { NotificationParamsPipe } from '../../core/format/notification-params';
import { DatePipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { StateComponent } from '../../ui/state';
import { CheckboxComponent } from '../../ui/checkbox';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';

@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, PageHeaderComponent, ButtonDirective, InputText, ...FIELD, ...SELECT],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('account.profile')" [subtitle]="session.me()?.email ?? ''" />
      <form class="dw-panel narrow" [formGroup]="form" (ngSubmit)="save()">
        <div class="dw-panel-body">
          <div class="dw-form">
            <dw-field [label]="t('fields.firstName')"><input pInputText formControlName="first_name" autocomplete="given-name" /></dw-field>
            <dw-field [label]="t('fields.lastName')"><input pInputText formControlName="last_name" autocomplete="family-name" /></dw-field>
            <dw-field [label]="t('fields.phone')"><input pInputText type="tel" formControlName="phone" autocomplete="tel" /></dw-field>
            <dw-field [label]="t('fields.language')">
              <dw-select formControlName="locale">
                @for (language of locale.available; track language.code) { <dw-option [value]="language.code" [label]="language.label" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('account.documentLanguage')">
              <dw-select formControlName="documents">
                <dw-option value="auto" [label]="t('account.documentLanguageAuto')" />
                @for (language of locale.available; track language.code) { <dw-option [value]="language.code" [label]="language.label" /> }
              </dw-select>
              <small dwHint>{{ t('account.documentLanguageHint') }}</small>
            </dw-field>
          </div>
          <div class="dw-form-actions"><button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button></div>
        </div>
      </form>
    </ng-container>
  `,
  styles: '.narrow { max-width: 720px; }',
})
export class ProfilePage {
  protected readonly session = inject(Session);
  protected readonly locale = inject(Locale);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);

  protected readonly busy = signal(false);
  private readonly me = this.session.me();
  protected readonly form = inject(FormBuilder).nonNullable.group({
    first_name: [this.me?.first_name ?? '', [Validators.required, Validators.maxLength(100)]],
    last_name: [this.me?.last_name ?? '', [Validators.required, Validators.maxLength(100)]],
    phone: [this.me?.phone ?? ''],
    locale: [this.locale.lang()],
    documents: [this.locale.documentsChoice()],
  });

  protected async save(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    try {
      const { documents, ...value } = this.form.getRawValue();
      await firstValueFrom(this.api.put('me', { ...value, phone: value.phone || null }));
      this.locale.choose(value.locale);
      this.locale.chooseDocuments(documents);
      await this.session.reload();
      this.toast.success('common.saved');
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

interface TwoFactorSetup {
  secret: string;
  qr_svg: string;
}

/** M0-09: password change and optional TOTP. Recovery codes are shown once, at activation. */
@Component({
  imports: [FormsModule, ReactiveFormsModule, TranslocoDirective, PageHeaderComponent, StatusComponent, ButtonDirective, InputText, ...FIELD],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('account.security')" />
      <div class="dw-stack narrow">
        <form class="dw-panel" [formGroup]="form" (ngSubmit)="changePassword()">
          <header class="dw-panel-head"><h2 class="dw-h2">{{ t('account.changePassword') }}</h2></header>
          <div class="dw-panel-body fields">
            <dw-field [label]="t('account.currentPassword')">
              <input pInputText type="password" formControlName="current_password" autocomplete="current-password" />
            </dw-field>
            <dw-field [label]="t('auth.newPassword')">
              <input pInputText type="password" formControlName="password" autocomplete="new-password" />
              <small dwHint>{{ t('auth.passwordRule') }}</small>
            </dw-field>
            <div class="dw-form-actions"><button pButton type="submit" [disabled]="busy()">{{ t('account.changePassword') }}</button></div>
          </div>
        </form>

        <section class="dw-panel">
          <header class="dw-panel-head">
            <h2 class="dw-h2 dw-spacer">{{ t('account.twoFactor.title') }}</h2>
            <dw-status kind="member" [value]="enabled() ? 'active' : 'suspended'" [label]="enabled() ? t('common.active') : t('common.inactive')" [tone]="enabled() ? 'success' : 'neutral'" />
          </header>
          <div class="dw-panel-body dw-stack-sm">
            @if (recovery().length > 0) {
              <h3 class="dw-h3">{{ t('account.twoFactor.recovery') }}</h3>
              <p class="dw-muted">{{ t('account.twoFactor.recoveryHint') }}</p>
              <ul class="codes">@for (code of recovery(); track code) { <li class="dw-code">{{ code }}</li> }</ul>
              <div><button pButton severity="secondary" outlined type="button" (click)="recovery.set([])">{{ t('common.close') }}</button></div>
            } @else if (enabled()) {
              <p>{{ t('account.twoFactor.on') }}</p>
              <div><button pButton severity="secondary" outlined type="button" [disabled]="busy()" (click)="disable()">{{ t('account.twoFactor.disable') }}</button></div>
            } @else if (setup(); as s) {
              <p>{{ t('account.twoFactor.scan') }}</p>
              <div class="qr" [innerHTML]="qr()"></div>
              <p class="dw-code secret">{{ s.secret }}</p>
              <div class="dw-row">
                <dw-field class="code" [label]="t('account.twoFactor.code')">
                  <input pInputText inputmode="numeric" autocomplete="one-time-code" maxlength="6" [ngModel]="code()" (ngModelChange)="code.set($event)" (keydown.enter)="confirmSetup()" />
                </dw-field>
                <button pButton type="button" [disabled]="busy() || code().length < 6" (click)="confirmSetup()">{{ t('account.twoFactor.confirm') }}</button>
              </div>
            } @else {
              <p>{{ t('account.twoFactor.off') }}</p>
              <div><button pButton type="button" [disabled]="busy()" (click)="start()">{{ t('account.twoFactor.enable') }}</button></div>
            }
          </div>
        </section>
      </div>
    </ng-container>
  `,
  styles: `
    .narrow { max-width: 720px; } .fields { display: grid; gap: var(--dw-2); max-width: 420px; }
    .qr { width: 200px; height: 200px; padding: 4px; background: #fff; border: 1px solid var(--dw-line); border-radius: var(--dw-radius); }
    .secret { font-size: 14px; letter-spacing: 0.08em; overflow-wrap: anywhere; } .code { width: 180px; }
    .codes { list-style: none; margin: 0; padding: var(--dw-3); display: grid; grid-template-columns: repeat(2, max-content); gap: 4px var(--dw-5); background: var(--dw-surface-2); border-radius: var(--dw-radius); font-size: 14px; }
  `,
})
export class SecurityPage {
  private readonly session = inject(Session);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);
  private readonly sanitizer = inject(DomSanitizer);

  protected readonly busy = signal(false);
  protected readonly enabled = computed(() => this.session.me()?.two_factor_enabled ?? false);
  protected readonly setup = signal<TwoFactorSetup | null>(null);
  protected readonly code = signal('');
  protected readonly recovery = signal<string[]>([]);
  // The SVG is generated by our own API from the TOTP secret: no user-supplied markup goes through here.
  protected readonly qr = computed<SafeHtml>(() => this.sanitizer.bypassSecurityTrustHtml(this.setup()?.qr_svg ?? ''));
  protected readonly form = inject(FormBuilder).nonNullable.group({
    current_password: ['', Validators.required],
    password: ['', [Validators.required, Validators.minLength(12), Validators.pattern(/^(?=.*[A-Za-z])(?=.*\d).+$/)]],
  });

  protected async changePassword(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.put('me/password', this.form.getRawValue()));
      this.form.reset();
      this.toast.success('account.passwordChanged');
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }

  protected async start(): Promise<void> {
    await this.run(async () => this.setup.set(await firstValueFrom(this.api.post<TwoFactorSetup>('me/two-factor'))));
  }

  protected async confirmSetup(): Promise<void> {
    await this.run(async () => {
      const result = await firstValueFrom(this.api.post<{ recovery_codes: string[] }>('me/two-factor/confirm', { code: this.code() }));
      this.recovery.set(result.recovery_codes);
      this.setup.set(null);
      this.code.set('');
      await this.session.reload();
      this.toast.success('account.twoFactor.enabled');
    });
  }

  protected async disable(): Promise<void> {
    const password = await this.confirm.ask({ title: this.toast.text('account.twoFactor.disableConfirm'), password: true, danger: true, confirmLabel: this.toast.text('account.twoFactor.disable') });
    if (typeof password === 'string') {
      await this.run(async () => {
        await firstValueFrom(this.api.delete('me/two-factor', { password }));
        await this.session.reload();
        this.toast.success('account.twoFactor.disabled');
      });
    }
  }

  private async run(action: () => Promise<void>): Promise<void> {
    this.busy.set(true);
    try {
      await action();
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

interface Preference {
  notification_type: string;
  database: boolean;
  mail: boolean;
  mail_available: boolean;
}

interface NotificationPage {
  data: AppNotification[];
  meta: { unread_count: number; next_cursor?: string | null };
}

/** M7-03: the inbox and, per kind of event, whether it is shown in the application and sent by email. */
@Component({
  imports: [TranslocoDirective, PageHeaderComponent, StateComponent, DatePipe, NotificationParamsPipe, IconComponent, ButtonDirective, CheckboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('notifications.title')">
        @if (unread() > 0) { <button pButton severity="secondary" outlined type="button" (click)="readAll()"><dw-icon>done_all</dw-icon>{{ t('notifications.readAll') }}</button> }
      </dw-page-header>
      <div class="dw-split">
        <section class="dw-panel">
          <dw-state [loading]="loading()" [error]="error()" [empty]="items().length === 0" [keepContent]="items().length > 0" icon="notifications" [emptyTitle]="t('notifications.empty')" (retry)="load(true)">
            <ul class="inbox">
              @for (item of items(); track item.id) {
                <li [class.unread]="!item.read_at">
                  <button type="button" (click)="open(item)">
                    <strong>{{ t('notifications.types.' + item.type + '.title', (item.params | dwNotificationParams)) }}</strong>
                    <span>{{ t('notifications.types.' + item.type + '.body', (item.params | dwNotificationParams)) }}</span>
                    <small class="dw-faint">{{ item.created_at | dwDate: 'datetime' }}</small>
                  </button>
                </li>
              }
            </ul>
            @if (cursor()) { <div class="more"><button pButton text type="button" (click)="load(false)">{{ t('common.seeAll') }}</button></div> }
          </dw-state>
        </section>

        <section class="dw-panel">
          <header class="dw-panel-head"><h2 class="dw-h2">{{ t('notifications.preferences') }}</h2></header>
          <table class="dw-table prefs">
            <thead><tr><th scope="col"></th><th scope="col">{{ t('notifications.inApp') }}</th><th scope="col">{{ t('notifications.byMail') }}</th></tr></thead>
            <tbody>
              @for (preference of preferences(); track preference.notification_type) {
                <tr>
                  <th scope="row">{{ t('notifications.labels.' + preference.notification_type) }}</th>
                  <td><dw-checkbox [checked]="preference.database" (checkedChange)="set(preference, 'database', $event)" [ariaLabel]="t('notifications.inApp')" /></td>
                  <td>@if (preference.mail_available) { <dw-checkbox [checked]="preference.mail" (checkedChange)="set(preference, 'mail', $event)" [ariaLabel]="t('notifications.byMail')" /> } @else { — }</td>
                </tr>
              }
            </tbody>
          </table>
        </section>
      </div>
    </ng-container>
  `,
  styles: `
    .inbox { list-style: none; margin: 0; padding: 0; }
    .inbox li { border-block-end: 1px solid var(--dw-line-soft); border-inline-start: 3px solid transparent; }
    .inbox li.unread { border-inline-start-color: var(--dw-primary); background: var(--dw-primary-soft); }
    .inbox button { all: unset; box-sizing: border-box; cursor: pointer; display: grid; gap: 2px; width: 100%; padding: 10px var(--dw-4); font-size: 13px; }
    .inbox button:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: -2px; }
    .inbox span { color: var(--dw-ink-2); } .more { padding: var(--dw-2); text-align: center; }
    .prefs th, .prefs td { text-align: center; }
    .prefs tbody th[scope='row'] { text-align: start; font-weight: 400; padding: 7px var(--dw-3); border-block-end: 1px solid var(--dw-line-soft); }
    .prefs thead th:first-child { text-align: start; }
  `,
})
export class NotificationsPage {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly router = inject(Router);
  private readonly session = inject(Session);

  protected readonly items = signal<AppNotification[]>([]);
  protected readonly unread = signal(0);
  protected readonly cursor = signal<string | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  private readonly all = signal<Preference[]>([]);
  // Only the events this kind of account can receive are listed.
  protected readonly preferences = computed(() => {
    const provider = this.session.audience() === 'provider';
    const hidden = provider
      ? ['stock.', 'pickup.confirmed', 'pickup.refused', 'pickup.collected', 'pickup.received', 'pickup.variance_flagged', 'accreditation.expir', 'document.certificate_uploaded', 'report.', 'payment.', 'invoice.', 'subscription.', 'company.']
      : ['pickup.requested', 'pickup.cancelled', 'pickup.completed', 'accreditation.reviewed', 'payment.declared', 'company.'];

    return this.all().filter((preference) => !hidden.some((prefix) => preference.notification_type.startsWith(prefix)));
  });

  constructor() {
    this.load(true);
    this.api.get<Preference[]>('me/notification-preferences').subscribe({ next: (rows) => this.all.set(rows), error: () => undefined });
  }

  protected load(reset: boolean): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.raw<NotificationPage>('me/notifications', { cursor: reset ? null : this.cursor() }).subscribe({
      next: (page) => {
        this.items.update((items) => (reset ? page.data : [...items, ...page.data]));
        this.unread.set(page.meta.unread_count);
        this.cursor.set(page.meta.next_cursor ?? null);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected open(item: AppNotification): void {
    if (!item.read_at) {
      this.api.post(`me/notifications/${item.id}/read`).subscribe({ next: () => this.load(true), error: () => undefined });
    }
    if (item.link) {
      void this.router.navigateByUrl(item.link);
    }
  }

  protected readAll(): void {
    this.api.post('me/notifications/read-all').subscribe({ next: () => this.load(true), error: (error: unknown) => this.toast.error(error) });
  }

  protected set(preference: Preference, channel: 'database' | 'mail', enabled: boolean): void {
    this.all.update((rows) => rows.map((row) => (row.notification_type === preference.notification_type ? { ...row, [channel]: enabled } : row)));
    this.api.put('me/notification-preferences', { preferences: [{ notification_type: preference.notification_type, channel, is_enabled: enabled }] }).subscribe({
      next: () => this.toast.success('common.saved'),
      error: (error: unknown) => {
        this.all.update((rows) => rows.map((row) => (row.notification_type === preference.notification_type ? { ...row, [channel]: !enabled } : row)));
        this.toast.error(error);
      },
    });
  }
}

const STEP_LINKS: Record<string, { path: string; icon: string }> = {
  sites: { path: '/sites', icon: 'factory' },
  waste_types: { path: '/waste-types', icon: 'category' },
  users: { path: '/admin/users', icon: 'group' },
  providers: { path: '/providers', icon: 'handshake' },
};

/** M0-07: four steps, each done on its real screen. A step can be skipped and resumed; nothing here is a copy of a form. */
@Component({
  imports: [RouterLink, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, IconComponent, ButtonDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('onboarding.title')" [subtitle]="t('onboarding.lead')" />
      <dw-state [loading]="!state() && !error()" [error]="error()" (retry)="load()">
        @if (state(); as s) {
          <ol class="steps">
            @for (step of s.steps; track step.key; let index = $index) {
              <li class="dw-panel" [class.done]="step.done">
                <span class="index" aria-hidden="true">@if (step.done) { <dw-icon>check</dw-icon> } @else { {{ index + 1 }} }</span>
                <div class="dw-spacer">
                  <h2 class="dw-h2">{{ t('onboarding.steps.' + step.key + '.title') }}</h2>
                  <p class="dw-muted">{{ t('onboarding.steps.' + step.key + '.body') }}</p>
                  <p class="dw-faint">{{ t('onboarding.steps.' + step.key + '.count', { count: step.count }) }}</p>
                </div>
                <dw-status kind="alert" [value]="step.done ? 'ok' : 'warning'" [tone]="step.done ? 'success' : step.skipped ? 'neutral' : 'warning'"
                  [label]="t(step.done ? 'onboarding.done' : step.skipped ? 'onboarding.skipped' : 'onboarding.todo')" />
                <div class="dw-row-tight">
                  @if (!step.done && canEdit) {
                    <button pButton text type="button" [disabled]="busy()" (click)="skip(step.key, !step.skipped)">{{ t(step.skipped ? 'onboarding.resumeStep' : 'onboarding.skip') }}</button>
                  }
                  <a pButton severity="secondary" outlined [routerLink]="links[step.key].path"><dw-icon>{{ links[step.key].icon }}</dw-icon>{{ t('onboarding.open') }}</a>
                </div>
              </li>
            }
          </ol>
          <div class="dw-form-actions"><button pButton type="button" [disabled]="busy()" (click)="finish()">{{ t('onboarding.finish') }}</button></div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .steps { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--dw-3); max-width: 960px; }
    .steps li { display: flex; align-items: center; gap: var(--dw-4); padding: var(--dw-4); flex-wrap: wrap; }
    .index { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 50%; background: var(--dw-neutral-soft); font-weight: 600; flex: none; }
    li.done .index { background: var(--dw-success-soft); color: var(--dw-success); }
    .steps .dw-spacer { min-width: 240px; }
  `,
})
export class OnboardingPage {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);
  private readonly router = inject(Router);

  protected readonly links = STEP_LINKS;
  protected readonly canEdit = this.session.can('company.update');
  protected readonly state = signal<OnboardingState | null>(null);
  protected readonly error = signal<unknown>(null);
  protected readonly busy = signal(false);

  constructor() {
    this.load();
  }

  protected load(): void {
    this.error.set(null);
    this.api.get<OnboardingState>('onboarding').subscribe({ next: (state) => this.state.set(state), error: (error: unknown) => this.error.set(error) });
  }

  protected async skip(step: string, skipped: boolean): Promise<void> {
    this.busy.set(true);
    try {
      this.state.set(await firstValueFrom(this.api.put<OnboardingState>(`onboarding/steps/${step}`, { skipped })));
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected async finish(): Promise<void> {
    if (this.canEdit) {
      await firstValueFrom(this.api.post('onboarding/dismiss')).catch(() => undefined);
    }
    await this.router.navigateByUrl('/dashboard');
  }
}
