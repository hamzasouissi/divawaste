import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { Tab, TabList, TabPanel, TabPanels, Tabs } from 'primeng/tabs';

import { Api, openBlob } from '../../core/api/api';
import { Toast } from '../../core/feedback/toast';
import { MoneyPipe, NumberPipe } from '../../core/format/pipes';
import { HelpComponent } from '../../ui/help';
import { IconComponent } from '../../ui/icon';
import { KpiComponent, PageHeaderComponent, StatusComponent } from '../../ui/page';
import { Period, PeriodFilterComponent, presetPeriod } from '../../ui/period';
import { StateComponent } from '../../ui/state';
import { OfStatus, statusTone } from './production-data';

interface FinanceRow {
  ulid: string; order_number: string; status: OfStatus; article: string; client: string | null; customer_order: string | null; quantity_planned: number; quantity_produced: number | null;
  planned_material_cost: string | null; material_cost_known: string; material_cost_estimated: string; expenses: string; total_cost: string; cost_complete: boolean; unit_cost: string | null;
  unit_price: string | null; produced_value: string | null; invoiced: string | null; gain: string | null; margin_pct: number | null; estimated: boolean; missing: string[];
}

interface FinanceReport {
  period: { from: string; to: string };
  currency: string;
  totals: { orders: number; material_cost_known: string; material_cost_estimated: string; expenses: string; total_cost: string; planned_material_cost: string | null; produced_value: string | null;
    invoiced: string | null; gain: string | null; gain_orders: number; incomplete_orders: number };
  orders: FinanceRow[];
  by_client: { client: string | null; orders: number; quantity_produced: number; total_cost: string; produced_value: string | null; gain: string | null; gain_orders: number }[];
  by_material: { material: string; reference: string; name: string; unit: string; planned: string | null; consumed: string; allocated: string; unallocated: string; cost_known: string; cost_estimated: string;
    unknown_quantity: string; variance: string | null; variance_pct: number | null }[];
  by_supplier: { supplier: string | null; lines: number; lots: number; cost: string; unpriced_lines: number }[];
  missing: Record<string, number>;
}

/**
 * Costs and gains of the manufacturing orders of a period. Every total says what it leaves out: a gain is added up
 * only for the orders where it could be calculated, and what is missing for the others is listed.
 */
