import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialog, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatMenuModule } from '@angular/material/menu';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom, forkJoin } from 'rxjs';

import { Api } from '../../core/api/api';
import { StockResponse, StockRow, StockThreshold, Zone } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, KgPipe, NumberPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { KpiComponent, PageHeaderComponent, StatusComponent } from '../../ui/page';
import { StateComponent } from '../../ui/state';

interface SiteGroup {
  site: string;
  name: string;
  total: number;
  zones: { zone: string; name: string; total: number; capacity: number | null; level: 'warning' | 'exceeded' | null; rows: StockRow[] }[];
}

@Component({
  imports: [ReactiveFormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatSelectModule, MatSlideToggleModule, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ t(data ? 'stock.editThreshold' : 'stock.addThreshold') }}</h2>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <mat-dialog-content>
          <div class="dw-form">
            @if (!data) {
              <mat-form-field class="dw-span-2">
                <mat-label>{{ t('fields.site') }}</mat-label>
                <mat-select formControlName="site" required (selectionChange)="loadZones($event.value)">
                  @for (site of sites; track site.ulid) { <mat-option [value]="site.ulid">{{ site.name }}</mat-option> }
                </mat-select>
              </mat-form-field>
              <mat-form-field>
                <mat-label>{{ t('fields.zone') }}</mat-label>
                <mat-select formControlName="zone">
                  <mat-option value="">{{ t('stock.anyZone') }}</mat-option>
                  @for (zone of zones(); track zone.ulid) { <mat-option [value]="zone.ulid">{{ zone.name }}</mat-option> }
                </mat-select>
              </mat-form-field>
              <mat-form-field>
                <mat-label>{{ t('fields.wasteType') }}</mat-label>
                <mat-select formControlName="waste_type">
                  <mat-option value="">{{ t('stock.anyType') }}</mat-option>
                  @for (type of types(); track type.ulid) { <mat-option [value]="type.ulid">{{ type.name }}</mat-option> }
                </mat-select>
              </mat-form-field>
            }
            <mat-form-field>
              <mat-label>{{ t('stock.max') }}</mat-label>
              <input matInput formControlName="max_quantity_kg" inputmode="decimal" required />
              @if (form.controls.max_quantity_kg.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
            </mat-form-field>
            <mat-form-field><mat-label>{{ t('stock.warningPct') }}</mat-label><input matInput type="number" formControlName="warning_pct" min="1" max="100" required /></mat-form-field>
            @if (data) { <mat-slide-toggle formControlName="is_active" class="dw-span-2">{{ t('common.active') }}</mat-slide-toggle> }
          </div>
        </mat-dialog-content>
        <mat-dialog-actions align="end">
          <button mat-button type="button" mat-dialog-close>{{ t('common.cancel') }}</button>
          <button mat-flat-button type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </mat-dialog-actions>
      </form>
    </ng-container>
  `,
  styles: 'mat-dialog-content { min-width: min(520px, 82vw); }',
})
export class ThresholdDialog {
  protected readonly data = inject<StockThreshold | null>(MAT_DIALOG_DATA);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly reference = inject(ReferenceData);
  private readonly ref = inject<MatDialogRef<ThresholdDialog, boolean>>(MatDialogRef);

  protected readonly sites = inject(Session).sitesFor('stock.thresholds.manage');
  protected readonly types = toSignal(this.reference.wasteTypes(), { initialValue: [] });
  protected readonly zones = signal<Zone[]>([]);
  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    site: [this.sites[0]?.ulid ?? ''],
    zone: [''],
    waste_type: [''],
    max_quantity_kg: [this.data?.max_quantity_kg ?? '', [Validators.required, Validators.pattern(/^\d+([.,]\d{1,3})?$/)]],
    warning_pct: [this.data?.warning_pct ?? 90, [Validators.required, Validators.min(1), Validators.max(100)]],
    is_active: [this.data?.is_active ?? true],
  });

  constructor() {
    if (!this.data && this.form.controls.site.value) {
      this.loadZones(this.form.controls.site.value);
    }
  }

  protected loadZones(site: string): void {
    this.form.controls.zone.setValue('');
    this.reference.zones(site).subscribe((zones) => this.zones.set(zones));
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
      await firstValueFrom(this.data
        ? this.api.put(`stock-thresholds/${this.data.ulid}`, { max_quantity_kg: max, warning_pct: value.warning_pct, is_active: value.is_active })
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
 * M2-07: what is on each site right now, by zone and by waste type, with how full each storage area is and the
 * state of every alert threshold. Nothing is stored: the API sums the lots present on site at each request.
 */
@Component({
  imports: [RouterLink, MatButtonModule, MatFormFieldModule, MatIconModule, MatMenuModule, MatSelectModule, TranslocoDirective, KpiComponent, PageHeaderComponent, StatusComponent, StateComponent,
    DatePipe, KgPipe, NumberPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('stock.title')" [subtitle]="t('stock.subtitle')">
        @if (sites.length > 1) {
          <mat-form-field class="site">
            <mat-label>{{ t('fields.site') }}</mat-label>
            <mat-select [value]="site()" (selectionChange)="site.set($event.value); load()">
              <mat-option value="">{{ t('common.allSites') }}</mat-option>
              @for (item of sites; track item.ulid) { <mat-option [value]="item.ulid">{{ item.name }}</mat-option> }
            </mat-select>
          </mat-form-field>
        }
        <button mat-stroked-button type="button" (click)="load()"><mat-icon>refresh</mat-icon>{{ t('common.refresh') }}</button>
        @if (session.can('pickups.request')) {
          <a mat-flat-button routerLink="/pickups/new"><mat-icon>local_shipping</mat-icon>{{ t('stock.requestPickup') }}</a>
        }
      </dw-page-header>

      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (stock(); as s) {
          <div class="dw-stack">
            <div class="dw-grid-4">
              <dw-kpi [label]="t('stock.total')" [value]="s.meta.total_kg | dwKg: 'auto'" [hint]="s.meta.generated_at | dwDate: 'datetime'" />
              <dw-kpi [label]="t('stock.awaiting')" [value]="awaiting() | dwKg: 'auto'" />
              <dw-kpi [label]="t('status.alert.exceeded')" [value]="exceeded()" [tone]="exceeded() > 0 ? 'danger' : 'neutral'" />
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
                          @if (zone.capacity) {
                            <span class="meter" role="meter" [attr.aria-label]="t('stock.zoneFill')" [attr.aria-valuenow]="fill(zone.total, zone.capacity)" aria-valuemin="0" aria-valuemax="100">
                              <span class="fill" [class]="zone.level ?? 'ok'" [style.inline-size.%]="fill(zone.total, zone.capacity)"></span>
                            </span>
                            <span class="dw-faint">{{ fill(zone.total, zone.capacity) | dwNumber }} % / {{ zone.capacity | dwKg: 'auto' }}</span>
                            @if (zone.level) { <dw-status kind="alert" [value]="zone.level" /> }
                          }
                        </div>
                        <table>
                          <tbody>
                            @for (row of zone.rows; track row.waste_type) {
                              <tr>
                                <td>{{ row.waste_type_name }} @if (row.is_hazardous) { <mat-icon class="hazard" [title]="t('common.hazardous')" [attr.aria-label]="t('common.hazardous')">warning</mat-icon> }</td>
                                <td class="dw-num">{{ t('dashboard.lots', { count: row.lots }) }}</td>
                                <td class="dw-num strong">{{ row.quantity_kg | dwKg }}</td>
                                <td class="dw-num dw-faint">@if (row.unit && row.unit !== 'kg' && row.quantity_in_unit) { {{ row.quantity_in_unit | dwNumber: 2 }} {{ row.unit }} }</td>
                                <td class="dw-faint">@if (+row.awaiting_pickup_kg > 0) { {{ t('stock.awaiting') }} {{ row.awaiting_pickup_kg | dwKg }} }</td>
                                <td class="dw-faint">{{ t('stock.oldest') }} : {{ row.oldest_lot_at | dwDate }}</td>
                              </tr>
                            }
                          </tbody>
                        </table>
                      </div>
                    }
                  </section>
                }
              </div>
            </dw-state>

            <section class="dw-panel">
              <div class="dw-panel-head">
                <h2 class="dw-h2">{{ t('stock.thresholds') }}</h2><span class="dw-spacer"></span>
                @if (canManage) { <button mat-stroked-button type="button" (click)="editThreshold(null)"><mat-icon>add</mat-icon>{{ t('stock.addThreshold') }}</button> }
              </div>
              @if (thresholds().length === 0) {
                <p class="dw-panel-body dw-muted">{{ t('stock.noThreshold') }}</p>
              } @else {
                <table class="thresholds">
                  <thead><tr><th>{{ t('fields.site') }}</th><th>{{ t('fields.zone') }}</th><th>{{ t('fields.wasteType') }}</th><th class="dw-num">{{ t('stock.current') }}</th><th class="dw-num">{{ t('stock.max') }}</th><th>{{ t('fields.status') }}</th><th></th></tr></thead>
                  <tbody>
                    @for (threshold of thresholds(); track threshold.ulid) {
                      <tr [class.off]="!threshold.is_active">
                        <td>{{ threshold.site_name }}</td><td>{{ threshold.zone_name ?? t('stock.anyZone') }}</td><td>{{ threshold.waste_type_name ?? t('stock.anyType') }}</td>
                        <td class="dw-num">{{ current(threshold.ulid)?.quantity_kg | dwKg }}</td>
                        <td class="dw-num">{{ threshold.max_quantity_kg | dwKg }} <span class="dw-faint">({{ threshold.warning_pct }} %)</span></td>
                        <td>@if (!threshold.is_active) { <dw-status kind="member" value="suspended" [label]="t('common.inactive')" tone="neutral" /> } @else { <dw-status kind="alert" [value]="current(threshold.ulid)?.alert_level ?? 'ok'" /> }</td>
                        <td class="dw-num">
                          @if (session.can('stock.thresholds.manage', threshold.site)) {
                            <button mat-icon-button type="button" [matMenuTriggerFor]="menu" [attr.aria-label]="t('common.actions')"><mat-icon>more_vert</mat-icon></button>
                            <mat-menu #menu="matMenu">
                              <button mat-menu-item type="button" (click)="editThreshold(threshold)"><mat-icon>edit</mat-icon>{{ t('common.edit') }}</button>
                              <button mat-menu-item type="button" (click)="removeThreshold(threshold)"><mat-icon>delete</mat-icon>{{ t('common.delete') }}</button>
                            </mat-menu>
                          }
                        </td>
                      </tr>
                    }
                  </tbody>
                </table>
              }
            </section>
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .site { width: 220px; }
    .zone { padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }
    .zone:last-child { border-block-end: 0; }
    .zone-head { display: flex; align-items: center; gap: var(--dw-3); flex-wrap: wrap; margin-block-end: 6px; }
    .meter { flex: 0 1 180px; height: 8px; border-radius: 4px; background: var(--dw-line-soft); overflow: hidden; }
    .fill { display: block; height: 100%; max-inline-size: 100%; background: var(--dw-success); }
    .fill.warning { background: #d68a00; } .fill.exceeded { background: var(--dw-danger); }
    /* Fixed layout: the columns line up from one zone to the next, although each zone has its own table. */
    table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
    td { padding: 5px 8px; overflow: hidden; text-overflow: ellipsis; }
    .zone td:nth-child(1) { padding-inline-start: 0; width: 28%; } .zone td:nth-child(2) { width: 9%; } .zone td:nth-child(3) { width: 11%; }
    .zone td:nth-child(4) { width: 10%; } .zone td:nth-child(5) { width: 22%; } .zone td:nth-child(6) { width: 20%; }
    .thresholds { table-layout: auto; }
    .strong { font-weight: 600; }
    .hazard { font-size: 15px; width: 15px; height: 15px; color: var(--dw-warning); vertical-align: -2px; }
    .thresholds th { text-align: start; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dw-ink-3); padding: 8px; border-block-end: 1px solid var(--dw-line); }
    .thresholds td { padding: 6px 8px; border-block-end: 1px solid var(--dw-line-soft); width: auto; }
    .thresholds th:first-child, .thresholds td:first-child { padding-inline-start: var(--dw-4); }
    .thresholds tr.off td { color: var(--dw-ink-3); }
  `,
})
export class StockPage {
  protected readonly session = inject(Session);
  private readonly api = inject(Api);
  private readonly dialog = inject(MatDialog);
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
        zone = { zone: row.zone, name: row.zone_name, total: Number(row.zone_quantity_kg), capacity: row.zone_capacity_kg ? Number(row.zone_capacity_kg) : null, level: row.zone_alert_level, rows: [] };
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

  protected fill(total: number, capacity: number): number {
    return Math.round((total / capacity) * 100);
  }

  protected current(ulid: string): StockResponse['thresholds'][number] | undefined {
    return this.stock()?.thresholds.find((item) => item.ulid === ulid);
  }

  protected editThreshold(threshold: StockThreshold | null): void {
    this.dialog.open<ThresholdDialog, StockThreshold | null, boolean>(ThresholdDialog, { data: threshold }).afterClosed().subscribe((saved) => saved && this.load());
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
