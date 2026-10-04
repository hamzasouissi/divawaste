import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormArray, FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ulid } from 'ulid';

import { Api } from '../../core/api/api';
import { Lot, Zone } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { LocalizedPipe, NumberPipe } from '../../core/format/pipes';

const DIALOG = [ReactiveFormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, TranslocoDirective] as const;
const WEIGHT = [Validators.required, Validators.min(0), Validators.max(999999), Validators.pattern(/^\d+([.,]\d{1,3})?$/)];
const decimal = (value: string | number): string => String(value).replace(',', '.');

/**
 * M2-01 on the desktop: site, zone, type and weight are the only required fields; the rest takes the
 * defaults of the waste type. The lot ULID is generated here so a double submission cannot create two lots.
 */
@Component({
  imports: [...DIALOG, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ t('lots.createTitle') }}</h2>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <mat-dialog-content>
          <div class="dw-form">
            <mat-form-field>
              <mat-label>{{ t('fields.site') }}</mat-label>
              <mat-select formControlName="site" required (selectionChange)="loadZones($event.value)">
                @for (site of sites; track site.ulid) { <mat-option [value]="site.ulid">{{ site.name }}</mat-option> }
              </mat-select>
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ t('fields.zone') }}</mat-label>
              <mat-select formControlName="zone" required>
                @for (zone of zones(); track zone.ulid) { <mat-option [value]="zone.ulid">{{ zone.name }}</mat-option> }
              </mat-select>
            </mat-form-field>
            <mat-form-field class="dw-span-2">
              <mat-label>{{ t('fields.wasteType') }}</mat-label>
              <mat-select formControlName="waste_type" required>
                @for (type of types(); track type.ulid) {
                  <mat-option [value]="type.ulid">{{ type.name }}@if (type.is_hazardous) { — {{ t('common.hazardous') }} }</mat-option>
                }
              </mat-select>
              @if (form.controls.waste_type.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ t('fields.netWeight') }}</mat-label>
              <input matInput formControlName="net_weight_kg" inputmode="decimal" required cdkFocusInitial />
              @if (form.controls.net_weight_kg.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
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
            <mat-form-field><mat-label>{{ t('lots.grammage') }}</mat-label><input matInput formControlName="grammage_gsm" inputmode="decimal" /></mat-form-field>
            <mat-form-field class="dw-span-2"><mat-label>{{ t('fields.notes') }}</mat-label><textarea matInput formControlName="notes" rows="2" maxlength="1000"></textarea></mat-form-field>
          </div>
        </mat-dialog-content>
        <mat-dialog-actions align="end">
          <button mat-button type="button" mat-dialog-close>{{ t('common.cancel') }}</button>
          <button mat-flat-button type="submit" [disabled]="busy()">{{ t('common.create') }}</button>
        </mat-dialog-actions>
      </form>
    </ng-container>
  `,
  styles: 'mat-dialog-content { min-width: min(620px, 82vw); }',
})
export class CreateLotDialog {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly reference = inject(ReferenceData);
  private readonly ref = inject<MatDialogRef<CreateLotDialog, Lot>>(MatDialogRef);

  protected readonly sites = inject(Session).sitesFor('lots.create');
  protected readonly types = toSignal(this.reference.wasteTypes(), { initialValue: [] });
  protected readonly vocabulary = toSignal(this.reference.vocabulary());
  protected readonly zones = signal<Zone[]>([]);
  protected readonly busy = signal(false);
  /** One identifier for the whole life of the dialog: retrying after a network error cannot duplicate the lot. */
  private readonly lotUlid = ulid();

  protected readonly form = inject(FormBuilder).nonNullable.group({
    site: [this.sites[0]?.ulid ?? '', Validators.required],
    zone: ['', Validators.required],
    waste_type: ['', Validators.required],
    net_weight_kg: ['', WEIGHT],
    packaging_type: [null as string | null],
    color_family: [null as string | null],
    grammage_gsm: [''],
    notes: [''],
  });

  constructor() {
    if (this.form.controls.site.value) {
      this.loadZones(this.form.controls.site.value);
    }
  }

  protected loadZones(site: string): void {
    this.form.controls.zone.reset('');
    this.reference.zones(site).subscribe((zones) => {
      this.zones.set(zones);
      this.form.controls.zone.setValue(zones[0]?.ulid ?? '');
    });
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    try {
      const lot = await firstValueFrom(this.api.post<Lot>('lots', {
        ...value, ulid: this.lotUlid, net_weight_kg: decimal(value.net_weight_kg), grammage_gsm: value.grammage_gsm ? decimal(value.grammage_gsm) : null, notes: value.notes || null,
      }));
      this.toast.success('lots.created', { number: lot.lot_number });
      this.ref.close(lot);
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

export interface LotActionData {
  lot: Lot;
}

/** Move a lot to a zone of its site (storing it if it was only created). */
@Component({
  imports: [...DIALOG],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ t(data.lot.status === 'stored' ? 'lots.move' : 'lots.store') }} — {{ data.lot.lot_number }}</h2>
      <mat-dialog-content>
        <mat-form-field>
          <mat-label>{{ t('lots.targetZone') }}</mat-label>
          <mat-select [formControl]="zone" required>
            @for (item of zones(); track item.ulid) { <mat-option [value]="item.ulid">{{ item.name }}</mat-option> }
          </mat-select>
        </mat-form-field>
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ t('common.cancel') }}</button>
        <button mat-flat-button type="button" [disabled]="busy() || zone.invalid" (click)="submit()">{{ t('common.confirm') }}</button>
      </mat-dialog-actions>
    </ng-container>
  `,
  styles: 'mat-dialog-content { min-width: min(420px, 80vw); }',
})
export class StoreLotDialog {
  protected readonly data = inject<LotActionData>(MAT_DIALOG_DATA);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject<MatDialogRef<StoreLotDialog, Lot>>(MatDialogRef);

