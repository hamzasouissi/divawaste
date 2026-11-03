import { ChangeDetectionStrategy, Component, DestroyRef, effect, inject, input, signal } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';

import { Davi } from '../core/davi/davi';
import { IconComponent } from './icon';

const KEY = 'dw.help.open';

function opened(): string[] {
  try {
    return JSON.parse(localStorage.getItem(KEY) ?? '[]') as string[];
  } catch {
    return [];
  }
}

/**
 * "How this page works", in a few plain sentences under the title of a page (G-11). Folded by default into one quiet
 * line, so the page leads with its content; opened on demand, and the choice is remembered per page on the device.
 * With DAVI on, the explanation moves into DAVI's card at the top of the page ("Comment marche cette page ?"), so a
 * page shows one guide, not two.
 *
 *   <dw-help topic="stock" />   → texts `help.stock.title` and `help.stock.text` (one sentence per line)
 */
@Component({
  selector: 'dw-help',
  imports: [TranslocoDirective, ButtonDirective, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[class.off]': 'davi.available()' },
  template: `
    <ng-container *transloco="let t">
      @if (davi.available()) {
        <!-- shown in DAVI's card -->
      } @else if (open()) {
        <aside class="help" role="note">
          <span class="ico"><dw-icon aria-hidden="true">lightbulb</dw-icon></span>
          <div class="body">
            <strong>{{ t('help.' + topic() + '.title') }}</strong>
            <ul>@for (line of lines(t('help.' + topic() + '.text')); track $index) { <li>{{ line }}</li> }</ul>
          </div>
          <div class="acts">
            <button pButton text size="small" type="button" (click)="toggle(false)">{{ t('help.hide') }}</button>
          </div>
        </aside>
      } @else {
        <button type="button" class="show" (click)="toggle(true)" [attr.aria-expanded]="false"><dw-icon>lightbulb</dw-icon><span>{{ t('help.' + topic() + '.title') }}</span><span class="more">{{ t('help.show') }}</span></button>
      }
    </ng-container>
  `,
  styles: `
    :host { display: block; margin-block: -8px 18px; } :host(.off) { display: none; }
    .help { display: flex; align-items: flex-start; gap: 14px; padding: 16px 16px 16px 18px; border-radius: var(--dw-radius-lg); background: var(--dw-surface); box-shadow: var(--dw-shadow);
      color: var(--dw-ink); font-size: 13.5px; line-height: 21px; animation: dw-rise 160ms var(--dw-ease); }
    .ico { display: grid; place-items: center; flex: none; width: 32px; height: 32px; border-radius: 9px; background: var(--dw-warning-soft); color: var(--dw-warning); }
    .ico dw-icon { font-size: 18px; }
    .body { flex: 1; min-inline-size: 0; }
    .body strong { font-weight: 620; }
    ul { margin: 6px 0 0; padding-inline-start: 18px; display: grid; gap: 3px; color: var(--dw-ink-2); }
    .show { all: unset; display: inline-flex; align-items: center; gap: 8px; padding: 4px 2px; font-size: 13px; color: var(--dw-ink-3); cursor: pointer; border-radius: 6px; }
    .show dw-icon { font-size: 16px; color: var(--dw-warning); }
    .show .more { color: var(--dw-primary); font-weight: 560; }
    .show:hover .more { text-decoration: underline; }
    .show.davi { margin-inline-start: 14px; } .show.davi dw-icon { color: var(--dw-primary); }
    .acts { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; } .ask dw-icon { font-size: 16px; }
    .show:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
  `,
})
export class HelpComponent {
  protected readonly davi = inject(Davi);
  readonly topic = input.required<string>();
  private readonly opened = signal(opened());

  constructor() {
    effect(() => this.davi.helpTopic.set(this.topic()));
    inject(DestroyRef).onDestroy(() => {
      if (this.davi.helpTopic() === this.topic()) {
        this.davi.helpTopic.set(null);
      }
    });
  }

  protected open(): boolean {
    return this.opened().includes(this.topic());
  }

  protected lines(text: string): string[] {
    return text.split('\n').filter((line) => line.trim() !== '');
  }

  protected toggle(show: boolean): void {
    const next = show ? [...this.opened(), this.topic()] : this.opened().filter((topic) => topic !== this.topic());
    this.opened.set(next);
    try {
      localStorage.setItem(KEY, JSON.stringify(next));
    } catch {
      // Private browsing: the choice lasts for the visit only.
    }
  }
}
