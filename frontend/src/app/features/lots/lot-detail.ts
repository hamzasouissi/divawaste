import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { forkJoin } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { Tooltip } from 'primeng/tooltip';
import { Davi } from '../../core/davi/davi';

import { Api, openBlob } from '../../core/api/api';
import { Lot, LotDetailMeta, LotEvent } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { CodePipe, DatePipe, KgPipe, LocalizedPipe, LotMeasurePipe, TermPipe } from '../../core/format/pipes';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { StateComponent } from '../../ui/state';
import { LotActionData, LotCompositionDialog, LotOriginDialog, LotPackagesDialog, SplitLotDialog, StoreLotDialog, WeighLotDialog } from './lot-dialogs';
import { CreateListingDialog, CreateListingData } from '../marketplace/listing-dialogs';
import { Dialogs } from '../../ui/dialog';
import { IconComponent } from '../../ui/icon';
import { StepsComponent } from '../../ui/steps';
import { COLOR } from '../../ui/swatch';
import { MenuAction, MenuComponent } from '../../ui/menu';

type LotAction = 'weigh' | 'pickup' | 'print' | 'store' | 'split' | 'sell' | 'origin';

/**
 * A lot and its whole story: what it is, where it is, every change with its time and author (M2-05),
 * where it came from and what it became (M2-06), and which pickup took it.
 */
