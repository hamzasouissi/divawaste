import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { Tab, TabList, TabPanel, TabPanels, Tabs } from 'primeng/tabs';

import { Api, openBlob } from '../../core/api/api';
import { Localized, Lot } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, KgPipe, LocalizedPipe, MoneyPipe, NumberPipe } from '../../core/format/pipes';
import { DIALOG_PARTS, Dialogs, dialogData } from '../../ui/dialog';
import { IconComponent } from '../../ui/icon';
import { KpiComponent, PageHeaderComponent, StatusComponent } from '../../ui/page';
import { Period, PeriodFilterComponent, presetPeriod } from '../../ui/period';
import { StateComponent } from '../../ui/state';

interface StatRow {
  key: string;
  label: string | Localized;
  sublabel: string | null;
  lots: number;
  measured_kg: string;
  estimated_kg: string;
  total_kg: string;
  pieces: number | null;
  g_per_piece: number | null;
  consumed_kg: string | null;
  waste_rate_pct: number | null;
  net_amount: string;
}

type Section = 'by_order' | 'by_client' | 'by_article' | 'by_step' | 'by_material' | 'by_supplier';

interface Stats extends Record<Section, StatRow[]> {
  period: { from: string; to: string };
  totals: {
    waste_kg: string; measured_kg: string; estimated_kg: string; unattributed_kg: string; unattributed_lots: number;
    lots: number; orders: number; pieces: number | null; g_per_piece: number | null;
  };
}

interface LotsDialogData {
  title: string;
  filter: Record<string, string>;
  period: Period;
}

