import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';

import { IconComponent } from './icon';

/** One thing that waits for someone, as returned by `GET attention` (or built by a screen from its own data). */
export interface AttentionItem {
  key: string;
  area: string;
  tone: 'danger' | 'warning' | 'info' | 'neutral';
  count: number;
  params: Record<string, unknown>;
  link: string;
  query?: Record<string, string>;
  /** Already-translated text, for items a screen builds itself. */
  text?: string;
  icon?: string;
}

const ICONS: Record<string, string> = {
  slips_to_sign: 'draw', signed_copies: 'upload_file', documents_outstanding: 'assignment_late', missing_codes: 'qr_code_2', declarations_due: 'fact_check',
  permits_expired: 'gavel', permits_expiring: 'gavel', variances: 'scale', awaiting_provider: 'schedule', drafts: 'edit_note',
  accreditation_expired: 'verified', accreditation_expiring: 'verified', contracts_late: 'event_busy', contracts_ending: 'event',
  erp_feeds_failed: 'sync_problem', types_without_permit: 'gavel', pickups_date_passed: 'event_busy',
  lots_unweighed_in_pickup: 'scale', lots_unweighed_long: 'scale', lots_packages_unconfirmed: 'inventory_2', lots_not_stored: 'move_down',
  setup_sites_without_zones: 'factory', setup_no_waste_types: 'category', setup_no_stock_limit: 'speed',
  marketplace_offers: 'storefront', provider_requests: 'inbox', provider_to_receive: 'local_shipping', provider_to_treat: 'recycling', provider_offers: 'storefront',
  own_accreditation_expired: 'verified', own_accreditation_expiring: 'verified', own_accreditation_pending: 'hourglass_top',
};

/**
 * The list of what needs a decision, most urgent first: a tone, a sentence, the page that settles it. Used by the
 * dashboard ("À traiter") and the compliance page ("Ce qui manque"); empty, it says that all is in order.
 */
@Component({
  selector: 'dw-attention',
  imports: [TranslocoDirective, RouterLink, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (items().length > 0) {
        <ul>
          @for (item of items(); track item.key + (item.params['provider'] ?? '')) {
            <li>
              <a [routerLink]="item.link" [queryParams]="item.query ?? null" [class]="item.tone">
                <span class="ico"><dw-icon>{{ item.icon ?? icon(item.key) }}</dw-icon></span>
                <span class="text">{{ item.text ?? t('attention.' + item.key, item.params) }}</span>
                <dw-icon class="go" aria-hidden="true">arrow_forward</dw-icon>
              </a>
            </li>
          }
        </ul>
      } @else {
        <div class="clear"><span class="ok"><dw-icon>check</dw-icon></span><span><strong>{{ t('attention.clearTitle') }}</strong><span>{{ emptyText() || t('attention.clear') }}</span></span></div>
      }
    </ng-container>
  `,
  styles: `
    ul { list-style: none; margin: 0; padding: 6px; }
    a { display: flex; align-items: center; gap: 12px; min-height: 52px; padding: 8px 12px; border-radius: 10px; color: var(--dw-ink); transition: background-color 140ms; }
    a:hover { background: var(--dw-surface-2); text-decoration: none; }
    li + li { margin-block-start: 2px; }
    .ico { display: grid; place-items: center; flex: none; width: 34px; height: 34px; border-radius: 9px; background: var(--dw-neutral-soft); color: var(--dw-ink-2); }
    .ico dw-icon { font-size: 18px; }
    .danger .ico { background: var(--dw-danger-soft); color: var(--dw-danger); }
    .warning .ico { background: var(--dw-warning-soft); color: var(--dw-warning); }
    .info .ico { background: var(--dw-info-soft); color: var(--dw-info); }
    .text { flex: 1; min-width: 0; font-size: 13.5px; line-height: 19px; }
    .go { font-size: 17px; color: var(--dw-ink-3); opacity: 0; transform: translateX(-4px); transition: opacity 140ms, transform 160ms var(--dw-ease); }
    a:hover .go, a:focus-visible .go { opacity: 1; transform: none; color: var(--dw-primary); }
    .clear { display: flex; align-items: center; gap: 14px; padding: 22px 20px; }
    .clear > span:last-child { display: flex; flex-direction: column; gap: 2px; font-size: 13px; color: var(--dw-ink-3); }
    .clear strong { font-size: 14px; color: var(--dw-ink); font-weight: 620; }
    .ok { display: grid; place-items: center; flex: none; width: 38px; height: 38px; border-radius: 50%; background: var(--dw-success-soft); color: var(--dw-success); }
  `,
})
export class AttentionComponent {
  readonly items = input.required<AttentionItem[]>();
  readonly emptyText = input('');

  protected icon(key: string): string {
    return ICONS[key] ?? 'info';
  }
}
