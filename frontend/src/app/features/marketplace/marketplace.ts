import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';
import { firstValueFrom } from 'rxjs';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { CodePipe, DatePipe, KgPipe, MoneyPipe, NumberPipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { Confirm } from '../../ui/confirm';
import { Column, TABLE } from '../../ui/data-table';
import { Dialogs } from '../../ui/dialog';
import { FilterBarComponent } from '../../ui/filter-bar';
import { HelpComponent } from '../../ui/help';
import { IconComponent } from '../../ui/icon';
import { StepsComponent } from '../../ui/steps';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { SELECT } from '../../ui/select';
import { StateComponent } from '../../ui/state';
import { COLOR } from '../../ui/swatch';
import { CreateListingData, CreateListingDialog, ProposalData, ProposalDialog } from './listing-dialogs';
import { BuyerListing, ListingStatus, Negotiation, SellerListing } from './marketplace-models';
import { NegotiationComponent, PriceComponent } from './negotiation';

/**
 * What a listing offers, the same for both sides: the waste, how much of it (known weight, lots nobody weighed counted
 * apart), what it is made of, and its lots one by one. Where the lots are in the seller's workshop is never shown.
 */
@Component({
  selector: 'dw-listing-facts',
  imports: [TranslocoDirective, ...COLOR, CodePipe, DatePipe, KgPipe, NumberPipe, MoneyPipe, StatusComponent, PriceComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @let l = listing();
      <section class="dw-panel">
        <div class="dw-panel-head"><h2 class="dw-h2">{{ t('marketplace.offer') }}</h2></div>
        <div class="dw-panel-body dw-stack-sm">
          @if (l.description) { <p class="description">{{ l.description }}</p> }
          <dl class="dw-facts">
            <div><dt>{{ t('fields.wasteType') }}</dt><dd>{{ l.waste_type.name }}@if (l.waste_type.family) { <span class="dw-faint"> · {{ l.waste_type.family | dwCode: 'family' }}</span> }
              @if (l.is_hazardous) { <dw-status kind="alert" value="warning" [label]="t('common.hazardous')" /> }</dd></div>
            <div><dt>{{ t('fields.weight') }}</dt>
              <dd class="weight">@if (l.totals.weight_kg !== null) { {{ l.totals.weight_kg | dwKg }} } @else { {{ t('marketplace.notWeighed') }} }
                @for (q of quantities(); track q.unit) { <div>{{ q.value | dwNumber: 3 }} {{ q.unit | dwCode: 'unit' }}</div> }
                @if (l.totals.unweighed_lots > 0) { <div class="dw-faint basis">{{ t('marketplace.plusUnweighed', { count: l.totals.unweighed_lots }) }}</div> }</dd></div>
            <div><dt>{{ t('marketplace.lots') }}</dt><dd>{{ l.totals.lots_count }}</dd></div>
            <div><dt>{{ t('lots.composition') }}</dt>
              <dd>@for (line of l.composition ?? []; track line.material) { <span class="chip">{{ line.material | dwCode: 'material' }} {{ +line.pct }} %</span> }
                @empty { <span class="dw-faint">{{ t('marketplace.compositionPerLot') }}</span> }</dd></div>
            <div><dt>{{ t('marketplace.askingPrice') }}</dt>
              <dd>@if (l.asking_price; as price) { <dw-price [terms]="price" [currency]="l.currency_code" />
                @if (price.amount !== null) { <div class="dw-faint basis">{{ t('marketplace.forTheWhole', { amount: (abs(+price.amount) | dwMoney: l.currency_code) }) }}</div> } }
                @else { <span class="dw-faint">{{ t('marketplace.noAskingPrice') }}</span> }</dd></div>
            <div><dt>{{ t('marketplace.availableFrom') }}</dt><dd>{{ l.available_from ? (l.available_from | dwDate) : t('marketplace.now') }}</dd></div>
            <div><dt>{{ t('marketplace.closesAt') }}</dt><dd>{{ l.closes_at ? (l.closes_at | dwDate) : t('marketplace.noClosing') }}</dd></div>
          </dl>
        </div>
      </section>

      <section class="dw-panel">
        <div class="dw-panel-head"><h2 class="dw-h2">{{ t('marketplace.lotsOfListing') }}</h2></div>
        <div class="dw-panel-body table">
          <table class="dw-table-plain">
            <thead><tr><th>{{ t('lots.number') }}</th><th class="num">{{ t('fields.weight') }}</th><th>{{ t('lots.composition') }}</th><th>{{ t('lots.color') }}</th><th>{{ t('lots.packaging') }}</th></tr></thead>
            <tbody>
              @for (lot of l.lots ?? []; track lot.lot_number) {
                <tr>
                  <td class="dw-mono">{{ lot.lot_number }}</td>
                  <td class="num">@if (lot.net_weight_kg !== null) { {{ lot.net_weight_kg | dwKg }}@if (lot.weight_basis === 'estimated') { <span class="dw-faint"> ({{ t('lots.weightBasis.estimated') }})</span> } }
                    @else if (lot.quantity !== null && lot.unit) { {{ lot.quantity | dwNumber: 3 }} {{ lot.unit | dwCode: 'unit' }} } @else { <span class="dw-faint">{{ t('marketplace.notWeighed') }}</span> }</td>
                  <td>@for (line of lot.composition; track line.material) { <span class="chip">{{ line.material | dwCode: 'material' }} {{ +line.pct }} %</span> } @empty { — }
                    @if (lot.composition_source === 'waste_type') { <div class="dw-faint basis">{{ t('lots.compositionSource.waste_type') }}</div> }</td>
                  <td>@if (lot.color_family) { <dw-color [code]="lot.color_family" /> } {{ lot.color_label ?? '' }}@if (!lot.color_family && !lot.color_label) { — }</td>
                  <td>@if (lot.packaging_type) { {{ lot.package_count ?? 1 }} × {{ lot.packaging_type | dwCode: 'packaging' }} } @else { {{ t('lots.loose') }} }</td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      </section>
    </ng-container>
  `,
  styles: `
    :host { display: grid; gap: var(--dw-4); }
    .description { margin: 0; white-space: pre-line; }
    .weight { font-weight: 600; } .basis { font-weight: 400; font-size: 12.5px; }
    .chip { display: inline-block; padding: 1px 8px; margin-inline-end: 4px; border-radius: 999px; background: var(--dw-neutral-soft); font-size: 12.5px; }
    .table { overflow-x: auto; } table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { text-align: start; padding: 6px 8px; border-block-end: 1px solid var(--dw-line-soft); vertical-align: top; } .num { text-align: end; white-space: nowrap; }
  `,
})
export class ListingFactsComponent {
  readonly listing = input.required<SellerListing | BuyerListing>();
  protected readonly abs = Math.abs;
  protected readonly quantities = computed(() => Object.entries(this.listing().totals.quantities).map(([unit, value]) => ({ unit, value })));
}

/**
 * Seller side: the listings of the company. A listing gathers lots of one site and one waste type; it is prepared as
 * a draft, published to the eligible providers, then sold, cancelled or expired.
 */
@Component({
  imports: [TranslocoDirective, ButtonDirective, ...TABLE, ...SELECT, PageHeaderComponent, StatusComponent, IconComponent, HelpComponent, FilterBarComponent, KgPipe, DatePipe, PriceComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('marketplace.title')" [subtitle]="t('marketplace.subtitle')">
        @if (canManage) { <button pButton type="button" (click)="create()"><dw-icon>storefront</dw-icon>{{ t('marketplace.newListing') }}</button> }
      </dw-page-header>
      <dw-help topic="marketplace" />

      <div class="dw-panel">
        <dw-filter-bar [active]="status().length > 0 ? 1 : 0" (cleared)="status.set([]); load()">
          <dw-select [placeholder]="t('fields.status')" multiple [value]="status()" (valueChange)="status.set($any($event)); load()">
            @for (value of statuses; track value) { <dw-option [value]="value" [label]="t('status.listing.' + value)" /> }
          </dw-select>
        </dw-filter-bar>
        <dw-data-table [columns]="columns" [rows]="rows()" [loading]="loading()" [error]="error()" [clickable]="true" emptyIcon="storefront"
          [emptyTitle]="t('marketplace.emptyTitle')" [emptyHint]="t('marketplace.emptyHint')" (rowClick)="open($event)" (retry)="load()">
          <ng-template dwCell="number" let-l><span class="dw-mono">{{ l.listing_number }}</span></ng-template>
          <ng-template dwCell="title" let-l>{{ l.title }}<div class="dw-faint">{{ l.site?.name }}</div></ng-template>
          <ng-template dwCell="weight" let-l>@if (l.totals.weight_kg !== null) { {{ l.totals.weight_kg | dwKg }} } @else { — }@if (l.totals.unweighed_lots > 0) { <div class="dw-faint">+ {{ l.totals.unweighed_lots }} {{ t('marketplace.unweighedShort') }}</div> }</ng-template>
          <ng-template dwCell="asking" let-l>@if (l.asking_price) { <dw-price [terms]="l.asking_price" [currency]="l.currency_code" /> } @else { <span class="dw-faint">{{ t('marketplace.openPrice') }}</span> }</ng-template>
          <ng-template dwCell="offers" let-l>{{ l.negotiations_count }}@if (l.awaiting_seller > 0) { <span class="todo">{{ t('marketplace.toAnswer', { count: l.awaiting_seller }) }}</span> }</ng-template>
          <ng-template dwCell="closes" let-l>{{ l.closes_at ? (l.closes_at | dwDate) : '—' }}</ng-template>
          <ng-template dwCell="status" let-l><dw-status kind="listing" [value]="l.status" /></ng-template>
          @if (canManage) { <button dwEmptyAction pButton type="button" (click)="create()"><dw-icon>storefront</dw-icon>{{ t('marketplace.newListing') }}</button> }
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: '.todo { margin-inline-start: 8px; padding: 1px 8px; border-radius: 999px; background: var(--dw-warning-soft, var(--dw-surface-2)); color: var(--dw-warning); font-size: 12px; font-weight: 600; }',
})
export class MarketplaceListingsPage implements OnInit {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly dialog = inject(Dialogs);

  protected readonly canManage = inject(Session).sitesFor('marketplace.manage').length > 0;
  protected readonly statuses: ListingStatus[] = ['draft', 'published', 'sold', 'expired', 'cancelled'];
  protected readonly status = signal<ListingStatus[]>([]);
  protected readonly rows = signal<SellerListing[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly columns: Column<SellerListing>[] = [
    { key: 'number', header: 'marketplace.number' },
    { key: 'title', header: 'marketplace.listingTitle' },
    { key: 'weight', header: 'fields.weight', align: 'end' },
    { key: 'asking', header: 'marketplace.askingPrice', hideBelow: 900 },
    { key: 'offers', header: 'marketplace.offers' },
    { key: 'closes', header: 'marketplace.closesAt', hideBelow: 1100 },
    { key: 'status', header: 'fields.status' },
  ];

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get<SellerListing[]>('marketplace/listings', { filter: { status: this.status() } }).subscribe({
      next: (rows) => { this.rows.set(rows); this.loading.set(false); },
      error: (error: unknown) => { this.error.set(error); this.loading.set(false); },
    });
  }

  protected open(listing: SellerListing): void {
    void this.router.navigate(['/marketplace', listing.ulid]);
  }

  protected create(): void {
    this.dialog.open<CreateListingDialog, CreateListingData, SellerListing>(CreateListingDialog, { data: {} }).afterClosed()
      .subscribe((listing) => listing && void this.router.navigate(['/marketplace', listing.ulid]));
  }
}

/**
 * Seller side: one listing, its lots, and every negotiation side by side — each buyer in its own thread, none of them
 * seeing the others. The seller answers each one: accept, counter-offer, refuse.
 */
@Component({
  imports: [TranslocoDirective, RouterLink, ButtonDirective, PageHeaderComponent, StatusComponent, StateComponent, IconComponent, ListingFactsComponent, NegotiationComponent, PriceComponent, DatePipe, StepsComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (listing(); as l) {
          <dw-page-header [title]="l.title" [subtitle]="l.listing_number + ' · ' + l.site.name" back="/marketplace" [backLabel]="t('marketplace.title')">
            <dw-status dwTitleBadge kind="listing" [value]="l.status" />
            @if (canManage()) {
              @if (l.status === 'draft' || l.status === 'published') {
                <button pButton severity="secondary" outlined type="button" (click)="edit()"><dw-icon>edit</dw-icon>{{ t('common.edit') }}</button>
                <button pButton severity="danger" text type="button" (click)="cancel()">{{ t('marketplace.cancelListing') }}</button>
              }
              @if (l.status === 'draft') { <button pButton type="button" [disabled]="busy()" (click)="publish()"><dw-icon>campaign</dw-icon>{{ t('marketplace.publish') }}</button> }
            }
          </dw-page-header>
          <!-- G-07: a listing goes from draft to published to sold. -->
          <dw-steps class="lifecycle" [steps]="listingSteps(t)" [current]="l.status === 'expired' || l.status === 'cancelled' ? 'published' : l.status"
            [stopped]="l.status === 'expired' || l.status === 'cancelled' ? t('status.listing.' + l.status) : null" [finished]="l.status === 'sold'" [next]="t('marketplace.next.' + l.status)"
            [tone]="l.status === 'cancelled' ? 'danger' : 'normal'" />
          @if (l.status === 'draft') { <p class="banner">{{ t('marketplace.draftHint') }}</p> }
          @if (l.status === 'sold' && l.pickup; as pickup) {
            <p class="banner ok">{{ t('marketplace.soldHint') }} <a [routerLink]="['/pickups', pickup.ulid]" class="dw-mono">{{ pickup.pickup_number }}</a></p>
          }
          @if (l.status === 'cancelled' && l.cancellation_reason) { <p class="banner">{{ t('marketplace.cancelledBecause', { reason: l.cancellation_reason }) }}</p> }

          <div class="dw-split">
            <dw-listing-facts [listing]="l" />
            <div class="dw-stack">
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('marketplace.offersCount', { count: l.negotiations_count }) }}</h2></div>
                <div class="dw-panel-body">
                  <p class="dw-faint anonymous">{{ t(l.show_seller ? 'marketplace.shownHint' : 'marketplace.anonymousHint') }}</p>
                  <ul class="offers">
                    @for (n of l.negotiations; track n.ulid) {
                      <li>
                        <button type="button" [class.active]="selected() === n.ulid" (click)="select(n.ulid)">
                          <span class="buyer">{{ n.buyer?.legal_name }}@if (n.buyer?.city) { <span class="dw-faint"> · {{ n.buyer?.city }}</span> }</span>
                          @if (n.last_proposal; as p) { <span><dw-price [terms]="p" [currency]="p.currency_code" /></span> }
                          <span class="meta"><dw-status kind="negotiation" [value]="n.status" /> <span class="dw-faint">{{ n.updated_at | dwDate: 'datetime' }}</span></span>
                        </button>
                      </li>
                    } @empty { <li class="dw-faint">{{ t(l.status === 'published' ? 'marketplace.noOfferYet' : 'marketplace.noOffer') }}</li> }
                  </ul>
                </div>
              </section>
              @if (negotiation(); as n) {
                <section class="dw-panel">
                  <div class="dw-panel-head"><h2 class="dw-h2">{{ t('marketplace.negotiationWith', { name: n.buyer?.legal_name ?? '' }) }}</h2></div>
                  <div class="dw-panel-body"><dw-negotiation [negotiation]="n" [site]="l.site.ulid" (changed)="changed($event)" /></div>
                </section>
              }
            </div>
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .lifecycle { display: block; margin-block-end: 18px; }
    .banner { margin: 0 0 var(--dw-4); padding: 10px 12px; border-radius: var(--dw-radius); background: var(--dw-surface-2); font-size: 13.5px; } .banner.ok { background: var(--dw-success-soft, var(--dw-surface-2)); }
    .anonymous { margin: 0 0 var(--dw-2); font-size: 12.5px; }
    .offers { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
    .offers button { all: unset; box-sizing: border-box; cursor: pointer; display: grid; gap: 2px; width: 100%; padding: 8px 10px; border: 1px solid var(--dw-line-soft); border-radius: var(--dw-radius); }
    .offers button:hover, .offers button.active { border-color: var(--dw-primary); } .offers button:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: 2px; }
    .buyer { font-weight: 600; } .meta { display: flex; align-items: center; gap: 8px; font-size: 12px; }
  `,
})
export class MarketplaceListingPage implements OnInit {
  protected listingSteps(t: (key: string) => string): { key: string; label: string }[] {
    return ['draft', 'published', 'sold'].map((key) => ({ key, label: t('status.listing.' + key) }));
  }

  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly dialog = inject(Dialogs);
  private readonly confirm = inject(Confirm);
  private readonly toast = inject(Toast);
  private readonly transloco = inject(TranslocoService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  readonly ulid = input.required<string>();

  protected readonly listing = signal<SellerListing | null>(null);
  protected readonly negotiation = signal<Negotiation | null>(null);
  protected readonly selected = signal<string | null>(this.route.snapshot.queryParamMap.get('negotiation'));
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly busy = signal(false);
  protected readonly canManage = computed(() => this.session.can('marketplace.manage', this.listing()?.site.ulid));

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get<SellerListing>(`marketplace/listings/${this.ulid()}`).subscribe({
      next: (listing) => {
        this.listing.set(listing);
        this.loading.set(false);
        // The negotiation named by the link, else the first one that waits for the seller.
        const pick = this.selected() ?? listing.negotiations.find((n) => n.status === 'awaiting_seller')?.ulid ?? listing.negotiations[0]?.ulid ?? null;
        if (pick) {
          this.select(pick);
        }
      },
      error: (error: unknown) => { this.error.set(error); this.loading.set(false); },
    });
  }

  protected select(ulid: string): void {
    this.selected.set(ulid);
    void this.router.navigate([], { queryParams: { negotiation: ulid }, replaceUrl: true });
    this.api.get<Negotiation>(`marketplace/negotiations/${ulid}`).subscribe({ next: (n) => this.negotiation.set(n), error: (error: unknown) => this.toast.error(error) });
  }

  protected changed(negotiation: Negotiation): void {
    this.negotiation.set(negotiation);
    this.load();
  }

  protected edit(): void {
    const listing = this.listing();
    if (listing) {
      this.dialog.open<CreateListingDialog, CreateListingData, SellerListing>(CreateListingDialog, { data: { listing } }).afterClosed().subscribe((saved) => saved && this.load());
    }
  }

  protected async publish(): Promise<void> {
    const ok = await this.confirm.ask({ title: this.transloco.translate('marketplace.publishTitle'), message: this.transloco.translate('marketplace.publishMessage'), confirmLabel: this.transloco.translate('marketplace.publish') });
    if (ok) {
      await this.post(`marketplace/listings/${this.ulid()}/publish`, {}, 'marketplace.published');
    }
  }

  protected async cancel(): Promise<void> {
    const reason = await this.confirm.ask({ title: this.transloco.translate('marketplace.cancelTitle'), reasonLabel: this.transloco.translate('marketplace.cancelReason'), danger: true,
      confirmLabel: this.transloco.translate('marketplace.cancelListing') });
    if (reason !== null) {
      await this.post(`marketplace/listings/${this.ulid()}/cancel`, { reason: reason === true ? null : reason }, 'marketplace.cancelled');
    }
  }

  private async post(path: string, body: unknown, success: string): Promise<void> {
    this.busy.set(true);
    try {
      this.listing.set(await firstValueFrom(this.api.post<SellerListing>(path, body)));
      this.toast.success(success);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

/**
 * Buyer side: the published listings of the waste the provider accepts, without the name of the seller (unless it
 * chose to show it), and the listings it negotiated.
 */
@Component({
  imports: [TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, HelpComponent, FilterBarComponent, SearchComponent, CheckboxComponent, KgPipe, DatePipe, CodePipe, PriceComponent, StateComponent, ButtonDirective, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('marketplace.catalogTitle')" [subtitle]="t('marketplace.catalogSubtitle')" />
      <dw-help topic="marketplaceBuyer" />
      <div class="dw-panel">
        <dw-filter-bar [active]="(search() ? 1 : 0) + (mine() ? 1 : 0)" (cleared)="search.set(''); mine.set(false); load()">
          <dw-search [placeholder]="t('marketplace.searchPlaceholder')" [query]="search()" (queryChange)="search.set($event); load()" />
          <dw-checkbox [checked]="mine()" (checkedChange)="mine.set($event); load()">{{ t('marketplace.mineOnly') }}</dw-checkbox>
        </dw-filter-bar>
      </div>
      <!-- B-04: each lot on offer as a card with what a buyer looks at first, and the offer one click away. -->
      <dw-state [loading]="loading()" [error]="error()" [empty]="rows().length === 0" icon="storefront" [emptyTitle]="t('marketplace.catalogEmptyTitle')" [emptyHint]="t('marketplace.catalogEmptyHint')" (retry)="load()">
        <div class="cards">
          @for (l of rows(); track l.ulid) {
            <article class="card" (click)="open(l)" (keydown.enter)="open(l)" tabindex="0" role="link" [attr.aria-label]="l.title">
              <header>
                <span class="dw-mono dw-faint">{{ l.listing_number }}</span>
                @if (l.my_negotiation; as n) { <dw-status kind="negotiation" [value]="n.status" /> } @else if (l.status !== 'published') { <dw-status kind="listing" [value]="l.status" /> }
              </header>
              <h2 class="dw-h2">{{ l.title }}</h2>
              <p class="dw-faint who">{{ l.seller?.legal_name ?? t('marketplace.anonymousSeller') }}@if (l.location.region) { · {{ l.location.region }} }</p>
              <p class="waste">{{ l.waste_type.name }}</p>
              @if (l.composition?.length) { <p class="chips">@for (line of l.composition; track line.material) { <span class="chip">{{ line.material | dwCode: 'material' }} {{ +line.pct }} %</span> }</p> }
              <div class="facts">
                <div><span class="dw-faint">{{ t('fields.weight') }}</span><strong>@if (l.totals.weight_kg !== null) { {{ l.totals.weight_kg | dwKg }} } @else { — }</strong></div>
                <div><span class="dw-faint">{{ t('marketplace.askingPrice') }}</span><strong>@if (l.asking_price) { <dw-price [terms]="l.asking_price" [currency]="l.currency_code" /> } @else { {{ t('marketplace.openPrice') }} }</strong></div>
                <div><span class="dw-faint">{{ t('marketplace.closesAt') }}</span><strong>{{ l.closes_at ? (l.closes_at | dwDate) : '—' }}</strong></div>
              </div>
              <footer>
                @if (l.my_negotiation?.your_turn) { <span class="turn">{{ t('marketplace.yourTurn') }}</span> }
                <span class="dw-spacer"></span>
                @if (!l.my_negotiation && l.status === 'published') {
                  <button pButton type="button" size="small" (click)="offer(l); $event.stopPropagation()"><dw-icon>gavel</dw-icon>{{ t('marketplace.makeOffer') }}</button>
                } @else {
                  <button pButton severity="secondary" outlined type="button" size="small" (click)="open(l); $event.stopPropagation()">{{ t('common.open') }}</button>
                }
              </footer>
            </article>
          }
        </div>
      </dw-state>
    </ng-container>
  `,
  styles: `
    .turn { color: var(--dw-primary); font-weight: 650; font-size: 12.5px; }
    .cards { display: grid; gap: 16px; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); margin-block-start: 16px; }
    .card { display: grid; gap: 8px; align-content: start; padding: 18px; border-radius: var(--dw-radius-lg); background: var(--dw-surface); box-shadow: var(--dw-shadow); cursor: pointer;
      transition: box-shadow 180ms var(--dw-ease), transform 180ms var(--dw-ease); }
    .card:hover { box-shadow: var(--dw-shadow-hover); transform: translateY(-2px); } .card:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .card header, .card footer { display: flex; align-items: center; gap: 8px; } .card footer { margin-block-start: 6px; padding-block-start: 12px; border-block-start: 1px solid var(--dw-line-soft); }
    .card h2 { margin: 0; } .who { margin: -4px 0 0; font-size: 12.5px; } .waste { margin: 0; font-weight: 600; }
    .chips { display: flex; flex-wrap: wrap; gap: 4px; margin: 0; } .chip { padding: 1px 8px; border-radius: 999px; background: var(--dw-neutral-soft); font-size: 12px; }
    .facts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; } .facts div { display: grid; gap: 2px; font-size: 12px; } .facts strong { font-size: 14px; }
  `,
})
export class MarketplaceCatalogPage implements OnInit {
  private readonly api = inject(Api);
  private readonly router = inject(Router);

  protected readonly search = signal('');
  protected readonly mine = signal(false);
  protected readonly rows = signal<BuyerListing[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly columns: Column<BuyerListing>[] = [
    { key: 'title', header: 'marketplace.listingTitle' },
    { key: 'waste', header: 'fields.wasteType', hideBelow: 720 },
    { key: 'weight', header: 'fields.weight', align: 'end' },
    { key: 'asking', header: 'marketplace.askingPrice', hideBelow: 900 },
    { key: 'closes', header: 'marketplace.closesAt', hideBelow: 1100 },
    { key: 'mine', header: 'marketplace.myOffer' },
  ];

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get<BuyerListing[]>('marketplace/catalog', { filter: { search: this.search() || null, mine: this.mine() || null } }).subscribe({
      next: (rows) => { this.rows.set(rows); this.loading.set(false); },
      error: (error: unknown) => { this.error.set(error); this.loading.set(false); },
    });
  }

  protected open(listing: BuyerListing): void {
    void this.router.navigate(['/provider/marketplace', listing.ulid]);
  }

  protected offer(listing: BuyerListing): void {
    void this.router.navigate(['/provider/marketplace', listing.ulid], { queryParams: { offer: listing.asking_price ? 'asking' : 'price' } });
  }
}

/**
 * Buyer side: one listing, and the provider's own negotiation on it — or the offer that opens one: the asking price
 * as it is, or another price.
 */
@Component({
  imports: [TranslocoDirective, RouterLink, ButtonDirective, PageHeaderComponent, StatusComponent, StateComponent, IconComponent, ListingFactsComponent, NegotiationComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (listing(); as l) {
          <dw-page-header [title]="l.title" [subtitle]="l.listing_number + ' · ' + (l.seller?.legal_name ?? t('marketplace.anonymousSeller')) + (l.location.region ? ' · ' + l.location.region : '')"
            back="/provider/marketplace" [backLabel]="t('marketplace.catalogTitle')">
            <dw-status dwTitleBadge kind="listing" [value]="l.status" />
          </dw-page-header>
          <div class="dw-split">
            <dw-listing-facts [listing]="l" />
            <div class="dw-stack">
              @if (negotiation(); as n) {
                <section class="dw-panel">
                  <div class="dw-panel-head"><h2 class="dw-h2">{{ t('marketplace.myNegotiation') }}</h2></div>
                  <div class="dw-panel-body"><dw-negotiation [negotiation]="n" (changed)="changed($event)" /></div>
                </section>
              }
              @if (l.can_offer && canOffer) {
                <section class="dw-panel">
                  <div class="dw-panel-head"><h2 class="dw-h2">{{ t(negotiation() ? 'marketplace.newOffer' : 'marketplace.makeOffer') }}</h2></div>
                  <div class="dw-panel-body dw-stack-sm">
                    <p class="dw-muted">{{ t('marketplace.offerHint') }}</p>
                    <div class="actions">
                      @if (l.asking_price; as price) {
                        <button pButton type="button" (click)="offer(true)"><dw-icon>check</dw-icon>{{ t('marketplace.offerAsking') }}</button>
                      }
                      <button pButton type="button" [severity]="l.asking_price ? 'secondary' : null" [outlined]="!!l.asking_price" (click)="offer(false)"><dw-icon>sell</dw-icon>{{ t('marketplace.offerOther') }}</button>
                    </div>
                  </div>
                </section>
              }
              @if (l.pickup; as pickup) {
                <section class="dw-panel"><div class="dw-panel-body">{{ t('marketplace.dealPickup') }} <a [routerLink]="['/provider/pickups', pickup.ulid]" class="dw-mono">{{ pickup.pickup_number }}</a></div></section>
              }
            </div>
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: '.actions { display: flex; gap: var(--dw-2); flex-wrap: wrap; } p { margin: 0; font-size: 13px; }',
})
export class MarketplaceOfferPage implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private offered = false;
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly transloco = inject(TranslocoService);

  readonly ulid = input.required<string>();

  protected readonly canOffer = inject(Session).can('provider.marketplace.offer');
  protected readonly listing = signal<BuyerListing | null>(null);
  protected readonly negotiation = signal<Negotiation | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get<BuyerListing>(`marketplace/catalog/${this.ulid()}`).subscribe({
      next: (listing) => {
        this.listing.set(listing);
        // Arrived from a card's "Faire une offre": the offer form opens at once, once.
        const asked = this.route.snapshot.queryParamMap.get('offer');
        if (asked && !this.offered && listing.status === 'published' && !listing.my_negotiation) {
          this.offered = true;
          queueMicrotask(() => this.offer(asked === 'asking'));
        }
        this.loading.set(false);
        if (listing.my_negotiation) {
          this.api.get<Negotiation>(`marketplace/negotiations/${listing.my_negotiation.ulid}`).subscribe((n) => this.negotiation.set(n));
        }
      },
      error: (error: unknown) => { this.error.set(error); this.loading.set(false); },
    });
  }

  protected offer(asking: boolean): void {
    const listing = this.listing();
    if (!listing) {
      return;
    }
    const data: ProposalData = {
      path: `marketplace/catalog/${listing.ulid}/offers`, title: this.transloco.translate('marketplace.offerTitle', { number: listing.listing_number }), currency: listing.currency_code,
      weightKg: listing.totals.weight_kg, unweighed: listing.totals.unweighed_lots, from: asking ? listing.asking_price : (listing.asking_price ?? null), availableFrom: listing.available_from, fixed: asking && !!listing.asking_price,
    };
    this.dialog.open<ProposalDialog, ProposalData, Negotiation>(ProposalDialog, { data }).afterClosed().subscribe((result) => result && this.load());
  }

  protected changed(negotiation: Negotiation): void {
    this.negotiation.set(negotiation);
    this.load();
  }
}
