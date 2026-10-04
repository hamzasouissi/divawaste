import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { EChartsCoreOption } from 'echarts/core';
import { forkJoin } from 'rxjs';

import { Api, Page } from '../../core/api/api';
import { Dashboard, DirectoryProvider, OnboardingState, Pickup, StockResponse } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { DatePipe, KgPipe, MoneyPipe, NumberPipe } from '../../core/format/pipes';
import { Locale } from '../../core/i18n/i18n';
import { ChartComponent } from '../../ui/chart';
import { KpiComponent, PageHeaderComponent, StatusComponent } from '../../ui/page';
import { Period, PeriodFilterComponent, presetPeriod } from '../../ui/period';
import { StateComponent } from '../../ui/state';

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
@Component({
  imports: [RouterLink, MatButtonModule, MatIconModule, TranslocoDirective, ChartComponent, KpiComponent, PageHeaderComponent, StatusComponent, PeriodFilterComponent, StateComponent,
    DatePipe, KgPipe, MoneyPipe, NumberPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('dashboard.title')" [subtitle]="session.company()?.legal_name ?? ''">
        <dw-period-filter [(value)]="period" (changed)="load()" />
      </dw-page-header>

      @if (onboarding(); as steps) {
        @if (!steps.completed && !steps.dismissed && session.can('company.update')) {
          <a class="onboarding dw-panel" routerLink="/onboarding">
            <mat-icon aria-hidden="true">rocket_launch</mat-icon>
            <span><strong>{{ t('onboarding.bannerTitle') }}</strong> {{ t('onboarding.bannerBody', { done: doneSteps(), total: steps.steps.length }) }}</span>
            <span class="dw-spacer"></span>
            <span class="link">{{ t('onboarding.resume') }}</span>
          </a>
        }
      }

      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (data(); as d) {
          <div class="dw-stack">
            @if (attention().length > 0) {
              <section class="dw-panel" [attr.aria-label]="t('dashboard.attention')">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('dashboard.attention') }}</h2></div>
                <ul class="attention">
                  @for (item of attention(); track item.text) {
                    <li><a [routerLink]="item.link" [class]="item.tone"><mat-icon aria-hidden="true">{{ item.icon }}</mat-icon><span>{{ item.text }}</span><mat-icon class="go" aria-hidden="true">chevron_right</mat-icon></a></li>
                  }
                </ul>
              </section>
            }

            <div class="dw-grid-4">
              <dw-kpi [label]="t('dashboard.generated')" [value]="d.totals.generated_kg | dwKg: 'auto'" [hint]="t('dashboard.lots', { count: d.totals.generated_lots })" />
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

            <div class="dw-split">
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('dashboard.byMonth') }}</h2></div>
                <div class="dw-panel-body"><dw-chart [option]="monthChart()" [label]="t('dashboard.byMonth')" [height]="280" /></div>
              </section>
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('dashboard.byChannel') }}</h2></div>
                <div class="dw-panel-body">
                  @if (d.by_channel.length > 0) {
                    <dw-chart [option]="channelChart()" [label]="t('dashboard.byChannel')" [height]="280" />
                  } @else {
                    <p class="dw-muted none">{{ t('dashboard.noEvacuation') }}</p>
                  }
                </div>
              </section>
            </div>

            <div class="dw-grid-2">
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
              <section class="dw-panel">
                <div class="dw-panel-head">
                  <h2 class="dw-h2">{{ t('dashboard.bySite') }}</h2>
                </div>
                <div class="dw-panel-body">
                  <table class="mini">
                    <thead><tr><th>{{ t('fields.site') }}</th><th class="dw-num">{{ t('dashboard.lotsShort') }}</th><th class="dw-num">{{ t('fields.quantity') }}</th><th class="share">{{ t('dashboard.share') }}</th></tr></thead>
                    <tbody>
                      @for (site of d.by_site; track site.site) {
                        <tr>
                          <td>{{ site.name }}</td><td class="dw-num">{{ site.lots }}</td><td class="dw-num">{{ site.kg | dwKg: 'auto' }}</td>
                          <td class="share"><span class="bar" [style.inline-size.%]="share(site.kg)" aria-hidden="true"></span><span class="dw-sr-only">{{ share(site.kg) | dwNumber: 0 : ' %' }}</span></td>
                        </tr>
                      } @empty {
                        <tr><td colspan="4" class="dw-muted">{{ t('dashboard.noLots') }}</td></tr>
                      }
                    </tbody>
                  </table>
                </div>
              </section>
            </div>

            @if (pickups().length > 0) {
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('dashboard.openPickups') }}</h2><span class="dw-spacer"></span><a routerLink="/pickups">{{ t('common.seeAll') }}</a></div>
                <table class="mini padded">
                  <thead><tr><th>{{ t('pickups.number') }}</th><th>{{ t('pickups.provider') }}</th><th>{{ t('fields.site') }}</th><th>{{ t('pickups.date') }}</th><th class="dw-num">{{ t('fields.weight') }}</th><th>{{ t('fields.status') }}</th></tr></thead>
                  <tbody>
                    @for (pickup of pickups(); track pickup.ulid) {
                      <tr>
                        <td><a [routerLink]="['/pickups', pickup.ulid]" class="dw-mono">{{ pickup.pickup_number }}</a></td><td>{{ pickup.provider?.legal_name }}</td><td>{{ pickup.site?.name }}</td>
                        <td>{{ pickup.confirmed_date ?? pickup.requested_date | dwDate }}</td><td class="dw-num">{{ pickup.declared_weight_kg | dwKg }}</td>
                        <td><dw-status kind="pickup" [value]="pickup.status" /></td>
                      </tr>
                    }
                  </tbody>
                </table>
              </section>
            }
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .onboarding { display: flex; align-items: center; gap: var(--dw-3); padding: var(--dw-3) var(--dw-4); margin-block-end: var(--dw-4); color: var(--dw-ink); border-color: var(--dw-primary); background: var(--dw-primary-soft); }
    .onboarding:hover { text-decoration: none; } .onboarding .link { color: var(--dw-primary); font-weight: 600; }
    .attention { list-style: none; margin: 0; padding: 0; }
    .attention a { display: flex; align-items: center; gap: var(--dw-3); padding: 10px var(--dw-4); color: var(--dw-ink); border-block-end: 1px solid var(--dw-line-soft); }
    .attention li:last-child a { border-block-end: 0; }
    .attention a:hover { background: var(--dw-surface-2); text-decoration: none; }
    .attention a > mat-icon:first-child { font-size: 20px; width: 20px; height: 20px; }
    .attention .warning > mat-icon:first-child { color: var(--dw-warning); } .attention .danger > mat-icon:first-child { color: var(--dw-danger); } .attention .info > mat-icon:first-child { color: var(--dw-info); }
    .attention span { flex: 1; } .go { color: var(--dw-ink-3); }
    :host-context([dir='rtl']) .go { transform: scaleX(-1); }
    .none { padding-block: var(--dw-6); text-align: center; }
    .mini { width: 100%; border-collapse: collapse; font-size: 13px; }
    .mini th { text-align: start; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dw-ink-3); padding: 6px 8px; border-block-end: 1px solid var(--dw-line); }
    .mini td { padding: 8px; border-block-end: 1px solid var(--dw-line-soft); }
    .mini.padded th:first-child, .mini.padded td:first-child { padding-inline-start: var(--dw-4); }
    .mini tr:last-child td { border-block-end: 0; }
    .share { width: 32%; } .bar { display: block; height: 8px; border-radius: 4px; background: var(--dw-primary); min-inline-size: 2px; }
  `,
})
export class DashboardPage {
  protected readonly session = inject(Session);
  private readonly api = inject(Api);
  private readonly transloco = inject(TranslocoService);
  private readonly locale = inject(Locale);

  protected readonly period = signal<Period>(presetPeriod('quarter'));
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
      legend: { bottom: 0, icon: 'roundRect' },
      grid: { left: 8, right: 12, top: 16, bottom: 36, containLabel: true },
      xAxis: { type: 'category', data: months.map((month) => label.format(new Date(`${month.month}-01T12:00:00`))), axisTick: { show: false } },
      yAxis: { type: 'value', axisLabel: { formatter: (value: number) => (value >= 1000 ? `${value / 1000} t` : `${value} kg`) }, splitLine: { lineStyle: { color: '#e9edf0' } } },
      series: [
        { name: this.transloco.translate('dashboard.generated'), type: 'bar', barMaxWidth: 28, data: months.map((month) => +month.generated_kg) },
        { name: this.transloco.translate('dashboard.evacuated'), type: 'line', smooth: false, symbolSize: 6, data: months.map((month) => +month.evacuated_kg) },
      ],
    };
  });

  protected readonly channelChart = computed<EChartsCoreOption>(() => ({
    tooltip: { trigger: 'item', valueFormatter: (value: number) => `${new Intl.NumberFormat(this.locale.intl).format(value)} kg` },
    legend: { bottom: 0, icon: 'circle', itemGap: 14 },
    series: [
      {
        // Shares are read in the tooltip and the legend: labels around a donut collide as soon as slices are small.
        type: 'pie', radius: ['50%', '74%'], center: ['50%', '40%'], label: { show: false }, emphasis: { scale: false },
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
      xAxis: { type: 'value', splitLine: { lineStyle: { color: '#e9edf0' } } },
      yAxis: { type: 'category', data: rows.map((row) => row.name), axisTick: { show: false } },
      series: [{ type: 'bar', barMaxWidth: 16, data: rows.map((row) => ({ value: +row.kg, ...(row.is_hazardous ? { itemStyle: { color: '#c9812a' } } : {}) })) }],
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
