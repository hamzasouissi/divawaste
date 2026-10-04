import { ChangeDetectionStrategy, Component, DestroyRef, computed, effect, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';

import { Api, openBlob } from '../../core/api/api';
import { GeneratedReport } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe } from '../../core/format/pipes';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { Period, PeriodFilterComponent, presetPeriod } from '../../ui/period';
import { StateComponent } from '../../ui/state';

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
  imports: [FormsModule, MatButtonModule, MatFormFieldModule, MatIconModule, MatSelectModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, PeriodFilterComponent,
    StateComponent, DatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('reports.title')" [subtitle]="t('reports.subtitle')" />

      <div class="dw-stack">
        @if (canExport) {
          <section class="dw-panel">
            <header class="dw-panel-head"><h2 class="dw-h2">{{ t('reports.new') }}</h2></header>
            <div class="dw-panel-body generate">
              <mat-form-field class="type">
                <mat-label>{{ t('reports.type') }}</mat-label>
                <mat-select [ngModel]="type()" (ngModelChange)="type.set($event)">
                  @for (item of types; track item) { <mat-option [value]="item">{{ t('reports.types.' + item) }}</mat-option> }
                </mat-select>
              </mat-form-field>
              <mat-form-field class="format">
                <mat-label>{{ t('reports.format') }}</mat-label>
                <mat-select [ngModel]="format()" (ngModelChange)="format.set($event)">
                  <mat-option value="pdf">PDF</mat-option><mat-option value="xlsx">Excel</mat-option>
                </mat-select>
              </mat-form-field>
              <dw-period-filter [value]="period()" (valueChange)="period.set($event)" permission="reports.export" />
              <button mat-flat-button type="button" [disabled]="busy()" (click)="generate()"><mat-icon>play_arrow</mat-icon>{{ t('reports.new') }}</button>
            </div>
          </section>
        }

        <section class="dw-panel">
          <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading() && !polling" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1"
            [pageSize]="list.perPage()" emptyIcon="summarize" [emptyTitle]="t('reports.emptyTitle')" [emptyHint]="t('reports.emptyHint')"
            (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (retry)="list.reload()">
            <ng-template dwCell="type" let-r>{{ t('reports.types.' + r.report_type) }}<div class="dw-faint">{{ r.parameters.from | dwDate }} → {{ r.parameters.to | dwDate }}</div></ng-template>
            <ng-template dwCell="format" let-r><span class="dw-mono">{{ r.format === 'xlsx' ? 'Excel' : 'PDF' }}</span></ng-template>
            <ng-template dwCell="requested" let-r>{{ r.created_at | dwDate: 'datetime' }}</ng-template>
            <ng-template dwCell="expires" let-r>{{ r.expires_at | dwDate }}</ng-template>
            <ng-template dwCell="status" let-r><dw-status kind="report" [value]="r.status" /></ng-template>
            <ng-template dwCell="actions" let-r>
              @if (r.downloadable) { <button mat-stroked-button type="button" (click)="download(r)"><mat-icon>download</mat-icon>{{ t('common.download') }}</button> }
            </ng-template>
          </dw-data-table>
        </section>

        @if (productionSites.length > 0) {
          <section class="dw-panel">
            <header class="dw-panel-head">
              <div class="dw-spacer"><h2 class="dw-h2">{{ t('reports.production') }}</h2><p class="dw-muted">{{ t('reports.productionHint') }}</p></div>
              <mat-form-field class="year">
                <mat-label>{{ t('compliance.declarations.year') }}</mat-label>
                <mat-select [ngModel]="year()" (ngModelChange)="year.set($event)">
                  @for (item of years; track item) { <mat-option [value]="item">{{ item }}</mat-option> }
                </mat-select>
              </mat-form-field>
            </header>
            <dw-state [loading]="volumesLoading()" [error]="volumesError()" [keepContent]="true" (retry)="loadVolumes()">
              <div class="scroll">
                <table class="volumes">
                  <thead><tr><th scope="col">{{ t('fields.site') }}</th>@for (month of months(); track month.key) { <th scope="col">{{ month.label }}</th> }</tr></thead>
                  <tbody>
                    @for (site of productionSites; track site.ulid) {
                      <tr>
                        <th scope="row">{{ site.name }}</th>
                        @for (month of months(); track month.key) {
                          <td>
                            <input inputmode="numeric" [disabled]="month.future || !canEnter(site.ulid)" [value]="volumes()[site.ulid + month.key] ?? ''"
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
    .generate { display: flex; align-items: center; gap: var(--dw-2) var(--dw-3); flex-wrap: wrap; }
    .type { width: 300px; } .format { width: 120px; } .year { width: 120px; }
    .scroll { overflow-x: auto; }
    .volumes { border-collapse: collapse; font-size: 13px; width: 100%; }
    .volumes th { padding: 8px var(--dw-3); font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dw-ink-2); text-align: end; white-space: nowrap; }
    .volumes th[scope='row'], .volumes thead th:first-child { text-align: start; text-transform: none; font-size: 13px; letter-spacing: 0; color: var(--dw-ink); }
    .volumes td { padding: 4px; border-block-start: 1px solid var(--dw-line-soft); }
    .volumes input { width: 100%; min-width: 72px; height: 30px; padding-inline: 6px; text-align: end; font: inherit; font-variant-numeric: tabular-nums; border: 1px solid var(--dw-line); border-radius: var(--dw-radius); background: var(--dw-surface); }
    .volumes input:focus { outline: 2px solid var(--dw-primary); outline-offset: -1px; }
    .volumes input:disabled { background: var(--dw-surface-2); color: var(--dw-ink-3); }
  `,
})
export class ReportsPage {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);

  protected readonly canExport = this.session.can('reports.export');
  protected readonly types: GeneratedReport['report_type'][] = ['rse_summary', 'waste_register', 'economic_balance'];
  protected readonly type = signal<GeneratedReport['report_type']>('rse_summary');
  protected readonly format = signal<'pdf' | 'xlsx'>('pdf');
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

      return { key, label: new Intl.DateTimeFormat('fr', { month: 'short' }).format(new Date(this.year(), index, 1)), future: key > current };
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
      await firstValueFrom(this.api.post('reports', { report_type: this.type(), format: this.format(), ...this.period() }));
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
