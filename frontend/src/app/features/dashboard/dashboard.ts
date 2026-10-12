import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { EChartsCoreOption } from 'echarts/core';
import { forkJoin } from 'rxjs';
import { ButtonDirective } from 'primeng/button';

import { Api, Page } from '../../core/api/api';
import { Dashboard, DirectoryProvider, OnboardingState, Pickup, StockResponse } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { DatePipe, KgPipe, MoneyPipe, NumberPipe } from '../../core/format/pipes';
import { Locale } from '../../core/i18n/i18n';
import { ChartComponent } from '../../ui/chart';
import { KpiComponent, PageHeaderComponent, StatusComponent } from '../../ui/page';
import { Period, PeriodFilterComponent, presetPeriod } from '../../ui/period';
import { StateComponent } from '../../ui/state';
import { IconComponent } from '../../ui/icon';

interface Attention {
  icon: string;
  tone: 'warning' | 'danger' | 'info';
  text: string;
  link: string;
}

/**
 * M6-01 / M6-02. The screen answers, in order: what needs me today, how much waste we produce, where it goes,
 * what it costs or earns. Every figure comes from the API, which computes it from the lots and the pickups.
 */
interface ProductionSummary {
  totals: { measured_kg: string; estimated_kg: string; unattributed_kg: string };
  by_order: { key: string; label: string; sublabel: string | null; total_kg: string; g_per_piece: number | null; waste_rate_pct: number | null }[];
}

