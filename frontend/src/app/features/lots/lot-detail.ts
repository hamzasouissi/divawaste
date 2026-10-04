import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { forkJoin } from 'rxjs';

import { Api, openBlob } from '../../core/api/api';
import { Lot, LotDetailMeta, LotEvent } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, KgPipe, LocalizedPipe } from '../../core/format/pipes';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { StateComponent } from '../../ui/state';
import { LotActionData, SplitLotDialog, StoreLotDialog, WeighLotDialog } from './lot-dialogs';

/**
 * A lot and its whole story: what it is, where it is, every change with its time and author (M2-05),
 * where it came from and what it became (M2-06), and which pickup took it.
 */
@Component({
  imports: [RouterLink, MatButtonModule, MatIconModule, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, DatePipe, KgPipe, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (lot(); as l) {
          <dw-page-header [title]="t('lots.detail', { number: l.lot_number })" back="/lots" [backLabel]="t('lots.title')">
            <dw-status dwTitleBadge kind="lot" [value]="l.status" />
            @if (onSite() && session.can('lots.move', l.site?.ulid)) {
              <button mat-stroked-button type="button" (click)="act('store')"><mat-icon>move_down</mat-icon>{{ t(l.status === 'stored' ? 'lots.move' : 'lots.store') }}</button>
            }
            @if (transformable() && session.can('lots.weigh', l.site?.ulid)) {
              <button mat-stroked-button type="button" (click)="act('weigh')"><mat-icon>scale</mat-icon>{{ t('lots.weigh') }}</button>
            }
            @if (transformable() && session.can('lots.split', l.site?.ulid)) {
              <button mat-stroked-button type="button" (click)="act('split')"><mat-icon>call_split</mat-icon>{{ t('lots.split') }}</button>
            }
            @if (session.can('lots.print_label', l.site?.ulid)) {
              <button mat-flat-button type="button" (click)="print()" [disabled]="printing()"><mat-icon>print</mat-icon>{{ t('lots.printLabel') }}</button>
            }
          </dw-page-header>

          <div class="dw-split">
            <div class="dw-stack">
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('lots.identity') }}</h2></div>
                <div class="dw-panel-body">
                  <dl class="dw-facts">
                    <div><dt>{{ t('fields.wasteType') }}</dt><dd>{{ l.waste_type?.name }} @if (l.is_hazardous) { <dw-status kind="alert" value="warning" [label]="t('common.hazardous')" /> }</dd></div>
                    <div><dt>{{ t('fields.weight') }}</dt><dd class="weight">{{ l.net_weight_kg | dwKg }}</dd></div>
                    <div><dt>{{ t('fields.site') }}</dt><dd>{{ l.site?.name }}</dd></div>
                    <div><dt>{{ t('fields.zone') }}</dt><dd>{{ l.zone?.name }}</dd></div>
                    <div><dt>{{ t('lots.regulatoryCode') }}</dt><dd class="dw-mono">{{ l.regulatory_code ?? '—' }}</dd></div>
                    <div><dt>{{ t('lots.origin') }}</dt><dd>{{ t('origin.' + l.origin_type) }}</dd></div>
                    <div><dt>{{ t('lots.generatedAt') }}</dt><dd>{{ l.generated_at | dwDate: 'datetime' }}</dd></div>
                    <div><dt>{{ t('lots.packaging') }}</dt><dd>{{ l.packaging_type ?? '—' }}</dd></div>
                    <div><dt>{{ t('lots.color') }}</dt><dd>{{ l.color_label ?? l.color_family ?? '—' }}</dd></div>
                    <div><dt>{{ t('lots.grammage') }}</dt><dd>{{ l.grammage_gsm ?? '—' }}</dd></div>
                    <div><dt>{{ t('lots.composition') }}</dt>
                      <dd>
                        @for (line of meta()?.composition ?? []; track line.material) { <span class="chip">{{ line.name | dwLocalized }} {{ +line.pct }} %</span> } @empty { — }
                      </dd>
                    </div>
                    @if (l.notes) { <div><dt>{{ t('fields.notes') }}</dt><dd>{{ l.notes }}</dd></div> }
                  </dl>
                </div>
              </section>

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
                          @if (event.weight_kg && (event.event_type === 'weighed' || event.event_type === 'created')) { <span class="dw-muted">{{ event.weight_kg | dwKg }}</span> }
                          @if (event.payload?.['previous_weight_kg']; as previous) { <span class="dw-faint">({{ t('lots.previousWeight', { kg: previous }) }})</span> }
                        </div>
                        <div class="when dw-faint">{{ event.occurred_at | dwDate: 'datetime' }} @if (event.actor) { · {{ t('lots.by', { actor: event.actor }) }} } · {{ event.source }}</div>
                      </div>
                    </li>
                  }
                </ol>
              </section>
            </div>

            <div class="dw-stack">
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('lots.qr') }}</h2></div>
                <div class="dw-panel-body dw-stack-sm">
                  <div class="dw-mono tag">{{ l.qr }}</div>
                  <p class="dw-faint">{{ t('lots.printCount', { count: l.label_print_count }) }} · 100 × 50 mm</p>
                </div>
              </section>
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
    .weight { font-size: 18px; font-weight: 600; }
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
    .tag { word-break: break-all; padding: 8px 10px; background: var(--dw-surface-2); border: 1px solid var(--dw-line-soft); border-radius: var(--dw-radius); }
  `,
})
export class LotDetailPage implements OnInit {
  protected readonly session = inject(Session);
  private readonly api = inject(Api);
  private readonly dialog = inject(MatDialog);
  private readonly toast = inject(Toast);

  /** Route parameter. */
  readonly ulid = input.required<string>();

  protected readonly lot = signal<Lot | null>(null);
  protected readonly meta = signal<LotDetailMeta | null>(null);
  protected readonly events = signal<LotEvent[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly printing = signal(false);

  protected readonly transformable = computed(() => ['created', 'stored'].includes(this.lot()?.status ?? ''));
  protected readonly onSite = computed(() => this.transformable());

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

  protected act(action: 'store' | 'weigh' | 'split'): void {
    const lot = this.lot();
    if (!lot) {
      return;
    }
    const component = action === 'store' ? StoreLotDialog : action === 'weigh' ? WeighLotDialog : SplitLotDialog;
    this.dialog.open<unknown, LotActionData, Lot | Lot[]>(component, { data: { lot } }).afterClosed().subscribe((result) => result && this.load());
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
