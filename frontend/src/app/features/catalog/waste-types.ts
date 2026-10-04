import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormArray, FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MAT_DIALOG_DATA, MatDialog, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatTabsModule } from '@angular/material/tabs';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';

import { Api } from '../../core/api/api';
import { Localized, WasteType } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { LocalizedPipe } from '../../core/format/pipes';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { StateComponent } from '../../ui/state';

interface CatalogItem {
  code: string;
  name: Localized;
  waste_family: string | null;
  regulatory_code: string | null;
  is_hazardous: boolean;
  treatment_channel: string | null;
  waste_type: string | null;
}

const DECIMAL = Validators.pattern(/^\d+([.,]\d{1,3})?$/);
const num = (value: string | null): string | null => (!value || value.trim() === '' ? null : value.replace(',', '.'));

/** M1-02 / M1-04: a company's own type, or the editable attributes of an existing one. */
@Component({
  imports: [ReactiveFormsModule, MatButtonModule, MatCheckboxModule, MatDialogModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, TranslocoDirective, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ t(data ? 'wasteTypes.edit' : 'wasteTypes.new') }}</h2>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <mat-dialog-content>
          @if (data) { <p class="dw-muted note">{{ t('wasteTypes.frozen') }}</p> }
          <div class="dw-form">
            @if (!data) {
              <mat-form-field>
                <mat-label>{{ t('fields.code') }}</mat-label>
                <input matInput formControlName="code" maxlength="40" required />
                @if (form.controls.code.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
              </mat-form-field>
              <mat-form-field>
                <mat-label>{{ t('wasteTypes.family') }}</mat-label>
                <mat-select formControlName="waste_family" required>
                  @for (family of vocabulary()?.waste_families ?? []; track family.code) { <mat-option [value]="family.code">{{ family.name | dwLocalized }}</mat-option> }
                </mat-select>
              </mat-form-field>
            }
            <mat-form-field class="dw-span-2"><mat-label>{{ t('fields.name') }}</mat-label><input matInput formControlName="name" maxlength="150" required /></mat-form-field>
            @if (!data) {
              <mat-form-field>
                <mat-label>{{ t('lots.regulatoryCode') }}</mat-label>
                <mat-select formControlName="regulatory_code">
                  <mat-option [value]="null">—</mat-option>
                  @for (code of vocabulary()?.regulatory_waste_codes ?? []; track code.code) {
                    <mat-option [value]="code.code">{{ code.code }} — {{ code.label | dwLocalized }}@if (code.is_hazardous) { * }</mat-option>
                  }
                </mat-select>
              </mat-form-field>
              <mat-checkbox formControlName="is_hazardous">{{ t('common.hazardous') }}</mat-checkbox>
            }
            <mat-form-field>
              <mat-label>{{ t('wasteTypes.unit') }}</mat-label>
              <mat-select formControlName="unit" required>
                @for (unit of vocabulary()?.units ?? []; track unit.code) { <mat-option [value]="unit.code">{{ unit.name | dwLocalized }} ({{ unit.symbol }})</mat-option> }
              </mat-select>
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ t('wasteTypes.channel') }}</mat-label>
              <mat-select formControlName="treatment_channel">
                <mat-option [value]="null">—</mat-option>
                @for (channel of vocabulary()?.treatment_channels ?? []; track channel.code) { <mat-option [value]="channel.code">{{ channel.name | dwLocalized }}</mat-option> }
              </mat-select>
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ t('lots.packaging') }}</mat-label>
              <mat-select formControlName="packaging_type">
                <mat-option [value]="null">—</mat-option>
                @for (packaging of vocabulary()?.packaging_types ?? []; track packaging.code) { <mat-option [value]="packaging.code">{{ packaging.name | dwLocalized }}</mat-option> }
              </mat-select>
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ t('lots.color') }}</mat-label>
              <mat-select formControlName="color_family">
                <mat-option [value]="null">—</mat-option>
                @for (color of vocabulary()?.color_families ?? []; track color.code) { <mat-option [value]="color.code">{{ color.name | dwLocalized }}</mat-option> }
              </mat-select>
            </mat-form-field>
            <mat-form-field><mat-label>{{ t('lots.grammage') }}</mat-label><input matInput formControlName="default_grammage_gsm" inputmode="decimal" /></mat-form-field>
            <mat-form-field><mat-label>{{ t('wasteTypes.density') }}</mat-label><input matInput formControlName="density_kg_m3" inputmode="decimal" /></mat-form-field>
            <mat-form-field><mat-label>{{ t('wasteTypes.unitWeight') }}</mat-label><input matInput formControlName="unit_weight_kg" inputmode="decimal" /></mat-form-field>

            <h3 class="dw-h3 dw-form-section">{{ t('wasteTypes.defaultComposition') }}</h3>
            <div class="dw-span-2 composition" formArrayName="composition">
              @for (group of composition.controls; track group; let index = $index) {
                <div class="line" [formGroupName]="index">
                  <mat-form-field>
                    <mat-label>{{ t('wasteTypes.material') }}</mat-label>
                    <mat-select formControlName="material" required>
                      @for (material of vocabulary()?.materials ?? []; track material.code) { <mat-option [value]="material.code">{{ material.name | dwLocalized }}</mat-option> }
                    </mat-select>
                  </mat-form-field>
                  <mat-form-field class="pct"><mat-label>{{ t('wasteTypes.pct') }}</mat-label><input matInput formControlName="pct" inputmode="decimal" required /></mat-form-field>
                  <button mat-icon-button type="button" (click)="composition.removeAt(index)" [attr.aria-label]="t('common.remove')"><mat-icon>close</mat-icon></button>
                </div>
              }
              <div class="dw-row">
                <button mat-stroked-button type="button" (click)="addMaterial()"><mat-icon>add</mat-icon>{{ t('wasteTypes.addMaterial') }}</button>
                @if (composition.length > 0) { <span [class.bad]="total() !== 100" role="status">{{ t('wasteTypes.compositionTotal', { total: total() }) }}</span> }
              </div>
              @if (form.controls.composition.errors?.['server']; as message) { <p class="bad" role="alert">{{ message }}</p> }
            </div>
          </div>
        </mat-dialog-content>
        <mat-dialog-actions align="end">
          <button mat-button type="button" mat-dialog-close>{{ t('common.cancel') }}</button>
          <button mat-flat-button type="submit" [disabled]="busy() || (composition.length > 0 && total() !== 100)">{{ t('common.save') }}</button>
        </mat-dialog-actions>
      </form>
    </ng-container>
  `,
  styles: `
    mat-dialog-content { min-width: min(640px, 84vw); } .note { margin-block-end: var(--dw-3); font-size: 13px; }
    .composition { display: grid; gap: var(--dw-1); } .line { display: flex; gap: var(--dw-2); align-items: center; } .pct { max-width: 110px; }
    .bad { color: var(--dw-danger); font-weight: 600; }
  `,
})
export class WasteTypeDialog {
  protected readonly data = inject<WasteType | null>(MAT_DIALOG_DATA);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly reference = inject(ReferenceData);
  private readonly fb = inject(FormBuilder).nonNullable;
  private readonly ref = inject<MatDialogRef<WasteTypeDialog, WasteType>>(MatDialogRef);

  protected readonly vocabulary = toSignal(this.reference.vocabulary());
  protected readonly busy = signal(false);
  protected readonly total = signal(0);
  protected readonly form = this.fb.group({
    code: [this.data?.code ?? '', [Validators.required, Validators.pattern(/^[A-Za-z0-9_-]+$/)]],
    name: [this.data?.name ?? '', Validators.required],
    waste_family: [this.data?.waste_family ?? '', Validators.required],
    regulatory_code: [this.data?.regulatory_code ?? null as string | null],
    is_hazardous: [this.data?.is_hazardous ?? false],
    unit: [this.data?.unit ?? 'kg', Validators.required],
    treatment_channel: [this.data?.treatment_channel ?? null as string | null],
    packaging_type: [this.data?.packaging_type ?? null as string | null],
    color_family: [this.data?.color_family ?? null as string | null],
    default_grammage_gsm: [this.data?.default_grammage_gsm ?? '', DECIMAL],
    density_kg_m3: [this.data?.density_kg_m3 ?? '', DECIMAL],
    unit_weight_kg: [this.data?.unit_weight_kg ?? '', DECIMAL],
    composition: this.fb.array((this.data?.default_composition ?? []).map((line) => this.materialGroup(line.material, String(Number(line.pct))))),
  });

  constructor() {
    this.form.controls.composition.valueChanges.subscribe(() => this.recompute());
    this.recompute();
  }

  protected get composition(): FormArray<FormGroup> {
    return this.form.controls.composition as FormArray<FormGroup>;
  }

  protected addMaterial(): void {
    this.composition.push(this.materialGroup('', ''));
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const { composition, ...value } = this.form.getRawValue();
    const body = {
      ...value, default_grammage_gsm: num(value.default_grammage_gsm), density_kg_m3: num(value.density_kg_m3), unit_weight_kg: num(value.unit_weight_kg),
      default_composition: composition.length > 0 ? composition.map((line) => ({ material: line['material'] as string, pct: String(line['pct']).replace(',', '.') })) : null,
    };
    try {
      const type = await firstValueFrom(this.data ? this.api.put<WasteType>(`waste-types/${this.data.ulid}`, body) : this.api.post<WasteType>('waste-types', body));
      this.reference.refreshWasteTypes();
      this.toast.success('wasteTypes.saved');
      this.ref.close(type);
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }

  private materialGroup(material: string, pct: string): FormGroup {
    return this.fb.group({ material: [material, Validators.required], pct: [pct, [Validators.required, Validators.pattern(/^\d+([.,]\d{1,2})?$/)]] });
  }

  private recompute(): void {
    this.total.set(Math.round(this.form.controls.composition.getRawValue().reduce((sum, line) => sum + (Number(String(line['pct']).replace(',', '.')) || 0), 0) * 100) / 100);
  }
}

/** M1-02 / M1-03: the company's waste types and the reference catalogue they come from. */
@Component({
  imports: [MatButtonModule, MatCheckboxModule, MatIconModule, MatTabsModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, SearchComponent, StateComponent, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('wasteTypes.title')" [subtitle]="t('wasteTypes.subtitle')">
        @if (canManage) { <button mat-flat-button type="button" (click)="edit(null)"><mat-icon>add</mat-icon>{{ t('wasteTypes.new') }}</button> }
      </dw-page-header>

      <div class="dw-panel">
        <mat-tab-group animationDuration="0" (selectedIndexChange)="$event === 1 && loadCatalog()">
          <mat-tab [label]="t('wasteTypes.mine')">
            <div class="filters">
              <dw-search [placeholder]="t('common.search')" (queryChange)="search.set($event); list.reload()" />
              <mat-checkbox [checked]="inactive()" (change)="inactive.set($event.checked); list.reload()">{{ t('wasteTypes.showInactive') }}</mat-checkbox>
            </div>
            <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1" [pageSize]="list.perPage()"
              [clickable]="canManage" [emptyTitle]="t('wasteTypes.emptyTitle')" [emptyHint]="t('wasteTypes.emptyHint')" emptyIcon="category"
              (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="edit($event)" (retry)="list.reload()">
              <ng-template dwCell="code" let-type><span class="dw-mono">{{ type.code }}</span></ng-template>
              <ng-template dwCell="name" let-type>{{ type.name }} @if (type.is_hazardous) { <dw-status kind="alert" value="warning" [label]="t('common.hazardous')" /> }</ng-template>
              <ng-template dwCell="regulatory" let-type><span class="dw-mono">{{ type.regulatory_code ?? '—' }}</span></ng-template>
              <ng-template dwCell="channel" let-type>{{ type.treatment_channel ? t('channels.' + type.treatment_channel) : '—' }}</ng-template>
              <ng-template dwCell="composition" let-type>
                @for (line of type.default_composition ?? []; track line.material) { <span class="chip">{{ line.material }} {{ +line.pct }} %</span> } @empty { — }
              </ng-template>
              <ng-template dwCell="status" let-type>
                <dw-status kind="member" [value]="type.is_active ? 'active' : 'suspended'" [label]="t(type.is_active ? 'common.active' : 'common.inactive')" />
              </ng-template>
              <ng-template dwCell="actions" let-type>
                @if (canManage) {
                  <button mat-button type="button" (click)="toggle(type); $event.stopPropagation()">{{ t(type.is_active ? 'common.deactivate' : 'common.activate') }}</button>
                }
              </ng-template>
            </dw-data-table>
          </mat-tab>
          <mat-tab [label]="t('wasteTypes.catalog')">
            <dw-state [loading]="catalogLoading()" [error]="catalogError()" (retry)="loadCatalog(true)">
              <ul class="catalog">
                @for (item of catalog(); track item.code) {
                  <li>
                    <div class="what">
                      <strong>{{ item.name | dwLocalized }}</strong>
                      @if (item.is_hazardous) { <dw-status kind="alert" value="warning" [label]="t('common.hazardous')" /> }
                      <span class="dw-faint dw-mono">{{ item.regulatory_code }}</span>
                      @if (item.treatment_channel) { <span class="dw-faint">· {{ t('channels.' + item.treatment_channel) }}</span> }
                    </div>
                    @if (item.waste_type) {
                      <dw-status kind="member" value="active" [label]="t('wasteTypes.activated')" />
                    } @else if (canManage) {
                      <button mat-stroked-button type="button" [disabled]="activating() === item.code" (click)="activate(item)">{{ t('wasteTypes.activate') }}</button>
                    }
                  </li>
                }
              </ul>
            </dw-state>
          </mat-tab>
        </mat-tab-group>
      </div>
    </ng-container>
  `,
  styles: `
    .filters { display: flex; align-items: center; gap: var(--dw-4); flex-wrap: wrap; padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }
    .chip { display: inline-block; padding: 1px 8px; margin-inline-end: 4px; border-radius: 999px; background: var(--dw-neutral-soft); font-size: 12px; }
    .catalog { list-style: none; margin: 0; padding: 0; }
    .catalog li { display: flex; align-items: center; gap: var(--dw-3); padding: 10px var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); min-height: 52px; }
    .catalog li:last-child { border-block-end: 0; }
    .what { flex: 1; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  `,
})
export class WasteTypesPage {
  private readonly api = inject(Api);
  private readonly dialog = inject(MatDialog);
  private readonly toast = inject(Toast);
  private readonly reference = inject(ReferenceData);

