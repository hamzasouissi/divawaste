import { ChangeDetectionStrategy, Component, OnDestroy, effect, input, output, signal } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';
import { InputText } from 'primeng/inputtext';

import { IconComponent } from './icon';

/** Search box of a list: emits once the user pauses typing, so the server is not queried on every key. */
@Component({
  selector: 'dw-search',
  imports: [InputText, IconComponent, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <dw-icon class="lens">search</dw-icon>
    <input pInputText type="search" [placeholder]="placeholder()" [attr.aria-label]="placeholder()" [value]="value()" (input)="onInput($any($event.target).value)" (keydown.escape)="clear()" />
    @if (value()) {
      <button *transloco="let t" type="button" class="clear" (click)="clear()" [attr.aria-label]="t('common.clear')"><dw-icon>close</dw-icon></button>
    }
  `,
  styles: `
    :host { position: relative; display: inline-flex; align-items: center; min-width: 220px; }
    input { width: 100%; padding-inline: 30px 28px; }
    input::-webkit-search-cancel-button { display: none; }
    .lens { position: absolute; inset-inline-start: 9px; font-size: 16px; color: var(--dw-ink-3); pointer-events: none; }
    .clear { all: unset; position: absolute; inset-inline-end: 6px; display: grid; place-items: center; width: 20px; height: 20px; border-radius: 4px; cursor: pointer;
      color: var(--dw-ink-3); font-size: 14px; }
    .clear:hover { background: var(--dw-surface-3); color: var(--dw-ink); }
    .clear:focus-visible { outline: 2px solid var(--dw-focus); }
  `,
  host: { class: 'dw-search', '[class.has-value]': 'value() !== ""' },
})
export class SearchComponent implements OnDestroy {
  readonly placeholder = input('');
  /** Current search of the list, so that "reset filters" also empties the box. */
  readonly query = input('');
  readonly queryChange = output<string>();

  protected readonly value = signal('');
  private timer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    effect(() => {
      const query = this.query();
      if (query !== this.value().trim()) {
        this.value.set(query);
      }
    });
  }

  protected onInput(value: string): void {
    this.value.set(value);
    if (this.timer) {
      clearTimeout(this.timer);
    }
    this.timer = setTimeout(() => this.queryChange.emit(value.trim()), 300);
  }

  protected clear(): void {
    if (this.timer) {
      clearTimeout(this.timer);
    }
    this.value.set('');
    this.queryChange.emit('');
  }

  ngOnDestroy(): void {
    if (this.timer) {
      clearTimeout(this.timer);
    }
  }
}