  protected readonly zones = toSignal(inject(ReferenceData).zones(this.data.lot.site?.ulid ?? ''), { initialValue: [] });
  protected readonly zone = inject(FormBuilder).nonNullable.control(this.data.lot.zone?.ulid ?? '', Validators.required);
  protected readonly busy = signal(false);

  protected async submit(): Promise<void> {
    this.busy.set(true);
    try {
      this.ref.close(await firstValueFrom(this.api.post<Lot>(`lots/${this.data.lot.ulid}/store`, { zone: this.zone.value })));
      this.toast.success('lots.stored');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

/** M2-04: corrected weighing. The version is sent so two people cannot overwrite each other silently. */
@Component({
  imports: [...DIALOG],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ t('lots.weigh') }} — {{ data.lot.lot_number }}</h2>
      <mat-dialog-content>
        <mat-form-field>
          <mat-label>{{ t('lots.newWeight') }}</mat-label>
          <input matInput [formControl]="weight" inputmode="decimal" required cdkFocusInitial (keydown.enter)="submit()" />
        </mat-form-field>
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ t('common.cancel') }}</button>
        <button mat-flat-button type="button" [disabled]="busy() || weight.invalid" (click)="submit()">{{ t('common.save') }}</button>
      </mat-dialog-actions>
    </ng-container>
  `,
  styles: 'mat-dialog-content { min-width: min(360px, 80vw); }',
})
export class WeighLotDialog {
  protected readonly data = inject<LotActionData>(MAT_DIALOG_DATA);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject<MatDialogRef<WeighLotDialog, Lot>>(MatDialogRef);

  protected readonly weight = inject(FormBuilder).nonNullable.control(this.data.lot.net_weight_kg, WEIGHT);
  protected readonly busy = signal(false);

  protected async submit(): Promise<void> {
    if (this.weight.invalid) {
      return;
    }
    this.busy.set(true);
    try {
      this.ref.close(await firstValueFrom(this.api.post<Lot>(`lots/${this.data.lot.ulid}/weigh`, { net_weight_kg: decimal(this.weight.value), version: this.data.lot.version })));
      this.toast.success('lots.weighed');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

/** M2-06: split. The running total shows at once whether the weights still add up to the original lot. */
@Component({
  imports: [...DIALOG, NumberPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ t('lots.split') }} — {{ data.lot.lot_number }}</h2>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <mat-dialog-content class="dw-stack-sm">
          <p class="dw-muted">{{ t('lots.splitHint') }}</p>
          <div formArrayName="outputs" class="outputs">
            @for (control of outputs.controls; track control; let index = $index) {
              <div class="output">
                <mat-form-field>
                  <mat-label>{{ t('lots.splitOutput', { index: index + 1 }) }}</mat-label>
                  <input matInput [formControlName]="index" inputmode="decimal" required />
                </mat-form-field>
                @if (outputs.length > 2) {
                  <button mat-icon-button type="button" (click)="outputs.removeAt(index); recompute()" [attr.aria-label]="t('common.remove')"><mat-icon>close</mat-icon></button>
                }
              </div>
            }
          </div>
          <button mat-stroked-button type="button" (click)="add()" class="add"><mat-icon>add</mat-icon>{{ t('lots.addOutput') }}</button>
          <p [class.gap]="gap()" role="status">{{ t('lots.splitTotal', { total: (total() | dwNumber: 3), source: (data.lot.net_weight_kg | dwNumber: 3) }) }}</p>
          <mat-form-field>
            <mat-label>{{ t('fields.comment') }}@if (!gap()) { ({{ t('common.optional') }}) }</mat-label>
            <textarea matInput formControlName="notes" rows="2" maxlength="1000" [required]="gap()"></textarea>
            @if (gap()) { <mat-hint>{{ t('errors.SPLIT_WEIGHT_GAP') }}</mat-hint> }
          </mat-form-field>
        </mat-dialog-content>
        <mat-dialog-actions align="end">
          <button mat-button type="button" mat-dialog-close>{{ t('common.cancel') }}</button>
          <button mat-flat-button type="submit" [disabled]="busy() || (gap() && !form.controls.notes.value.trim())">{{ t('lots.split') }}</button>
        </mat-dialog-actions>
      </form>
    </ng-container>
  `,
  styles: `
    mat-dialog-content { min-width: min(460px, 82vw); }
    .outputs { display: grid; gap: var(--dw-1); } .output { display: flex; align-items: center; gap: var(--dw-2); }
    .add { justify-self: start; } .gap { color: var(--dw-warning); font-weight: 600; }
  `,
})
export class SplitLotDialog {
  protected readonly data = inject<LotActionData>(MAT_DIALOG_DATA);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly fb = inject(FormBuilder).nonNullable;
  private readonly ref = inject<MatDialogRef<SplitLotDialog, Lot[]>>(MatDialogRef);

