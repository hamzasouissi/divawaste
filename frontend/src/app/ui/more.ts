import { ChangeDetectionStrategy, Component, booleanAttribute, input, model } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';

import { IconComponent } from './icon';

/**
 * "Plus de détails" (G-04): the optional fields of a form, folded under one line that says what they hold or what
 * defaults apply. A form shows what is required first; whoever needs the rest opens it.
 *
 *   <dw-more [summary]="'Conditionnement : balle, pris du type'"> …fields… </dw-more>
 */
@Component({
  selector: 'dw-more',
  imports: [TranslocoDirective, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'dw-span-2', '[class.open]': 'open()' },
  template: `
    <ng-container *transloco="let t">
      <button type="button" class="toggle" (click)="open.set(!open())" [attr.aria-expanded]="open()">
        <dw-icon class="chev" aria-hidden="true">expand_more</dw-icon>
        <span class="label">{{ label() || t('common.moreDetails') }}</span>
        @if (!open() && summary()) { <span class="summary">{{ summary() }}</span> }
      </button>
      @if (open()) {
        <div class="body" [class.dw-form]="grid()"><ng-content /></div>
      }
    </ng-container>
  `,
  styles: `
    :host { display: block; border-block-start: 1px solid var(--dw-line-soft); padding-block-start: 6px; }
    .toggle { all: unset; box-sizing: border-box; display: flex; align-items: center; gap: 8px; width: 100%; min-height: 36px; padding: 4px 2px; cursor: pointer; border-radius: 8px; }
    .toggle:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .chev { font-size: 20px; color: var(--dw-ink-3); transition: transform 160ms var(--dw-ease); }
    :host(.open) .chev { transform: rotate(180deg); }
    .label { font-size: 13.5px; font-weight: 600; color: var(--dw-primary); white-space: nowrap; }
    .summary { flex: 1; min-width: 0; font-size: 12.5px; color: var(--dw-ink-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .body { padding-block: 10px 4px; animation: dw-rise 160ms var(--dw-ease); }
  `,
})
export class MoreComponent {
  readonly label = input('');
  readonly summary = input('');
  /** Lay the folded fields out on the two-column form grid. */
  readonly grid = input(true, { transform: booleanAttribute });
  readonly open = model(false);
}

/**
 * Two or three choices side by side, one always chosen: Pesé / Estimé, Je reçois / Je paie. Clearer than a checkbox
 * whose unchecked meaning has to be guessed.
 */
@Component({
  selector: 'dw-segmented',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="seg" role="radiogroup" [attr.aria-label]="ariaLabel() || null">
      @for (option of options(); track option.value) {
        <button type="button" role="radio" [attr.aria-checked]="value() === option.value" [class.on]="value() === option.value" [disabled]="disabled()"
          (click)="value.set(option.value)">{{ option.label }}</button>
      }
    </div>
  `,
  styles: `
    :host { display: inline-flex; }
    .seg { display: inline-flex; padding: 3px; gap: 2px; border-radius: 10px; background: var(--dw-surface-3); }
    button { all: unset; box-sizing: border-box; min-height: 30px; padding: 4px 12px; border-radius: 8px; font-size: 13px; font-weight: 560; color: var(--dw-ink-2); cursor: pointer; white-space: nowrap;
      transition: background-color 140ms, color 140ms, box-shadow 140ms; }
    button:hover:not(.on):not(:disabled) { color: var(--dw-ink); }
    button.on { background: var(--dw-surface); color: var(--dw-ink); box-shadow: 0 1px 2px rgb(13 20 27 / 10%), 0 0 0 1px rgb(13 20 27 / 4%); font-weight: 620; }
    button:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 1px; }
    button:disabled { opacity: 0.5; cursor: not-allowed; }
  `,
})
export class SegmentedComponent<T = string> {
  readonly options = input.required<{ value: T; label: string }[]>();
  readonly value = model<T | null>(null);
  readonly disabled = input(false, { transform: booleanAttribute });
  readonly ariaLabel = input('');
}
