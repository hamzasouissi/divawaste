import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';

import { Zone, ZoneFill } from '../core/api/models';
import { CodePipe, KgPipe } from '../core/format/pipes';
import { StatusComponent } from './page';

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
 * How full a zone is: a bar, the percentage, what still fits in real terms ("8 × Balle ou 20 × Sac"), and the lots
 * that are not in the count or not where they should be.
 */
@Component({
  selector: 'dw-zone-fill',
  imports: [TranslocoDirective, CodePipe, KgPipe, StatusComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (fill().pct !== null) {
        <span class="meter" role="meter" [attr.aria-label]="t('stock.zoneFill')" [attr.aria-valuenow]="pct()" aria-valuemin="0" aria-valuemax="100">
          <span class="bar" [class]="fill().alert_level ?? 'ok'" [style.inline-size.%]="pct()"></span>
        </span>
        <span class="pct">{{ pct() }} %</span>
        @if (fill().alert_level; as level) { <dw-status kind="alert" [value]="level" /> }
        @if (details()) {
          <span class="dw-faint">
            {{ t('stock.remaining') }}
            @for (item of counts(); track item.kind; let first = $first) { @if (!first) { {{ t('common.or') }} } {{ item.count }} × {{ item.kind | dwCode: 'packaging' }} }
            @if (fill().remaining.kg !== null) { @if (counts().length > 0) { · } {{ fill().remaining.kg | dwKg: 'auto' }} }
          </span>
        }
      } @else if (placeholder()) {
        <span class="dw-faint">{{ placeholder() }}</span>
      }
      @if (details() && breakdown().length > 0) {
        <span class="dw-faint">{{ t('stock.holds') }} @for (item of breakdown(); track item.kind; let first = $first) { @if (!first) { · } {{ item.count }} × {{ item.kind | dwCode: 'packaging' }} }</span>
      }
      @if ((fill().unconfirmed_lots ?? 0) > 0) { <span class="note" [title]="t('stock.unconfirmedHint')">{{ t('stock.unconfirmed', { count: fill().unconfirmed_lots }) }}</span> }
      @if (fill().uncounted > 0) { <span class="note" [title]="t('stock.uncountedHint')">{{ t('stock.uncounted', { count: fill().uncounted }) }}</span> }
      @if (fill().misplaced > 0) { <span class="note" [title]="t('stock.misplacedHint')">{{ t('stock.misplaced', { count: fill().misplaced }) }}</span> }
    </ng-container>
  `,
  styles: `
    :host { display: inline-flex; align-items: center; gap: var(--dw-2) var(--dw-3); flex-wrap: wrap; }
    .meter { flex: 0 0 120px; inline-size: 120px; height: 8px; border-radius: 4px; background: var(--dw-line-soft); overflow: hidden; }
    .bar { display: block; height: 100%; max-inline-size: 100%; background: var(--dw-success); }
    .bar.warning { background: #d68a00; } .bar.exceeded { background: var(--dw-danger); }
    .pct { font-variant-numeric: tabular-nums; font-weight: 600; }
    .note { padding: 1px 8px; border-radius: 999px; background: var(--dw-warning-soft, #fdf1d8); color: var(--dw-ink-2); font-size: 12px; }
  `,
})
export class ZoneFillComponent {
  readonly fill = input.required<ZoneFill>();
  /** Shows what still fits next to the percentage. */
  readonly details = input(true);
  /** What the zone holds, in packages per packaging (loose lots are reported apart, they have no packages). */
  protected readonly breakdown = computed(() => Object.entries(this.fill().packages ?? {}).filter(([kind, count]) => kind !== '' && count > 0).map(([kind, count]) => ({ kind, count })));
  /** Text shown when the zone has no limit. */
  readonly placeholder = input('');

  protected readonly pct = computed(() => Math.round(this.fill().pct ?? 0));
  protected readonly counts = computed(() => Object.entries(this.fill().remaining.counts).map(([kind, count]) => ({ kind, count })));
}
