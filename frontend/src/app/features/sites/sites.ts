import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MAT_DIALOG_DATA, MatDialog, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { Router } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom, map } from 'rxjs';

import { Api } from '../../core/api/api';
import { Localized, Site, Zone } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { KgPipe, LocalizedPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { StateComponent } from '../../ui/state';

const DIALOG = [ReactiveFormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoDirective] as const;
const DECIMAL = Validators.pattern(/^\d+([.,]\d{1,3})?$/);
const num = (value: string): string | null => (value.trim() === '' ? null : value.replace(',', '.'));

@Component({
  imports: [...DIALOG],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ t(data ? 'sites.edit' : 'sites.new') }}</h2>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <mat-dialog-content>
          <div class="dw-form">
            <mat-form-field>
              <mat-label>{{ t('fields.code') }}</mat-label>
              <input matInput formControlName="code" maxlength="20" required />
              @if (form.controls.code.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ t('sites.kind') }}</mat-label>
              <mat-select formControlName="site_kind">
                @for (kind of kinds; track kind) { <mat-option [value]="kind">{{ t('sites.kinds.' + kind) }}</mat-option> }
              </mat-select>
            </mat-form-field>
            <mat-form-field class="dw-span-2"><mat-label>{{ t('fields.name') }}</mat-label><input matInput formControlName="name" maxlength="150" required /></mat-form-field>
            <mat-form-field class="dw-span-2"><mat-label>{{ t('fields.address') }}</mat-label><input matInput formControlName="address_line1" /></mat-form-field>
            <mat-form-field><mat-label>{{ t('fields.city') }}</mat-label><input matInput formControlName="city" /></mat-form-field>
            <mat-form-field><mat-label>{{ t('fields.postalCode') }}</mat-label><input matInput formControlName="postal_code" /></mat-form-field>
            <mat-form-field><mat-label>{{ t('sites.regulatoryId') }}</mat-label><input matInput formControlName="regulatory_identifier" /></mat-form-field>
            <mat-form-field><mat-label>{{ t('fields.capacityKg') }}</mat-label><input matInput formControlName="capacity_kg" inputmode="decimal" /></mat-form-field>
          </div>
        </mat-dialog-content>
        <mat-dialog-actions align="end">
          <button mat-button type="button" mat-dialog-close>{{ t('common.cancel') }}</button>
          <button mat-flat-button type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </mat-dialog-actions>
      </form>
    </ng-container>
  `,
  styles: 'mat-dialog-content { min-width: min(600px, 82vw); }',
})
export class SiteDialog {
  protected readonly data = inject<Site | null>(MAT_DIALOG_DATA);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly session = inject(Session);
  private readonly ref = inject<MatDialogRef<SiteDialog, Site>>(MatDialogRef);

  protected readonly kinds = ['production', 'warehouse', 'mixed'] as const;
  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    code: [{ value: this.data?.code ?? '', disabled: this.data !== null }, [Validators.required, Validators.pattern(/^[A-Za-z0-9_-]+$/)]],
    name: [this.data?.name ?? '', Validators.required],
    site_kind: [this.data?.site_kind ?? 'production'],
    address_line1: [this.data?.address_line1 ?? ''],
    city: [this.data?.city ?? ''],
    postal_code: [this.data?.postal_code ?? ''],
    regulatory_identifier: [this.data?.regulatory_identifier ?? ''],
    capacity_kg: [this.data?.capacity_kg ?? '', DECIMAL],
  });

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const body = { ...value, capacity_kg: num(value.capacity_kg), country: this.session.company()?.country };
    try {
      const site = await firstValueFrom(this.data ? this.api.put<Site>(`sites/${this.data.ulid}`, body) : this.api.post<Site>('sites', body));
      // A new site changes what the user may do (company-wide roles cover it at once).
      await this.session.reload();
      this.toast.success('sites.saved');
      this.ref.close(site);
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

interface ZoneDialogData {
  site: Site;
  zone: Zone | null;
}

@Component({
  imports: [...DIALOG, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ t(data.zone ? 'sites.editZone' : 'sites.addZone') }} — {{ data.site.name }}</h2>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <mat-dialog-content>
          <div class="dw-form">
            <mat-form-field>
              <mat-label>{{ t('fields.code') }}</mat-label>
              <input matInput formControlName="code" maxlength="20" required />
              @if (form.controls.code.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ t('sites.zoneType') }}</mat-label>
              <mat-select formControlName="zone_type" required>
                @for (type of vocabulary()?.zone_types ?? []; track type.code) { <mat-option [value]="type.code">{{ type.name | dwLocalized }}</mat-option> }
              </mat-select>
            </mat-form-field>
            <mat-form-field class="dw-span-2"><mat-label>{{ t('fields.name') }}</mat-label><input matInput formControlName="name" maxlength="150" required /></mat-form-field>
            <mat-form-field><mat-label>{{ t('fields.capacityKg') }}</mat-label><input matInput formControlName="capacity_kg" inputmode="decimal" /></mat-form-field>
            <mat-form-field><mat-label>{{ t('sites.alertPct') }}</mat-label><input matInput type="number" formControlName="capacity_alert_pct" min="1" max="100" /></mat-form-field>
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
export class ZoneDialog {
  protected readonly data = inject<ZoneDialogData>(MAT_DIALOG_DATA);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly reference = inject(ReferenceData);
  private readonly ref = inject<MatDialogRef<ZoneDialog, Zone>>(MatDialogRef);

  protected readonly vocabulary = toSignal(this.reference.vocabulary());
  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    code: [this.data.zone?.code ?? '', [Validators.required, Validators.pattern(/^[A-Za-z0-9_-]+$/)]],
    name: [this.data.zone?.name ?? '', Validators.required],
    zone_type: [this.data.zone?.zone_type ?? '', Validators.required],
    capacity_kg: [this.data.zone?.capacity_kg ?? '', DECIMAL],
    capacity_alert_pct: [this.data.zone?.capacity_alert_pct ?? 90, [Validators.min(1), Validators.max(100)]],
  });

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const body = { ...value, capacity_kg: num(value.capacity_kg) };
    try {
      const zone = await firstValueFrom(this.data.zone ? this.api.put<Zone>(`zones/${this.data.zone.ulid}`, body) : this.api.post<Zone>(`sites/${this.data.site.ulid}/zones`, body));
      this.reference.refreshZones(this.data.site.ulid);
      this.toast.success('sites.zoneSaved');
      this.ref.close(zone);
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

/** M1-01: the company's sites. */
@Component({
  imports: [MatButtonModule, MatCheckboxModule, MatIconModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, SearchComponent, KgPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('sites.title')" [subtitle]="t('sites.subtitle')">
        @if (canManage) { <button mat-flat-button type="button" (click)="create()"><mat-icon>add</mat-icon>{{ t('sites.new') }}</button> }
      </dw-page-header>
      <div class="dw-panel">
        <div class="filters">
          <dw-search [placeholder]="t('common.search')" (queryChange)="search.set($event); list.reload()" />
          <mat-checkbox [checked]="inactive()" (change)="inactive.set($event.checked); list.reload()">{{ t('sites.showInactive') }}</mat-checkbox>
        </div>
        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1" [pageSize]="list.perPage()"
          [clickable]="true" [emptyTitle]="t('sites.emptyTitle')" [emptyHint]="t('sites.emptyHint')" emptyIcon="factory"
          (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="open($event)" (retry)="list.reload()">
          <ng-template dwCell="code" let-site><span class="dw-mono">{{ site.code }}</span></ng-template>
          <ng-template dwCell="kind" let-site>{{ t('sites.kinds.' + site.site_kind) }}</ng-template>
          <ng-template dwCell="zones" let-site>{{ t('sites.zonesCount', { count: site.zones_count }) }}</ng-template>
          <ng-template dwCell="capacity" let-site>{{ site.capacity_kg | dwKg: 'auto' }}</ng-template>
          <ng-template dwCell="status" let-site><dw-status kind="member" [value]="site.is_active ? 'active' : 'suspended'" [label]="t(site.is_active ? 'common.active' : 'common.inactive')" /></ng-template>
          @if (canManage) { <button dwEmptyAction mat-flat-button type="button" (click)="create()"><mat-icon>add</mat-icon>{{ t('sites.new') }}</button> }
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: '.filters { display: flex; align-items: center; gap: var(--dw-4); flex-wrap: wrap; padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }',
})
export class SitesPage {
  private readonly api = inject(Api);
  private readonly dialog = inject(MatDialog);
  private readonly router = inject(Router);

  protected readonly canManage = inject(Session).can('sites.manage');
  protected readonly search = signal('');
  protected readonly inactive = signal(false);
  protected readonly list = new ListStore<Site>((query) => this.api.list<Site>('sites', { ...query, search: this.search(), with_inactive: this.inactive() }));
  protected readonly columns: Column<Site>[] = [
    { key: 'code', header: 'fields.code', width: '110px' },
    { key: 'name', header: 'fields.name', value: (site) => site.name },
    { key: 'city', header: 'fields.city', value: (site) => site.city, hideBelow: 640 },
    { key: 'kind', header: 'sites.kind', hideBelow: 900 },
    { key: 'zones', header: 'sites.zones' },
    { key: 'capacity', header: 'fields.capacityKg', align: 'end', hideBelow: 900 },
    { key: 'status', header: 'fields.status' },
  ];

  protected open(site: Site): void {
    void this.router.navigate(['/sites', site.ulid]);
  }

  protected create(): void {
    this.dialog.open<SiteDialog, Site | null, Site>(SiteDialog, { data: null }).afterClosed().subscribe((site) => site && this.open(site));
  }
}

/** A site and its zones. Zones holding waste cannot be closed; nothing is ever deleted (traceability). */
@Component({
  imports: [MatButtonModule, MatIconModule, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, KgPipe, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (site(); as s) {
          <dw-page-header [title]="s.name" [subtitle]="s.code" back="/sites" [backLabel]="t('sites.title')">
            <dw-status dwTitleBadge kind="member" [value]="s.is_active ? 'active' : 'suspended'" [label]="t(s.is_active ? 'common.active' : 'common.inactive')" />
            @if (canManage) {
              <button mat-stroked-button type="button" (click)="toggleSite()">{{ t(s.is_active ? 'common.deactivate' : 'common.activate') }}</button>
              <button mat-stroked-button type="button" (click)="edit()"><mat-icon>edit</mat-icon>{{ t('common.edit') }}</button>
            }
          </dw-page-header>

          <div class="dw-stack">
            <section class="dw-panel dw-panel-body">
              <dl class="dw-facts">
                <div><dt>{{ t('sites.kind') }}</dt><dd>{{ t('sites.kinds.' + s.site_kind) }}</dd></div>
                <div><dt>{{ t('fields.address') }}</dt><dd>{{ s.address_line1 ?? '—' }} {{ s.postal_code }} {{ s.city }}</dd></div>
                <div><dt>{{ t('sites.regulatoryId') }}</dt><dd>{{ s.regulatory_identifier ?? '—' }}</dd></div>
                <div><dt>{{ t('fields.capacityKg') }}</dt><dd>{{ s.capacity_kg | dwKg: 'auto' }}</dd></div>
              </dl>
            </section>

            <section class="dw-panel">
              <div class="dw-panel-head">
                <h2 class="dw-h2">{{ t('sites.zones') }}</h2><span class="dw-spacer"></span>
                @if (canManageZones()) { <button mat-stroked-button type="button" (click)="editZone(null)"><mat-icon>add</mat-icon>{{ t('sites.addZone') }}</button> }
              </div>
              <table>
                <thead><tr><th>{{ t('fields.code') }}</th><th>{{ t('fields.name') }}</th><th>{{ t('sites.zoneType') }}</th><th class="dw-num">{{ t('fields.capacityKg') }}</th><th>{{ t('fields.status') }}</th><th></th></tr></thead>
                <tbody>
                  @for (zone of s.zones ?? []; track zone.ulid) {
                    <tr [class.off]="!zone.is_active">
                      <td class="dw-mono">{{ zone.code }}</td>
                      <td>{{ zone.name }} @if (zone.is_waste_storage) { <dw-status kind="lot" value="stored" [label]="t('sites.storage')" /> }</td>
                      <td>{{ zoneType(zone.zone_type) | dwLocalized: (zone.zone_type ?? '—') }}</td>
                      <td class="dw-num">{{ zone.capacity_kg | dwKg: 'auto' }}</td>
                      <td><dw-status kind="member" [value]="zone.is_active ? 'active' : 'suspended'" [label]="t(zone.is_active ? 'common.active' : 'common.inactive')" /></td>
                      <td class="dw-num">
                        @if (canManageZones()) {
                          <button mat-button type="button" (click)="editZone(zone)">{{ t('common.edit') }}</button>
                          <button mat-button type="button" (click)="toggleZone(zone)">{{ t(zone.is_active ? 'common.deactivate' : 'common.activate') }}</button>
                        }
                      </td>
                    </tr>
                  }
                </tbody>
              </table>
            </section>
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { text-align: start; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dw-ink-3); padding: 8px 12px; border-block-end: 1px solid var(--dw-line); }
    td { padding: 6px 12px; border-block-end: 1px solid var(--dw-line-soft); }
    tr:last-child td { border-block-end: 0; } tr.off td { color: var(--dw-ink-3); }
  `,
})
export class SiteDetailPage implements OnInit {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly dialog = inject(MatDialog);
  private readonly confirm = inject(Confirm);
  private readonly toast = inject(Toast);
  private readonly reference = inject(ReferenceData);

