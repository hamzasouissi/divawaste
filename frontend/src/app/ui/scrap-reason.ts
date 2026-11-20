import { ChangeDetectionStrategy, Component, input } from '@angular/core';

import { ScrapReasonRef } from '../core/api/models';

/**
 * A scrap reason as a pill in its own colour: code, then label. The colour marks the reason; the text stays in the
 * colours of text, so the pill reads the same for who does not see the colour.
 *
 *   <dw-scrap-reason [reason]="lot.scrap_reason" />
 */
@Component({
  selector: 'dw-scrap-reason',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (reason(); as r) {
      <span class="dot" aria-hidden="true"></span><span class="code">{{ r.code }}</span><span class="label">{{ r.label }}</span>
    }
  `,
  styles: `
    :host { --c: var(--dw-ink-3); display: inline-flex; align-items: center; gap: 7px; max-inline-size: 100%; min-block-size: 26px; padding: 2px 11px 2px 9px;
      border-radius: 999px; font-size: 13px; line-height: 1.3; background: color-mix(in srgb, var(--c) 12%, var(--dw-surface));
      box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--c) 24%, transparent); color: var(--dw-ink); }
    :host(:empty) { display: none; }
    .dot { inline-size: 8px; block-size: 8px; border-radius: 50%; background: var(--c); flex: none; }
    .code { font-family: var(--dw-font-mono, ui-monospace, monospace); font-weight: 650; font-size: 12px; color: color-mix(in srgb, var(--c) 55%, var(--dw-ink)); }
    .label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  `,
  host: { '[style.--c]': 'reason()?.color || null' },
})
export class ScrapReasonComponent {
  readonly reason = input<ScrapReasonRef | null | undefined>(null);
}