@Component({
  imports: [
    RouterLink, HelpComponent, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, KpiComponent, PeriodFilterComponent, ButtonDirective, IconComponent, NumberPipe, MoneyPipe,
    Tabs, TabList, Tab, TabPanels, TabPanel,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('production.report.title')" [subtitle]="t('production.report.subtitle')">
        <button pButton severity="secondary" outlined type="button" [disabled]="exporting()" (click)="export()"><dw-icon>download</dw-icon>{{ t('production.report.export') }}</button>
      </dw-page-header>
      <dw-help topic="productionFinance" />
      <div class="filters"><dw-period-filter [(value)]="period" permission="production.finance.view" (changed)="load()" /></div>

      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (report(); as r) {
          <div class="dw-grid-4 kpis">
            <dw-kpi [label]="t('production.finance.totalCost')" [value]="r.totals.total_cost | dwMoney: r.currency"
              [hint]="t('production.report.costSplit', { material: (add(r.totals.material_cost_known, r.totals.material_cost_estimated) | dwMoney: r.currency), expenses: (r.totals.expenses | dwMoney: r.currency) })" />
            <dw-kpi [label]="t('production.finance.producedValue')" [value]="r.totals.produced_value === null ? '—' : (r.totals.produced_value | dwMoney: r.currency)" [hint]="t('production.finance.producedValueHint')" />
            <dw-kpi [label]="t('production.finance.invoiced')" [value]="r.totals.invoiced === null ? '—' : (r.totals.invoiced | dwMoney: r.currency)" [hint]="t('production.report.invoicedHint')" />
            <dw-kpi [label]="t('production.finance.gain')" [value]="r.totals.gain === null ? t('production.finance.notCalculable') : (r.totals.gain | dwMoney: r.currency)"
              [tone]="r.totals.incomplete_orders > 0 ? 'warning' : (r.totals.gain !== null && +r.totals.gain < 0 ? 'danger' : 'success')"
              [hint]="t('production.report.gainHint', { counted: r.totals.gain_orders, total: r.totals.orders })" />
          </div>

          @if (missingKeys(r).length > 0) {
            <aside class="missing" role="note">
              <dw-icon>info</dw-icon>
              <div><strong>{{ t('production.report.missingTitle') }}</strong>
                <ul>@for (key of missingKeys(r); track key) { <li>{{ t('production.report.missingLine', { count: r.missing[key], reason: t('production.missing.' + key) }) }}</li> }</ul></div>
            </aside>
          }

          <div class="dw-panel">
            <p-tabs [value]="0">
              <p-tablist>
                <p-tab [value]="0">{{ t('production.report.byOrder') }} ({{ r.orders.length }})</p-tab>
                <p-tab [value]="1">{{ t('production.report.byClient') }}</p-tab>
                <p-tab [value]="2">{{ t('production.report.byMaterial') }}</p-tab>
                <p-tab [value]="3">{{ t('production.report.bySupplier') }}</p-tab>
              </p-tablist>
              <p-tabpanels>
                <p-tabpanel [value]="0">
                  @if (r.orders.length === 0) { <p class="empty">{{ t('production.report.empty') }}</p> } @else {
                    <div class="scroll">
                      <table class="dw-table">
                        <thead><tr><th>{{ t('production.of.one') }}</th><th>{{ t('production.clients.one') }}</th><th class="num">{{ t('production.of.producedPlanned') }}</th>
                          <th class="num">{{ t('production.report.material') }}</th><th class="num">{{ t('production.expenses.title') }}</th><th class="num">{{ t('production.finance.totalCost') }}</th>
                          <th class="num">{{ t('production.finance.unitCost') }}</th><th class="num">{{ t('production.finance.producedValue') }}</th><th class="num">{{ t('production.finance.gain') }}</th></tr></thead>
                        <tbody>
                          @for (row of r.orders; track row.ulid) {
                            <tr>
                              <td><a class="dw-mono" [routerLink]="['/production/manufacturing-orders', row.ulid]">{{ row.order_number }}</a> <dw-status kind="accreditation" [value]="tone(row.status)" [label]="t('production.ofStatus.' + row.status)" />
                                <div class="dw-faint">{{ row.article }}</div></td>
                              <td>{{ row.client ?? '—' }}<div class="dw-faint dw-mono">{{ row.customer_order }}</div></td>
                              <td class="num">{{ row.quantity_produced === null ? '—' : (row.quantity_produced | dwNumber) }} / {{ row.quantity_planned | dwNumber }}</td>
                              <td class="num">{{ add(row.material_cost_known, row.material_cost_estimated) | dwMoney: r.currency }}
                                @if (+row.material_cost_estimated > 0) { <div class="dw-faint">{{ t('production.report.ofWhichEstimated', { amount: (row.material_cost_estimated | dwMoney: r.currency) }) }}</div> }</td>
                              <td class="num">{{ row.expenses | dwMoney: r.currency }}</td>
                              <td class="num"><strong>{{ row.total_cost | dwMoney: r.currency }}</strong>@if (!row.cost_complete) { <div class="warn">{{ t('production.finance.incomplete') }}</div> }</td>
                              <td class="num">{{ row.unit_cost === null ? '—' : (row.unit_cost | dwMoney: r.currency) }}</td>
                              <td class="num">{{ row.produced_value === null ? '—' : (row.produced_value | dwMoney: r.currency) }}</td>
                              <td class="num">
                                @if (row.gain === null) { <span class="warn" [title]="reasons(row.missing)">{{ t('production.finance.notCalculable') }}</span><div class="dw-faint why">{{ reasons(row.missing) }}</div> }
                                @else { <strong [class.bad]="+row.gain < 0" [class.good]="+row.gain >= 0">{{ row.gain | dwMoney: r.currency }}</strong><div class="dw-faint">{{ row.margin_pct }} %@if (row.estimated) { · {{ t('production.estimated') }} }</div> }
                              </td>
                            </tr>
                          }
                        </tbody>
                      </table>
                    </div>
                  }
                </p-tabpanel>
                <p-tabpanel [value]="1">
                  <div class="scroll">
                    <table class="dw-table">
                      <thead><tr><th>{{ t('production.clients.one') }}</th><th class="num">{{ t('production.of.title') }}</th><th class="num">{{ t('production.report.produced') }}</th>
                        <th class="num">{{ t('production.finance.totalCost') }}</th><th class="num">{{ t('production.finance.producedValue') }}</th><th class="num">{{ t('production.finance.gain') }}</th></tr></thead>
                      <tbody>
                        @for (row of r.by_client; track $index) {
                          <tr><td>{{ row.client ?? t('production.report.noClient') }}</td><td class="num">{{ row.orders }}</td><td class="num">{{ row.quantity_produced | dwNumber }}</td>
                            <td class="num">{{ row.total_cost | dwMoney: r.currency }}</td><td class="num">{{ row.produced_value === null ? '—' : (row.produced_value | dwMoney: r.currency) }}</td>
                            <td class="num">{{ row.gain === null ? t('production.finance.notCalculable') : (row.gain | dwMoney: r.currency) }}
                              @if (row.gain_orders < row.orders) { <div class="dw-faint">{{ t('production.report.gainHint', { counted: row.gain_orders, total: row.orders }) }}</div> }</td></tr>
                        } @empty { <tr><td colspan="6" class="empty">{{ t('production.report.empty') }}</td></tr> }
                      </tbody>
                    </table>
                  </div>
                </p-tabpanel>
                <p-tabpanel [value]="2">
                  <p class="dw-muted intro">{{ t('production.report.materialIntro') }}</p>
                  <div class="scroll">
                    <table class="dw-table">
                      <thead><tr><th>{{ t('production.materials.one') }}</th><th class="num">{{ t('production.consumed.planned') }}</th><th class="num">{{ t('production.consumed.actual') }}</th>
                        <th class="num">{{ t('production.consumed.variance') }}</th><th class="num">{{ t('production.report.unallocated') }}</th><th class="num">{{ t('production.cost') }}</th></tr></thead>
                      <tbody>
                        @for (row of r.by_material; track row.material) {
                          <tr><td><a [routerLink]="['/production/catalog', row.material]"><strong class="dw-mono">{{ row.reference }}</strong></a> {{ row.name }}</td>
                            <td class="num">{{ row.planned === null ? '—' : (row.planned | dwNumber: 1) + ' ' + row.unit }}</td><td class="num">{{ row.consumed | dwNumber: 1 }} {{ row.unit }}</td>
                            <td class="num" [class.bad]="(row.variance_pct ?? 0) > 0" [class.good]="(row.variance_pct ?? 0) < 0">{{ row.variance_pct === null ? '—' : (row.variance_pct > 0 ? '+' : '') + row.variance_pct + ' %' }}</td>
                            <td class="num" [class.warn]="+row.unallocated > 0">{{ row.unallocated | dwNumber: 1 }} {{ row.unit }}</td>
                            <td class="num">{{ add(row.cost_known, row.cost_estimated) | dwMoney: r.currency }}@if (+row.unknown_quantity > 0) { <div class="warn">{{ t('production.report.unknownQuantity', { quantity: (row.unknown_quantity | dwNumber: 1), unit: row.unit }) }}</div> }</td></tr>
                        } @empty { <tr><td colspan="6" class="empty">{{ t('production.report.empty') }}</td></tr> }
                      </tbody>
                    </table>
                  </div>
                </p-tabpanel>
                <p-tabpanel [value]="3">
                  <p class="dw-muted intro">{{ t('production.report.supplierIntro') }}</p>
                  <div class="scroll">
                    <table class="dw-table">
                      <thead><tr><th>{{ t('production.suppliers.one') }}</th><th class="num">{{ t('production.received.title') }}</th><th class="num">{{ t('production.report.lines') }}</th><th class="num">{{ t('production.report.consumedValue') }}</th></tr></thead>
                      <tbody>
                        @for (row of r.by_supplier; track $index) {
                          <tr><td>{{ row.supplier ?? t('production.report.noLot') }}</td><td class="num">{{ row.lots }}</td><td class="num">{{ row.lines }}</td>
                            <td class="num">{{ row.cost | dwMoney: r.currency }}@if (row.unpriced_lines > 0) { <div class="warn">{{ t('production.report.unpricedLines', { count: row.unpriced_lines }) }}</div> }</td></tr>
                        } @empty { <tr><td colspan="4" class="empty">{{ t('production.report.empty') }}</td></tr> }
                      </tbody>
                    </table>
                  </div>
                </p-tabpanel>
              </p-tabpanels>
            </p-tabs>
          </div>
          <p class="dw-faint method">{{ t('production.report.method') }}</p>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .filters { margin-block-end: var(--dw-4); } .kpis { margin-block-end: var(--dw-4); }
    .missing { display: flex; gap: var(--dw-3); margin-block-end: var(--dw-4); padding: var(--dw-3) var(--dw-4); border: 1px solid var(--dw-warning); border-radius: var(--dw-radius); background: var(--dw-warning-soft, var(--dw-surface-2)); }
    .missing dw-icon { color: var(--dw-warning); } .missing ul { margin: 4px 0 0; padding-inline-start: 18px; font-size: 13px; }
    .scroll { overflow-x: auto; } table { inline-size: 100%; } .num { text-align: end; white-space: nowrap; }
    .intro { margin: 0; padding: var(--dw-3) var(--dw-4); font-size: 13px; border-block-end: 1px solid var(--dw-line-soft); }
    .empty { margin: 0; padding: var(--dw-5) var(--dw-4); text-align: center; color: var(--dw-ink-3); }
    .warn { color: var(--dw-warning); font-size: 12px; } td.warn { font-size: inherit; } .bad { color: var(--dw-danger); } .good { color: var(--dw-success); }
    .why { white-space: normal; max-inline-size: 22ch; margin-inline-start: auto; font-size: 11.5px; }
    .method { margin: var(--dw-3) 0 0; font-size: 12.5px; max-inline-size: 110ch; }
  `,
})
export class ProductionFinancePage {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly transloco = inject(TranslocoService);

  protected readonly tone = statusTone;
  protected readonly period = signal<Period>(presetPeriod('year'));
  protected readonly report = signal<FinanceReport | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly exporting = signal(false);

  constructor() {
    this.load();
  }

  protected add(a: string, b: string): number {
    return Number(a) + Number(b);
  }

  protected missingKeys(report: FinanceReport): string[] {
    return Object.keys(report.missing);
  }

  protected reasons(missing: string[]): string {
    return missing.map((reason) => this.transloco.translate('production.missing.' + reason)).join(' · ');
  }

  private query(): { from: string; to: string } {
    return { from: this.period().from, to: this.period().to };
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get<FinanceReport>('production/reports/finance', this.query()).subscribe({
      next: (report) => {
        this.report.set(report);
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
      openBlob(await firstValueFrom(this.api.blob('production/reports/finance/export', this.query())), `couts-gains-${this.period().from}-${this.period().to}.xlsx`);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.exporting.set(false);
    }
  }
}
