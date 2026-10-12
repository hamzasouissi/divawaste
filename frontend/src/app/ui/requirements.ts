import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';

import { LimitResult } from '../core/api/models';
import { LimitText } from '../core/format/limit-text';
import { CodePipe } from '../core/format/pipes';
import { StatusComponent } from './page';

/**
 * What a provider asks of a collection, one requirement per line: which waste, the minimum or the packaging asked for,
 * what the collection amounts to, and whether falling short refuses the request or only warns. A weight and a number of
 * bales are two lines with two answers.
 */
@Component({
  selector: 'dw-requirements',
  imports: [TranslocoDirective, CodePipe, StatusComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ul *transloco="let t">
      @for (limit of requirements(); track limit.key) {
        <li [class]="limit.outcome">
          <div class="line">
            <span class="name">{{ text.name(limit) }}</span>
            @if (limit.rule; as rule) {
              <span class="dw-faint">{{ rule.family ? (rule.family | dwCode: 'family') : rule.catalog_item }}@if (rule.channel) { · {{ t('channels.' + rule.channel) }} }</span>
            }
            <dw-status kind="limit" [value]="limit.outcome" [label]="t('requirements.outcome.' + limit.outcome)" />
            <span class="mode" [class.blocking]="limit.blocking">{{ t(limit.blocking ? 'requirements.blocking' : 'requirements.warningOnly') }}</span>
          </div>
          <div class="said">{{ text.requirement(limit) }}</div>
          @if (limit.kind === 'minimum' && (limit.outside_count ?? 0) > 0) {
            <div class="dw-faint">{{ t('requirements.outside', { count: limit.outside_count, packaging: (limit.packaging_type ?? '' | dwCode: 'packaging') }) }} {{ (limit.outside_lots ?? []).join(', ') }}</div>
          }
        </li>
      }
    </ul>
  `,
  styles: `
    :host { display: block; min-inline-size: 0; }
    ul { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--dw-2); font-size: 13px; }
    .line { display: flex; align-items: center; flex-wrap: wrap; gap: var(--dw-1) var(--dw-2); }
    .name { font-weight: 600; }
    .mode { font-size: 12px; color: var(--dw-ink-2); } .mode.blocking { font-weight: 600; color: var(--dw-ink); }
    .said { overflow-wrap: anywhere; } .exceeded .said { color: var(--dw-danger); font-weight: 600; } .warning .said, .unverifiable .said { color: var(--dw-warning); font-weight: 600; }
  `,
})
export class RequirementsComponent {
  readonly requirements = input.required<LimitResult[]>();
  protected readonly text = inject(LimitText);
}
