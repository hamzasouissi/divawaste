import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';

import { PickupLine, ToleranceDecision, ToleranceRuleSnapshot, ToleranceStatus } from '../../core/api/models';
import { NumberPipe, QuantityPipe } from '../../core/format/pipes';
import { StatusComponent } from '../../ui/page';

type Stage = 'declared' | 'departure' | 'received';

/** A line of waste counted in pieces or litres: it is declared, loaded and received by its quantity, not by a weight. */
export function counted(line: Pick<PickupLine, 'measure_dimension'>): boolean {
  return (line.measure_dimension ?? 'mass') !== 'mass';
}

/** The figure of a line at one stage, with its unit: kilograms for weighed waste, the unit of the waste otherwise. */
export function measure(line: PickupLine, stage: Stage): { value: string | null; unit: string } {
  return counted(line)
    ? { value: line[`${stage}_quantity`] ?? null, unit: line.unit ?? 'piece' }
    : { value: line[`${stage}_weight_kg`] ?? null, unit: 'kg' };
}

/** Why the outcome is what it is, as a translation key and its parameters: screens never show a formula. */
export function reason(decision: Pick<ToleranceDecision, 'warning_pct' | 'blocking_pct' | 'direction' | 'difference'>, status: ToleranceStatus): { key: string; limit: string | null } {
  switch (status) {
    case 'blocking':
      return { key: 'tolerance.reasons.blocking', limit: decision.blocking_pct };
    case 'warning':
      return { key: 'tolerance.reasons.warning', limit: decision.warning_pct };
    case 'no_reference':
      return { key: 'tolerance.reasons.no_reference', limit: null };
    case 'not_measured':
      return { key: 'tolerance.reasons.not_measured', limit: null };
    default: {
      // A gap on the side the rule does not look at is accepted whatever its size: say so instead of "within 5 %".
      const ignored = (decision.direction === 'excess' && Number(decision.difference) < 0) || (decision.direction === 'shortage' && Number(decision.difference) > 0);

      return { key: ignored ? 'tolerance.reasons.ignored_side' : 'tolerance.reasons.within', limit: decision.warning_pct ?? decision.blocking_pct };
    }
  }
}

/** What someone has to do next about a pickup, given the worst outcome of its lots. */
export function nextStep(status: ToleranceStatus | null | undefined, acknowledged: boolean, mayAuthorize: boolean): 'none' | 'explain' | 'authorize' | 'ask_authorized' {
  if (acknowledged || !status || status === 'within' || status === 'not_measured') {
    return 'none';
  }
  if (status === 'blocking') {
    return mayAuthorize ? 'authorize' : 'ask_authorized';
  }

  return 'explain';
}

/**
 * The limits of a rule in words: "jusqu'à 5 % sans justification · au-delà de 10 %, accord requis", and where the
 * rule comes from.
 */
