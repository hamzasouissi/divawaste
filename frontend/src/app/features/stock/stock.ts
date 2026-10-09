import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom, forkJoin } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { ToggleSwitch } from 'primeng/toggleswitch';

import { Api } from '../../core/api/api';
import { StockResponse, StockRow, StockThreshold, Zone, ZoneFill } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { CodePipe, DatePipe, KgPipe, KnownWeightPipe, NumberPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { KpiComponent, PageHeaderComponent, StatusComponent } from '../../ui/page';
import { StateComponent } from '../../ui/state';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { HelpComponent } from '../../ui/help';
import { IconComponent } from '../../ui/icon';
import { MenuAction, MenuComponent } from '../../ui/menu';
import { SELECT } from '../../ui/select';
import { ZoneFillComponent } from '../../ui/zone-fill';

interface SiteGroup {
  site: string;
  name: string;
  total: number;
  zones: { zone: string; name: string; total: number; fill: ZoneFill; rows: StockRow[] }[];
}

/** The limit that watches a line of stock, with what it currently measures. */
interface AppliedLimit {
  limit: StockThreshold;
  quantity: number;
  max: number;
  pct: number;
  level: 'warning' | 'exceeded' | null;
  /** What the limit covers, when it is wider than the line: the whole zone, this type on the whole site, the whole site. */
  scope: 'zone' | 'siteType' | 'site' | null;
}

interface LimitDialogData {
  limit: StockThreshold | null;
  /** A new limit opened from a line of stock starts on that line. */
  prefill?: { site: string; zone: string; waste_type: string };
}

@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, ...FIELD, ...SELECT, ...DIALOG_PARTS, ToggleSwitch],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data.limit ? 'stock.editThreshold' : 'stock.addThreshold') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <p class="dw-muted intro">{{ t('stock.limitIntro') }}</p>
          <div class="dw-form">
            @if (!data.limit) {
              <dw-field class="dw-span-2" [label]="t('fields.site')">
                <dw-select formControlName="site" required>
                  @for (site of sites; track site.ulid) { <dw-option [value]="site.ulid" [label]="site.name" /> }
                </dw-select>
              </dw-field>
              <dw-field [label]="t('fields.zone')">
                <dw-select formControlName="zone">
                  <dw-option value="" [label]="t('stock.anyZone')" />
                  @for (zone of zones(); track zone.ulid) { <dw-option [value]="zone.ulid" [label]="zone.name" /> }
                </dw-select>
              </dw-field>
              <dw-field [label]="t('fields.wasteType')">
                <dw-select formControlName="waste_type">
                  <dw-option value="" [label]="t('stock.anyType')" />
                  @for (type of types(); track type.ulid) { <dw-option [value]="type.ulid" [label]="type.name" /> }
                </dw-select>
              </dw-field>
            }
            <dw-field [label]="t('stock.max')">
              <input pInputText formControlName="max_quantity_kg" inputmode="decimal" required />
              <small dwHint>{{ t('stock.maxHint') }}</small>
            </dw-field>
            <dw-field [label]="t('stock.warningPct')">
              <input pInputText type="number" formControlName="warning_pct" min="1" max="100" required />
              <small dwHint>{{ t('stock.warningHint') }}</small>
            </dw-field>
            @if (data.limit) { <div class="dw-switch dw-span-2"><p-toggleswitch formControlName="is_active" inputId="threshold-active" /><label for="threshold-active">{{ t('common.active') }}</label></div> }
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: '.dw-dialog-body { inline-size: min(560px, 82vw); } .intro { margin: 0 0 var(--dw-3); font-size: 13px; }',
})
export class ThresholdDialog {
  protected readonly data = dialogData<LimitDialogData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly reference = inject(ReferenceData);
  private readonly ref = inject(DialogRef);

