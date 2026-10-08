import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom, map } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';

import { Api } from '../../core/api/api';
import { Localized, Site, Zone } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { CodePipe, KgPipe, LocalizedPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { StateComponent } from '../../ui/state';
import { CheckboxComponent } from '../../ui/checkbox';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';
import { FilterBarComponent } from '../../ui/filter-bar';
import { ZoneFillComponent } from '../../ui/zone-fill';

const DIALOG = [ReactiveFormsModule, TranslocoDirective] as const;
const DECIMAL = Validators.pattern(/^\d+([.,]\d{1,3})?$/);
const num = (value: string): string | null => (value.trim() === '' ? null : value.replace(',', '.'));

@Component({
  imports: [...DIALOG, ButtonDirective, InputText, ...FIELD, ...SELECT, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'sites.edit' : 'sites.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field [label]="t('fields.code')">
              <input pInputText formControlName="code" maxlength="20" required />
            </dw-field>
            <dw-field [label]="t('sites.kind')">
              <dw-select formControlName="site_kind">
                @for (kind of kinds; track kind) { <dw-option [value]="kind" [label]="t('sites.kinds.' + kind)" /> }
              </dw-select>
            </dw-field>
            <dw-field class="dw-span-2" [label]="t('fields.name')"><input pInputText formControlName="name" maxlength="150" required /></dw-field>
            <dw-field class="dw-span-2" [label]="t('fields.address')"><input pInputText formControlName="address_line1" /></dw-field>
            <dw-field [label]="t('fields.city')"><input pInputText formControlName="city" /></dw-field>
            <dw-field [label]="t('fields.postalCode')"><input pInputText formControlName="postal_code" /></dw-field>
            <dw-field [label]="t('sites.regulatoryId')"><input pInputText formControlName="regulatory_identifier" /></dw-field>
            <dw-field [label]="t('fields.capacityKg')"><input pInputText formControlName="capacity_kg" inputmode="decimal" /></dw-field>
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: '.dw-dialog-body { min-width: min(600px, 82vw); }',
})
export class SiteDialog {
  protected readonly data = dialogData<Site | null>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly session = inject(Session);
  private readonly ref = inject(DialogRef);

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
  imports: [...DIALOG, LocalizedPipe, ButtonDirective, InputText, ...FIELD, ...SELECT, ...DIALOG_PARTS, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data.zone ? 'sites.editZone' : 'sites.addZone') }} — {{ data.site.name }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field [label]="t('fields.code')">
              <input pInputText formControlName="code" maxlength="20" required />
            </dw-field>
            <dw-field [label]="t('sites.zoneType')">
              <dw-select formControlName="zone_type">
                <dw-option value="" [label]="t('sites.noZoneType')" />
                @for (type of vocabulary()?.zone_types ?? []; track type.code) { <dw-option [value]="type.code" [label]="type.name | dwLocalized" /> }
              </dw-select>
            </dw-field>
            <dw-field class="dw-span-2" [label]="t('fields.name')"><input pInputText formControlName="name" maxlength="150" required /></dw-field>

            <section class="dw-span-2 part">
              <h3 class="dw-h3">{{ t('sites.capacity') }}</h3>
              <p class="dw-muted">{{ t('sites.capacityHint') }}</p>
              @for (line of capacities.controls; track line; let i = $index) {
                <div class="line" [formGroup]="line">
                  <dw-select formControlName="packaging_type" [ariaLabel]="t('lots.packaging')" [placeholder]="t('lots.packaging')">
                    @for (packaging of vocabulary()?.packaging_types ?? []; track packaging.code) {
                      <dw-option [value]="packaging.code" [label]="packaging.name | dwLocalized" [disabled]="taken(packaging.code, i)" />
                    }
                  </dw-select>
                  <input pInputText type="number" formControlName="max_count" min="1" max="100000" [attr.aria-label]="t('sites.maxLots')" [placeholder]="t('sites.maxLots')" />
                  <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="capacities.removeAt(i)" [attr.aria-label]="t('common.remove')"><dw-icon>close</dw-icon></button>
                </div>
              }
              <button pButton text type="button" class="add" (click)="addCapacity()"><dw-icon>add</dw-icon>{{ t('sites.addCapacity') }}</button>
            </section>
            <dw-field [label]="t('sites.capacityKgLimit')"><input pInputText formControlName="capacity_kg" inputmode="decimal" /></dw-field>
            <dw-field [label]="t('sites.alertPct')"><input pInputText type="number" formControlName="capacity_alert_pct" min="1" max="100" /></dw-field>

            <section class="dw-span-2 part">
              <h3 class="dw-h3">{{ t('sites.reservedFor') }}</h3>
              <p class="dw-muted">{{ t('sites.reservedHint') }}</p>
            </section>
            <dw-field [label]="t('sites.acceptedTypes')">
              <dw-select formControlName="accepted_waste_types" multiple clearable [placeholder]="t('sites.anyType')">
                @for (type of types(); track type.ulid) { <dw-option [value]="type.ulid" [label]="type.name" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('sites.acceptedColors')">
              <dw-select formControlName="accepted_colors" multiple clearable [placeholder]="t('sites.anyColor')">
                @for (color of vocabulary()?.color_families ?? []; track color.code) { <dw-option [value]="color.code" [label]="color.name | dwLocalized" /> }
              </dw-select>
            </dw-field>
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { inline-size: min(640px, 82vw); }
    .part { display: grid; gap: var(--dw-2); margin-block-start: var(--dw-2); }
    .part p { margin: 0; font-size: 13px; }
    .line { display: grid; grid-template-columns: minmax(0, 1fr) 150px auto; gap: var(--dw-2); align-items: center; }
    .add { justify-self: start; }
  `,
})
export class ZoneDialog {
  protected readonly data = dialogData<ZoneDialogData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly reference = inject(ReferenceData);
  private readonly ref = inject(DialogRef);

  private readonly fb = inject(FormBuilder);

  protected readonly vocabulary = toSignal(this.reference.vocabulary());
  protected readonly types = toSignal(this.reference.wasteTypes(), { initialValue: [] });
  protected readonly busy = signal(false);
  /** "This zone holds 40 bales, or 100 bags": one line per packaging. */
  protected readonly capacities = this.fb.array((this.data.zone?.capacities ?? []).map((line) => this.capacityLine(line.packaging_type, line.max_count)));
  protected readonly form = this.fb.nonNullable.group({
    code: [this.data.zone?.code ?? '', [Validators.required, Validators.pattern(/^[A-Za-z0-9_-]+$/)]],
    name: [this.data.zone?.name ?? '', Validators.required],
    zone_type: [this.data.zone?.zone_type ?? ''],
    capacity_kg: [this.data.zone?.capacity_kg ?? '', DECIMAL],
    capacity_alert_pct: [this.data.zone?.capacity_alert_pct ?? 90, [Validators.min(1), Validators.max(100)]],
    accepted_waste_types: [this.data.zone?.accepted_waste_types ?? []],
    accepted_colors: [this.data.zone?.accepted_colors ?? []],
    capacities: this.capacities,
  });

  private capacityLine(packaging = '', count: number | null = null): FormGroup {
    return this.fb.nonNullable.group({
      packaging_type: [packaging, Validators.required],
      max_count: [count, [Validators.required, Validators.min(1), Validators.max(100000)]],
    });
  }

  protected addCapacity(): void {
    this.capacities.push(this.capacityLine());
  }

  /** A packaging already used on another line cannot be chosen twice. */
  protected taken(code: string, index: number): boolean {
    return this.capacities.controls.some((line, i) => i !== index && line.value.packaging_type === code);
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const body = { ...value, zone_type: value.zone_type || null, capacity_kg: num(value.capacity_kg) };
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
  imports: [TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, SearchComponent, KgPipe, IconComponent, ButtonDirective, CheckboxComponent, FilterBarComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('sites.title')" [subtitle]="t('sites.subtitle')">
        @if (canManage) { <button pButton type="button" (click)="create()"><dw-icon>add</dw-icon>{{ t('sites.new') }}</button> }
      </dw-page-header>
      <div class="dw-panel">
        <dw-filter-bar [active]="(search() ? 1 : 0) + (inactive() ? 1 : 0)" (cleared)="search.set(''); inactive.set(false); list.reload()">
          <dw-search [placeholder]="t('common.search')" [query]="search()" (queryChange)="search.set($event); list.reload()" />
          <dw-checkbox [checked]="inactive()" (checkedChange)="inactive.set($event); list.reload()">{{ t('sites.showInactive') }}</dw-checkbox>
        </dw-filter-bar>
        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1" [pageSize]="list.perPage()"
          [clickable]="true" [emptyTitle]="t('sites.emptyTitle')" [emptyHint]="t('sites.emptyHint')" emptyIcon="factory"
          (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="open($event)" (retry)="list.reload()">
          <ng-template dwCell="code" let-site><span class="dw-mono">{{ site.code }}</span></ng-template>
          <ng-template dwCell="kind" let-site>{{ t('sites.kinds.' + site.site_kind) }}</ng-template>
          <ng-template dwCell="zones" let-site>{{ t('sites.zonesCount', { count: site.zones_count }) }}</ng-template>
          <ng-template dwCell="capacity" let-site>{{ site.capacity_kg | dwKg: 'auto' }}</ng-template>
          <ng-template dwCell="status" let-site><dw-status kind="member" [value]="site.is_active ? 'active' : 'suspended'" [label]="t(site.is_active ? 'common.active' : 'common.inactive')" /></ng-template>
          @if (canManage) { <button dwEmptyAction pButton type="button" (click)="create()"><dw-icon>add</dw-icon>{{ t('sites.new') }}</button> }
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: '.filters { display: flex; align-items: center; gap: var(--dw-4); flex-wrap: wrap; padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }',
})
export class SitesPage {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
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
  imports: [TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, KgPipe, CodePipe, LocalizedPipe, IconComponent, ButtonDirective, ZoneFillComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (site(); as s) {
          <dw-page-header [title]="s.name" [subtitle]="s.code" back="/sites" [backLabel]="t('sites.title')">
            <dw-status dwTitleBadge kind="member" [value]="s.is_active ? 'active' : 'suspended'" [label]="t(s.is_active ? 'common.active' : 'common.inactive')" />
            @if (canManage) {
              <button pButton severity="secondary" outlined type="button" (click)="toggleSite()">{{ t(s.is_active ? 'common.deactivate' : 'common.activate') }}</button>
              <button pButton severity="secondary" outlined type="button" (click)="edit()"><dw-icon>edit</dw-icon>{{ t('common.edit') }}</button>
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
                @if (canManageZones()) { <button pButton severity="secondary" outlined type="button" (click)="editZone(null)"><dw-icon>add</dw-icon>{{ t('sites.addZone') }}</button> }
              </div>
              <table class="dw-table">
                <thead><tr><th>{{ t('fields.code') }}</th><th>{{ t('fields.name') }}</th><th>{{ t('sites.capacity') }}</th><th>{{ t('stock.zoneFill') }}</th><th>{{ t('sites.reservedFor') }}</th><th>{{ t('fields.status') }}</th><th></th></tr></thead>
                <tbody>
                  @for (zone of s.zones ?? []; track zone.ulid) {
                    <tr [class.off]="!zone.is_active">
                      <td class="dw-mono">{{ zone.code }}</td>
                      <td>
                        {{ zone.name }} @if (zone.is_waste_storage) { <dw-status kind="lot" value="stored" [label]="t('sites.storage')" /> }
                        @if (zone.zone_type) { <div class="dw-faint">{{ zoneType(zone.zone_type) | dwLocalized: zone.zone_type }}</div> }
                      </td>
                      <td>
                        @for (line of zone.capacities; track line.packaging_type) { <div>{{ line.max_count }} × {{ line.packaging_type | dwCode: 'packaging' }}</div> }
                        @if (hasWeightLimit(zone)) { <div>{{ zone.capacity_kg | dwKg: 'auto' }}</div> }
                        @if (zone.capacities.length === 0 && !hasWeightLimit(zone)) { <span class="dw-faint">{{ t('sites.noLimit') }}</span> }
                      </td>
                      <td><dw-zone-fill [fill]="zone.fill" [details]="false" [placeholder]="zone.fill.lots > 0 ? t('dashboard.lots', { count: zone.fill.lots }) : '—'" /></td>
                      <td>
                        @if (zone.accepted_waste_types.length === 0 && zone.accepted_colors.length === 0) {
                          <span class="dw-faint">{{ t('sites.anything') }}</span>
                        } @else {
                          @if (zone.accepted_waste_types.length > 0) { <div>{{ typeNames(zone.accepted_waste_types) }}</div> }
                          @if (zone.accepted_colors.length > 0) { <div class="dw-faint">@for (color of zone.accepted_colors; track color; let last = $last) { {{ color | dwCode: 'color' }}@if (!last) { , } }</div> }
                        }
                      </td>
                      <td><dw-status kind="member" [value]="zone.is_active ? 'active' : 'suspended'" [label]="t(zone.is_active ? 'common.active' : 'common.inactive')" /></td>
                      <td class="dw-num">
                        @if (canManageZones()) {
                          <button pButton text type="button" (click)="editZone(zone)">{{ t('common.edit') }}</button>
                          <button pButton text type="button" (click)="toggleZone(zone)">{{ t(zone.is_active ? 'common.deactivate' : 'common.activate') }}</button>
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
    tr.off td { color: var(--dw-ink-3); }
  `,
})
export class SiteDetailPage implements OnInit {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly dialog = inject(Dialogs);
  private readonly confirm = inject(Confirm);
  private readonly toast = inject(Toast);
  private readonly reference = inject(ReferenceData);

  readonly ulid = input.required<string>();
  protected readonly site = signal<Site | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly canManage = this.session.can('sites.manage');
  private readonly typeNameByUlid = toSignal(this.reference.wasteTypes().pipe(map((types) => new Map(types.map((type) => [type.ulid, type.name])))));
  private readonly zoneTypes = toSignal(this.reference.vocabulary().pipe(map((vocabulary) => new Map(vocabulary.zone_types.map((type) => [type.code, type.name])))));

  ngOnInit(): void {
    this.load();
  }

  /** "Confection" rather than the stored code "sewing". */
  protected zoneType(code: string | null): Localized | null {
    return (code && this.zoneTypes()?.get(code)) || null;
  }

  protected hasWeightLimit(zone: Zone): boolean {
    return Number(zone.capacity_kg ?? 0) > 0;
  }

  /** Names of the waste types a zone is meant for; a type that is no longer active is counted, not named. */
  protected typeNames(ulids: string[]): string {
    const names = this.typeNameByUlid();
    const known = ulids.map((ulid) => names?.get(ulid)).filter((name): name is string => Boolean(name));
    const others = ulids.length - known.length;

    return [...known, ...(others > 0 ? [`+${others}`] : [])].join(', ');
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