@Component({
  imports: [...COLOR, TermPipe, CodePipe, RouterLink, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, DatePipe, KgPipe, LocalizedPipe, LotMeasurePipe, IconComponent, ButtonDirective, MenuComponent, Tooltip, StepsComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (lot(); as l) {
          <dw-page-header [title]="t('lots.detail', { number: l.lot_number })" back="/lots" [backLabel]="t('lots.title')">
            <dw-status dwTitleBadge kind="lot" [value]="l.status" />
            <!-- LD-01: the one thing to do now, the rest in a menu; LD-05: how often the label was printed. -->
            @if (primary(); as main) {
              <button pButton type="button" (click)="run(main.key)" [disabled]="main.key === 'print' && printing()"><dw-icon>{{ main.icon }}</dw-icon>{{ t(main.label) }}</button>
            }
            @if (more().length > 0) {
              <button pButton severity="secondary" outlined type="button" (click)="menu.open($event, menuActions())" aria-haspopup="menu"><dw-icon>more_horiz</dw-icon>{{ t('common.moreActions') }}</button>
              <dw-menu #menu />
            }
          </dw-page-header>

          <!-- G-07: where the lot is in its life, and what comes next. -->
          <dw-steps class="lifecycle" [steps]="lotSteps(t)" [current]="l.status" [finished]="l.status === 'closed'" [next]="t('lots.next.' + l.status)" [label]="t('lots.timeline')" />
          <div class="dw-split">
            <div class="dw-stack">
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('lots.identity') }}</h2></div>
                <div class="dw-panel-body">
                  <!-- LD-03: the facts in three groups; what can change is changed where it is read. -->
                  <div class="groups">
                    <div class="group">
                      <div class="dw-label">{{ t('lots.factsWhat') }}</div>
                      <dl class="dw-facts">
                        <div><dt>{{ t('fields.wasteType') }}</dt><dd>{{ l.waste_type?.name }} @if (l.is_hazardous) { <dw-status kind="alert" value="warning" [label]="t('common.hazardous')" /> }</dd></div>
                        <div><dt>{{ t(l.quantity !== null && l.unit && l.unit !== 'kg' ? 'fields.quantity' : 'fields.weight') }}</dt>
                          <dd class="weight">{{ l | dwLotMeasure }}
                            @if (transformable() && session.can('lots.weigh', l.site?.ulid)) { <button type="button" class="edit" (click)="act('weigh')" [attr.aria-label]="t('lots.weigh')" [pTooltip]="t('lots.weigh')"><dw-icon>edit</dw-icon></button> }
                            @if (l.weight_basis && l.weight_basis !== 'measured') { <div class="dw-faint basis">{{ t('lots.weightBasis.' + l.weight_basis) }}</div> }
                          </dd></div>
                        <div><dt>{{ t('lots.packaging') }}</dt>
                          <dd>
                            @if (l.packaging_type) {
                              {{ l.package_count ?? 1 }} × {{ l.packaging_type | dwCode: 'packaging' }}
                              @if (!l.package_count_confirmed) { <dw-status kind="member" value="suspended" tone="neutral" [label]="t('lots.packagesDefault')" /> }
                              @if (session.can('lots.update', l.site?.ulid)) { <button type="button" class="edit" (click)="act('packages')" [attr.aria-label]="t(present() ? 'common.edit' : 'lots.packagesCorrect')" [pTooltip]="t(present() ? 'common.edit' : 'lots.packagesCorrect')"><dw-icon>edit</dw-icon></button> }
                            } @else { {{ t('lots.loose') }} }
                          </dd>
                        </div>
                        <div class="wide"><dt>{{ t('lots.composition') }}</dt>
                          <dd>
                            @for (line of meta()?.composition ?? []; track line.material) { <span class="chip">{{ line.name | dwLocalized }} {{ +line.pct }} %</span> } @empty { <span class="dw-faint">{{ t('lots.compositionUnknown') }}</span> }
                            @if (present() && session.can('lots.update', l.site?.ulid)) { <button type="button" class="edit" (click)="act('composition')" [attr.aria-label]="t(meta()?.composition?.length ? 'common.edit' : 'lots.compositionAdd')" [pTooltip]="t(meta()?.composition?.length ? 'common.edit' : 'lots.compositionAdd')"><dw-icon>{{ meta()?.composition?.length ? 'edit' : 'add' }}</dw-icon></button> }
                            <!-- Defaults of the waste type are a guess about this lot: said so, for someone to check. -->
                            @if (l.composition_source && l.composition_source !== 'entered') { <div class="dw-faint basis">{{ t('lots.compositionSource.' + l.composition_source) }}</div> }
                          </dd>
                        </div>
                        @if (l.color_family || l.color_label) { <div><dt>{{ t('lots.color') }}</dt><dd>@if (l.color_family) { <dw-color [code]="l.color_family" /> }@if (l.color_family && l.color_label) { · }{{ l.color_label ?? '' }}</dd></div> }
                        @if (l.grammage_gsm) { <div><dt>{{ t('lots.grammage') }}</dt><dd>{{ l.grammage_gsm }}</dd></div> }
                      </dl>
                    </div>
                    <div class="group">
                      <div class="dw-label">{{ t('lots.factsWhere') }}</div>
                      <dl class="dw-facts">
                        <div><dt>{{ t('fields.site') }}</dt><dd>{{ l.site?.name }}</dd></div>
                        <div><dt>{{ t('fields.zone') }}</dt><dd>{{ l.zone?.name }}
                          @if (onSite() && session.can('lots.move', l.site?.ulid)) { <button type="button" class="edit" (click)="act('store')" [attr.aria-label]="t('lots.move')" [pTooltip]="t('lots.move')"><dw-icon>edit</dw-icon></button> }</dd></div>
                      </dl>
                    </div>
                    <div class="group">
                      <div class="dw-label">{{ t('lots.factsTrace') }}</div>
                      <dl class="dw-facts">
                        <div><dt>{{ t('lots.regulatoryCode') }}</dt><dd class="dw-mono">{{ l.regulatory_code ?? '—' }}</dd></div>
                        <div><dt>{{ t('lots.origin') }}</dt><dd>{{ t('origin.' + l.origin_type) }}</dd></div>
                        <div><dt>{{ t('lots.generatedAt') }}</dt><dd>{{ l.generated_at | dwDate: 'datetime' }}</dd></div>
                        @if (l.notes) { <div class="wide"><dt>{{ t('fields.notes') }}</dt><dd>{{ l.notes }}</dd></div> }
                      </dl>
                    </div>
                  </div>
                </div>
              </section>

              @if (session.uses('production') && l.origin; as o) {
                <section class="dw-panel">
                  <div class="dw-panel-head"><h2 class="dw-h2">{{ t('lots.production') }}</h2></div>
                  <div class="dw-panel-body">
                    <dl class="dw-facts">
                      <div><dt>{{ t('lots.productionOrder') }}</dt><dd class="dw-mono">{{ o.production_order?.order_number ?? '—' }}</dd></div>
                      <div><dt>{{ t('production.of.step') }}</dt><dd>{{ o.step ? (o.step.name | dwLocalized) : '—' }}</dd></div>
                      <div><dt>{{ t('production.articles.one') }}</dt><dd>{{ o.article ? o.article.reference + ' — ' + o.article.name : '—' }}</dd></div>
                      <div><dt>{{ t('production.clients.one') }}</dt><dd>{{ o.client ?? '—' }}</dd></div>
                      <div><dt>{{ t('production.orders.one') }}</dt><dd class="dw-mono">{{ o.customer_order ?? '—' }}</dd></div>
                      <div><dt>{{ t('lots.rawMaterial') }}</dt><dd>{{ o.raw_material ? o.raw_material.reference + ' — ' + o.raw_material.name : '—' }} @if (o.batch) { <span class="dw-faint">· {{ t('production.materials.batchNumber') }} {{ o.batch.batch_number }}</span> }</dd></div>
                      <div><dt>{{ t('production.suppliers.one') }}</dt><dd>{{ o.supplier ?? '—' }}</dd></div>
                    </dl>
                  </div>
                </section>
              }

              @if (meta(); as m) {
                @if (m.parents.length > 0 || m.children.length > 0) {
                  <section class="dw-panel">
                    <div class="dw-panel-head"><h2 class="dw-h2">{{ t('lots.lineage') }}</h2></div>
                    <div class="dw-panel-body lineage">
                      @if (m.parents.length > 0) {
                        <div><div class="dw-label">{{ t('lots.parents') }}</div>
                          @for (parent of m.parents; track parent.ulid) { <a class="link" [routerLink]="['/lots', parent.ulid]"><span class="dw-mono">{{ parent.lot_number }}</span> · {{ parent.quantity_kg | dwKg }}</a> }
                        </div>
                      }
                      @if (m.children.length > 0) {
                        <div><div class="dw-label">{{ t('lots.children') }}</div>
                          @for (child of m.children; track child.ulid) {
                            <a class="link" [routerLink]="['/lots', child.ulid]"><span class="dw-mono">{{ child.lot_number }}</span> · {{ child.quantity_kg | dwKg }} <dw-status kind="lot" [value]="child.status" /></a>
                          }
                        </div>
                      }
                    </div>
                  </section>
                }
              }

              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('lots.timeline') }}</h2></div>
                <ol class="timeline">
                  @for (event of events(); track $index) {
                    <li>
                      <span class="dot" aria-hidden="true"></span>
                      <div>
                        <div class="what">
                          {{ t('lots.events.' + event.event_type) }}
                          @if (event.to_status && event.event_type !== 'created') { <dw-status kind="lot" [value]="event.to_status" /> }
                          @if (event.to_zone && event.from_zone !== event.to_zone) { <span class="dw-muted">→ {{ event.to_zone }}</span> }
                          @if (event.pickup_number) { <span class="dw-mono dw-muted">{{ event.pickup_number }}</span> }
                          @if (event.event_type === 'packages_corrected' && event.payload) {
                            <span class="dw-muted">{{ t('lots.packagesChange', { from: event.payload['previous_package_count'] ?? '—', to: event.payload['package_count'] }) }}@if (event.payload['after_departure']) { · {{ t('lots.afterDeparture') }} }@if (event.payload['reason']) { · {{ event.payload['reason'] }} }</span>
                          }
                          @if (event.weight_kg && (event.event_type === 'weighed' || event.event_type === 'created')) { <span class="dw-muted">{{ event.weight_kg | dwKg }}</span> }
                          @if (event.payload?.['previous_weight_kg']; as previous) { <span class="dw-faint">({{ t('lots.previousWeight', { kg: previous }) }})</span> }
                        </div>
                        <div class="when dw-faint">{{ event.occurred_at | dwDate: 'datetime' }} @if (event.actor) { · {{ t('lots.by', { actor: event.actor }) }} } · {{ event.source | dwTerm: 'terms.sources' }}</div>
                      </div>
                    </li>
                  }
                </ol>
              </section>
            </div>

            <div class="dw-stack">
              <section class="dw-panel dw-panel-body qr">
                <span class="qr-ico"><dw-icon aria-hidden="true">qr_code_2</dw-icon></span>
                <span><strong>{{ t('lots.qr') }}</strong><span class="dw-faint">{{ t('lots.printCount', { count: l.label_print_count }) }} · 100 × 50 mm</span></span>
              </section>
              @if (meta()?.listing; as listing) {
                <section class="dw-panel">
                  <div class="dw-panel-head"><h2 class="dw-h2">{{ t('marketplace.title') }}</h2></div>
                  <div class="dw-panel-body dw-stack-sm">
                    <a [routerLink]="['/marketplace', listing.ulid]" class="dw-mono">{{ listing.listing_number }}</a>
                    <dw-status kind="listing" [value]="listing.status" />
                    <p class="dw-faint">{{ t('marketplace.lotReserved') }}</p>
                  </div>
                </section>
              }
              @if (meta()?.pickup; as pickup) {
                <section class="dw-panel">
                  <div class="dw-panel-head"><h2 class="dw-h2">{{ t('lots.pickup') }}</h2></div>
                  <div class="dw-panel-body dw-stack-sm">
                    <a [routerLink]="['/pickups', pickup.ulid]" class="dw-mono">{{ pickup.pickup_number }}</a>
                    <div>{{ pickup.provider }}</div>
                    <dw-status kind="pickup" [value]="pickup.status" />
                    @if (pickup.received_weight_kg) { <div class="dw-muted">{{ t('pickups.received') }} : {{ pickup.received_weight_kg | dwKg }}</div> }
                  </div>
                </section>
              }
            </div>
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .lifecycle { margin-block-end: 20px; }
    .groups { display: grid; gap: 22px; } .group { display: grid; gap: 10px; } .group + .group { padding-block-start: 18px; border-block-start: 1px solid var(--dw-line-soft); } .wide { grid-column: 1 / -1; }
    .edit { all: unset; display: inline-grid; place-items: center; width: 26px; height: 26px; margin-inline-start: 4px; border-radius: 7px; vertical-align: middle; color: var(--dw-ink-3); cursor: pointer; transition: background-color 140ms, color 140ms; }
    .edit:hover { background: var(--dw-primary-soft); color: var(--dw-primary); } .edit:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 1px; } .edit dw-icon { font-size: 16px; }
    .linkish { all: unset; cursor: pointer; color: var(--dw-primary); margin-inline-start: 8px; font-size: 13px; } .linkish:hover { text-decoration: underline; } .linkish:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: 2px; }
    .weight { font-size: 18px; font-weight: 600; } .basis { font-size: 12.5px; font-weight: 400; }
    .chip { display: inline-block; padding: 1px 8px; margin-inline-end: 4px; border-radius: 999px; background: var(--dw-neutral-soft); font-size: 12.5px; }
    .lineage { display: grid; gap: var(--dw-4); grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
    .link { display: flex; align-items: center; gap: 6px; padding-block: 3px; }
    .timeline { list-style: none; margin: 0; padding: var(--dw-3) var(--dw-4); }
    .timeline li { position: relative; display: flex; gap: var(--dw-3); padding-block-end: var(--dw-3); }
    .timeline li::before { content: ''; position: absolute; inset-inline-start: 4px; inset-block: 12px 0; width: 1px; background: var(--dw-line); }
    .timeline li:last-child::before { display: none; }
    .dot { flex: none; width: 9px; height: 9px; margin-block-start: 5px; border-radius: 50%; background: var(--dw-primary); }
    .what { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-weight: 500; }
    .when { font-size: 12px; }
    .qr { display: flex; align-items: center; gap: 12px; } .qr > span:last-child { display: flex; flex-direction: column; gap: 2px; font-size: 12.5px; }
    .qr strong { font-size: 13.5px; } .qr-ico { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 10px; background: var(--dw-surface-3); color: var(--dw-ink-2); }
    .tag { word-break: break-all; padding: 8px 10px; background: var(--dw-surface-2); border: 1px solid var(--dw-line-soft); border-radius: var(--dw-radius); }
  `,
})
export class LotDetailPage implements OnInit {
  protected readonly session = inject(Session);
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly toast = inject(Toast);
  private readonly router = inject(Router);

  /** Route parameter. */
  readonly ulid = input.required<string>();

  private readonly davi = inject(Davi);
  protected readonly lot = signal<Lot | null>(null);
  protected readonly meta = signal<LotDetailMeta | null>(null);
  protected readonly events = signal<LotEvent[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly printing = signal(false);

  protected readonly transformable = computed(() => ['created', 'stored'].includes(this.lot()?.status ?? ''));
  protected readonly onSite = computed(() => this.transformable());
  /** Still on the site, a requested pickup included: its number of packages is edited. Once it has left, it is corrected with a reason. */
  protected readonly present = computed(() => ['created', 'stored', 'awaiting_pickup'].includes(this.lot()?.status ?? ''));

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    forkJoin({
      detail: this.api.raw<{ data: Lot; meta: LotDetailMeta }>(`lots/${this.ulid()}`),
      events: this.api.get<LotEvent[]>(`lots/${this.ulid()}/events`),
    }).subscribe({
      next: ({ detail, events }) => {
        this.lot.set(detail.data);
        // DAVI: "ce lot", "il", "cet enlèvement" mean the one on screen.
        this.davi.setFocus({ kind: 'lot', number: detail.data.lot_number });
        this.meta.set(detail.meta);
        this.events.set(events);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected lotSteps(t: (key: string) => string): { key: string; label: string }[] {
    return ['created', 'stored', 'awaiting_pickup', 'collected', 'treated', 'closed'].map((key) => ({ key, label: t('status.lot.' + key) }));
  }

  /** Every action the user may take on this lot now, in order of what usually comes next. */
  private readonly actions = computed(() => {
    const lot = this.lot();
    if (!lot) {
      return [];
    }
    const site = lot.site?.ulid;
    const can = (permission: string): boolean => this.session.can(permission, site);
    const all: { key: LotAction; label: string; icon: string; show: boolean }[] = [
      { key: 'weigh', label: lot.net_weight_kg === null ? 'lots.weighNow' : 'lots.weigh', icon: 'scale', show: this.transformable() && can('lots.weigh') },
      { key: 'pickup', label: 'lots.requestPickup', icon: 'local_shipping', show: this.transformable() && !this.meta()?.pickup && !this.meta()?.listing && can('pickups.request') },
      { key: 'print', label: 'lots.printLabel', icon: 'print', show: can('lots.print_label') },
      { key: 'store', label: lot.status === 'stored' ? 'lots.move' : 'lots.store', icon: 'move_down', show: this.onSite() && can('lots.move') },
      { key: 'split', label: 'lots.split', icon: 'call_split', show: this.transformable() && lot.net_weight_kg !== null && !['piece', 'l', 'm3'].includes(lot.unit ?? '') && can('lots.split') },
      { key: 'sell', label: 'marketplace.sellLot', icon: 'storefront', show: this.session.uses('marketplace') && this.transformable() && !this.meta()?.listing && can('marketplace.manage') },
      { key: 'origin', label: 'lots.editOrigin', icon: 'account_tree', show: this.session.uses('production') && can('lots.update') },
    ];

    return all.filter((action) => action.show);
  });
  /** A lot nobody weighed is weighed first; one on site is sent; otherwise its label is what people come for. */
  protected readonly primary = computed(() => {
    const actions = this.actions();
    const lot = this.lot();
    return (lot?.net_weight_kg === null ? actions.find((action) => action.key === 'weigh') : undefined)
      ?? actions.find((action) => action.key === 'pickup') ?? actions.find((action) => action.key === 'print') ?? actions[0] ?? null;
  });
  protected readonly more = computed(() => this.actions().filter((action) => action !== this.primary()));

  protected menuActions(): MenuAction[] {
    return this.more().map((action) => ({ label: this.toast.text(action.label), icon: action.icon, run: () => this.run(action.key) }));
  }

  protected run(key: LotAction): void {
    const lot = this.lot();
    if (key === 'print') {
      this.print();
    } else if (key === 'sell') {
      this.sell();
    } else if (key === 'pickup' && lot) {
      // LD-02: the request opens with this lot chosen.
      void this.router.navigate(['/pickups/new'], { queryParams: { site: lot.site?.ulid, lots: lot.ulid } });
    } else if (key !== 'pickup') {
      this.act(key);
    }
  }

  protected act(action: 'store' | 'weigh' | 'split' | 'origin' | 'packages' | 'composition'): void {
    const lot = this.lot();
    if (!lot) {
      return;
    }
    const component = action === 'store' ? StoreLotDialog : action === 'weigh' ? WeighLotDialog : action === 'origin' ? LotOriginDialog : action === 'packages' ? LotPackagesDialog
      : action === 'composition' ? LotCompositionDialog : SplitLotDialog;
    const composition = (this.meta()?.composition ?? []).map((line) => ({ material: line.material, pct: line.pct }));
    this.dialog.open<unknown, LotActionData & { composition: typeof composition }, Lot | Lot[]>(component, { data: { lot, composition } }).afterClosed().subscribe((result) => result && this.load());
  }

  /** Offers the lot on the bourse: a draft listing, with the other lots of its site and type if wanted. */
  protected sell(): void {
    const lot = this.lot();
    if (!lot?.site) {
      return;
    }
    this.dialog.open<CreateListingDialog, CreateListingData, { ulid: string }>(CreateListingDialog, { data: { site: lot.site.ulid, lots: [lot.ulid], wasteType: lot.waste_type?.ulid ?? null } })
      .afterClosed().subscribe((listing) => listing && void this.router.navigate(['/marketplace', listing.ulid]));
  }

  /** M2-02: thermal label, opened as a PDF sized 100 × 50 mm for the label printer. */
  protected print(): void {
    this.printing.set(true);
    this.api.blob(`lots/${this.ulid()}/label`).subscribe({
      next: (blob) => {
        openBlob(blob);
        this.printing.set(false);
        this.load();
      },
      error: (error: unknown) => {
        this.toast.error(error);
        this.printing.set(false);
      },
    });
  }
}
