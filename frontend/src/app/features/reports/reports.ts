import { ChangeDetectionStrategy, Component, DestroyRef, computed, effect, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';

import { Api, openBlob } from '../../core/api/api';
import { GeneratedReport } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Locale } from '../../core/i18n/i18n';
import { Toast } from '../../core/feedback/toast';
import { DatePipe } from '../../core/format/pipes';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { Period, PeriodFilterComponent, presetPeriod } from '../../ui/period';
import { StateComponent } from '../../ui/state';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';

interface Volume {
  site: string;
  month: string;
  pieces_produced: number;
}

/**
 * M6-04 exports and M6-02 production figures. Reports are built in the background: the list refreshes by itself
 * while one is being generated, and a notification is sent when it is ready.
 */
@Component({
  imports: [
    FormsModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, PeriodFilterComponent, StateComponent, DatePipe, IconComponent, ButtonDirective, InputText, ...FIELD, ...SELECT,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('reports.title')" [subtitle]="t('reports.subtitle')" />

      <div class="dw-stack">
        @if (canExport) {
          <section class="dw-panel">
            <header class="dw-panel-head"><h2 class="dw-h2">{{ t('reports.new') }}</h2></header>
            <div class="dw-panel-body generate">
              <dw-field class="type" [label]="t('reports.type')">
                <dw-select [ngModel]="type()" (ngModelChange)="type.set($event)">
                  @for (item of types; track item) { <dw-option [value]="item" [label]="t('reports.types.' + item)" /> }
                </dw-select>
              </dw-field>
              <dw-field class="format" [label]="t('reports.format')">
                <dw-select [ngModel]="format()" (ngModelChange)="format.set($event)">
                  <dw-option value="pdf" label="PDF" /><dw-option value="xlsx" label="Excel" />
                </dw-select>
              </dw-field>
              <dw-field class="format" [label]="t('reports.language')">
                <dw-select [ngModel]="language()" (ngModelChange)="language.set($event)">
                  @for (item of locale.available; track item.code) { <dw-option [value]="item.code" [label]="item.label" /> }
                </dw-select>
              </dw-field>
              <div class="dw-field period">
                <span class="dw-field-label">{{ t('period.label') }}</span>
                <dw-period-filter [value]="period()" (valueChange)="period.set($event)" permission="reports.export" />
              </div>
              <button pButton type="button" class="go" [disabled]="busy()" (click)="generate()"><dw-icon>play_arrow</dw-icon>{{ t('reports.new') }}</button>
            </div>
          </section>
        }

        <section class="dw-panel">
          <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading() && !polling" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1"
            [pageSize]="list.perPage()" emptyIcon="summarize" [emptyTitle]="t('reports.emptyTitle')" [emptyHint]="t('reports.emptyHint')"
            (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (retry)="list.reload()">
            <ng-template dwCell="type" let-r>{{ t('reports.types.' + r.report_type) }}<div class="dw-faint">{{ r.parameters.from | dwDate }} → {{ r.parameters.to | dwDate }}</div></ng-template>
            <ng-template dwCell="format" let-r>{{ r.format === 'xlsx' ? 'Excel' : 'PDF' }}@if (r.parameters.lang) { <span class="dw-faint"> · {{ r.parameters.lang.toUpperCase() }}</span> }</ng-template>
            <ng-template dwCell="requested" let-r>{{ r.created_at | dwDate: 'datetime' }}</ng-template>
            <ng-template dwCell="expires" let-r>{{ r.expires_at | dwDate }}</ng-template>
            <ng-template dwCell="status" let-r><dw-status kind="report" [value]="r.status" /></ng-template>
            <ng-template dwCell="actions" let-r>
              @if (r.downloadable) { <button pButton severity="secondary" outlined type="button" (click)="download(r)"><dw-icon>download</dw-icon>{{ t('common.download') }}</button> }
            </ng-template>
          </dw-data-table>
        </section>

        @if (productionSites.length > 0) {
          <section class="dw-panel">
            <header class="dw-panel-head">
              <div class="dw-spacer"><h2 class="dw-h2">{{ t('reports.production') }}</h2><p class="dw-muted">{{ t('reports.productionHint') }}</p></div>
              <dw-select class="year" [ariaLabel]="t('compliance.declarations.year')" [placeholder]="t('compliance.declarations.year')" [ngModel]="year()" (ngModelChange)="year.set($event)">
                @for (item of years; track item) { <dw-option [value]="item" [label]="item" /> }
              </dw-select>
            </header>
            <dw-state [loading]="volumesLoading()" [error]="volumesError()" [keepContent]="true" (retry)="loadVolumes()">
              <div class="scroll">
                <table class="dw-table volumes">
                  <thead><tr><th scope="col">{{ t('fields.site') }}</th>@for (month of months(); track month.key) { <th scope="col">{{ month.label }}</th> }</tr></thead>
                  <tbody>
                    @for (site of productionSites; track site.ulid) {
                      <tr>
                        <th scope="row">{{ site.name }}</th>
                        @for (month of months(); track month.key) {
                          <td>
                            <input pInputText inputmode="numeric" [disabled]="month.future || !canEnter(site.ulid)" [value]="volumes()[site.ulid + month.key] ?? ''"
                              (change)="save(site.ulid, month.key, $any($event.target))" [attr.aria-label]="site.name + ' — ' + month.label" />
                          </td>
                        }
                      </tr>
                    }
                  </tbody>
                </table>
              </div>
            </dw-state>
          </section>
        }
      </div>
    </ng-container>
  `,
  styles: `
    .generate { display: flex; align-items: flex-end; gap: var(--dw-3); flex-wrap: wrap; }
    .type { width: 300px; } .format { width: 120px; } .year { width: 120px; }
    .generate .go { min-height: var(--dw-control); }
    .scroll { overflow-x: auto; }
    .volumes thead th { text-align: end; } .volumes thead th:first-child { text-align: start; }
    .volumes tbody th[scope='row'] { text-align: start; font-weight: 500; padding: 4px var(--dw-3); border-block-end: 1px solid var(--dw-line-soft); white-space: nowrap; }
    .volumes td { padding: 4px; }
    .volumes input { width: 100%; min-width: 72px; min-height: 28px; padding: 4px 6px; text-align: end; font-variant-numeric: tabular-nums; }
  `,
})
export class ReportsPage {
  private readonly api = inject(Api);
  protected readonly locale = inject(Locale);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);

  protected readonly canExport = this.session.can('reports.export');
  protected readonly types: GeneratedReport['report_type'][] = ['rse_summary', 'waste_register', 'economic_balance'];
  protected readonly type = signal<GeneratedReport['report_type']>('rse_summary');
  protected readonly format = signal<'pdf' | 'xlsx'>('pdf');
  /** Language of the document: the user's own by default, changeable for this report. */
  protected readonly language = signal(this.locale.documents());
  protected readonly period = signal<Period>(presetPeriod('year'));
  protected readonly busy = signal(false);
  protected polling = false;
  protected readonly list = new ListStore<GeneratedReport>((query) => this.api.list<GeneratedReport>('reports', query));

  protected readonly columns: Column<GeneratedReport>[] = [
    { key: 'type', header: 'reports.type' },
    { key: 'format', header: 'reports.format' },
    { key: 'requested', header: 'reports.requested', hideBelow: 640 },
    { key: 'expires', header: 'reports.expires', hideBelow: 900 },
    { key: 'status', header: 'fields.status' },
    { key: 'actions', header: 'common.actions' },
  ];

  protected readonly productionSites = this.session.sitesFor('dashboard.view');
  protected readonly years = Array.from({ length: 4 }, (_, index) => new Date().getFullYear() - index);
  protected readonly year = signal(this.years[0]);
  protected readonly volumes = signal<Record<string, number | undefined>>({});
  protected readonly volumesLoading = signal(false);
  protected readonly volumesError = signal<unknown>(null);
  protected readonly months = computed(() => {
    const current = new Date().toISOString().slice(0, 7);

    return Array.from({ length: 12 }, (_, index) => {
      const key = `${this.year()}-${String(index + 1).padStart(2, '0')}`;

      return { key, label: new Intl.DateTimeFormat(this.locale.intl, { month: 'short' }).format(new Date(this.year(), index, 1)), future: key > current };
    });
  });

  constructor() {
    // While a report is being built, look again every few seconds; stop as soon as none is pending.
    const timer = setInterval(() => {
      this.polling = this.list.rows().some((report) => report.status === 'queued' || report.status === 'processing');
      if (this.polling) {
        this.list.reload();
      }
    }, 4000);
    inject(DestroyRef).onDestroy(() => clearInterval(timer));

    effect(() => {
      this.year();
      this.loadVolumes();
    });
  }

  protected canEnter(site: string): boolean {
    return this.session.can('production_volumes.manage', site);
  }

  protected async generate(): Promise<void> {
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.post('reports', { report_type: this.type(), format: this.format(), lang: this.language(), ...this.period() }));
      this.toast.success('reports.queued');
      this.list.setPage(1, this.list.perPage());
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected async download(report: GeneratedReport): Promise<void> {
    try {
      const blob = await firstValueFrom(this.api.blob(`reports/${report.ulid}/download`));
      openBlob(blob, `${report.report_type}-${report.parameters.from}-${report.parameters.to}.${report.format}`);
    } catch (error) {
      this.toast.error(error);
    }
  }

  protected loadVolumes(): void {
    if (this.productionSites.length === 0) {
      return;
    }
    this.volumesLoading.set(true);
    this.volumesError.set(null);
    this.api.get<Volume[]>('production-volumes', { year: this.year() }).subscribe({
      next: (rows) => {
        this.volumes.set(Object.fromEntries(rows.map((row) => [row.site + row.month, row.pieces_produced])));
        this.volumesLoading.set(false);
      },
      error: (error: unknown) => {
        this.volumesError.set(error);
        this.volumesLoading.set(false);
      },
    });
  }

  /** Saved cell by cell when the field is left: there is no form to submit and nothing to lose. */
  protected async save(site: string, month: string, input: HTMLInputElement): Promise<void> {
    const key = site + month;
    const text = input.value.replace(/\s/g, '');
    if (!/^\d{1,10}$/.test(text)) {
      input.value = String(this.volumes()[key] ?? '');

      return;
    }
    try {
      await firstValueFrom(this.api.put('production-volumes', { site, month, pieces_produced: Number(text) }));
      this.volumes.update((volumes) => ({ ...volumes, [key]: Number(text) }));
      this.toast.success('reports.productionSaved');
    } catch (error) {
      input.value = String(this.volumes()[key] ?? '');
      this.toast.error(error);
    }
  }
}