/** The lots behind a figure: from a manufacturing order or a raw material down to each lot and what became of it. */
@Component({
  imports: [TranslocoDirective, RouterLink, ButtonDirective, StatusComponent, StateComponent, DatePipe, KgPipe, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ data.title }}</dw-dialog-title>
      <div class="dw-dialog-body">
        <dw-state [loading]="loading()" [error]="error()" [empty]="rows().length === 0" [emptyTitle]="t('common.noResult')">
          <table class="dw-table">
            <thead><tr><th>{{ t('lots.number') }}</th><th>{{ t('lots.generatedAt') }}</th><th>{{ t('fields.wasteType') }}</th><th>{{ t('production.of.step') }}</th><th class="dw-num">{{ t('fields.weight') }}</th><th>{{ t('fields.status') }}</th></tr></thead>
            <tbody>
              @for (lot of rows(); track lot.ulid) {
                <tr>
                  <td class="dw-mono"><a [routerLink]="['/lots', lot.ulid]" dwDialogClose>{{ lot.lot_number }}</a></td>
                  <td>{{ lot.generated_at | dwDate }}</td><td>{{ lot.waste_type?.name }}</td><td>{{ lot.origin?.step ? pick(lot.origin?.step?.name) : '—' }}</td>
                  <td class="dw-num">{{ lot.net_weight_kg | dwKg }}</td><td><dw-status kind="lot" [value]="lot.status" /></td>
                </tr>
              }
            </tbody>
          </table>
        </dw-state>
      </div>
      <div class="dw-dialog-foot"><button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.close') }}</button></div>
    </ng-container>
  `,
  styles: '.dw-dialog-body { inline-size: min(760px, 90vw); max-block-size: 60vh; overflow: auto; }',
})
export class OriginLotsDialog {
  protected readonly data = dialogData<LotsDialogData>();
  private readonly localized = new LocalizedPipe();
  protected readonly rows = signal<Lot[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);

  constructor() {
    inject(Api).list<Lot>('lots', { filter: { ...this.data.filter, from: this.data.period.from, to: this.data.period.to }, per_page: 100, sort: '-generated_at' }).subscribe({
      next: (page) => {
        this.rows.set(page.data);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected pick(name: Localized | undefined): string {
    return name ? this.localized.transform(name) : '—';
  }
}

/**
 * Where the waste of a period comes from: by manufacturing order, client, article, stage, raw material and supplier.
 * Measured figures (the lot names its order) and estimated ones (the lot only says its stage; its weight is shared
 * between the orders at that stage that day) are always shown apart.
 */
@Component({
  imports: [
    TranslocoDirective, PageHeaderComponent, KpiComponent, StateComponent, PeriodFilterComponent, ButtonDirective, IconComponent, KgPipe, NumberPipe, MoneyPipe, LocalizedPipe,
    Tabs, TabList, Tab, TabPanels, TabPanel,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('production.stats.title')" [subtitle]="t('production.stats.subtitle')">
        <dw-period-filter [(value)]="period" permission="sites.view" (changed)="load()" />
        <button pButton severity="secondary" outlined type="button" [disabled]="exporting()" (click)="export()"><dw-icon>download</dw-icon>{{ t('production.stats.export') }}</button>
      </dw-page-header>

      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (stats(); as s) {
          <div class="dw-stack">
            <div class="dw-stats">
              <dw-kpi [label]="t('production.stats.waste')" [value]="s.totals.waste_kg | dwKg: 'auto'" [hint]="t('dashboard.lots', { count: s.totals.lots })" />
              <dw-kpi [label]="t('production.stats.measured')" [value]="s.totals.measured_kg | dwKg: 'auto'" [hint]="t('production.stats.measuredHint')" tone="success" />
              <dw-kpi [label]="t('production.stats.estimated')" [value]="s.totals.estimated_kg | dwKg: 'auto'" [hint]="t('production.stats.estimatedHint')" />
              <dw-kpi [label]="t('production.stats.unattributed')" [value]="s.totals.unattributed_kg | dwKg: 'auto'" [hint]="t('dashboard.lots', { count: s.totals.unattributed_lots })"
                [tone]="+s.totals.unattributed_kg > 0 ? 'warning' : 'neutral'" />
              <dw-kpi [label]="t('production.stats.perPiece')" [value]="s.totals.g_per_piece === null ? '—' : (s.totals.g_per_piece | dwNumber: 1) + ' g'"
                [hint]="s.totals.pieces === null ? t('production.stats.noPieces') : t('production.stats.pieces', { count: (s.totals.pieces | dwNumber) })" />
            </div>

            <section class="dw-panel">
              <p-tabs [value]="section()" (valueChange)="section.set($any($event))">
                <p-tablist>@for (item of sections; track item) { <p-tab [value]="item">{{ t('production.stats.sections.' + item) }} <span class="count">{{ s[item].length }}</span></p-tab> }</p-tablist>
                <p-tabpanels>
                  @for (item of sections; track item) {
                    <p-tabpanel [value]="item">
                      @if (s[item].length === 0) {
                        <p class="dw-panel-body dw-muted">{{ t('production.stats.empty') }}</p>
                      } @else {
                        <div class="scroll">
                          <table class="dw-table">
                            <thead>
                              <tr>
                                <th>{{ t('production.stats.sections.' + item) }}</th><th class="dw-num">{{ t('production.stats.lots') }}</th>
                                <th class="dw-num">{{ t('production.stats.measured') }}</th><th class="dw-num">{{ t('production.stats.estimated') }}</th><th>{{ t('production.stats.total') }}</th>
                                <th class="dw-num">{{ t('production.stats.piecesShort') }}</th><th class="dw-num">{{ t('production.stats.perPiece') }}</th>
                                <th class="dw-num" [title]="t('production.stats.rateHint')">{{ t('production.stats.rate') }}</th><th class="dw-num">{{ t('production.stats.balance') }}</th>
                              </tr>
                            </thead>
                            <tbody>
                              @for (row of s[item]; track row.key) {
                                <tr [class.clickable]="drillable(item)" (click)="lots(item, row)">
                                  <td><strong>{{ isText(row.label) ? row.label : ($any(row.label) | dwLocalized) }}</strong>@if (row.sublabel) { <div class="dw-faint">{{ row.sublabel }}</div> }</td>
                                  <td class="dw-num">{{ row.lots | dwNumber }}</td>
                                  <td class="dw-num">{{ row.measured_kg | dwKg }}</td>
                                  <td class="dw-num dw-faint">{{ +row.estimated_kg > 0 ? '≈ ' + (row.estimated_kg | dwKg) : '—' }}</td>
                                  <td>
                                    <div class="bar" [attr.aria-label]="row.total_kg | dwKg"><span class="m" [style.inline-size.%]="share(row.measured_kg, s[item])"></span><span class="e" [style.inline-size.%]="share(row.estimated_kg, s[item])"></span></div>
                                    <strong>{{ row.total_kg | dwKg }}</strong>
                                  </td>
                                  <td class="dw-num">{{ row.pieces | dwNumber }}</td>
                                  <td class="dw-num">{{ row.g_per_piece === null ? '—' : (row.g_per_piece | dwNumber: 1) + ' g' }}</td>
                                  <td class="dw-num">{{ row.waste_rate_pct === null ? '—' : (row.waste_rate_pct | dwNumber: 1) + ' %' }}</td>
                                  <td class="dw-num" [class.neg]="+row.net_amount < 0">{{ +row.net_amount === 0 ? '—' : (row.net_amount | dwMoney: currency) }}</td>
                                </tr>
                              }
                            </tbody>
                          </table>
                        </div>
                      }
                    </p-tabpanel>
                  }
                </p-tabpanels>
              </p-tabs>
            </section>
            <p class="dw-muted method">{{ t('production.stats.method') }}</p>
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .count { margin-inline-start: 4px; padding: 0 6px; border-radius: 999px; background: var(--dw-surface-2); font-size: 11.5px; color: var(--dw-ink-2); }
    .scroll { overflow-x: auto; }
    tr.clickable { cursor: pointer; } tr.clickable:hover td { background: var(--dw-surface-2); }
    .bar { display: flex; inline-size: 140px; height: 8px; border-radius: 4px; background: var(--dw-line-soft); overflow: hidden; margin-block-end: 2px; }
    .bar .m { background: var(--dw-primary); } .bar .e { background: repeating-linear-gradient(45deg, var(--dw-primary) 0 3px, transparent 3px 6px); opacity: 0.55; }
    .neg { color: var(--dw-danger); }
    .method { font-size: 12.5px; max-inline-size: 900px; }
  `,
})
export class ProductionStatsPage {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly dialog = inject(Dialogs);