@Component({
  selector: 'dw-tolerance-rule',
  imports: [TranslocoDirective, NumberPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (rule(); as r) {
        <span>
          @if (r.warning_pct) { {{ t('tolerance.limits.warning', { pct: (r.warning_pct | dwNumber: 2) }) }} }
          @if (r.warning_pct && r.blocking_pct) { · }
          @if (r.blocking_pct) { {{ t('tolerance.limits.blocking', { pct: (r.blocking_pct | dwNumber: 2) }) }} }
          @if (r.direction !== 'both') { · {{ t('tolerance.direction.' + r.direction) }} }
        </span>
        <span class="dw-faint source"> — {{ t('tolerance.source.' + r.source, { channel: r.channel ? t('channels.' + r.channel) : '' }) }}@if (r.basis === 'legal') { · {{ t('tolerance.basis.legal') }} : {{ r.reference }} }</span>
      }
    </ng-container>
  `,
  styles: `.source { font-size: 12.5px; }`,
})
export class ToleranceRuleText {
  readonly rule = input.required<ToleranceRuleSnapshot | null | undefined>();
}

/**
 * One reception explained to an operator: what left, what arrived, the gap, the tolerance that applies, the
 * result and why — the figures and the rule as they stood when the lot was received.
 */
@Component({
  selector: 'dw-tolerance-decision',
  imports: [TranslocoDirective, NumberPipe, QuantityPipe, StatusComponent, ToleranceRuleText],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (line().tolerance; as d) {
        <article class="decision" [class]="line().tolerance_status ?? ''">
          <header>
            <strong class="dw-mono">{{ line().lot_number_snapshot }}</strong><span>{{ line().waste_type_label_snapshot }}</span>
            <dw-status kind="tolerance" [value]="line().tolerance_status ?? 'within'" />
          </header>
          <dl>
            <div><dt>{{ t('tolerance.left') }}</dt><dd>{{ d.reference_quantity | dwQty: d.unit }}</dd></div>
            <div><dt>{{ t('tolerance.arrived') }}</dt><dd>{{ d.measured_quantity | dwQty: d.unit }}</dd></div>
            <div><dt>{{ t('tolerance.difference') }}</dt><dd>{{ d.difference | dwQty: d.unit : true }}</dd></div>
            <div><dt>{{ t('tolerance.gap') }}</dt><dd>@if (d.pct_abs !== null) { {{ d.pct_abs | dwNumber: 2 : ' %' }} } @else { {{ t('tolerance.noPercentage') }} }</dd></div>
            <div class="wide"><dt>{{ t('tolerance.applicable') }}</dt><dd><dw-tolerance-rule [rule]="d" /></dd></div>
            <div class="wide"><dt>{{ t('tolerance.reason') }}</dt><dd>{{ t(why().key, { pct: (why().limit | dwNumber: 2) }) }}</dd></div>
          </dl>
        </article>
      }
    </ng-container>
  `,
  styles: `
    .decision { border: 1px solid var(--dw-line); border-inline-start: 4px solid var(--dw-success); border-radius: var(--dw-radius); padding: 10px 14px; background: var(--dw-surface); }
    .decision.warning, .decision.no_reference { border-inline-start-color: var(--dw-warning); } .decision.blocking { border-inline-start-color: var(--dw-danger); }
    header { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-block-end: 8px; } header dw-status { margin-inline-start: auto; }
    dl { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px 16px; margin: 0; } .wide { grid-column: 1 / -1; }
    dt { font-size: 12px; color: var(--dw-ink-3); } dd { margin: 0; font-variant-numeric: tabular-nums; }
    @media (max-width: 640px) { dl { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  `,
})
export class ToleranceDecisionCard {
  readonly line = input.required<PickupLine>();
  protected readonly why = computed(() => reason(this.line().tolerance as ToleranceDecision, this.line().tolerance_status ?? 'within'));
}

interface SummaryRow {
  waste_type: { ulid: string; code: string; name: string };
  unit: string;
  received: number;
  within: number;
  warning: number;
  blocking: number;
  no_reference: number;
  not_judged: number;
}

/** A waste type whose receptions keep falling outside its tolerance: something in the weighing, counting or handling is off. */
export function repeated(row: Pick<SummaryRow, 'warning' | 'blocking'>): boolean {
  return row.warning + row.blocking >= 2;
}

/**
 * Receptions per waste type and what came of them: where what is received keeps disagreeing with what left.
 * Counts only — measured facts, no estimate.
 */
@Component({
  selector: 'dw-tolerance-summary',
  imports: [TranslocoDirective, StatusComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (rows().length > 0) {
        <section class="dw-panel">
          <div class="dw-panel-head"><h2 class="dw-h2">{{ t('tolerance.byType.title') }}</h2><span class="dw-muted">{{ t('tolerance.byType.hint') }}</span></div>
          <div class="scroll">
            <table class="dw-table">
              <thead>
                <tr>
                  <th>{{ t('fields.wasteType') }}</th><th class="dw-num">{{ t('tolerance.byType.received') }}</th><th class="dw-num">{{ t('status.tolerance.within') }}</th>
                  <th class="dw-num">{{ t('status.tolerance.warning') }}</th><th class="dw-num">{{ t('status.tolerance.blocking') }}</th><th class="dw-num">{{ t('status.tolerance.no_reference') }}</th>
                </tr>
              </thead>
              <tbody>
                @for (row of rows(); track row.waste_type.ulid + row.unit) {
                  <tr>
                    <td>{{ row.waste_type.name }} <span class="dw-faint">· {{ t('units.' + row.unit) }}</span> @if (repeated(row)) { <dw-status kind="tolerance" value="warning" [label]="t('tolerance.byType.repeated')" /> }</td>
                    <td class="dw-num">{{ row.received }}</td><td class="dw-num">{{ row.within }}</td>
                    <td class="dw-num" [class.warn]="row.warning > 0">{{ row.warning }}</td><td class="dw-num" [class.bad]="row.blocking > 0">{{ row.blocking }}</td>
                    <td class="dw-num">{{ row.no_reference }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        </section>
      }
    </ng-container>
  `,
  styles: `.scroll { overflow-x: auto; } .warn { color: var(--dw-warning); font-weight: 600; } .bad { color: var(--dw-danger); font-weight: 600; } section { margin-block-start: var(--dw-4); }`,
})
export class ToleranceSummary {
  /** Only the types with at least one reception judged by a rule: older receptions carry no outcome. */
  readonly rows = input.required<SummaryRow[]>();
  protected readonly repeated = repeated;
}