  protected readonly form = this.fb.group({ outputs: this.fb.array([this.fb.control('', WEIGHT), this.fb.control('', WEIGHT)]), notes: [''] });
  protected readonly busy = signal(false);
  protected readonly total = signal(0);
  /** Beyond 2 % of difference with the original weight, the API requires an explanation. */
  protected readonly gap = computed(() => {
    const source = Number(this.data.lot.net_weight_kg);

    return source > 0 && this.total() > 0 && Math.abs(this.total() - source) / source > 0.02;
  });

  constructor() {
    this.form.controls.outputs.valueChanges.subscribe(() => this.recompute());
  }

  protected get outputs(): FormArray {
    return this.form.controls.outputs;
  }

  protected add(): void {
    this.outputs.push(this.fb.control('', WEIGHT));
  }

  protected recompute(): void {
    this.total.set(this.form.controls.outputs.getRawValue().reduce((sum, value) => sum + (Number(decimal(value)) || 0), 0));
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    try {
      const lots = await firstValueFrom(this.api.post<Lot[]>(`lots/${this.data.lot.ulid}/split`, {
        outputs: value.outputs.map((weight) => ({ net_weight_kg: decimal(weight) })), notes: value.notes.trim() || null,
      }));
      this.toast.success('lots.splitDone', { count: lots.length });
      this.ref.close(lots);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

export interface GroupLotsData {
  lots: Lot[];
}

@Component({
  imports: [...DIALOG, NumberPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ t('lots.group') }}</h2>
      <mat-dialog-content class="dw-stack-sm">
        <p class="dw-muted">{{ t('lots.groupHint') }}</p>
        <p><strong>{{ data.lots.length }}</strong> × {{ data.lots[0].waste_type?.name }} — <strong>{{ total | dwNumber: 3 }} kg</strong></p>
        <mat-form-field>
          <mat-label>{{ t('lots.targetZone') }}</mat-label>
          <mat-select [formControl]="zone" required>
            @for (item of zones(); track item.ulid) { <mat-option [value]="item.ulid">{{ item.name }}</mat-option> }
          </mat-select>
        </mat-form-field>
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ t('common.cancel') }}</button>
        <button mat-flat-button type="button" [disabled]="busy() || zone.invalid" (click)="submit()">{{ t('lots.group') }}</button>
      </mat-dialog-actions>
    </ng-container>
  `,
  styles: 'mat-dialog-content { min-width: min(440px, 82vw); }',
})
export class GroupLotsDialog {
  protected readonly data = inject<GroupLotsData>(MAT_DIALOG_DATA);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject<MatDialogRef<GroupLotsDialog, Lot>>(MatDialogRef);

  protected readonly total = this.data.lots.reduce((sum, lot) => sum + Number(lot.net_weight_kg), 0);
  protected readonly zones = toSignal(inject(ReferenceData).zones(this.data.lots[0].site?.ulid ?? ''), { initialValue: [] });
  protected readonly zone = inject(FormBuilder).nonNullable.control(this.data.lots[0].zone?.ulid ?? '', Validators.required);
  protected readonly busy = signal(false);

  protected async submit(): Promise<void> {
    this.busy.set(true);
    try {
      const lot = await firstValueFrom(this.api.post<Lot>('lots/group', { lots: this.data.lots.map((item) => item.ulid), zone: this.zone.value }));
      this.toast.success('lots.groupDone', { number: lot.lot_number });
      this.ref.close(lot);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
