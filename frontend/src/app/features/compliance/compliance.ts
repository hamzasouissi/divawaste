import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';

import { Api, openBlob } from '../../core/api/api';
import { Declaration, DocumentRecord, Manifest, RegisterRow } from '../../core/api/models';
import { SignManifestDialog, SignedCopyDialog } from './manifest-dialogs';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, KgPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { Dialogs } from '../../ui/dialog';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { KpiComponent, PageHeaderComponent, StatusComponent } from '../../ui/page';
import { Period, PeriodFilterComponent, presetPeriod } from '../../ui/period';
import { CheckboxComponent } from '../../ui/checkbox';
import { IconComponent } from '../../ui/icon';
import { MenuAction, MenuComponent } from '../../ui/menu';
import { SELECT } from '../../ui/select';
import { FilterBarComponent } from '../../ui/filter-bar';

const FILTERS = `
  .filters dw-field { width: 220px; }
`;

/**
 * M5-01: the chronological register. Nothing is typed here: each line is a lot, with its exit when it has left
 * the site. Exports are generated in the background and delivered on the Reports screen.
 */
@Component({
  imports: [
    TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, PeriodFilterComponent, DatePipe, KgPipe, RouterLink, IconComponent, ButtonDirective, CheckboxComponent, FilterBarComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('compliance.register.title')" [subtitle]="t('compliance.register.subtitle')">
        @if (canExport) {
          <button pButton severity="secondary" outlined type="button" [disabled]="exporting()" (click)="export('xlsx')"><dw-icon>table_view</dw-icon>{{ t('compliance.register.exportXlsx') }}</button>
          <button pButton type="button" [disabled]="exporting()" (click)="export('pdf')"><dw-icon>picture_as_pdf</dw-icon>{{ t('compliance.register.exportPdf') }}</button>
        }
      </dw-page-header>

      <div class="dw-panel">
        <dw-filter-bar>
          <dw-period-filter [value]="period()" permission="documents.view" (changed)="setPeriod($event)" />
          <dw-checkbox [checked]="hazardous()" (checkedChange)="setHazardous($event)">{{ t('lots.hazardousOnly') }}</dw-checkbox>
        </dw-filter-bar>
        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1"
          [pageSize]="list.perPage()" [trackBy]="track" emptyIcon="menu_book" [emptyTitle]="t('compliance.register.emptyTitle')"
          (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (retry)="list.reload()">
          <ng-template dwCell="date" let-row>{{ row.generated_at | dwDate: 'datetime' }}</ng-template>
          <ng-template dwCell="lot" let-row><a class="dw-mono" [routerLink]="['/lots', row.lot]">{{ row.lot_number }}</a></ng-template>
          <ng-template dwCell="type" let-row>
            {{ row.waste_type }} @if (row.is_hazardous) { <dw-icon class="hazard" [title]="t('common.hazardous')" [attr.aria-label]="t('common.hazardous')">warning</dw-icon> }
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
 * M5-02: tracking slips — every waste leaves with one, hazardous or not. Same screen for the three parties; each one
 * signs when its turn comes, electronically or by hand on the printed slip (the API checks both the turn and the
 * password). A slip carrying a handwritten signature is closed by attaching the scan of the signed copy.
 */
@Component({
  imports: [TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, DatePipe, KgPipe, IconComponent, ButtonDirective, ...SELECT, FilterBarComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('compliance.manifests.title')" [subtitle]="t('compliance.manifests.subtitle')" />
      <div class="dw-panel">
        <dw-filter-bar [active]="list.activeCount()" (cleared)="list.resetFilter()">
          <dw-select [placeholder]="t('fields.status')" [value]="list.filter().status ?? ''" (valueChange)="list.setFilter({ status: $event || null })">
            <dw-option value="" [label]="t('common.all')" />
            @for (status of statuses; track status) { <dw-option [value]="status" [label]="t('status.manifest.' + status)" /> }
          </dw-select>
        </dw-filter-bar>
        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1"
          [pageSize]="list.perPage()" emptyIcon="assignment_late" [emptyTitle]="list.filtered() ? t('common.noResult') : t('compliance.manifests.emptyTitle')"
          [emptyHint]="list.filtered() ? '' : t('compliance.manifests.emptyHint')" (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (retry)="list.reload()">
          <ng-template dwCell="number" let-m><span class="dw-mono">{{ m.manifest_number }}</span><div class="dw-faint dw-mono">{{ m.pickup?.pickup_number }}</div></ng-template>
          <ng-template dwCell="waste" let-m>
            {{ m.waste_type }} @if (m.is_hazardous) { <dw-icon class="hazard" [title]="t('common.hazardous')" [attr.aria-label]="t('common.hazardous')">warning</dw-icon> }
            <div class="dw-faint">{{ m.regulatory_code }}</div>
          </ng-template>
          <ng-template dwCell="weight" let-m>{{ m.received_weight_kg ?? m.declared_weight_kg | dwKg }}</ng-template>
          <ng-template dwCell="signatures" let-m>
            <ul class="signatures">
              @for (signature of m.signatures; track signature.party) {
                <li [class.done]="signature.signed_at">
                  <dw-icon aria-hidden="true">{{ signature.signed_at ? 'check_circle' : 'radio_button_unchecked' }}</dw-icon>
                  <span><strong>{{ t('compliance.manifests.' + signature.party) }}</strong> — {{ signature.company.legal_name }}
                    <span class="dw-faint">
                      @if (signature.method === 'paper') { {{ t('compliance.manifests.signedOnPaper', { name: signature.signed_by, date: (signature.signed_at | dwDate) }) }} }
                      @else if (signature.signed_at) { {{ t('compliance.manifests.signedBy', { name: signature.signed_by, date: (signature.signed_at | dwDate: 'datetime') }) }} }
                      @else { {{ t(signature.external ? 'compliance.manifests.waitingPaper' : 'compliance.manifests.waiting') }} }
                    </span>
                  </span>
                </li>
              }
            </ul>
          </ng-template>
          <ng-template dwCell="status" let-m><dw-status kind="manifest" [value]="m.status" /></ng-template>
          <ng-template dwCell="actions" let-m>
            <div class="dw-row-tight">
              @if (canSign && m.status === 'pending_signed_copy' && owner) {
                <button pButton type="button" (click)="attach(m)"><dw-icon>upload_file</dw-icon>{{ t('compliance.manifests.attachCopy') }}</button>
              } @else if (canSign && m.status !== 'pending_signed_copy' && m.awaiting_company === company) {
                <button pButton type="button" (click)="sign(m)"><dw-icon>draw</dw-icon>{{ t(m.awaiting_external ? 'compliance.manifests.record' : 'compliance.manifests.sign') }}</button>
              }
              <button pButton severity="secondary" outlined type="button" (click)="pdf(m)"><dw-icon>picture_as_pdf</dw-icon>{{ t('compliance.manifests.pdf') }}</button>
            </div>
          </ng-template>
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: FILTERS + `
    .signatures { list-style: none; margin: 0; padding: 0; display: grid; gap: 2px; font-size: 12.5px; }
    .signatures li { display: flex; gap: 6px; align-items: flex-start; }
    .signatures dw-icon { font-size: 15px; margin-block-start: 2px; color: var(--dw-ink-3); flex: none; }
    .signatures li.done dw-icon { color: var(--dw-success); }
    .hazard { font-size: 15px; width: 15px; height: 15px; color: var(--dw-warning); vertical-align: -2px; }
  `,
})
export class ManifestsPage {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);
  private readonly dialog = inject(Dialogs);

  /** Only the producer, who owns the slip, attaches the signed copy. */
  protected readonly owner = this.session.audience() !== 'provider';

  protected readonly company = this.session.company()?.ulid ?? null;
  protected readonly canSign = this.session.can('documents.sign') || this.session.can('provider.manifests.sign');
  protected readonly statuses: Manifest['status'][] = ['pending_producer_signature', 'pending_transporter_signature', 'pending_receiver_signature', 'pending_signed_copy', 'completed', 'cancelled'];
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

  protected sign(manifest: Manifest): void {
    this.dialog.open<SignManifestDialog, Manifest, Manifest>(SignManifestDialog, { data: manifest }).afterClosed().subscribe((done) => done && this.list.reload());
  }

  protected attach(manifest: Manifest): void {
    this.dialog.open<SignedCopyDialog, Manifest, boolean>(SignedCopyDialog, { data: manifest }).afterClosed().subscribe((done) => done && this.list.reload());
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
  imports: [
    FormsModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, KpiComponent, DatePipe, KgPipe, IconComponent, ButtonDirective, ...SELECT, FilterBarComponent, MenuComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('compliance.declarations.title')" [subtitle]="t('compliance.declarations.subtitle')" />

      <div class="dw-stack">
        <div class="dw-panel">
          <dw-filter-bar>
            <dw-select [placeholder]="t('fields.site')" [ngModel]="site()" (ngModelChange)="site.set($event)">
              @for (item of sites; track item.ulid) { <dw-option [value]="item.ulid" [label]="item.name" /> }
            </dw-select>
            <dw-select class="year" [placeholder]="t('compliance.declarations.year')" [ngModel]="year()" (ngModelChange)="year.set($event)">
              @for (item of years; track item) { <dw-option [value]="item" [label]="item" /> }
            </dw-select>
            <button pButton type="button" [disabled]="!site() || busy()" (click)="prepare(site(), year())"><dw-icon>calculate</dw-icon>{{ t('compliance.declarations.prepare') }}</button>
          </dw-filter-bar>
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
                <button pButton text type="button" [disabled]="busy()" (click)="prepare(d.site?.ulid ?? '', d.reporting_year)"><dw-icon>refresh</dw-icon>{{ t('compliance.declarations.recompute') }}</button>
                @if (canValidate(d)) {
                  <button pButton type="button" [disabled]="busy()" (click)="validate(d)"><dw-icon>verified</dw-icon>{{ t('compliance.declarations.validate') }}</button>
                }
              }
              <button pButton severity="secondary" outlined type="button" (click)="exports.open($event, exportActions(d))" aria-haspopup="menu"><dw-icon>download</dw-icon>{{ t('common.export') }}<dw-icon>expand_more</dw-icon></button>
              <dw-menu #exports />
            </header>
            <div class="dw-panel-body dw-stack">
              <p class="dw-muted">{{ t('compliance.declarations.computedAt', { date: (d.computed_at | dwDate: 'datetime') }) }}</p>
              <div class="dw-stats">
                <dw-kpi [label]="t('compliance.declarations.produced')" [value]="d.figures.totals.produced_kg | dwKg: 'auto'" />
                <dw-kpi [label]="t('compliance.declarations.hazardousProduced')" [value]="d.figures.totals.hazardous_produced_kg | dwKg: 'auto'" />
                <dw-kpi [label]="t('compliance.declarations.evacuated')" [value]="d.figures.totals.evacuated_kg | dwKg: 'auto'" />
                <dw-kpi [label]="t('compliance.declarations.stockEnd')" [value]="d.figures.totals.stock_at_year_end_kg | dwKg: 'auto'" />
              </div>
              <div class="dw-grid-2">
                <div>
                  <h3 class="dw-h3">{{ t('compliance.declarations.produced') }}</h3>
                  <table class="dw-table figures">
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
                  <table class="dw-table figures">
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
    .figures { margin-block-start: var(--dw-2); }
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

  protected exportActions(declaration: Declaration): MenuAction[] {
    return [
      { label: 'PDF', icon: 'picture_as_pdf', run: () => void this.export(declaration, 'pdf') },
      { label: 'Excel', icon: 'table_view', run: () => void this.export(declaration, 'xlsx') },
    ];
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
  imports: [TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, DatePipe, IconComponent, ButtonDirective, ...SELECT, FilterBarComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t(provider ? 'provider.documents.title' : 'compliance.documents.title')" [subtitle]="t(provider ? 'provider.documents.subtitle' : 'compliance.documents.subtitle')" />
      <div class="dw-panel">
        <dw-filter-bar [active]="list.activeCount()" (cleared)="list.resetFilter()">
          <dw-select [placeholder]="t('compliance.documents.type')" [value]="list.filter().type ?? ''" (valueChange)="list.setFilter({ type: $event || null })">
            <dw-option value="" [label]="t('common.all')" />
            @for (type of types(); track type) { <dw-option [value]="type" [label]="t('compliance.documents.types.' + type)" /> }
          </dw-select>
          <dw-select class="year" [placeholder]="t('compliance.declarations.year')" [value]="list.filter().year ?? ''" (valueChange)="list.setFilter({ year: $any($event) || null })">
            <dw-option value="" [label]="t('common.all')" />
            @for (year of years; track year) { <dw-option [value]="year" [label]="year" /> }
          </dw-select>
        </dw-filter-bar>
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
            @if (d.has_file) { <button pButton severity="secondary" outlined type="button" (click)="open(d)"><dw-icon>open_in_new</dw-icon>{{ t('common.open') }}</button> }
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
