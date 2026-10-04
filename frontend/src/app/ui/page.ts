import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';

/**
 * Header of every screen: where you are, what it is, and the actions that apply to the whole screen
 * (projected on the trailing side). One primary action at most.
 */
@Component({
  selector: 'dw-page-header',
  imports: [RouterLink, MatIconModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="main">
      @if (back()) {
        <a class="back" [routerLink]="back()" [attr.aria-label]="backLabel()">
          <mat-icon aria-hidden="true">arrow_back</mat-icon>
          <span>{{ backLabel() }}</span>
        </a>
      }
      <div class="title-row">
        <h1 class="dw-h1">{{ title() }}</h1>
        <ng-content select="[dwTitleBadge]" />
      </div>
      @if (subtitle()) {
        <p class="dw-muted">{{ subtitle() }}</p>
      }
    </div>
    <div class="actions"><ng-content /></div>
  `,
  styles: `
    :host { display: flex; align-items: flex-end; gap: var(--dw-4); flex-wrap: wrap; margin-block-end: var(--dw-4); }
    .main { flex: 1 1 320px; min-width: 0; }
    .title-row { display: flex; align-items: center; gap: var(--dw-3); flex-wrap: wrap; }
    .back { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--dw-ink-2); margin-block-end: 2px; }
    .back mat-icon { font-size: 16px; width: 16px; height: 16px; }
    :host-context([dir='rtl']) .back mat-icon { transform: scaleX(-1); }
    .actions { display: flex; align-items: center; gap: var(--dw-2); flex-wrap: wrap; }
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
  line: { planned: 'neutral', loaded: 'info', not_loaded: 'warning', received: 'success', rejected: 'danger', treated: 'success' },
  company: { pending_verification: 'warning', pending_approval: 'warning', active: 'success', rejected: 'danger', suspended: 'danger', closed: 'neutral' },
  accreditation: { valid: 'success', expiring: 'warning', expired: 'danger', pending: 'warning', approved: 'success', rejected: 'danger', revoked: 'danger' },
  alert: { warning: 'warning', exceeded: 'danger', ok: 'success' },
  manifest: { draft: 'neutral', pending_producer_signature: 'warning', pending_transporter_signature: 'warning', pending_receiver_signature: 'warning', completed: 'success', cancelled: 'danger' },
  declaration: { draft: 'neutral', in_review: 'warning', validated: 'success', submitted: 'success' },
  document: { draft: 'neutral', pending_signatures: 'warning', issued: 'success', superseded: 'neutral', cancelled: 'danger' },
  report: { queued: 'neutral', processing: 'info', completed: 'success', failed: 'danger', expired: 'neutral' },
  invoice: { issued: 'warning', partially_paid: 'warning', paid: 'success', overdue: 'danger', cancelled: 'neutral' },
  subscription: { trialing: 'info', active: 'success', past_due: 'danger', suspended: 'danger', cancelled: 'neutral', expired: 'danger' },
  member: { active: 'success', suspended: 'danger' },
  invitation: { pending: 'warning', accepted: 'success', expired: 'neutral', revoked: 'neutral' },
  sync: { pending: 'warning', synced: 'success', failed: 'danger', conflict: 'danger' },
};

/**
 * Status as a compact badge. Text always accompanies the colour (never colour alone).
 *
 *   <dw-status kind="lot" [value]="lot.status" />
 */
@Component({
  selector: 'dw-status',
  imports: [TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<span class="badge" [class]="resolved()" *transloco="let t">{{ label() || t('status.' + kind() + '.' + value()) }}</span>`,
  styles: `
    :host { display: inline-flex; }
    .badge { display: inline-flex; align-items: center; height: 22px; padding-inline: 8px; border-radius: 999px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
    .neutral { background: var(--dw-neutral-soft); color: var(--dw-ink-2); }
    .info { background: var(--dw-info-soft); color: var(--dw-info); }
    .success { background: var(--dw-success-soft); color: var(--dw-success); }
    .warning { background: var(--dw-warning-soft); color: var(--dw-warning); }
    .danger { background: var(--dw-danger-soft); color: var(--dw-danger); }
  `,
})
export class StatusComponent {
  readonly kind = input.required<string>();
  readonly value = input.required<string>();
  /** Overrides the translated status text (already-translated label). */
  readonly label = input('');
  readonly tone = input<Tone | null>(null);

  protected readonly resolved = computed(() => this.tone() ?? TONES[this.kind()]?.[this.value()] ?? 'neutral');
}

/** A figure with its label; the building block of dashboards and summaries. */
@Component({
  selector: 'dw-kpi',
  imports: [MatIconModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="dw-label">{{ label() }}</div>
    <div class="value" [class]="tone()">{{ value() }}</div>
    @if (hint()) {
      <div class="hint dw-faint">{{ hint() }}</div>
    }
  `,
  styles: `
    :host { display: block; background: var(--dw-surface); border: 1px solid var(--dw-line); border-radius: var(--dw-radius-lg); padding: var(--dw-3) var(--dw-4); min-width: 0; }
    .value { font-size: 24px; line-height: 32px; font-weight: 600; letter-spacing: -0.01em; font-variant-numeric: tabular-nums; margin-block-start: 2px; overflow-wrap: anywhere; }
    .value.danger { color: var(--dw-danger); }
    .value.warning { color: var(--dw-warning); }
    .value.success { color: var(--dw-success); }
    .hint { font-size: 12px; }
  `,
})
export class KpiComponent {
  readonly label = input.required<string>();
  readonly value = input.required<string | number>();
  readonly hint = input('');
  readonly tone = input<Tone>('neutral');
}

export const PAGE = [PageHeaderComponent, StatusComponent, KpiComponent] as const;
