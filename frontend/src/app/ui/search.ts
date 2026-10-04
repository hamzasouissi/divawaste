import { ChangeDetectionStrategy, Component, OnDestroy, input, output, signal } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';

/** Search box of a list: emits once the user pauses typing, so the server is not queried on every key. */
@Component({
  selector: 'dw-search',
  imports: [MatIconModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <mat-icon aria-hidden="true">search</mat-icon>
    <input type="search" [placeholder]="placeholder()" [attr.aria-label]="placeholder()" [value]="value()" (input)="onInput($any($event.target).value)" />
  `,
  styles: `
    :host { display: inline-flex; align-items: center; gap: 6px; height: 36px; padding-inline: 10px; min-width: 220px; background: var(--dw-surface);
      border: 1px solid var(--dw-line); border-radius: var(--dw-radius); }
    :host:focus-within { border-color: var(--dw-primary); box-shadow: 0 0 0 1px var(--dw-primary); }
    mat-icon { font-size: 18px; width: 18px; height: 18px; color: var(--dw-ink-3); }
    input { all: unset; flex: 1; font-size: 13px; min-width: 0; }
  `,
})
export class SearchComponent implements OnDestroy {
  readonly placeholder = input('');
  readonly queryChange = output<string>();

  protected readonly value = signal('');
  private timer: ReturnType<typeof setTimeout> | null = null;

  protected onInput(value: string): void {
    this.value.set(value);
    if (this.timer) {
      clearTimeout(this.timer);
    }
    this.timer = setTimeout(() => this.queryChange.emit(value.trim()), 300);
  }

  ngOnDestroy(): void {
    if (this.timer) {
      clearTimeout(this.timer);
    }
  }
}