  readonly ulid = input.required<string>();
  protected readonly site = signal<Site | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly canManage = this.session.can('sites.manage');
  private readonly zoneTypes = toSignal(this.reference.vocabulary().pipe(map((vocabulary) => new Map(vocabulary.zone_types.map((type) => [type.code, type.name])))));

  ngOnInit(): void {
    this.load();
  }

  /** "Confection" rather than the stored code "sewing". */
  protected zoneType(code: string | null): Localized | null {
    return (code && this.zoneTypes()?.get(code)) || null;
  }

  protected canManageZones(): boolean {
    return this.session.can('zones.manage', this.ulid());
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get<Site>(`sites/${this.ulid()}`).subscribe({
      next: (site) => {
        this.site.set(site);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected edit(): void {
    this.dialog.open<SiteDialog, Site | null, Site>(SiteDialog, { data: this.site() }).afterClosed().subscribe((site) => site && this.load());
  }

  protected editZone(zone: Zone | null): void {
    const site = this.site();
    if (site) {
      this.dialog.open<ZoneDialog, ZoneDialogData, Zone>(ZoneDialog, { data: { site, zone } }).afterClosed().subscribe((saved) => saved && this.load());
    }
  }

  protected async toggleSite(): Promise<void> {
    const site = this.site();
    if (!site) {
      return;
    }
    if (site.is_active && !(await this.confirm.ask({ title: this.toast.text('sites.closeConfirm', { name: site.name }), message: this.toast.text('sites.closeHint'), danger: true }))) {
      return;
    }
    await this.run(this.api.post(`sites/${site.ulid}/${site.is_active ? 'deactivate' : 'activate'}`));
    await this.session.reload();
  }

  protected async toggleZone(zone: Zone): Promise<void> {
    await this.run(this.api.post(`zones/${zone.ulid}/${zone.is_active ? 'deactivate' : 'activate'}`));
    this.reference.refreshZones(this.ulid());
  }

  private async run(request: ReturnType<Api['post']>): Promise<void> {
    try {
      await firstValueFrom(request);
      this.toast.success('common.saved');
      this.load();
    } catch (error) {
      this.toast.error(error);
    }
  }
}
