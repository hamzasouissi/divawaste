import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';

import { LanguageComponent } from '../../ui/language';

/** Frame of the public screens: sign-in, registration, password and invitation flows. */
@Component({
  imports: [RouterOutlet, TranslocoDirective, LanguageComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <aside class="brand">
        <div class="logo"><img src="icons/icon.svg" alt="" width="36" height="36" /><span>DivaWaste Textile</span></div>
        <div>
          <p class="pitch">{{ t('auth.pitch') }}</p>
          <ul>
            <li>{{ t('auth.point1') }}</li>
            <li>{{ t('auth.point2') }}</li>
            <li>{{ t('auth.point3') }}</li>
          </ul>
        </div>
        <p class="legal">© Diva Software</p>
      </aside>
      <main class="form"><dw-language class="language" /><div class="card"><router-outlet /></div></main>
    </ng-container>
  `,
  styles: `
    :host { display: grid; grid-template-columns: minmax(320px, 2fr) 3fr; min-height: 100dvh; --dw-control: 40px; }
    .brand { display: flex; flex-direction: column; justify-content: space-between; gap: var(--dw-6); padding: var(--dw-6) var(--dw-7); background: var(--dw-nav); color: #d7e3e1; }
    .logo { display: flex; align-items: center; gap: 12px; color: #fff; font-size: 17px; font-weight: 600; }
    .pitch { font-size: 24px; line-height: 32px; font-weight: 600; color: #fff; letter-spacing: -0.01em; max-width: 22ch; margin-block-end: var(--dw-4); }
    ul { margin: 0; padding-inline-start: 18px; display: grid; gap: 8px; font-size: 14px; }
    .legal { font-size: 12px; color: #7f9390; }
    .form { position: relative; display: grid; place-items: center; padding: var(--dw-7) var(--dw-5) var(--dw-5); background: var(--dw-surface); }
    .language { position: absolute; inset-block-start: var(--dw-4); inset-inline-end: var(--dw-4); }
    .card { width: min(100%, 560px); }
    @media (max-width: 900px) {
      :host { grid-template-columns: minmax(0, 1fr); }
      .brand { padding: var(--dw-4) var(--dw-5); gap: var(--dw-3); }
      .brand > div, .legal { display: none; }
    }
  `,
})
export class AuthLayoutComponent {}
