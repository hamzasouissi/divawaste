import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { Tag } from 'primeng/tag';

import { IconComponent } from './icon';
import { DaviHintComponent } from '../layout/davi-hint';

/**
 * Header of every screen: where you are, what it is, and the actions that apply to the whole screen
 * (projected on the trailing side). One primary action at most, placed last.
 */
@Component({
  selector: 'dw-page-header',
  imports: [RouterLink, IconComponent, DaviHintComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="main">
      @if (back()) {
        <a class="back" [routerLink]="back()">
          <dw-icon>chevron_left</dw-icon>
          <span>{{ backLabel() }}</span>
        </a>
      }
      <div class="title-row">
        <h1>{{ title() }}</h1>
        <ng-content select="[dwTitleBadge]" />
      </div>
      @if (subtitle()) {
        <p class="subtitle">{{ subtitle() }}</p>
      }
    </div>
    <div class="actions"><ng-content /></div>
    <!-- DAVI's card of the page, under its title: the page's guide (its explanation folds inside). -->
    <dw-davi-hint />
  `,
  styles: `
    :host { display: flex; align-items: flex-end; gap: 12px 20px; flex-wrap: wrap; margin-block-end: 24px; min-height: 48px; }
    .main { flex: 1 1 320px; min-width: 0; }
    .title-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    h1 { font-size: 26px; line-height: 34px; font-weight: 680; letter-spacing: -0.025em; color: var(--dw-ink); }
    .subtitle { font-size: 14px; line-height: 21px; color: var(--dw-ink-2); margin-block-start: 4px; max-width: 80ch; }
    .back { display: inline-flex; align-items: center; gap: 2px; margin-inline-start: -4px; margin-block-end: 6px; font-size: 12.5px; font-weight: 560; color: var(--dw-ink-3); border-radius: 6px; }
    .back:hover { color: var(--dw-primary); text-decoration: none; }
    .back dw-icon { font-size: 16px; }
    :host-context([dir='rtl']) .back dw-icon { transform: scaleX(-1); }
    .actions { display: flex; align-items: center; gap: var(--dw-2); flex-wrap: wrap; }
    .actions:empty { display: none; }
  `,
})
export class PageHeaderComponent {
  readonly title = input.required<string>();
  readonly subtitle = input('');
  readonly back = input<string | unknown[] | null>(null);
  readonly backLabel = input('');
}

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

/** Tone of each business status. One place, so a status has the same colour on every screen. */
const TONES: Record<string, Record<string, Tone>> = {
  lot: { created: 'info', stored: 'neutral', awaiting_pickup: 'warning', collected: 'info', treated: 'success', closed: 'neutral' },
  pickup: { draft: 'neutral', requested: 'warning', confirmed: 'info', refused: 'danger', collected: 'info', received: 'success', completed: 'neutral', cancelled: 'danger' },
  // The bourse: a listing, and each buyer's negotiation on it.
  listing: { draft: 'neutral', published: 'info', sold: 'success', expired: 'neutral', cancelled: 'danger' },
  contract: { draft: 'neutral', upcoming: 'info', active: 'success', expired: 'neutral', terminated: 'danger' },
  negotiation: { awaiting_seller: 'warning', awaiting_buyer: 'warning', accepted: 'success', refused: 'danger', withdrawn: 'neutral', closed: 'neutral' },
  tolerance: { within: 'success', warning: 'warning', blocking: 'danger', no_reference: 'warning', not_measured: 'neutral' },
  line: { planned: 'neutral', loaded: 'info', not_loaded: 'warning', received: 'success', rejected: 'danger', treated: 'success' },
  company: { pending_verification: 'warning', pending_approval: 'warning', active: 'success', rejected: 'danger', suspended: 'danger', closed: 'neutral' },
  accreditation: { valid: 'success', expiring: 'warning', expired: 'danger', pending: 'warning', approved: 'success', rejected: 'danger', revoked: 'danger' },
  alert: { warning: 'warning', exceeded: 'danger', ok: 'success', unverifiable: 'warning' },
  limit: { respected: 'success', warning: 'warning', exceeded: 'danger', unverifiable: 'warning' },
  manifest: { draft: 'neutral', pending_producer_signature: 'warning', pending_transporter_signature: 'warning', pending_receiver_signature: 'warning', pending_signed_copy: 'warning', completed: 'success', cancelled: 'danger' },
  // The documents of a pickup, apart from its status: closed is not complete.
  compliance: { not_due: 'neutral', not_applicable: 'neutral', incomplete: 'danger', pending: 'warning', complete: 'success' },
  declaration: { draft: 'neutral', in_review: 'warning', validated: 'success', submitted: 'success' },
  document: { draft: 'neutral', pending_signatures: 'warning', issued: 'success', superseded: 'neutral', cancelled: 'danger' },
  report: { queued: 'neutral', processing: 'info', completed: 'success', failed: 'danger', expired: 'neutral' },
  invoice: { issued: 'warning', partially_paid: 'warning', paid: 'success', overdue: 'danger', cancelled: 'neutral' },
  subscription: { trialing: 'info', active: 'success', past_due: 'danger', suspended: 'danger', cancelled: 'neutral', expired: 'danger' },
  member: { active: 'success', suspended: 'danger' },
  invitation: { pending: 'warning', accepted: 'success', expired: 'neutral', revoked: 'neutral' },
  sync: { pending: 'warning', synced: 'success', failed: 'danger', conflict: 'danger' },
};

const SEVERITY: Record<Tone, 'secondary' | 'info' | 'success' | 'warn' | 'danger'> = { neutral: 'secondary', info: 'info', success: 'success', warning: 'warn', danger: 'danger' };

/**
 * Status as a compact tag (PrimeNG Tag). Text always accompanies the colour (never colour alone), and a
 * status has the same tone on every screen.
 *
 *   <dw-status kind="lot" [value]="lot.status" />
 */
@Component({
  selector: 'dw-status',
  imports: [Tag, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<p-tag *transloco="let t" class="dw-status-tag" [severity]="severity()" [value]="label() || t('status.' + kind() + '.' + value())" />`,
  styles: ':host { display: inline-flex; vertical-align: middle; }',
})
export class StatusComponent {
  readonly kind = input.required<string>();
  readonly value = input.required<string>();
  /** Overrides the translated status text (already-translated label). */
  readonly label = input('');
  readonly tone = input<Tone | null>(null);

  protected readonly resolved = computed(() => this.tone() ?? TONES[this.kind()]?.[this.value()] ?? 'neutral');
  protected readonly severity = computed(() => SEVERITY[this.resolved()]);
}

/**
 * A figure with its label; the building block of dashboards and summaries. Several of them go in a
 * `.dw-stats` strip: one panel, cells separated by hairlines, instead of a row of cards.
 */
@Component({
  selector: 'dw-kpi',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="label">{{ label() }}</div>
    <div class="value" [class]="tone() + ' ' + size()">{{ value() }}</div>
    @if (hint()) {
      <div class="hint">{{ hint() }}</div>
    }
  `,
  styles: `
    :host { display: block; background: var(--dw-surface); padding: 18px 20px 18px; min-width: 0; }
    .label { font-size: 12.5px; line-height: 16px; font-weight: 560; color: var(--dw-ink-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .value { font-size: 28px; line-height: 36px; font-weight: 680; letter-spacing: -0.03em; font-variant-numeric: tabular-nums; margin-block-start: 6px; color: var(--dw-ink); overflow-wrap: anywhere; }
    /* an amount in dinars with its millimes ("309 798,857 DT") keeps to one line in a quarter of the page */
    .value.long { font-size: 22px; line-height: 30px; }
    .value.longer { font-size: 18px; line-height: 26px; }
    .value.danger { color: var(--dw-danger); }
    .value.warning { color: var(--dw-warning); }
    .value.success { color: var(--dw-success); }
    .hint { font-size: 12px; line-height: 16px; color: var(--dw-ink-3); margin-block-start: 1px; }
  `,
})
export class KpiComponent {
  readonly label = input.required<string>();
  readonly value = input.required<string | number>();
  readonly hint = input('');
  readonly tone = input<Tone>('neutral');
  protected readonly size = computed(() => {
    const length = String(this.value() ?? '').length;

    return length > 13 ? 'longer' : length > 10 ? 'long' : '';
  });
}

export const PAGE = [PageHeaderComponent, StatusComponent, KpiComponent] as const;