@Component({
  imports: [
    RouterLink, TranslocoDirective, ButtonDirective, ChartComponent, KpiComponent, PageHeaderComponent, StatusComponent, PeriodFilterComponent, StateComponent, DatePipe, KgPipe, MoneyPipe, NumberPipe, IconComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('dashboard.title')" [subtitle]="session.company()?.legal_name ?? ''">
        <dw-period-filter [(value)]="period" (changed)="load()" />
      </dw-page-header>

      @if (onboarding(); as steps) {
        @if (!steps.completed && !steps.dismissed && session.can('company.update')) {
          <a class="onboarding dw-panel" routerLink="/onboarding">
            <dw-icon aria-hidden="true">rocket_launch</dw-icon>
            <span><strong>{{ t('onboarding.bannerTitle') }}</strong> {{ t('onboarding.bannerBody', { done: doneSteps(), total: steps.steps.length }) }}</span>
            <span class="dw-spacer"></span>
            <span class="link">{{ t('onboarding.resume') }}</span>
          </a>
        }
      }

      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (data(); as d) {
          <div class="dw-stack">
            <!-- 1. Figures of the period -->
            <div class="dw-stats">
              <dw-kpi [label]="t('dashboard.generated')" [value]="d.totals.generated_kg | dwKg: 'auto'" [hint]="t('dashboard.lots', { count: d.totals.generated_lots }) + ((d.totals.unweighed_lots ?? 0) > 0 ? ' · ' + t('lots.unweighedCount', { count: d.totals.unweighed_lots }) : '')" />
              <dw-kpi [label]="t('dashboard.stock')" [value]="stock()?.meta?.total_kg | dwKg: 'auto'" [hint]="t('dashboard.stockHint')" />
              <dw-kpi [label]="t('dashboard.recoveryRate')" [value]="d.totals.recovery_rate_pct | dwNumber: 1 : ' %'" [hint]="t('dashboard.recovered', { kg: (d.totals.recovered_kg | dwKg: 'auto') })" tone="success" />
              <dw-kpi [label]="t('dashboard.landfillShare')" [value]="d.totals.landfill_share_pct | dwNumber: 1 : ' %'" [hint]="t('dashboard.landfilled', { kg: (d.totals.landfill_kg | dwKg: 'auto') })"
                [tone]="+(d.totals.landfill_share_pct ?? 0) > 20 ? 'warning' : 'neutral'" />
              <dw-kpi [label]="t('dashboard.kgPerPiece')" [value]="d.totals.kg_per_piece === null ? '—' : (+d.totals.kg_per_piece * 1000 | dwNumber: 1 : ' g')"
                [hint]="d.totals.pieces_produced > 0 ? t('dashboard.pieces', { count: (d.totals.pieces_produced | dwNumber) }) : t('dashboard.noPieces')" />
              <dw-kpi [label]="t('dashboard.hazardous')" [value]="d.totals.hazardous_kg | dwKg: 'auto'" />
              <dw-kpi [label]="t('dashboard.evacuated')" [value]="d.totals.evacuated_kg | dwKg: 'auto'"
                [hint]="+d.totals.undeclared_channel_kg > 0 ? t('dashboard.undeclared', { kg: (d.totals.undeclared_channel_kg | dwKg: 'auto') }) : ''" />
              <dw-kpi [label]="t('dashboard.netBalance')" [value]="d.totals.net_balance | dwMoney: currency()" [tone]="+d.totals.net_balance < 0 ? 'danger' : 'success'"
                [hint]="t('dashboard.balanceHint', { revenue: (d.totals.revenue | dwMoney: currency()), cost: (d.totals.cost | dwMoney: currency()) })" />
            </div>

            <!-- 2. What needs a decision, next to 3. where the waste is -->
            <div [class.dw-grid-2]="attention().length > 0">
              @if (attention().length > 0) {
                <section class="dw-panel" [attr.aria-label]="t('dashboard.attention')">
                  <div class="dw-panel-head"><h2 class="dw-h2">{{ t('dashboard.attention') }}</h2><span class="count">{{ attention().length }}</span></div>
                  <ul class="attention">
                    @for (item of attention(); track item.text) {
                      <li><a [routerLink]="item.link" [class]="item.tone"><span class="mark"><dw-icon>{{ item.icon }}</dw-icon></span><span>{{ item.text }}</span><dw-icon class="go">chevron_right</dw-icon></a></li>
                    }
                  </ul>
                </section>
              }
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('dashboard.bySite') }}</h2></div>
                <table class="dw-table">
                  <thead><tr><th>{{ t('fields.site') }}</th><th class="num">{{ t('dashboard.lotsShort') }}</th><th class="num">{{ t('fields.quantity') }}</th><th class="share">{{ t('dashboard.share') }}</th></tr></thead>
                  <tbody>
                    @for (site of d.by_site; track site.site) {
                      <tr>
                        <td>{{ site.name }}</td><td class="num">{{ site.lots }}</td><td class="num">{{ site.kg | dwKg: 'auto' }}</td>
                        <td class="share"><span class="track"><span class="bar" [style.inline-size.%]="share(site.kg)" aria-hidden="true"></span></span><span class="dw-sr-only">{{ share(site.kg) | dwNumber: 0 : ' %' }}</span></td>
                      </tr>
                    } @empty {
                      <tr><td colspan="4" class="dw-muted">{{ t('dashboard.noLots') }}</td></tr>
                    }
                  </tbody>
                </table>
              </section>
            </div>

            <!-- 4. Pickups in progress -->
            @if (pickups().length > 0) {
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('dashboard.openPickups') }}</h2><span class="dw-spacer"></span><a class="more" routerLink="/pickups">{{ t('common.seeAll') }}<dw-icon>chevron_right</dw-icon></a></div>
                <div class="scroll">
                  <table class="dw-table">
                    <thead><tr><th>{{ t('pickups.number') }}</th><th>{{ t('pickups.provider') }}</th><th>{{ t('fields.site') }}</th><th>{{ t('pickups.date') }}</th><th class="num">{{ t('fields.weight') }}</th><th>{{ t('fields.status') }}</th></tr></thead>
                    <tbody>
                      @for (pickup of pickups(); track pickup.ulid) {
                        <tr>
                          <td><a [routerLink]="['/pickups', pickup.ulid]" class="dw-mono">{{ pickup.pickup_number }}</a></td><td>{{ pickup.provider?.legal_name }}</td><td>{{ pickup.site?.name }}</td>
                          <td>{{ pickup.confirmed_date ?? pickup.requested_date | dwDate }}</td><td class="num">{{ pickup.declared_weight_kg | dwKg }}</td>
                          <td><dw-status kind="pickup" [value]="pickup.status" /></td>
                        </tr>
                      }
                    </tbody>
                  </table>
                </div>
              </section>
            }

            <!-- 5. Trends -->
            <div class="dw-split">
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('dashboard.byMonth') }}</h2></div>
                <div class="dw-panel-body"><dw-chart [option]="monthChart()" [label]="t('dashboard.byMonth')" [height]="260" /></div>
              </section>
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('dashboard.byChannel') }}</h2></div>
                <div class="dw-panel-body">
                  @if (d.by_channel.length > 0) {
                    <dw-chart [option]="channelChart()" [label]="t('dashboard.byChannel')" [height]="260" />
                  } @else {
                    <p class="dw-muted none">{{ t('dashboard.noEvacuation') }}</p>
                  }
                </div>
              </section>
            </div>

            @if (production(); as p) {
              <section class="dw-panel">
                <div class="dw-panel-head">
                  <div class="dw-spacer"><h2 class="dw-h2">{{ t('dashboard.production') }}</h2><p class="dw-muted prod-sub">{{ t('dashboard.productionHint') }}</p></div>
                  <a pButton text routerLink="/production/stats">{{ t('dashboard.seeAll') }}<dw-icon>chevron_right</dw-icon></a>
                </div>
                <div class="dw-panel-body prod">
                  <div class="prod-figures">
                    <div><span class="dw-faint">{{ t('production.stats.measured') }}</span><strong>{{ p.totals.measured_kg | dwKg: 'auto' }}</strong></div>
                    <div><span class="dw-faint">{{ t('production.stats.estimated') }}</span><strong>{{ p.totals.estimated_kg | dwKg: 'auto' }}</strong></div>
                    <div><span class="dw-faint">{{ t('production.stats.unattributed') }}</span><strong>{{ p.totals.unattributed_kg | dwKg: 'auto' }}</strong></div>
                  </div>
                  <table class="dw-table">
                    <thead><tr><th>{{ t('production.of.short') }}</th><th class="dw-num">{{ t('production.stats.total') }}</th><th class="dw-num">{{ t('production.stats.perPiece') }}</th><th class="dw-num">{{ t('production.stats.rate') }}</th></tr></thead>
                    <tbody>
                      @for (row of p.by_order.slice(0, 5); track row.key) {
                        <tr><td><strong class="dw-mono">{{ row.label }}</strong> <span class="dw-faint">{{ row.sublabel }}</span></td><td class="dw-num">{{ row.total_kg | dwKg }}</td>
                          <td class="dw-num">{{ row.g_per_piece === null ? '—' : (row.g_per_piece | dwNumber: 1) + ' g' }}</td><td class="dw-num">{{ row.waste_rate_pct === null ? '—' : (row.waste_rate_pct | dwNumber: 1) + ' %' }}</td></tr>
                      }
                    </tbody>
                  </table>
                </div>
              </section>
            }

            <section class="dw-panel">
              <div class="dw-panel-head"><h2 class="dw-h2">{{ t('dashboard.byType') }}</h2></div>
              <div class="dw-panel-body">
                @if (d.by_waste_type.length > 0) {
                  <dw-chart [option]="typeChart()" [label]="t('dashboard.byType')" [height]="typeHeight()" />
                } @else {
                  <p class="dw-muted none">{{ t('dashboard.noLots') }}</p>
                }
              </div>
            </section>
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .prod-sub { margin: 2px 0 0; font-size: 13px; }
    .prod { display: grid; gap: var(--dw-3); }
    .prod-figures { display: flex; gap: var(--dw-5); flex-wrap: wrap; } .prod-figures div { display: grid; gap: 2px; } .prod-figures strong { font-size: 18px; }
    .onboarding { display: flex; align-items: center; gap: var(--dw-3); padding: 10px var(--dw-4); margin-block-end: var(--dw-4); color: var(--dw-ink); border-color: var(--dw-primary-line); background: var(--dw-primary-soft); }
    .onboarding:hover { text-decoration: none; border-color: var(--dw-primary); }
    .onboarding dw-icon { color: var(--dw-primary); }
    .onboarding .link { color: var(--dw-primary); font-weight: 600; }
    .count { display: inline-grid; place-items: center; min-width: 18px; height: 18px; padding-inline: 5px; border-radius: 9px; background: var(--dw-warning-soft); color: var(--dw-warning); font-size: 11px; font-weight: 700; }
    .attention { list-style: none; margin: 0; padding: 0; }
    .attention a { display: flex; align-items: center; gap: var(--dw-3); min-height: 40px; padding: 6px var(--dw-3) 6px var(--dw-4); color: var(--dw-ink); border-block-end: 1px solid var(--dw-line-soft); }
    .attention li:last-child a { border-block-end: 0; border-end-start-radius: var(--dw-radius-lg); border-end-end-radius: var(--dw-radius-lg); }
    .attention a:hover { background: var(--dw-surface-2); text-decoration: none; }
    .mark { flex: none; display: grid; place-items: center; width: 26px; height: 26px; border-radius: 6px; background: var(--dw-neutral-soft); color: var(--dw-ink-2); }
    .mark dw-icon { font-size: 16px; }
    .warning .mark { background: var(--dw-warning-soft); color: var(--dw-warning); }
    .danger .mark { background: var(--dw-danger-soft); color: var(--dw-danger); }
    .info .mark { background: var(--dw-info-soft); color: var(--dw-info); }
    .attention a > span:not(.mark) { flex: 1; }
    .go { color: var(--dw-ink-3); font-size: 16px; }
    :host-context([dir='rtl']) .go { transform: scaleX(-1); }
    .more { display: inline-flex; align-items: center; font-size: 12.5px; font-weight: 550; }
    .more dw-icon { font-size: 16px; }
    .scroll { overflow-x: auto; }
    .none { padding-block: var(--dw-6); text-align: center; }
    .share { width: 34%; }
    .track { display: block; height: 6px; border-radius: 3px; background: var(--dw-surface-3); overflow: hidden; }
    .bar { display: block; height: 100%; border-radius: 3px; background: var(--dw-primary); min-inline-size: 2px; }
  `,
})
export class DashboardPage {
  protected readonly session = inject(Session);
  private readonly api = inject(Api);
  private readonly transloco = inject(TranslocoService);
  private readonly locale = inject(Locale);

  protected readonly period = signal<Period>(presetPeriod('quarter'));
  /** Waste by manufacturing order, for who may read production statistics and only when some waste names an order. */
  protected readonly production = signal<ProductionSummary | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly data = signal<Dashboard | null>(null);
  protected readonly stock = signal<StockResponse | null>(null);
  protected readonly pickups = signal<Pickup[]>([]);
  protected readonly providers = signal<DirectoryProvider[]>([]);
  protected readonly onboarding = signal<OnboardingState | null>(null);

  protected readonly currency = computed(() => this.session.company()?.currency_code ?? 'TND');
  protected readonly doneSteps = computed(() => this.onboarding()?.steps.filter((step) => step.done || step.skipped).length ?? 0);
  protected readonly typeHeight = computed(() => Math.max(180, (this.data()?.by_waste_type.length ?? 0) * 30 + 40));

  /** What needs a decision today, most urgent first. */
  protected readonly attention = computed<Attention[]>(() => {
    const t = (key: string, params?: Record<string, unknown>): string => this.transloco.translate(key, params);
    const items: Attention[] = [];
    const exceeded = this.stock()?.thresholds.filter((threshold) => threshold.alert_level === 'exceeded').length ?? 0;
    const warning = this.stock()?.thresholds.filter((threshold) => threshold.alert_level === 'warning').length ?? 0;
    if (exceeded > 0) {
      items.push({ icon: 'error', tone: 'danger', text: t('dashboard.alerts.exceeded', { count: exceeded }), link: '/stock' });
    }
    if (warning > 0) {
      items.push({ icon: 'warning', tone: 'warning', text: t('dashboard.alerts.warning', { count: warning }), link: '/stock' });
    }
    // Below the limit on what is known, with lots nobody weighed: not a limit anyone can call respected.
    const unverifiable = this.stock()?.thresholds.filter((threshold) => threshold.constraint?.outcome === 'unverifiable').length ?? 0;
    if (unverifiable > 0) {
      items.push({ icon: 'help', tone: 'warning', text: t('dashboard.alerts.unverifiable', { count: unverifiable }), link: '/stock' });
    }
    // What zones and sites can physically hold: their room, their load, the weight of the site. Each zone and site once.
    const places = [...new Map((this.stock()?.data ?? []).map((row) => [row.zone, row.zone_fill.outcome])).values(), ...(this.stock()?.sites ?? []).map((site) => site.outcome)];
    const full = places.filter((outcome) => outcome === 'exceeded').length;
    const unknown = places.filter((outcome) => outcome === 'unverifiable').length;
    if (full > 0) {
      items.push({ icon: 'warehouse', tone: 'danger', text: t('dashboard.alerts.capacityExceeded', { count: full }), link: '/stock' });
    }
    if (unknown > 0) {
      items.push({ icon: 'help', tone: 'warning', text: t('dashboard.alerts.capacityUnverifiable', { count: unknown }), link: '/stock' });
    }
    const flagged = this.pickups().filter((pickup) => pickup.variance_flagged && !pickup.variance_acknowledged).length;
    if (flagged > 0) {
      items.push({ icon: 'scale', tone: 'danger', text: t('dashboard.alerts.variance', { count: flagged }), link: '/pickups' });
    }
    const requested = this.pickups().filter((pickup) => pickup.status === 'requested').length;
    if (requested > 0) {
      items.push({ icon: 'schedule', tone: 'info', text: t('dashboard.alerts.requested', { count: requested }), link: '/pickups' });
    }
    for (const provider of this.providers()) {
      const accreditation = provider.accreditations.find((item) => item.state !== 'valid');
      if (provider.partnership?.status === 'active' && accreditation) {
        items.push({
          icon: 'verified', tone: accreditation.state === 'expired' ? 'danger' : 'warning', link: '/providers',
          text: t(accreditation.state === 'expired' ? 'dashboard.alerts.accreditationExpired' : 'dashboard.alerts.accreditationExpiring', { provider: provider.legal_name, days: accreditation.days_left }),
        });
      }
    }

    return items;
  });

  protected readonly monthChart = computed<EChartsCoreOption>(() => {
    const months = this.data()?.by_month ?? [];
    const label = new Intl.DateTimeFormat(this.locale.intl, { month: 'short', year: '2-digit' });

    return {
      tooltip: { trigger: 'axis', valueFormatter: (value: number) => `${new Intl.NumberFormat(this.locale.intl).format(value)} kg` },
      legend: { top: 0, right: 0, icon: 'roundRect' },
      grid: { left: 4, right: 8, top: 36, bottom: 4, containLabel: true },
      xAxis: { type: 'category', data: months.map((month) => label.format(new Date(`${month.month}-01T12:00:00`))), axisTick: { show: false } },
      yAxis: { type: 'value', axisLabel: { formatter: (value: number) => (value >= 1000 ? `${value / 1000} t` : `${value} kg`) } },
      series: [
        { name: this.transloco.translate('dashboard.generated'), type: 'bar', barMaxWidth: 26, itemStyle: { borderRadius: [3, 3, 0, 0] }, data: months.map((month) => +month.generated_kg) },
        { name: this.transloco.translate('dashboard.evacuated'), type: 'line', smooth: false, symbol: 'circle', symbolSize: 6, lineStyle: { width: 2 }, data: months.map((month) => +month.evacuated_kg) },
      ],
    };
  });

  protected readonly channelChart = computed<EChartsCoreOption>(() => ({
    tooltip: { trigger: 'item', valueFormatter: (value: number) => `${new Intl.NumberFormat(this.locale.intl).format(value)} kg` },
    legend: { bottom: 0, icon: 'circle', itemGap: 12 },
    series: [
      {
        // Shares are read in the tooltip and the legend: labels around a donut collide as soon as slices are small.
        type: 'pie', radius: ['56%', '78%'], center: ['50%', '42%'], label: { show: false }, emphasis: { scale: false }, itemStyle: { borderColor: '#fff', borderWidth: 2 },
        data: (this.data()?.by_channel ?? []).map((row) => ({
          name: row.channel ? this.transloco.translate(`channels.${row.channel}`) : this.transloco.translate('dashboard.channelUndeclared'),
          value: +row.kg,
          // Landfill is always the same warning colour, whatever its rank.
          ...(row.is_landfill ? { itemStyle: { color: '#b84a4a' } } : row.channel === null ? { itemStyle: { color: '#b7c1c9' } } : {}),
        })),
      },
    ],
  }));

  protected readonly typeChart = computed<EChartsCoreOption>(() => {
    const rows = [...(this.data()?.by_waste_type ?? [])].reverse();

    return {
      tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' }, valueFormatter: (value: number) => `${new Intl.NumberFormat(this.locale.intl).format(value)} kg` },
      grid: { left: 8, right: 24, top: 8, bottom: 8, containLabel: true },
      xAxis: { type: 'value' },
      yAxis: { type: 'category', data: rows.map((row) => row.name), axisTick: { show: false } },
      series: [{ type: 'bar', barMaxWidth: 14, itemStyle: { borderRadius: [0, 3, 3, 0] }, data: rows.map((row) => ({ value: +row.kg, ...(row.is_hazardous ? { itemStyle: { color: '#c9812a' } } : {}) })) }],
    };
  });

  constructor() {
    this.load();
    if (this.session.can('company.update')) {
      this.api.get<OnboardingState>('onboarding').subscribe({ next: (state) => this.onboarding.set(state), error: () => undefined });
    }
  }

  protected load(): void {
    const { from, to, site } = this.period();
    this.loading.set(true);
    this.error.set(null);
    this.api.get<Dashboard>('dashboard', { from, to, site }).subscribe({
      next: (data) => {
        this.data.set(data);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });

    if (this.session.can('production.stats.view')) {
      this.api.get<ProductionSummary>('production/stats', { from, to, site }).subscribe({ next: (stats) => this.production.set(stats.by_order.length > 0 ? stats : null), error: () => this.production.set(null) });
    }

    // Operational side panels: each is optional (the user may not hold the permission) and never blocks the figures.
    forkJoin({
      stock: this.session.can('stock.view') ? this.api.raw<StockResponse>('stock', { filter: { site } }) : [null],
      pickups: this.session.can('pickups.view') ? this.api.list<Pickup>('pickups', { filter: { status: ['requested', 'confirmed', 'collected', 'received'], site }, per_page: 8 }) : [null],
      providers: this.session.can('providers.view') ? this.api.get<DirectoryProvider[]>('providers/directory', { filter: { partners_only: true } }) : [null],
    }).subscribe({
      next: ({ stock, pickups, providers }) => {
        this.stock.set(stock as StockResponse | null);
        this.pickups.set((pickups as Page<Pickup> | null)?.data ?? []);
        this.providers.set((providers as DirectoryProvider[] | null) ?? []);
      },
      error: () => undefined,
    });
  }

  protected share(kg: string): number {
    const total = +(this.data()?.totals.generated_kg ?? 0);

    return total > 0 ? Math.round((+kg / total) * 100) : 0;
  }
}
