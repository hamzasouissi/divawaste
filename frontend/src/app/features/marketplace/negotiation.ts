import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';
import { firstValueFrom } from 'rxjs';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, MoneyPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { Dialogs } from '../../ui/dialog';
import { IconComponent } from '../../ui/icon';
import { StatusComponent } from '../../ui/page';
import { ProposalData, ProposalDialog } from './listing-dialogs';
import { Negotiation, Proposal, Terms } from './marketplace-models';

/**
 * A price as both sides read it: "110,000 TND / t · the buyer pays". The sign of the money is said in words, never left
 * to a minus: who pays whom is the first thing to understand in a deal on waste.
 */
@Component({
  selector: 'dw-price',
  imports: [TranslocoDirective, MoneyPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <strong>{{ terms().unit_price | dwMoney: currency() }}</strong>{{ t('marketplace.per.' + terms().pricing_mode) }}
      <span class="who" [class.cost]="terms().direction === 'cost'">· {{ t('marketplace.directions.' + terms().direction) }}</span>
    </ng-container>
  `,
  styles: '.who { color: var(--dw-success); font-size: 12.5px; } .who.cost { color: var(--dw-warning); }',
})
export class PriceComponent {
  readonly terms = input.required<Terms>();
  readonly currency = input.required<string>();
}

/**
 * The private exchange between the seller and one buyer, proposal after proposal, and what the reader may do now:
 * accept the other side's last proposal as it is, answer it with another one, refuse — or, for the buyer, withdraw.
 * Accepting commits the company: it is confirmed, and says what follows (a pickup at the agreed price).
 */
@Component({
  selector: 'dw-negotiation',
  imports: [TranslocoDirective, RouterLink, ButtonDirective, StatusComponent, IconComponent, PriceComponent, DatePipe, MoneyPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @let n = negotiation();
      <div class="head">
        <dw-status kind="negotiation" [value]="n.status" />
        @if (n.your_turn) { <span class="turn">{{ t('marketplace.yourTurn') }}</span> }
        @else if (open()) { <span class="dw-faint">{{ t(n.party === 'seller' ? 'marketplace.waitingBuyer' : 'marketplace.waitingSeller') }}</span> }
      </div>

      @if (n.status === 'accepted') {
        <div class="deal" role="status">
          <dw-icon>handshake</dw-icon>
          <div>
            <strong>{{ t('marketplace.dealDone') }}</strong>
            @if (accepted(); as p) { <div><dw-price [terms]="p" [currency]="p.currency_code" /></div> }
            @if (n.pickup; as pickup) {
              <div>{{ t('marketplace.dealPickup') }} <a [routerLink]="[n.party === 'seller' ? '/pickups' : '/provider/pickups', pickup.ulid]" class="dw-mono">{{ pickup.pickup_number }}</a></div>
            }
            <div class="dw-faint">{{ t('marketplace.commission', { pct: +(n.commission_pct ?? 0) }) }}</div>
          </div>
        </div>
      } @else if (n.status === 'closed') {
        <p class="ended">{{ t('marketplace.closedReasons.' + (n.closed_reason ?? 'listing_cancelled')) }}</p>
      } @else if (n.status === 'refused' || n.status === 'withdrawn') {
        <p class="ended">{{ t(n.status === 'withdrawn' ? 'marketplace.withdrawnBy' : (n.refused_by === n.party ? 'marketplace.refusedByYou' : 'marketplace.refusedByOther')) }}@if (n.end_message) { — « {{ n.end_message }} » }</p>
      }

      <ol class="thread">
        @for (p of n.proposals; track p.ulid) {
          <li [class.mine]="p.party === n.party">
            <div class="who">{{ p.party === n.party ? t('marketplace.you') : t(p.party === 'seller' ? 'marketplace.seller' : 'marketplace.buyer', { name: n.buyer?.legal_name ?? '' }) }}
              <span class="dw-faint"> · {{ p.created_at | dwDate: 'datetime' }}</span></div>
            <div class="price"><dw-price [terms]="p" [currency]="p.currency_code" /></div>
            @if (p.amount !== null) {
              <div class="dw-muted">{{ t(+p.amount >= 0 ? 'marketplace.amountRevenue' : 'marketplace.amountCost', { amount: (abs(+p.amount) | dwMoney: p.currency_code) }) }}@if (p.amount_partial) { <span class="dw-faint"> · {{ t('marketplace.amountPartial') }}</span> }</div>
            }
            @if (p.pickup_date) { <div class="dw-muted">{{ t('marketplace.pickupOn', { date: (p.pickup_date | dwDate) }) }}</div> }
            @if (p.message) { <blockquote>{{ p.message }}</blockquote> }
            <div class="tags">
              @if (p.ulid === n.accepted_proposal) { <dw-status kind="negotiation" value="accepted" /> }
              @else if (open() && $last) {
                @if (p.expired) { <dw-status kind="alert" value="warning" [label]="t('marketplace.expired')" /> }
                @else if (p.expires_at) { <span class="dw-faint">{{ t('marketplace.validUntilDate', { date: (p.expires_at | dwDate) }) }}</span> }
                @if (p.outdated) { <dw-status kind="alert" value="warning" [label]="t('marketplace.outdated')" /> }
              }
            </div>
          </li>
        }
      </ol>

      @if (open()) {
        <div class="actions">
          @if (n.your_turn && last(); as p) {
            @if (canDecide()) {
              <button pButton type="button" [disabled]="busy() || p.expired || p.outdated" (click)="accept(p)"><dw-icon>check</dw-icon>{{ t('marketplace.accept') }}</button>
            }
            @if (canCounter()) {
              <button pButton type="button" severity="secondary" outlined [disabled]="busy()" (click)="counter(p)"><dw-icon>swap_horiz</dw-icon>{{ t('marketplace.counter') }}</button>
            }
          }
          @if (canDecide() && (n.party === 'seller' || n.your_turn)) {
            <button pButton type="button" severity="danger" text [disabled]="busy()" (click)="refuse()">{{ t('marketplace.refuse') }}</button>
          }
          @if (n.party === 'buyer' && canCounter() && !n.your_turn) {
            <button pButton type="button" severity="secondary" text [disabled]="busy()" (click)="withdraw()">{{ t('marketplace.withdraw') }}</button>
          }
        </div>
        @if (n.your_turn && last()?.outdated) { <p class="dw-faint hint">{{ t('marketplace.outdatedHint') }}</p> }
        @if (n.your_turn && last()?.expired) { <p class="dw-faint hint">{{ t('marketplace.expiredHint') }}</p> }
      }
    </ng-container>
  `,
  styles: `
    :host { display: grid; gap: var(--dw-3); }
    .head { display: flex; align-items: center; gap: var(--dw-2); flex-wrap: wrap; }
    .turn { font-weight: 600; color: var(--dw-primary); font-size: 13px; }
    .thread { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--dw-2); }
    .thread li { padding: 10px 12px; border: 1px solid var(--dw-line-soft); border-radius: var(--dw-radius); background: var(--dw-surface); max-inline-size: 92%; }
    .thread li.mine { justify-self: end; background: var(--dw-primary-soft, var(--dw-surface-2)); }
    .who { font-size: 12.5px; font-weight: 600; } .price { font-size: 15px; margin-block: 2px; }
    blockquote { margin: 6px 0 0; padding-inline-start: 8px; border-inline-start: 2px solid var(--dw-line); font-size: 13px; white-space: pre-line; }
    .tags { display: flex; gap: 6px; flex-wrap: wrap; margin-block-start: 4px; font-size: 12px; }
    .actions { display: flex; gap: var(--dw-2); flex-wrap: wrap; }
    .deal { display: flex; gap: var(--dw-3); padding: 10px 12px; border-radius: var(--dw-radius); background: var(--dw-success-soft, var(--dw-surface-2)); }
    .deal dw-icon { color: var(--dw-success); }
    .ended, .hint { margin: 0; font-size: 13px; }
  `,
})
export class NegotiationComponent {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly confirm = inject(Confirm);
  private readonly toast = inject(Toast);
  private readonly transloco = inject(TranslocoService);
  private readonly session = inject(Session);

  readonly negotiation = input.required<Negotiation>();
  /** The site of the listing, for the seller's permissions. */
  readonly site = input<string | null>(null);
  readonly changed = output<Negotiation>();

  protected readonly busy = signal(false);
  protected readonly abs = Math.abs;
  protected readonly open = computed(() => ['awaiting_seller', 'awaiting_buyer'].includes(this.negotiation().status));
  protected readonly last = computed<Proposal | null>(() => this.negotiation().proposals.at(-1) ?? null);
  protected readonly accepted = computed(() => this.negotiation().proposals.find((p) => p.ulid === this.negotiation().accepted_proposal) ?? null);
  protected readonly canDecide = computed(() => (this.negotiation().party === 'seller' ? this.session.can('marketplace.accept', this.site() ?? undefined) : this.session.can('provider.marketplace.offer')));
  protected readonly canCounter = computed(() => (this.negotiation().party === 'seller' ? this.session.can('marketplace.manage', this.site() ?? undefined) : this.session.can('provider.marketplace.offer')));

  protected async accept(proposal: Proposal): Promise<void> {
    const t = (key: string, params?: Record<string, unknown>): string => this.transloco.translate(key, params);
    const ok = await this.confirm.ask({
      title: t('marketplace.acceptTitle'),
      message: t(this.negotiation().party === 'seller' ? 'marketplace.acceptSellerMessage' : 'marketplace.acceptBuyerMessage'),
      confirmLabel: t('marketplace.accept'),
    });
    if (ok) {
      await this.run(`marketplace/negotiations/${this.negotiation().ulid}/accept`, { proposal: proposal.ulid }, 'marketplace.accepted');
    }
  }

  protected counter(proposal: Proposal): void {
    const listing = this.negotiation().listing;
    const data: ProposalData = {
      path: `marketplace/negotiations/${this.negotiation().ulid}/counter`, title: this.transloco.translate('marketplace.counterTitle', { number: listing.listing_number }),
      currency: listing.currency_code, weightKg: listing.totals.weight_kg, unweighed: listing.totals.unweighed_lots, from: proposal, availableFrom: listing.available_from,
    };
    this.dialog.open<ProposalDialog, ProposalData, Negotiation>(ProposalDialog, { data }).afterClosed().subscribe((result) => result && this.changed.emit(result));
  }

  protected async refuse(): Promise<void> {
    const reason = await this.confirm.ask({ title: this.transloco.translate('marketplace.refuseTitle'), reasonLabel: this.transloco.translate('marketplace.refuseReason'), danger: true,
      confirmLabel: this.transloco.translate('marketplace.refuse') });
    if (reason !== null) {
      await this.run(`marketplace/negotiations/${this.negotiation().ulid}/refuse`, { message: reason === true ? null : reason }, 'marketplace.refused');
    }
  }

  protected async withdraw(): Promise<void> {
    const ok = await this.confirm.ask({ title: this.transloco.translate('marketplace.withdrawTitle'), message: this.transloco.translate('marketplace.withdrawMessage'), danger: true,
      confirmLabel: this.transloco.translate('marketplace.withdraw') });
    if (ok) {
      await this.run(`marketplace/negotiations/${this.negotiation().ulid}/withdraw`, {}, 'marketplace.withdrawn');
    }
  }

  private async run(path: string, body: unknown, success: string): Promise<void> {
    this.busy.set(true);
    try {
      this.changed.emit(await firstValueFrom(this.api.post<Negotiation>(path, body)));
      this.toast.success(success);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