  protected readonly sections: Section[] = ['by_order', 'by_client', 'by_article', 'by_step', 'by_material', 'by_supplier'];
  protected readonly currency = inject(Session).company()?.currency_code ?? 'TND';
  protected readonly period = signal<Period>(presetPeriod('quarter'));
  protected readonly section = signal<Section>('by_order');
  protected readonly stats = signal<Stats | null>(null);
  protected readonly loading = signal(true);
  protected readonly exporting = signal(false);
  protected readonly error = signal<unknown>(null);
  private readonly query = computed(() => ({ from: this.period().from, to: this.period().to, site: this.period().site }));

  constructor() {
    this.load();
  }

  protected isText(label: unknown): label is string {
    return typeof label === 'string';
  }

  /** Width of a bar, relative to the largest row of the table. */
  protected share(kg: string, rows: StatRow[]): number {
    const max = Math.max(...rows.map((row) => Number(row.total_kg)), 0.001);

    return (Number(kg) / max) * 100;
  }

  protected drillable(section: Section): boolean {
    return section === 'by_order' || section === 'by_material';
  }

  /** The lots that name this order or this material (the measured part of the figure). */
  protected lots(section: Section, row: StatRow): void {
    if (this.drillable(section) && typeof row.label === 'string') {
      this.dialog.open<OriginLotsDialog, LotsDialogData>(OriginLotsDialog, { data: { title: row.label, filter: { [section === 'by_order' ? 'production_order' : 'raw_material']: row.key }, period: this.period() } });
    }
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get<Stats>('production/stats', this.query()).subscribe({
      next: (stats) => {
        this.stats.set(stats);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected async export(): Promise<void> {
    this.exporting.set(true);
    try {
      openBlob(await firstValueFrom(this.api.blob('production/stats/export', this.query())), `production-${this.period().from}-${this.period().to}.xlsx`);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.exporting.set(false);
    }
  }
}
