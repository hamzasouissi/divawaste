import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';

import { LimitResult } from '../core/api/models';
import { LimitText } from '../core/format/limit-text';
import { CodePipe } from '../core/format/pipes';
import { IconComponent } from './icon';
import { StatusComponent } from './page';

/**
 * What a provider asks of a collection, one requirement per line: which waste, the minimum or the packaging asked for,
 * what the collection amounts to, and whether falling short refuses the request or only warns. A weight and a number of
 * bales are two lines with two answers.
 */
@Component({
  selector: 'dw-requirements',
  imports: [TranslocoDirective, CodePipe, StatusComponent, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ul *transloco="let t">
      @for (limit of requirements(); track limit.key) {
        <li [class]="limit.outcome">
          <div class="line">
            <span class="name">{{ text.name(limit) }}</span>
            @if (limit.rule; as rule) {
              <span class="dw-faint">
                @if (rule.waste_type) { {{ rule.waste_type.name }} } @else if (rule.catalog_item) { {{ rule.catalog_item | dwCode: 'catalog' }} } @else { {{ rule.family | dwCode: 'family' }} }
                @if (rule.channel) { · {{ t('channels.' + rule.channel) }} }
              </span>
              @if (rule.scope === 'type') { <span class="scope">{{ t(rule.adds_to_family ? 'requirements.scope.typeAdds' : 'requirements.scope.type') }}</span> }
            }
            <dw-status kind="limit" [value]="limit.outcome" [label]="t('requirements.outcome.' + limit.outcome)" />
            <span class="mode" [class.blocking]="limit.blocking">{{ t(limit.blocking ? 'requirements.blocking' : 'requirements.warningOnly') }}</span>
          </div>
          <div class="said">{{ text.requirement(limit) }}</div>
          @if (limit.source === 'internal') {
            <div class="source internal"><dw-icon aria-hidden="true">edit_note</dw-icon>{{ t('requirements.source.internal') }}@if (limit.rule?.contract_reference) { — {{ limit.rule?.contract_reference }} }@if (limit.rule?.needs_review) { <strong> · {{ t('requirements.source.toReview') }}</strong> }</div>
          } @else if (limit.source === 'provider') {
            <div class="source"><dw-icon aria-hidden="true">verified_user</dw-icon>{{ t('requirements.source.provider') }}</div>
          }
          @if (limit.kind === 'minimum' && (limit.outside_count ?? 0) > 0) {
            <div class="dw-faint">{{ t('requirements.outside', { count: limit.outside_count, packaging: (limit.packaging_type ?? '' | dwCode: 'packaging') }) }} {{ (limit.outside_lots ?? []).join(', ') }}</div>
          }
          @if ((limit.own_rule_lots ?? []).length > 0) {
            <div class="dw-faint">{{ t('requirements.ownRule', { count: limit.own_rule_lots!.length }) }} {{ limit.own_rule_lots!.join(', ') }}</div>
          }
          @if (limit.at_request !== undefined) {
            <div class="compare">
              @if (limit.at_request; as before) {
                {{ t('requirements.atRequest', { outcome: t('requirements.outcome.' + before.outcome) }) }}
              } @else {
                {{ t('requirements.atRequestChanged') }}
              }
              @if ((limit.left_lots ?? []).length > 0) { · {{ t('requirements.leftLots', { count: limit.left_lots!.length }) }} {{ limit.left_lots!.join(', ') }} }
            </div>
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
    .source { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--dw-ink-2); } .source dw-icon { font-size: 14px; width: 14px; height: 14px; } .source.internal { font-style: italic; }
    .scope { font-size: 12px; padding: 0 6px; border-radius: 999px; background: var(--dw-surface-2); color: var(--dw-ink-2); }
    .compare { font-size: 12px; color: var(--dw-ink-2); overflow-wrap: anywhere; }
    .said { overflow-wrap: anywhere; } .exceeded .said { color: var(--dw-danger); font-weight: 600; } .warning .said, .unverifiable .said { color: var(--dw-warning); font-weight: 600; }
  `,
})
export class RequirementsComponent {
  readonly requirements = input.required<LimitResult[]>();
  protected readonly text = inject(LimitText);
}