  protected readonly canManage = inject(Session).can('waste_types.manage');
  protected readonly search = signal('');
  protected readonly inactive = signal(false);
  protected readonly list = new ListStore<WasteType>((query) => this.api.list<WasteType>('waste-types', { ...query, search: this.search(), active_only: !this.inactive() }));
  protected readonly catalog = signal<CatalogItem[]>([]);
  protected readonly catalogLoading = signal(false);
  protected readonly catalogError = signal<unknown>(null);
  protected readonly activating = signal<string | null>(null);
  private readonly catalogLoaded = computed(() => this.catalog().length > 0);

  protected readonly columns: Column<WasteType>[] = [
    { key: 'code', header: 'fields.code', width: '120px' },
    { key: 'name', header: 'fields.name' },
    { key: 'regulatory', header: 'lots.regulatoryCode', hideBelow: 900 },
    { key: 'channel', header: 'wasteTypes.channel', hideBelow: 900 },
    { key: 'composition', header: 'lots.composition', hideBelow: 1200 },
    { key: 'status', header: 'fields.status' },
    { key: 'actions', header: '', align: 'end' },
  ];

  protected loadCatalog(force = false): void {
    if (this.catalogLoaded() && !force) {
      return;
    }
    this.catalogLoading.set(true);
    this.catalogError.set(null);
    this.api.get<CatalogItem[]>('waste-catalog').subscribe({
      next: (items) => {
        this.catalog.set(items);
        this.catalogLoading.set(false);
      },
      error: (error: unknown) => {
        this.catalogError.set(error);
        this.catalogLoading.set(false);
      },
    });
  }

  protected edit(type: WasteType | null): void {
    this.dialog.open<WasteTypeDialog, WasteType | null, WasteType>(WasteTypeDialog, { data: type }).afterClosed().subscribe((saved) => saved && this.list.reload());
  }

  /** Activation copies the catalogue defaults (code, hazard status, usual channel) into a type of the company. */
  protected async activate(item: CatalogItem): Promise<void> {
    this.activating.set(item.code);
    try {
      await firstValueFrom(this.api.post('waste-types', { catalog_item: item.code, code: item.code.toUpperCase().slice(0, 40) }));
      this.reference.refreshWasteTypes();
      this.toast.success('wasteTypes.saved');
      this.loadCatalog(true);
      this.list.reload();
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.activating.set(null);
    }
  }

  protected async toggle(type: WasteType): Promise<void> {
    try {
      await firstValueFrom(this.api.post(`waste-types/${type.ulid}/${type.is_active ? 'deactivate' : 'activate'}`));
      this.reference.refreshWasteTypes();
      this.list.reload();
    } catch (error) {
      this.toast.error(error);
    }
  }
}
