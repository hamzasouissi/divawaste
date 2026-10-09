import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';

import { LimitResult } from '../core/api/models';
import { CodePipe, KgPipe } from '../core/format/pipes';
import { StatusComponent } from './page';

/** The most serious answer among several limits: exceeded, then unverifiable, then warning. Null when there is none. */
export function worstOutcome(limits: readonly Pick<LimitResult, 'outcome'>[]): LimitResult['outcome'] | null {
  const rank = { respected: 0, warning: 1, unverifiable: 2, exceeded: 3 } as const;

  return limits.reduce<LimitResult['outcome'] | null>((worst, limit) => (worst === null || rank[limit.outcome] > rank[worst] ? limit.outcome : worst), null);
}

/** The limits that refuse an operation: blocking, and exceeded or impossible to verify. */
export function failingLimits<T extends Pick<LimitResult, 'outcome' | 'blocking'>>(limits: readonly T[]): T[] {
  return limits.filter((limit) => limit.blocking && (limit.outcome === 'exceeded' || limit.outcome === 'unverifiable'));
}

/**
 * Limits answered one by one, each in its own unit: "Poids : 450 kg / 500 kg — 90 % — Respecté". A weight, a number of
 * packages and a number of lots never share a bar or a percentage; the lots a limit could not count are named under it.
 */
@Component({
  selector: 'dw-limits',
  imports: [TranslocoDirective, CodePipe, KgPipe, StatusComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ul *transloco="let t" [class.compact]="compact()">
      @for (limit of limits(); track limit.key) {
        <li [class]="limit.outcome">
          <div class="line">
            <span class="name">{{ t('limits.name.' + limit.key) }}</span>
            <span class="figures">
              @if (limit.lines; as lines) {
                @for (line of lines; track line.packaging_type; let first = $first) { @if (!first) { + } {{ line.used }} / {{ line.max }} × {{ line.packaging_type | dwCode: 'packaging' }} }
              } @else if (limit.unit === 'kg') {
                {{ limit.used | dwKg: 'auto' }} / {{ limit.max | dwKg: 'auto' }}
              } @else {
                {{ t('limits.lotsOf', { used: limit.used, max: limit.max }) }}
              }
            </span>
            @if (limit.pct !== null) {
              <span class="meter" role="meter" [attr.aria-label]="t('limits.name.' + limit.key)" [attr.aria-valuenow]="limit.pct" aria-valuemin="0" aria-valuemax="100"><span class="bar" [style.inline-size.%]="limit.pct"></span></span>
              <span class="pct">{{ limit.outcome === 'unverifiable' ? t('limits.atLeast', { pct: round(limit.pct) }) : round(limit.pct) + ' %' }}</span>
            }
            <dw-status kind="limit" [value]="limit.outcome" />
          </div>
          <div class="more">
            @if (limit.outcome === 'exceeded') {
              <span class="over">
                @if (limit.lines; as lines) {
                  @if (lines.length === 1 && lines[0].excess !== null) { {{ t('limits.over', { by: lines[0].excess + ' × ' + (lines[0].packaging_type | dwCode: 'packaging') }) }} }
                  @else { {{ t('limits.overShare', { pct: round((limit.pct ?? 100) - 100) }) }} }
                } @else if (limit.unit === 'kg') { {{ t('limits.over', { by: (limit.excess | dwKg: 'auto') }) }} }
                @else { {{ t('limits.over', { by: t('dashboard.lots', { count: limit.excess }) }) }} }
              </span>
            } @else if (!compact() || limit.outcome === 'warning') {
              <span class="dw-faint">
                {{ t('stock.remaining') }}
                @if (limit.lines; as lines) { @for (line of lines; track line.packaging_type; let first = $first) { @if (!first) { {{ t('common.or') }} } {{ line.remaining }} × {{ line.packaging_type | dwCode: 'packaging' }} } }
                @else if (limit.unit === 'kg') { {{ limit.remaining | dwKg: 'auto' }} }
                @else { {{ t('dashboard.lots', { count: limit.remaining }) }} }
                @if (limit.outcome === 'unverifiable') { {{ t('limits.onKnown') }} }
              </span>
            }
            @if (limit.missing_count > 0) {
              <span class="missing">
                {{ t(limit.unit === 'kg' ? 'limits.missingWeight' : 'limits.missingPackages', { count: limit.missing_count }) }}
                {{ limit.missing_lots.join(', ') }}@if (limit.missing_count > limit.missing_lots.length) { … }
              </span>
            }
            @if ((limit.outside_count ?? 0) > 0) {
              <span class="dw-faint">{{ t('limits.outside', { count: limit.outside_count }) }} {{ (limit.outside_lots ?? []).join(', ') }}</span>
            }
          </div>
        </li>
      }
    </ul>
  `,
  styles: `
    :host { display: block; min-inline-size: 0; }
    ul { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--dw-2); }
    .line { display: flex; align-items: center; flex-wrap: wrap; gap: var(--dw-1) var(--dw-3); }
    .name { font-weight: 600; min-inline-size: 64px; }
    .figures, .pct { font-variant-numeric: tabular-nums; } .pct { font-weight: 600; }
    .meter { flex: 0 0 96px; inline-size: 96px; height: 8px; border-radius: 4px; background: var(--dw-line-soft); overflow: hidden; }
    .bar { display: block; height: 100%; max-inline-size: 100%; background: var(--dw-success); }
    .warning .bar, .unverifiable .bar { background: #d68a00; } .exceeded .bar { background: var(--dw-danger); }
    .more { display: flex; flex-wrap: wrap; gap: 0 var(--dw-3); font-size: 12.5px; line-height: 18px; }
    .over { color: var(--dw-danger); font-weight: 600; } .missing { color: var(--dw-warning); font-weight: 600; overflow-wrap: anywhere; }
    .compact .meter { flex-basis: 64px; inline-size: 64px; } .compact .name { min-inline-size: 0; }
  `,
})
export class LimitsComponent {
  readonly limits = input.required<LimitResult[]>();
  /** In a table cell: what is left is only said when it matters. */
  readonly compact = input(false);

  protected round(value: number): number {
    return Math.round(value);
  }
}