  protected readonly sites = inject(Session).sitesFor('stock.thresholds.manage');
  protected readonly types = toSignal(this.reference.wasteTypes(), { initialValue: [] });
  protected readonly zones = signal<Zone[]>([]);
  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    site: [this.data.prefill?.site ?? this.sites[0]?.ulid ?? ''],
    zone: [this.data.prefill?.zone ?? ''],
    waste_type: [this.data.prefill?.waste_type ?? ''],
    max_quantity_kg: [this.data.limit?.max_quantity_kg ?? '', [Validators.required, Validators.pattern(/^\d+([.,]\d{1,3})?$/)]],
    warning_pct: [this.data.limit?.warning_pct ?? 90, [Validators.required, Validators.min(1), Validators.max(100)]],
    is_active: [this.data.limit?.is_active ?? true],
  });

  constructor() {
    const site = this.form.controls.site.value;
    if (!this.data.limit && site) {
      this.reference.zones(site).subscribe((zones) => this.zones.set(zones));
    }
    // Another site has other zones: the zone chosen for the previous one no longer means anything.
    this.form.controls.site.valueChanges.pipe(takeUntilDestroyed()).subscribe((next) => {
      this.form.controls.zone.setValue('');
      this.reference.zones(next).subscribe((zones) => this.zones.set(zones));
    });
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const max = value.max_quantity_kg.replace(',', '.');
    try {
      await firstValueFrom(this.data.limit
        ? this.api.put(`stock-thresholds/${this.data.limit.ulid}`, { max_quantity_kg: max, warning_pct: value.warning_pct, is_active: value.is_active })
        : this.api.post('stock-thresholds', { site: value.site, zone: value.zone || null, waste_type: value.waste_type || null, max_quantity_kg: max, warning_pct: value.warning_pct }));
      this.toast.success('stock.thresholdSaved');
      this.ref.close(true);
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

/**
 * M2-07: what is on each site right now, by zone and by waste type. Two things watch the stock and both are shown on
 * the lines they concern: the capacity of a zone (how full the place is) and the limits (a maximum weight for a site,
 * a zone or a waste type). When none is defined, the page says so and offers to define one — an absent limit must
 * not look like "everything is fine". Nothing is stored: the API sums the lots present on site at each request.
 */
@Component({
  imports: [
    CodePipe, RouterLink, TranslocoDirective, ZoneFillComponent, HelpComponent, KpiComponent, PageHeaderComponent, StatusComponent, StateComponent, DatePipe, KgPipe, KnownWeightPipe, NumberPipe, IconComponent, ButtonDirective, ...SELECT, MenuComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('stock.title')" [subtitle]="t('stock.subtitle')">
        @if (sites.length > 1) {
          <dw-select class="site" [ariaLabel]="t('fields.site')" [placeholder]="t('fields.site')" [value]="site()" (valueChange)="site.set($event); load()">
            <dw-option value="" [label]="t('common.allSites')" />
            @for (item of sites; track item.ulid) { <dw-option [value]="item.ulid" [label]="item.name" /> }
          </dw-select>
        }
        <button pButton severity="secondary" outlined type="button" (click)="load()"><dw-icon>refresh</dw-icon>{{ t('common.refresh') }}</button>
        @if (session.can('pickups.request')) {
          <a pButton routerLink="/pickups/new"><dw-icon>local_shipping</dw-icon>{{ t('stock.requestPickup') }}</a>
        }
      </dw-page-header>
      <dw-help topic="stock" />

      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (stock(); as s) {
          <div class="dw-stack">
            <div class="dw-stats">
              <dw-kpi [label]="t('stock.total')" [value]="s.meta.total_kg | dwKg: 'auto'" [hint]="(s.meta.unweighed_lots ?? 0) > 0 ? t('lots.plusUnweighed', { count: s.meta.unweighed_lots }) : (s.meta.generated_at | dwDate: 'datetime')" [tone]="(s.meta.unweighed_lots ?? 0) > 0 ? 'warning' : 'neutral'" />
              <dw-kpi [label]="t('stock.awaiting')" [value]="awaiting() | dwKg: 'auto'" />
              <dw-kpi [label]="t('status.alert.exceeded')" [value]="exceeded()" [tone]="exceeded() > 0 ? 'danger' : 'neutral'" [hint]="limitsHint(t)" />
              <dw-kpi [label]="t('status.alert.warning')" [value]="warnings()" [tone]="warnings() > 0 ? 'warning' : 'neutral'" />
            </div>

            <dw-state [empty]="groups().length === 0" icon="warehouse" [emptyTitle]="t('stock.emptyTitle')" [emptyHint]="t('stock.emptyHint')">
              <div class="dw-stack">
                @for (group of groups(); track group.site) {
                  <section class="dw-panel">
                    <div class="dw-panel-head"><h2 class="dw-h2">{{ group.name }}</h2><span class="dw-spacer"></span><strong>{{ group.total | dwKg: 'auto' }}</strong></div>
                    @for (zone of group.zones; track zone.zone) {
                      <div class="zone">
                        <div class="zone-head">
                          <span class="dw-h3">{{ zone.name }}</span>
                          <span class="dw-muted">{{ zone.total | dwKg }}</span>
                          <span class="what">{{ t('stock.capacity') }}</span>
                          <dw-zone-fill [fill]="zone.fill" />
                          @if (zone.fill.pct === null) {
                            <span class="none"><dw-icon aria-hidden="true">info</dw-icon>{{ t('stock.noCapacity') }}</span>
                            @if (session.can('zones.manage', group.site)) { <a class="define" [routerLink]="['/sites', group.site]">{{ t('stock.defineCapacity') }}</a> }
                          }
                        </div>
                        <div class="scroll">
                          <table>
                            <thead>
                              <tr><th>{{ t('fields.wasteType') }}</th><th class="dw-num">{{ t('production.stats.lots') }}</th><th class="dw-num">{{ t('fields.weight') }}</th>
                                <th>{{ t('stock.waiting') }}</th><th>{{ t('stock.oldest') }}</th><th>{{ t('stock.limit') }}</th></tr>
                            </thead>
                            <tbody>
                              @for (row of zone.rows; track row.waste_type) {
                                <tr>
                                  <td>{{ row.waste_type_name }} @if (row.is_hazardous) { <dw-icon class="hazard" [title]="t('common.hazardous')" [attr.aria-label]="t('common.hazardous')">warning</dw-icon> }</td>
                                  <td class="dw-num">{{ row.lots }}</td>
                                  <td class="dw-num strong">{{ row.quantity_kg | dwKgKnown: row.unweighed_lots }}
                                    @if (row.unit && row.unit !== 'kg' && row.quantity_in_unit) { <div class="dw-faint">{{ row.quantity_in_unit | dwNumber: 2 }} {{ row.unit | dwCode: 'unit' }}</div> }</td>
                                  <td class="dw-faint">{{ +row.awaiting_pickup_kg > 0 ? (row.awaiting_pickup_kg | dwKg) : '—' }}</td>
                                  <td class="dw-faint">{{ row.oldest_lot_at | dwDate }}</td>
                                  <td>
                                    @if (limitFor(row); as applied) {
                                      <div class="limit">
                                        <span class="meter" role="meter" [attr.aria-label]="t('stock.limit')" [attr.aria-valuenow]="applied.pct" aria-valuemin="0" aria-valuemax="100"><span class="bar" [class]="applied.level ?? 'ok'" [style.inline-size.%]="applied.pct"></span></span>
                                        <strong>{{ applied.pct }} %</strong>
                                        @if (applied.level) { <dw-status kind="alert" [value]="applied.level" /> }
                                      </div>
                                      <div class="dw-faint">{{ applied.quantity | dwKg }} / {{ applied.max | dwKg }}@if (applied.scope) { — {{ t('stock.scope.' + applied.scope) }} }</div>
                                    } @else {
                                      <span class="none"><dw-icon aria-hidden="true">info</dw-icon>{{ t('stock.noLimit') }}</span>
                                      @if (session.can('stock.thresholds.manage', row.site)) { <button type="button" class="define" (click)="define(row)">{{ t('stock.defineLimit') }}</button> }
                                    }
                                  </td>
                                </tr>
                              }
                            </tbody>
                          </table>
                        </div>
                      </div>
                    }
                  </section>
                }
              </div>
            </dw-state>

            <section class="dw-panel">
              <div class="dw-panel-head">
                <div class="dw-spacer"><h2 class="dw-h2">{{ t('stock.thresholds') }}</h2><p class="dw-muted sub">{{ t('stock.limitsHint') }}</p></div>
                @if (canManage) { <button pButton severity="secondary" outlined type="button" (click)="editThreshold(null)"><dw-icon>add</dw-icon>{{ t('stock.addThreshold') }}</button> }
              </div>
              @if (thresholds().length === 0) {
                <p class="dw-panel-body none block"><dw-icon aria-hidden="true">info</dw-icon>{{ t('stock.noThreshold') }}</p>
              } @else {
                <div class="scroll">
                  <table class="dw-table thresholds">
                    <thead><tr><th>{{ t('fields.site') }}</th><th>{{ t('fields.zone') }}</th><th>{{ t('fields.wasteType') }}</th><th>{{ t('stock.usage') }}</th><th class="dw-num">{{ t('stock.max') }}</th><th>{{ t('fields.status') }}</th><th></th></tr></thead>
                    <tbody>
                      @for (threshold of thresholds(); track threshold.ulid) {
                        <tr [class.off]="!threshold.is_active">
                          <td>{{ threshold.site_name }}</td><td>{{ threshold.zone_name ?? t('stock.anyZone') }}</td><td>{{ threshold.waste_type_name ?? t('stock.anyType') }}</td>
                          <td>
                            <div class="limit">
                              <span class="meter" role="meter" [attr.aria-label]="t('stock.usage')" [attr.aria-valuenow]="usage(threshold)" aria-valuemin="0" aria-valuemax="100"><span class="bar" [class]="current(threshold.ulid)?.alert_level ?? 'ok'" [style.inline-size.%]="usage(threshold)"></span></span>
                              <strong>{{ usage(threshold) }} %</strong>
                            </div>
                            <div class="dw-faint">{{ current(threshold.ulid)?.quantity_kg | dwKgKnown: current(threshold.ulid)?.unweighed_lots }}</div>
                            @if ((current(threshold.ulid)?.unweighed_lots ?? 0) > 0 && current(threshold.ulid)?.alert_level !== 'exceeded') { <div class="unverifiable">{{ t('stock.unverifiable') }}</div> }
                          </td>
                          <td class="dw-num">{{ threshold.max_quantity_kg | dwKg }} <div class="dw-faint">{{ t('stock.warnAt', { pct: threshold.warning_pct }) }}</div></td>
                          <td>@if (!threshold.is_active) { <dw-status kind="member" value="suspended" [label]="t('common.inactive')" tone="neutral" /> } @else { <dw-status kind="alert" [value]="current(threshold.ulid)?.alert_level ?? 'ok'" /> }</td>
                          <td class="dw-num">
                            @if (session.can('stock.thresholds.manage', threshold.site)) {
                              <button pButton text rounded severity="secondary" size="small" class="dw-icon-btn" type="button" (click)="menu.open($event, thresholdActions(threshold))" [attr.aria-label]="t('common.actions')" aria-haspopup="menu"><dw-icon>more_horiz</dw-icon></button>
                            }
                          </td>
                        </tr>
                      }
                    </tbody>
                  </table>
                </div>
              }
            </section>
          </div>
        }
      </dw-state>
      <dw-menu #menu />
    </ng-container>
  `,
  styles: `
    .site { width: 220px; }
    .zone { padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }
    .zone:last-child { border-block-end: 0; }
    .zone-head { display: flex; align-items: center; gap: var(--dw-2) var(--dw-3); flex-wrap: wrap; margin-block-end: 6px; }
    .what { font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dw-ink-3); margin-inline-start: var(--dw-3); }
    .scroll { overflow-x: auto; }
    /* Fixed layout: the columns line up from one zone to the next, although each zone has its own table. */
    .zone table { width: 100%; min-inline-size: 720px; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
    .zone th { padding: 4px 8px; text-align: start; font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dw-ink-3); border-block-end: 1px solid var(--dw-line-soft); }
    .zone th.dw-num { text-align: end; }
    .zone td { padding: 7px 8px; vertical-align: top; }
    .zone th:nth-child(1), .zone td:nth-child(1) { padding-inline-start: 0; width: 24%; } .zone th:nth-child(2) { width: 8%; } .zone th:nth-child(3) { width: 13%; }
    .zone th:nth-child(4) { width: 13%; } .zone th:nth-child(5) { width: 14%; } .zone th:nth-child(6) { width: 28%; }
    .strong { font-weight: 600; }
    .hazard { font-size: 15px; width: 15px; height: 15px; color: var(--dw-warning); vertical-align: -2px; }
    .limit { display: flex; align-items: center; gap: var(--dw-2); } .unverifiable { color: var(--dw-warning); font-size: 12.5px; font-weight: 600; }
    .meter { flex: 0 0 110px; height: 8px; border-radius: 4px; background: var(--dw-line-soft); overflow: hidden; }
    .bar { display: block; height: 100%; max-inline-size: 100%; background: var(--dw-success); }
    .bar.warning { background: #d68a00; } .bar.exceeded { background: var(--dw-danger); }
    .none { display: inline-flex; align-items: center; gap: 4px; color: var(--dw-ink-2); font-size: 12.5px; }
    .none dw-icon { font-size: 15px; color: var(--dw-ink-3); }
    .none.block { display: flex; }
    .define { all: unset; cursor: pointer; margin-inline-start: 6px; color: var(--dw-primary); font-size: 12.5px; font-weight: 600; text-decoration: underline; }
    .define:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: 2px; }
    .sub { margin: 2px 0 0; font-size: 13px; max-inline-size: 760px; }
    .thresholds tr.off td { color: var(--dw-ink-3); }
  `,
})
export class StockPage {
  protected readonly session = inject(Session);
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly confirm = inject(Confirm);
  private readonly toast = inject(Toast);

  protected readonly sites = this.session.sitesFor('stock.view');
  protected readonly canManage = this.session.sitesFor('stock.thresholds.manage').length > 0;
  protected readonly site = signal('');
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly stock = signal<StockResponse | null>(null);
  protected readonly thresholds = signal<StockThreshold[]>([]);

  protected readonly awaiting = computed(() => (this.stock()?.data ?? []).reduce((sum, row) => sum + Number(row.awaiting_pickup_kg), 0));
  protected readonly exceeded = computed(() => (this.stock()?.thresholds ?? []).filter((item) => item.alert_level === 'exceeded').length);
  protected readonly warnings = computed(() => (this.stock()?.thresholds ?? []).filter((item) => item.alert_level === 'warning').length);

  /** Rows grouped site → zone, in the order the API returns them (by name). */
  protected readonly groups = computed<SiteGroup[]>(() => {
    const groups = new Map<string, SiteGroup>();
    for (const row of this.stock()?.data ?? []) {
      const group = groups.get(row.site) ?? { site: row.site, name: row.site_name, total: 0, zones: [] };
      let zone = group.zones.find((item) => item.zone === row.zone);
      if (!zone) {
        zone = { zone: row.zone, name: row.zone_name, total: Number(row.zone_quantity_kg), fill: row.zone_fill, rows: [] };
        group.zones.push(zone);
      }
      zone.rows.push(row);
      group.total += Number(row.quantity_kg);
      groups.set(row.site, group);
    }

    return [...groups.values()];
  });

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    forkJoin({
      stock: this.api.raw<StockResponse>('stock', { filter: { site: this.site() || null } }),
      thresholds: this.api.list<StockThreshold>('stock-thresholds', { per_page: 100 }),
    }).subscribe({
      next: ({ stock, thresholds }) => {
        this.stock.set(stock);
        this.thresholds.set(thresholds.data);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  /** Under the "limit exceeded" figure: zero means little when no limit exists. */
  protected limitsHint(t: (key: string, params?: Record<string, unknown>) => string): string {
    const active = this.thresholds().filter((item) => item.is_active).length;

    return active === 0 ? t('stock.noLimitDefined') : t('stock.limitsWatching', { count: active });
  }

  protected current(ulid: string): StockResponse['thresholds'][number] | undefined {
    return this.stock()?.thresholds.find((item) => item.ulid === ulid);
  }

  protected usage(limit: StockThreshold): number {
    const max = Number(limit.max_quantity_kg);

    return max > 0 ? Math.round((Number(this.current(limit.ulid)?.quantity_kg ?? 0) / max) * 100) : 0;
  }

  /**
   * The limit that watches a line: the most precise one among those that cover it (this zone and this type, then the
   * zone, then the type on the site, then the site). It is shown with what it measures, which may be more than the line.
   */
  protected limitFor(row: StockRow): AppliedLimit | null {
    const covering = this.thresholds().filter((item) => item.is_active && item.site === row.site && (item.zone === null || item.zone === row.zone) && (item.waste_type === null || item.waste_type === row.waste_type));
    const precision = (item: StockThreshold): number => (item.zone ? 2 : 0) + (item.waste_type ? 1 : 0);
    const limit = covering.sort((a, b) => precision(b) - precision(a))[0];
    if (!limit) {
      return null;
    }
    const state = this.current(limit.ulid);
    const quantity = Number(state?.quantity_kg ?? 0);
    const max = Number(limit.max_quantity_kg);

    return {
      limit, quantity, max, pct: max > 0 ? Math.round((quantity / max) * 100) : 0, level: state?.alert_level ?? null,
      scope: limit.zone && limit.waste_type ? null : limit.zone ? 'zone' : limit.waste_type ? 'siteType' : 'site',
    };
  }

  /** A new limit for exactly this line: this zone and this waste type. */
  protected define(row: StockRow): void {
    this.dialog.open<ThresholdDialog, LimitDialogData, boolean>(ThresholdDialog, { data: { limit: null, prefill: { site: row.site, zone: row.zone, waste_type: row.waste_type } } })
      .afterClosed().subscribe((saved) => saved && this.load());
  }

  protected thresholdActions(threshold: StockThreshold): MenuAction[] {
    return [
      { label: this.toast.text('common.edit'), icon: 'edit', run: () => this.editThreshold(threshold) },
      { label: this.toast.text('common.delete'), icon: 'delete', danger: true, run: () => void this.removeThreshold(threshold) },
    ];
  }

  protected editThreshold(threshold: StockThreshold | null): void {
    this.dialog.open<ThresholdDialog, LimitDialogData, boolean>(ThresholdDialog, { data: { limit: threshold } }).afterClosed().subscribe((saved) => saved && this.load());
  }

  protected async removeThreshold(threshold: StockThreshold): Promise<void> {
    if (!(await this.confirm.ask({ title: `${this.toast.text('common.delete')} — ${threshold.site_name}`, danger: true }))) {
      return;
    }
    try {
      await firstValueFrom(this.api.delete(`stock-thresholds/${threshold.ulid}`));
      this.toast.success('stock.thresholdDeleted');
      this.load();
    } catch (error) {
      this.toast.error(error);
    }
  }
}
