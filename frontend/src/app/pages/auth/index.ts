import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { SessionStore } from '../../entities/session';
import { SignInFormComponent } from '../../features/sign-in';
import { LocaleService, SupportedLocale } from '../../shared/i18n/locale.service';

@Component({
  template: `
    <div class="auth-page">
      <header class="auth-header">
        <a class="wordmark" routerLink="/" aria-label="Diva Waste">
          <span class="brand-mark" aria-hidden="true">D</span>
          <span>Diva Waste</span>
        </a>
        <label class="locale-control">
          <span>{{ locale.t('language') }}</span>
          <select [value]="locale.locale()" (change)="changeLocale($any($event.target).value)">
            <option value="fr">Français</option>
            <option value="en">English</option>
            <option value="ar">العربية</option>
          </select>
        </label>
      </header>

      <main class="auth-main">
        <section class="identity" aria-labelledby="product-name">
          <p class="eyebrow">{{ locale.t('workspaceType') }}</p>
          <h1 id="product-name">{{ locale.t('workspaceTitle') }}</h1>
          <p class="identity-copy">{{ locale.t('workspaceDescription') }}</p>
          <div class="identity-rule" aria-hidden="true"><span></span><span></span><span></span></div>
          <p class="identity-footnote">{{ locale.t('workspaceFootnote') }}</p>
        </section>

        <section class="access-panel" aria-labelledby="access-title">
          <p class="eyebrow">{{ locale.t('accountAccess') }}</p>
          <h2 id="access-title">{{ title() }}</h2>
          <p class="access-copy">{{ description() }}</p>

          @if (session.status() === 'tenant-unavailable') {
            <div class="notice" role="alert">
              <span class="notice-mark" aria-hidden="true">!</span>
              <p>{{ locale.t('tenantUnavailableBody') }}</p>
            </div>
            <button class="button secondary retry" type="button" (click)="checkAccess()" [disabled]="checking()">
              {{ locale.t(checking() ? 'checkingSession' : 'checkAccess') }}
            </button>
          } @else if (session.status() === 'error') {
            <div class="notice" role="alert">
              <span class="notice-mark" aria-hidden="true">!</span>
              <p>{{ locale.t('sessionErrorBody') }}</p>
            </div>
            <app-sign-in-form [returnUrl]="returnUrl" />
          } @else {
            <app-sign-in-form [returnUrl]="returnUrl" />
          }
        </section>
      </main>

      <footer class="auth-footer">
        <span>{{ locale.t('workspaceFootnote') }}</span>
        <span class="footer-mark" aria-hidden="true">DW</span>
      </footer>
    </div>
  `,
  styles: `
    :host { display: block; min-height: 100dvh; }
    .auth-page { min-height: 100dvh; display: grid; grid-template-rows: auto 1fr auto; padding: 0 5vw; color: var(--text); background: var(--surface-muted); }
    .auth-header, .auth-footer { width: 100%; max-width: 1360px; margin-inline: auto; display: flex; align-items: center; justify-content: space-between; }
    .auth-header { min-height: 92px; border-bottom: 1px solid var(--border); }
    .wordmark { display: inline-flex; align-items: center; gap: .75rem; color: var(--text); font-size: 1rem; font-weight: 700; text-decoration: none; }
    .brand-mark { width: 38px; aspect-ratio: 1; display: grid; place-items: center; border-radius: 7px; background: var(--primary); color: white; font-weight: 800; }
    .locale-control { display: flex; align-items: center; gap: .6rem; color: var(--text-muted); font-size: .8rem; }
    .locale-control select { min-height: 38px; padding: .35rem 2rem .35rem .65rem; border: 1px solid var(--border); border-radius: 5px; background: var(--surface); color: var(--text); }
    .auth-main { width: 100%; max-width: 1120px; margin: auto; padding: 70px 0 82px; display: grid; grid-template-columns: minmax(0, 1fr) minmax(360px, .8fr); align-items: center; gap: 9%; }
    .identity { max-width: 520px; padding-inline-end: 1rem; }
    .eyebrow { margin: 0 0 1.15rem; color: var(--primary); font-size: .75rem; font-weight: 750; text-transform: uppercase; }
    .identity h1 { max-width: 540px; margin: 0; font-family: var(--font-display); font-size: 3.35rem; font-weight: 500; line-height: 1.08; }
    .identity-copy { max-width: 430px; margin: 1.3rem 0 0; color: var(--text-muted); font-size: 1.08rem; line-height: 1.7; }
    .identity-rule { display: flex; gap: 5px; margin-top: 2.25rem; }
    .identity-rule span { display: block; height: 3px; width: 28px; background: var(--primary); }
    .identity-rule span:nth-child(2) { width: 11px; background: var(--accent); }
    .identity-rule span:nth-child(3) { width: 5px; background: var(--border-strong); }
    .identity-footnote { margin: 2rem 0 0; color: var(--text-muted); font-size: .82rem; }
    .access-panel { max-width: 470px; min-width: 0; padding-inline-start: 3.2rem; border-inline-start: 1px solid var(--border); }
    .access-panel h2 { margin: 0; font-family: var(--font-display); font-size: 2.25rem; font-weight: 500; line-height: 1.2; }
    .access-copy { margin: .8rem 0 1.8rem; color: var(--text-muted); line-height: 1.65; }
    .notice { display: grid; grid-template-columns: 26px minmax(0, 1fr); align-items: start; gap: .75rem; padding: 1rem 0; border-block: 1px solid var(--border); }
    .notice-mark { width: 23px; aspect-ratio: 1; display: grid; place-items: center; border-radius: 50%; background: var(--accent-soft); color: var(--accent-strong); font-size: .8rem; font-weight: 750; }
    .notice p { margin: 0; color: var(--text); font-size: .9rem; line-height: 1.55; }
    .button { min-height: 46px; display: inline-flex; align-items: center; justify-content: center; gap: .5rem; padding: .7rem 1rem; border: 1px solid transparent; border-radius: 5px; color: #fff; background: var(--primary); font: inherit; font-weight: 650; text-decoration: none; transition: background-color 140ms ease, border-color 140ms ease; }
    .button:hover { background: var(--primary-strong); }
    .button.secondary { color: var(--text); background: transparent; border-color: var(--border-strong); }
    .button.secondary:hover { background: var(--surface); }
    .button:disabled { cursor: wait; opacity: .65; }
    .sign-in { width: 100%; margin-top: 1rem; }
    .retry { width: 100%; margin-top: 1rem; }
    .auth-footer { min-height: 64px; border-top: 1px solid var(--border); color: var(--text-muted); font-size: .78rem; }
    .footer-mark { color: var(--primary); font-size: .7rem; font-weight: 800; }
    @media (max-width: 760px) {
      .auth-page { padding-inline: 6vw; }
      .auth-header { min-height: 76px; }
      .auth-main { max-width: 560px; padding: 42px 0 48px; grid-template-columns: minmax(0, 1fr); gap: 2.5rem; }
      .identity { padding: 0; }
      .identity h1 { font-size: 2.65rem; }
      .identity-copy { margin-top: .8rem; font-size: 1rem; }
      .identity-rule, .identity-footnote { display: none; }
      .access-panel { padding: 2rem 0 0; border-inline-start: 0; border-top: 1px solid var(--border); }
      .access-panel h2 { font-size: 1.9rem; }
    }
    @media (max-width: 420px) { .locale-control { gap: .35rem; } .locale-control select { max-width: 118px; } }
    @media (prefers-reduced-motion: reduce) { .button { transition: none; } }
  `,
  imports: [RouterLink, SignInFormComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AuthPage {
  readonly session = inject(SessionStore);
  readonly locale = inject(LocaleService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  readonly checking = signal(false);
  readonly returnUrl = this.safeReturnUrl();

  title(): string {
    const status = this.session.status();
    if (status === 'tenant-unavailable') return this.locale.t('tenantUnavailableTitle');
    if (status === 'error') return this.locale.t('sessionErrorTitle');
    return this.locale.t('signInTitle');
  }

  description(): string {
    const status = this.session.status();
    if (status === 'tenant-unavailable') return this.locale.t('tenantUnavailableDescription');
    if (status === 'error') return this.locale.t('sessionErrorDescription');
    return this.locale.t('signInDescription');
  }

  changeLocale(value: string): void {
    this.locale.set(value as SupportedLocale);
  }

  async checkAccess(): Promise<void> {
    if (this.checking()) return;
    this.checking.set(true);
    await this.session.retry();
    if (this.session.status() === 'authenticated') await this.router.navigateByUrl(this.returnUrl);
    this.checking.set(false);
  }

  private safeReturnUrl(): string {
    const candidate = this.route.snapshot.queryParamMap.get('returnUrl');
    return candidate?.startsWith('/') && !candidate.startsWith('//') ? candidate : '/sites';
  }
}