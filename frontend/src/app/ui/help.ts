import { ChangeDetectionStrategy, Component, input, signal } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';

import { IconComponent } from './icon';

const KEY = 'dw.help.hidden';

function hidden(): string[] {
  try {
    return JSON.parse(localStorage.getItem(KEY) ?? '[]') as string[];
  } catch {
    return [];
  }
}

/**
 * "How this page works", in a few plain sentences under the title of a page. Shown until the user hides it; a small
 * "Aide" link brings it back. The choice is remembered per page on the device.
 *
 *   <dw-help topic="stock" />   → texts `help.stock.title` and `help.stock.text` (one sentence per line)
 */
@Component({
  selector: 'dw-help',
  imports: [TranslocoDirective, ButtonDirective, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (open()) {
        <aside class="help" role="note">
          <dw-icon aria-hidden="true">lightbulb</dw-icon>
          <div class="body">
            <strong>{{ t('help.' + topic() + '.title') }}</strong>
            <ul>@for (line of lines(t('help.' + topic() + '.text')); track $index) { <li>{{ line }}</li> }</ul>
          </div>
          <button pButton text size="small" type="button" (click)="toggle(false)">{{ t('help.hide') }}</button>
        </aside>
      } @else {
        <button pButton text size="small" type="button" class="show" (click)="toggle(true)"><dw-icon>help</dw-icon>{{ t('help.show') }}</button>
      }
    </ng-container>
  `,
  styles: `
    :host { display: block; margin-block-end: var(--dw-4); }
    .help { display: flex; align-items: flex-start; gap: var(--dw-3); padding: var(--dw-3) var(--dw-4); border-radius: var(--dw-radius); background: var(--dw-info-soft); color: var(--dw-ink); font-size: 13.5px; line-height: 20px; }
    .help > dw-icon { flex: none; color: var(--dw-info); font-size: 20px; margin-block-start: 1px; }
    .body { flex: 1; min-inline-size: 0; }
    ul { margin: 4px 0 0; padding-inline-start: 18px; display: grid; gap: 2px; }
    .show { margin-block-start: -8px; }
  `,
})
export class HelpComponent {
  readonly topic = input.required<string>();
  private readonly closed = signal(hidden());

  protected open(): boolean {
    return !this.closed().includes(this.topic());
  }

  protected lines(text: string): string[] {
    return text.split('\n').filter((line) => line.trim() !== '');
  }

  protected toggle(show: boolean): void {
    const next = show ? this.closed().filter((topic) => topic !== this.topic()) : [...this.closed(), this.topic()];
    this.closed.set(next);
    try {
      localStorage.setItem(KEY, JSON.stringify(next));
    } catch {
      // Private browsing: the choice lasts for the visit only.
    }
  }
}
