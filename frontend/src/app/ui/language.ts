import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';

import { Locale } from '../core/i18n/i18n';

/**
 * Language choice (Français / English). The choice applies at once, is remembered on this device and, for a
 * signed-in user, saved to the profile so that emails follow it too (see App).
 */
@Component({
  selector: 'dw-language',
  imports: [TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="switch" role="group" *transloco="let t" [attr.aria-label]="t('fields.language')">
      @for (language of locale.available; track language.code) {
        <button type="button" [class.on]="locale.lang() === language.code" [attr.aria-pressed]="locale.lang() === language.code" [attr.lang]="language.code"
          [attr.title]="language.label" (click)="locale.choose(language.code)">{{ language.code.toUpperCase() }}</button>
      }
    </div>
  `,
  styles: `
    :host { display: inline-flex; }
    .switch { display: inline-flex; border: 1px solid var(--dw-line); border-radius: var(--dw-radius); overflow: hidden; background: var(--dw-surface); }
    button { all: unset; box-sizing: border-box; cursor: pointer; min-width: 40px; min-height: 32px; padding: 0 10px; text-align: center; font-size: 12.5px; font-weight: 600; color: var(--dw-ink-2); }
    button + button { border-inline-start: 1px solid var(--dw-line); }
    button.on { background: var(--dw-primary); color: #fff; }
    button:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: -2px; }
    :host(.large) button { min-height: var(--dw-touch, 48px); min-width: 72px; font-size: 16px; }
  `,
})
export class LanguageComponent {
  protected readonly locale = inject(Locale);
}
