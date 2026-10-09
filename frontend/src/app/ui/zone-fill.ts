import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';

import { Zone, ZoneFill } from '../core/api/models';
import { CodePipe } from '../core/format/pipes';
import { LimitsComponent } from './limits';

/**
 * Why a lot does not belong in a zone, or null when it does. A zone with no list accepts anything; a lot whose
 * colour is not stated is never called misplaced. The zone may come from data saved before these lists existed.
 */
export function zoneMismatch(zone: Partial<Pick<Zone, 'accepted_waste_types' | 'accepted_colors'>> | null | undefined, wasteType: string | null, color: string | null): 'type' | 'color' | null {
  const types = zone?.accepted_waste_types ?? [];
  const colors = zone?.accepted_colors ?? [];
  if (types.length > 0 && wasteType && !types.includes(wasteType)) {
    return 'type';
  }

  return colors.length > 0 && color && !colors.includes(color) ? 'color' : null;
}

/**
 * How full a zone is: its room and its load, each on its own line with its own figures, then what it holds and the lots
 * that are not where they should be.
 */
@Component({
  selector: 'dw-zone-fill',
  imports: [TranslocoDirective, CodePipe, LimitsComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (fill().constraints.length > 0) {
        <dw-limits class="limits" [limits]="fill().constraints" [compact]="!details()" />
      } @else if (placeholder()) {
        <span class="dw-faint">{{ placeholder() }}</span>
      }
      @if (details() && breakdown().length > 0) {
        <span class="dw-faint">{{ t('stock.holds') }} @for (item of breakdown(); track item.kind; let first = $first) { @if (!first) { · } {{ item.count }} × {{ item.kind | dwCode: 'packaging' }} }</span>
      }
      @if ((fill().unconfirmed_lots ?? 0) > 0) { <span class="note" [title]="t('stock.unconfirmedHint')">{{ t('stock.unconfirmed', { count: fill().unconfirmed_lots }) }}</span> }
      @if (fill().misplaced > 0) { <span class="note" [title]="t('stock.misplacedHint')">{{ t('stock.misplaced', { count: fill().misplaced }) }}</span> }
    </ng-container>
  `,
  styles: `
    :host { display: inline-flex; align-items: center; gap: var(--dw-2) var(--dw-3); flex-wrap: wrap; }
    .limits { flex: 1 1 100%; }
    .note { padding: 1px 8px; border-radius: 999px; background: var(--dw-warning-soft, #fdf1d8); color: var(--dw-ink-2); font-size: 12px; }
  `,
})
export class ZoneFillComponent {
  readonly fill = input.required<ZoneFill>();
  /** Shows what still fits under each limit. */
  readonly details = input(true);
  /** What the zone holds, in packages per packaging (loose lots are reported apart, they have no packages). */
  protected readonly breakdown = computed(() => Object.entries(this.fill().packages ?? {}).filter(([kind, count]) => kind !== '' && count > 0).map(([kind, count]) => ({ kind, count })));
  /** Text shown when the zone has no limit. */
  readonly placeholder = input('');
}
