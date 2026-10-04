import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatMenuModule } from '@angular/material/menu';
import { MatSelectModule } from '@angular/material/select';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';

import { Api, openBlob } from '../../core/api/api';
import { Declaration, DocumentRecord, Manifest, RegisterRow } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, KgPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { KpiComponent, PageHeaderComponent, StatusComponent } from '../../ui/page';
import { Period, PeriodFilterComponent, presetPeriod } from '../../ui/period';

const FILTERS = `
  .filters { display: flex; align-items: center; gap: var(--dw-2) var(--dw-3); flex-wrap: wrap; padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }
  .filters mat-form-field { width: 220px; }
`;

/**
 * M5-01: the chronological register. Nothing is typed here: each line is a lot, with its exit when it has left
 * the site. Exports are generated in the background and delivered on the Reports screen.
 */
@Component({
  imports: [MatButtonModule, MatCheckboxModule, MatIconModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, PeriodFilterComponent, DatePipe, KgPipe, RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('compliance.register.title')" [subtitle]="t('compliance.register.subtitle')">
        @if (canExport) {
          <button mat-stroked-button type="button" [disabled]="exporting()" (click)="export('xlsx')"><mat-icon>table_view</mat-icon>{{ t('compliance.register.exportXlsx') }}</button>
          <button mat-flat-button type="button" [disabled]="exporting()" (click)="export('pdf')"><mat-icon>picture_as_pdf</mat-icon>{{ t('compliance.register.exportPdf') }}</button>
        }
      </dw-page-header>

      <div class="dw-panel">
        <div class="filters">
          <dw-period-filter [value]="period()" permission="documents.view" (changed)="setPeriod($event)" />
          <mat-checkbox [checked]="hazardous()" (change)="setHazardous($event.checked)">{{ t('lots.hazardousOnly') }}</mat-checkbox>
        </div>
        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1"
          [pageSize]="list.perPage()" [trackBy]="track" emptyIcon="menu_book" [emptyTitle]="t('compliance.register.emptyTitle')"
          (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (retry)="list.reload()">
          <ng-template dwCell="date" let-row>{{ row.generated_at | dwDate: 'datetime' }}</ng-template>
          <ng-template dwCell="lot" let-row><a class="dw-mono" [routerLink]="['/lots', row.lot]">{{ row.lot_number }}</a></ng-template>
          <ng-template dwCell="type" let-row>
            {{ row.waste_type }} @if (row.is_hazardous) { <mat-icon class="hazard" [title]="t('common.hazardous')" [attr.aria-label]="t('common.hazardous')">warning</mat-icon> }
          </ng-template>
          <ng-template dwCell="weight" let-row>{{ row.net_weight_kg | dwKg }}</ng-template>
          <ng-template dwCell="status" let-row><dw-status kind="lot" [value]="row.status" /></ng-template>
          <ng-template dwCell="exit" let-row>
            @if (row.pickup_number) { <span class="dw-mono">{{ row.pickup_number }}</span> <span class="dw-faint"> · {{ row.collected_at | dwDate }}</span> } @else { — }
          </ng-template>
          <ng-template dwCell="channel" let-row>{{ row.channel ? t('channels.' + row.channel) : '—' }}</ng-template>
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: FILTERS + '.hazard { font-size: 15px; width: 15px; height: 15px; color: var(--dw-warning); vertical-align: -3px; }',
})
export class RegisterPage {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);

  protected readonly canExport = this.session.can('reports.export');
  protected readonly period = signal<Period>(presetPeriod('year'));
  protected readonly hazardous = signal(false);
  protected readonly exporting = signal(false);
  protected readonly track = (row: RegisterRow): string => row.lot;
  protected readonly list = new ListStore<RegisterRow>(
    (query) => this.api.list<RegisterRow>('register', { ...this.period(), hazardous: this.hazardous() ? true : null, page: query['page'] as number, per_page: query['per_page'] as number }),
    { perPage: 50 },
  );

  protected readonly columns: Column<RegisterRow>[] = [
    { key: 'date', header: 'lots.generatedAt' },
    { key: 'lot', header: 'lots.number' },
    { key: 'site', header: 'fields.site', value: (row) => `${row.site} · ${row.zone}`, hideBelow: 1200 },
    { key: 'type', header: 'fields.wasteType' },
    { key: 'code', header: 'lots.regulatoryCode', value: (row) => row.regulatory_code, hideBelow: 900 },
    { key: 'weight', header: 'fields.weight', align: 'end' },
    { key: 'status', header: 'fields.status', hideBelow: 640 },
    { key: 'exit', header: 'compliance.register.exit', hideBelow: 900 },
    { key: 'provider', header: 'pickups.provider', value: (row) => row.provider, hideBelow: 1200 },
    { key: 'channel', header: 'pickups.channel', hideBelow: 1200 },
  ];

  protected setPeriod(period: Period): void {
    this.period.set(period);
    this.list.setPage(1, this.list.perPage());
  }

  protected setHazardous(value: boolean): void {
    this.hazardous.set(value);
    this.list.setPage(1, this.list.perPage());
  }

  protected async export(format: 'pdf' | 'xlsx'): Promise<void> {
    this.exporting.set(true);
    try {
      await firstValueFrom(this.api.post('reports', { report_type: 'waste_register', format, ...this.period() }));
      this.toast.info('compliance.register.exportQueued');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.exporting.set(false);
    }
  }
}

/**
 * M5-02: tracking slips of hazardous waste. Same screen for the three parties; each one signs when its turn
 * comes, with its password (the API checks both the turn and the password).
 */
@Component({
  imports: [MatButtonModule, MatFormFieldModule, MatIconModule, MatSelectModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, DatePipe, KgPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('compliance.manifests.title')" [subtitle]="t('compliance.manifests.subtitle')" />
      <div class="dw-panel">
        <div class="filters">
          <mat-form-field>
            <mat-label>{{ t('fields.status') }}</mat-label>
            <mat-select [value]="list.filter().status ?? ''" (selectionChange)="list.setFilter({ status: $event.value || null })">
              <mat-option value="">{{ t('common.all') }}</mat-option>
              @for (status of statuses; track status) { <mat-option [value]="status">{{ t('status.manifest.' + status) }}</mat-option> }
            </mat-select>
          </mat-form-field>
        </div>
        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1"
          [pageSize]="list.perPage()" emptyIcon="assignment_late" [emptyTitle]="list.filtered() ? t('common.noResult') : t('compliance.manifests.emptyTitle')"
          [emptyHint]="list.filtered() ? '' : t('compliance.manifests.emptyHint')" (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (retry)="list.reload()">
          <ng-template dwCell="number" let-m><span class="dw-mono">{{ m.manifest_number }}</span><div class="dw-faint dw-mono">{{ m.pickup?.pickup_number }}</div></ng-template>
          <ng-template dwCell="waste" let-m>{{ m.waste_type }}<div class="dw-faint">{{ m.regulatory_code }}</div></ng-template>
          <ng-template dwCell="weight" let-m>{{ m.received_weight_kg ?? m.declared_weight_kg | dwKg }}</ng-template>
          <ng-template dwCell="signatures" let-m>
            <ul class="signatures">
              @for (signature of m.signatures; track signature.party) {
                <li [class.done]="signature.signed_at">
                  <mat-icon aria-hidden="true">{{ signature.signed_at ? 'check_circle' : 'radio_button_unchecked' }}</mat-icon>
                  <span><strong>{{ t('compliance.manifests.' + signature.party) }}</strong> — {{ signature.company.legal_name }}
                    <span class="dw-faint">@if (signature.signed_at) { {{ t('compliance.manifests.signedBy', { name: signature.signed_by, date: (signature.signed_at | dwDate: 'datetime') }) }} } @else { {{ t('compliance.manifests.waiting') }} }</span>
                  </span>
                </li>
              }
            </ul>
          </ng-template>
          <ng-template dwCell="status" let-m><dw-status kind="manifest" [value]="m.status" /></ng-template>
          <ng-template dwCell="actions" let-m>
            <div class="dw-row-tight">
              @if (canSign && m.awaiting_company === company) {
                <button mat-flat-button type="button" [disabled]="busy()" (click)="sign(m)"><mat-icon>draw</mat-icon>{{ t('compliance.manifests.sign') }}</button>
              }
              <button mat-stroked-button type="button" (click)="pdf(m)"><mat-icon>picture_as_pdf</mat-icon>{{ t('compliance.manifests.pdf') }}</button>
            </div>
          </ng-template>
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: FILTERS + `
    .signatures { list-style: none; margin: 0; padding: 0; display: grid; gap: 2px; font-size: 12.5px; }
    .signatures li { display: flex; gap: 6px; align-items: flex-start; }
    .signatures mat-icon { font-size: 15px; width: 15px; height: 15px; margin-block-start: 2px; color: var(--dw-ink-3); flex: none; }
    .signatures li.done mat-icon { color: var(--dw-success); }
  `,
})
export class ManifestsPage {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);

  protected readonly company = this.session.company()?.ulid ?? null;
  protected readonly canSign = this.session.can('documents.sign') || this.session.can('provider.manifests.sign');
  protected readonly busy = signal(false);
  protected readonly statuses: Manifest['status'][] = ['pending_producer_signature', 'pending_transporter_signature', 'pending_receiver_signature', 'completed', 'cancelled'];
  protected readonly list = new ListStore<Manifest, { status?: string | null }>((query) => this.api.list<Manifest>('manifests', query));

  protected readonly columns: Column<Manifest>[] = [
    { key: 'number', header: 'compliance.manifests.number' },
    { key: 'waste', header: 'compliance.manifests.waste' },
    { key: 'packages', header: 'compliance.manifests.packages', align: 'end', value: (m) => m.package_count, hideBelow: 1200 },
    { key: 'weight', header: 'fields.weight', align: 'end', hideBelow: 900 },
    { key: 'signatures', header: 'compliance.manifests.signatures', hideBelow: 900 },
    { key: 'status', header: 'fields.status' },
    { key: 'actions', header: 'common.actions' },
  ];

  protected async sign(manifest: Manifest): Promise<void> {
    const password = await this.confirm.ask({
      title: this.toast.text('compliance.manifests.signTitle', { number: manifest.manifest_number }),
      message: this.toast.text('compliance.manifests.signMessage'),
      confirmLabel: this.toast.text('compliance.manifests.sign'),
      password: true,
    });
    if (typeof password !== 'string') {
      return;
    }
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.post(`manifests/${manifest.ulid}/sign`, { password }));
      this.toast.success('compliance.manifests.signed');
      this.list.reload();
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected async pdf(manifest: Manifest): Promise<void> {
    try {
      openBlob(await firstValueFrom(this.api.blob(`manifests/${manifest.ulid}/pdf`)));
    } catch (error) {
      this.toast.error(error);
    }
  }
}

/**
 * M5-03: the yearly declaration of a site, computed from its lots and pickups. A draft can be recomputed as
 * often as needed; validating freezes the figures and archives the document.
 */
@Component({
  imports: [FormsModule, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatMenuModule, MatSelectModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent,
    KpiComponent, DatePipe, KgPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('compliance.declarations.title')" [subtitle]="t('compliance.declarations.subtitle')" />

      <div class="dw-stack">
        <div class="dw-panel">
          <div class="filters">
            <mat-form-field>
              <mat-label>{{ t('fields.site') }}</mat-label>
              <mat-select [ngModel]="site()" (ngModelChange)="site.set($event)">
                @for (item of sites; track item.ulid) { <mat-option [value]="item.ulid">{{ item.name }}</mat-option> }
              </mat-select>
            </mat-form-field>
            <mat-form-field class="year">
              <mat-label>{{ t('compliance.declarations.year') }}</mat-label>
              <mat-select [ngModel]="year()" (ngModelChange)="year.set($event)">
                @for (item of years; track item) { <mat-option [value]="item">{{ item }}</mat-option> }
              </mat-select>
            </mat-form-field>
            <button mat-flat-button type="button" [disabled]="!site() || busy()" (click)="prepare(site(), year())"><mat-icon>calculate</mat-icon>{{ t('compliance.declarations.prepare') }}</button>
          </div>
          <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1"
            [pageSize]="list.perPage()" [clickable]="true" emptyIcon="fact_check" [emptyTitle]="t('compliance.declarations.emptyTitle')" [emptyHint]="t('compliance.declarations.emptyHint')"
            (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="selected.set($event)" (retry)="list.reload()">
            <ng-template dwCell="produced" let-d>{{ d.figures.totals.produced_kg | dwKg: 'auto' }}</ng-template>
            <ng-template dwCell="evacuated" let-d>{{ d.figures.totals.evacuated_kg | dwKg: 'auto' }}</ng-template>
            <ng-template dwCell="computed" let-d>{{ d.computed_at | dwDate: 'datetime' }}</ng-template>
            <ng-template dwCell="status" let-d><dw-status kind="declaration" [value]="d.status" /></ng-template>
          </dw-data-table>
        </div>

        @if (selected(); as d) {
          <section class="dw-panel" aria-live="polite">
            <header class="dw-panel-head">
              <h2 class="dw-h2 dw-spacer">{{ d.site?.name }} — {{ d.reporting_year }} <span class="dw-faint">({{ d.format_code }})</span></h2>
              <dw-status kind="declaration" [value]="d.status" />
              @if (d.status === 'draft' || d.status === 'in_review') {
                <button mat-button type="button" [disabled]="busy()" (click)="prepare(d.site?.ulid ?? '', d.reporting_year)"><mat-icon>refresh</mat-icon>{{ t('compliance.declarations.recompute') }}</button>
                @if (canValidate(d)) {
                  <button mat-flat-button type="button" [disabled]="busy()" (click)="validate(d)"><mat-icon>verified</mat-icon>{{ t('compliance.declarations.validate') }}</button>
                }
              }
              <button mat-stroked-button type="button" [matMenuTriggerFor]="exports"><mat-icon>download</mat-icon>{{ t('common.export') }}</button>
              <mat-menu #exports="matMenu">
                <button mat-menu-item type="button" (click)="export(d, 'pdf')">PDF</button>
                <button mat-menu-item type="button" (click)="export(d, 'xlsx')">Excel</button>
              </mat-menu>
            </header>
            <div class="dw-panel-body dw-stack">
              <p class="dw-muted">{{ t('compliance.declarations.computedAt', { date: (d.computed_at | dwDate: 'datetime') }) }}</p>
              <div class="dw-grid-4">
                <dw-kpi [label]="t('compliance.declarations.produced')" [value]="d.figures.totals.produced_kg | dwKg: 'auto'" />
                <dw-kpi [label]="t('compliance.declarations.hazardousProduced')" [value]="d.figures.totals.hazardous_produced_kg | dwKg: 'auto'" />
                <dw-kpi [label]="t('compliance.declarations.evacuated')" [value]="d.figures.totals.evacuated_kg | dwKg: 'auto'" />
                <dw-kpi [label]="t('compliance.declarations.stockEnd')" [value]="d.figures.totals.stock_at_year_end_kg | dwKg: 'auto'" />
              </div>
              <div class="dw-grid-2">
                <div>
                  <h3 class="dw-h3">{{ t('compliance.declarations.produced') }}</h3>
                  <table class="figures">
                    <thead><tr><th>{{ t('lots.regulatoryCode') }}</th><th>{{ t('fields.wasteType') }}</th><th class="dw-num">{{ t('dashboard.lotsShort') }}</th><th class="dw-num">{{ t('fields.weight') }}</th></tr></thead>
                    <tbody>
                      @for (row of d.figures.produced; track $index) {
                        <tr><td class="dw-mono">{{ row.regulatory_code ?? '—' }}</td><td>{{ row.waste_type }} @if (row.is_hazardous) { <span class="dw-faint">({{ t('common.hazardous') }})</span> }</td>
                          <td class="dw-num">{{ row.lots }}</td><td class="dw-num">{{ row.kg | dwKg }}</td></tr>
                      } @empty { <tr><td colspan="4" class="dw-faint">{{ t('common.empty') }}</td></tr> }
                    </tbody>
                  </table>
                </div>
                <div>
                  <h3 class="dw-h3">{{ t('compliance.declarations.evacuated') }}</h3>
                  <table class="figures">
                    <thead><tr><th>{{ t('fields.wasteType') }}</th><th>{{ t('compliance.declarations.destination') }}</th><th>{{ t('pickups.channel') }}</th><th class="dw-num">{{ t('fields.weight') }}</th></tr></thead>
                    <tbody>
                      @for (row of d.figures.evacuated; track $index) {
                        <tr><td>{{ row.waste_type }}</td><td>{{ row.provider }}</td><td>{{ row.channel ? t('channels.' + row.channel) : '—' }}</td><td class="dw-num">{{ row.kg | dwKg }}</td></tr>
                      } @empty { <tr><td colspan="4" class="dw-faint">{{ t('common.empty') }}</td></tr> }
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </section>
        }
      </div>
    </ng-container>
  `,
  styles: FILTERS + `
    .filters .year { width: 120px; }
    .figures { width: 100%; border-collapse: collapse; font-size: 13px; margin-block-start: var(--dw-2); }
    .figures th { text-align: start; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dw-ink-2); padding: 6px 8px; border-block-end: 1px solid var(--dw-line); }
    .figures td { padding: 6px 8px; border-block-end: 1px solid var(--dw-line-soft); }
    .figures th.dw-num { text-align: end; }
  `,
})
export class DeclarationsPage {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);

  protected readonly sites = this.session.sitesFor('declarations.prepare');
  protected readonly years = Array.from({ length: Math.max(1, new Date().getFullYear() - 2023) }, (_, index) => new Date().getFullYear() - index);
  protected readonly site = signal(this.sites[0]?.ulid ?? '');
  // The usual case: last year's declaration, prepared at the start of the following year.
  protected readonly year = signal(this.years[1] ?? this.years[0]);
  protected readonly busy = signal(false);
  protected readonly selected = signal<Declaration | null>(null);
  protected readonly list = new ListStore<Declaration>((query) => this.api.list<Declaration>('declarations', query));

  protected readonly columns: Column<Declaration>[] = [
    { key: 'year', header: 'compliance.declarations.year', value: (d) => d.reporting_year },
    { key: 'site', header: 'fields.site', value: (d) => d.site?.name },
    { key: 'produced', header: 'compliance.declarations.produced', align: 'end' },
    { key: 'evacuated', header: 'compliance.declarations.evacuated', align: 'end', hideBelow: 640 },
    { key: 'computed', header: 'fields.date', hideBelow: 900 },
    { key: 'status', header: 'fields.status' },
  ];

  protected canValidate(declaration: Declaration): boolean {
    return this.session.can('declarations.validate', declaration.site?.ulid);
  }

  protected async prepare(site: string, year: number): Promise<void> {
    this.busy.set(true);
    try {
      this.selected.set(await firstValueFrom(this.api.post<Declaration>(`sites/${site}/declarations`, { reporting_year: year })));
      this.toast.success('compliance.declarations.computed');
      this.list.reload();
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected async validate(declaration: Declaration): Promise<void> {
    if (!(await this.confirm.ask({
      title: this.toast.text('compliance.declarations.validateConfirm', { year: declaration.reporting_year }),
      message: this.toast.text('compliance.declarations.validateHint'),
      confirmLabel: this.toast.text('compliance.declarations.validate'),
    }))) {
      return;
    }
    this.busy.set(true);
    try {
      this.selected.set(await firstValueFrom(this.api.post<Declaration>(`declarations/${declaration.ulid}/validate`)));
      this.toast.success('compliance.declarations.validated');
      this.list.reload();
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected async export(declaration: Declaration, format: 'pdf' | 'xlsx'): Promise<void> {
    try {
      const blob = await firstValueFrom(this.api.blob(`declarations/${declaration.ulid}/export`, { format }));
      openBlob(blob, format === 'xlsx' ? `declaration-${declaration.reporting_year}.xlsx` : undefined);
    } catch (error) {
      this.toast.error(error);
    }
  }
}

/** M5-04: the archive. Read-only: a regulated document is never edited or deleted from here. */
@Component({
  imports: [MatButtonModule, MatFormFieldModule, MatIconModule, MatSelectModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, DatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t(provider ? 'provider.documents.title' : 'compliance.documents.title')" [subtitle]="t(provider ? 'provider.documents.subtitle' : 'compliance.documents.subtitle')" />
      <div class="dw-panel">
        <div class="filters">
          <mat-form-field>
            <mat-label>{{ t('compliance.documents.type') }}</mat-label>
            <mat-select [value]="list.filter().type ?? ''" (selectionChange)="list.setFilter({ type: $event.value || null })">
              <mat-option value="">{{ t('common.all') }}</mat-option>
              @for (type of types(); track type) { <mat-option [value]="type">{{ t('compliance.documents.types.' + type) }}</mat-option> }
            </mat-select>
          </mat-form-field>
          <mat-form-field class="year">
            <mat-label>{{ t('compliance.declarations.year') }}</mat-label>
            <mat-select [value]="list.filter().year ?? ''" (selectionChange)="list.setFilter({ year: $event.value || null })">
              <mat-option value="">{{ t('common.all') }}</mat-option>
              @for (year of years; track year) { <mat-option [value]="year">{{ year }}</mat-option> }
            </mat-select>
          </mat-form-field>
          @if (list.filtered()) { <button mat-button type="button" (click)="list.resetFilter()">{{ t('common.resetFilters') }}</button> }
        </div>
        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1"
          [pageSize]="list.perPage()" emptyIcon="folder_open" [emptyTitle]="list.filtered() ? t('common.noResult') : t('compliance.documents.emptyTitle')"
          [emptyHint]="list.filtered() ? '' : t('compliance.documents.emptyHint')" (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (retry)="list.reload()">
          <ng-template dwCell="type" let-d>{{ t('compliance.documents.types.' + d.document_type) }}</ng-template>
          <ng-template dwCell="title" let-d>{{ d.title }}<div class="dw-faint dw-mono">{{ d.reference_number }}</div></ng-template>
          <ng-template dwCell="pickup" let-d><span class="dw-mono">{{ d.pickup?.pickup_number ?? '—' }}</span></ng-template>
          <ng-template dwCell="issued" let-d>{{ d.issued_at ?? d.created_at | dwDate }}</ng-template>
          <ng-template dwCell="retention" let-d>{{ d.retention_until | dwDate }}</ng-template>
          <ng-template dwCell="status" let-d><dw-status kind="document" [value]="d.status" /></ng-template>
          <ng-template dwCell="actions" let-d>
            @if (d.has_file) { <button mat-stroked-button type="button" (click)="open(d)"><mat-icon>open_in_new</mat-icon>{{ t('common.open') }}</button> }
          </ng-template>
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: FILTERS + '.filters .year { width: 120px; }',
})
export class DocumentsPage {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);

  protected readonly provider = this.session.audience() === 'provider';
  protected readonly years = Array.from({ length: 5 }, (_, index) => new Date().getFullYear() - index);
  // A provider is never a party to a declaration.
  protected readonly types = computed<DocumentRecord['document_type'][]>(() => ['recycling_certificate', 'destruction_certificate', 'hazardous_manifest', ...(this.provider ? [] : (['annual_declaration'] as const))]);
  protected readonly list = new ListStore<DocumentRecord, { type?: string | null; year?: number | null }>((query) => this.api.list<DocumentRecord>('documents', query));

  protected readonly columns: Column<DocumentRecord>[] = [
    { key: 'type', header: 'compliance.documents.type' },
    { key: 'title', header: 'compliance.documents.reference' },
    { key: 'pickup', header: 'lots.pickup', hideBelow: 900 },
    { key: 'site', header: 'fields.site', value: (d) => d.site?.name, hideBelow: 1200 },
    { key: 'by', header: 'compliance.documents.uploadedBy', value: (d) => d.uploaded_by?.legal_name, hideBelow: 1200 },
    { key: 'issued', header: 'compliance.documents.issuedAt', hideBelow: 640 },
    { key: 'retention', header: 'compliance.documents.retention', hideBelow: 900 },
    { key: 'status', header: 'fields.status', hideBelow: 640 },
    { key: 'actions', header: 'common.actions' },
  ];

  protected async open(document: DocumentRecord): Promise<void> {
    try {
      openBlob(await firstValueFrom(this.api.blob(`documents/${document.ulid}/download`)));
    } catch (error) {
      this.toast.error(error);
    }
  }
}
