import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom, map } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';

import { Api, openBlob } from '../../core/api/api';
import { WasteAuthorization } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { CodePipe, DatePipe, KgPipe, LocalizedPipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { DateComponent } from '../../ui/date';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { LimitsComponent } from '../../ui/limits';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SELECT } from '../../ui/select';
import { StateComponent } from '../../ui/state';
import { HelpComponent } from '../../ui/help';

const DECIMAL = Validators.pattern(/^\d+([.,]\d{1,3})?$/);

/**
 * An authorisation as the document states it: a waste type, a period, and an allowed quantity written in whichever
 * form the document uses — a weight, a number of lots, lots per packaging. Once pickups draw on it, only the
 * reference, the notes, the alert level and the withdrawal can still change (the API refuses the rest).
 */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, LocalizedPipe, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...DIALOG_PARTS, IconComponent, DateComponent, CheckboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'authorizations.edit' : 'authorizations.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          @if (data?.in_use) { <p class="dw-muted note">{{ t('authorizations.frozen') }}</p> }
          <div class="dw-form">
            <dw-field [label]="t('fields.wasteType')">
              <dw-select formControlName="waste_type" required>
                @for (type of types(); track type.ulid) { <dw-option [value]="type.ulid" [label]="type.name" /> }
              </dw-select>
              @if (types().length === 0) { <small dwHint>{{ t('authorizations.noType') }}</small> }
            </dw-field>
            <dw-field [label]="t('authorizations.reference')"><input pInputText formControlName="reference" maxlength="100" required /></dw-field>
            <dw-field [label]="t('authorizations.validFrom')"><dw-date formControlName="valid_from" required /></dw-field>
            <dw-field [label]="t('authorizations.validUntil')"><dw-date formControlName="valid_until" [min]="form.controls.valid_from.value || null" required /></dw-field>

            <section class="dw-span-2 part">
              <h3 class="dw-h3">{{ t('authorizations.quantity') }}</h3>
              <p class="dw-muted">{{ t('authorizations.quantityHint') }}</p>
            </section>
            <dw-field [label]="t('authorizations.maxKg')"><input pInputText formControlName="max_kg" inputmode="decimal" /></dw-field>
            <dw-field [label]="t('authorizations.maxLots')"><input pInputText type="number" formControlName="max_lots" min="1" /></dw-field>
            <section class="dw-span-2 part">
              @for (line of limits.controls; track line; let i = $index) {
                <div class="line" [formGroup]="line">
                  <dw-select formControlName="packaging_type" [ariaLabel]="t('lots.packaging')" [placeholder]="t('lots.packaging')">
                    @for (packaging of vocabulary()?.packaging_types ?? []; track packaging.code) {
                      <dw-option [value]="packaging.code" [label]="packaging.name | dwLocalized" [disabled]="taken(packaging.code, i)" />
                    }
                  </dw-select>
                  <input pInputText type="number" formControlName="max_count" min="1" [attr.aria-label]="t('sites.maxLots')" [placeholder]="t('sites.maxLots')" />
                  <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" [disabled]="form.disabled || limits.disabled" (click)="limits.removeAt(i)" [attr.aria-label]="t('common.remove')"><dw-icon>close</dw-icon></button>
                </div>
              }
              @if (!data?.in_use) { <button pButton text type="button" class="add" (click)="addLimit()"><dw-icon>add</dw-icon>{{ t('sites.addCapacity') }}</button> }
              @if (limits.length > 1) {
                <dw-checkbox formControlName="shared_packaging_allowance">{{ t('authorizations.shared') }}</dw-checkbox>
                <p class="dw-muted">{{ t(form.controls.shared_packaging_allowance.value ? 'authorizations.sharedOn' : 'authorizations.sharedOff') }}</p>
              }
              @if (noQuantity()) { <small class="dw-field-error" role="alert">{{ t('authorizations.quantityRequired') }}</small> }
            </section>

            <dw-field [label]="t('authorizations.alertPct')"><input pInputText type="number" formControlName="alert_pct" min="1" max="100" /></dw-field>
            <dw-field [label]="t('authorizations.issuedOn')"><dw-date formControlName="issued_on" /></dw-field>
            <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="1000"></textarea></dw-field>
            <label class="dw-span-2 file">
              <input type="file" accept="application/pdf,image/jpeg,image/png" (change)="pick($event)" />
              <dw-icon aria-hidden="true">upload_file</dw-icon><span>{{ file()?.name || t(data?.has_file ? 'authorizations.replaceFile' : 'authorizations.file') }}</span>
            </label>
            @if (data) { <dw-checkbox class="dw-span-2" formControlName="is_active">{{ t('authorizations.active') }}</dw-checkbox> }
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
    .note { margin: 0 0 var(--dw-3); font-size: 13px; }
    .part { display: grid; gap: var(--dw-2); } .part p { margin: 0; font-size: 13px; }
    .line { display: grid; grid-template-columns: minmax(0, 1fr) 150px auto; gap: var(--dw-2); align-items: center; }
    .add { justify-self: start; }
    .file { display: flex; align-items: center; gap: 8px; padding: 12px; border: 1px dashed var(--dw-line); border-radius: var(--dw-radius); cursor: pointer; font-size: 13px; }
    .file:focus-within { outline: 2px solid var(--dw-primary); outline-offset: 2px; } .file input { position: absolute; opacity: 0; width: 1px; height: 1px; }
  `,
})
export class AuthorizationDialog {
  protected readonly data = dialogData<WasteAuthorization | null>();
  private readonly api = inject(Api);
  private readonly http = inject(HttpClient);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  private readonly reference = inject(ReferenceData);
  private readonly fb = inject(FormBuilder);

  protected readonly vocabulary = toSignal(this.reference.vocabulary());
  /** Only the waste types marked as needing an authorisation. */
  protected readonly types = toSignal(this.reference.wasteTypes().pipe(map((types) => types.filter((type) => type.requires_authorization))), { initialValue: [] });
  protected readonly busy = signal(false);
  protected readonly file = signal<File | null>(null);
  protected readonly limits = this.fb.array((this.data?.limits ?? []).map((line) => this.limitLine(line.packaging_type, line.max_count)));
  protected readonly form = this.fb.nonNullable.group({
    waste_type: [this.data?.waste_type.ulid ?? '', Validators.required],
    reference: [this.data?.reference ?? '', Validators.required],
    valid_from: [this.data?.valid_from ?? '', Validators.required],
    valid_until: [this.data?.valid_until ?? '', Validators.required],
    max_kg: [this.data?.max_kg ?? '', DECIMAL],
    max_lots: [this.data?.max_lots ?? (null as number | null), Validators.min(1)],
    alert_pct: [this.data?.alert_pct ?? 80, [Validators.min(1), Validators.max(100)]],
    issued_on: [this.data?.issued_on ?? ''],
    notes: [this.data?.notes ?? ''],
    is_active: [this.data?.is_active ?? true],
    limits: this.limits,
    shared_packaging_allowance: [{ value: this.data?.shared_packaging_allowance ?? false, disabled: this.data?.in_use ?? false }],
  });
  private readonly value = toSignal(this.form.valueChanges, { initialValue: this.form.getRawValue() });
  private readonly submitted = signal(false);
  protected readonly noQuantity = computed(() => {
    const value = this.value();

    return this.submitted() && !value.max_kg && !value.max_lots && (value.limits ?? []).length === 0;
  });

  constructor() {
    // What was authorised is history once pickups draw on it.
    if (this.data?.in_use) {
      for (const name of ['waste_type', 'valid_from', 'valid_until', 'max_kg', 'max_lots', 'limits'] as const) {
        this.form.controls[name].disable();
      }
    }
  }

  private limitLine(packaging = '', count: number | null = null): FormGroup {
    return this.fb.nonNullable.group({ packaging_type: [packaging, Validators.required], max_count: [count, [Validators.required, Validators.min(1)]] });
  }

  protected addLimit(): void {
    this.limits.push(this.limitLine());
  }

  protected taken(code: string, index: number): boolean {
    return this.limits.controls.some((line, i) => i !== index && line.getRawValue().packaging_type === code);
  }

  protected pick(event: Event): void {
    this.file.set((event.target as HTMLInputElement).files?.[0] ?? null);
  }

  protected async submit(): Promise<void> {
    this.submitted.set(true);
    const value = this.form.getRawValue();
    if (this.form.invalid || (!value.max_kg && !value.max_lots && value.limits.length === 0)) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const body = { ...value, shared_packaging_allowance: value.shared_packaging_allowance && value.limits.length > 1, max_kg: value.max_kg ? String(value.max_kg).replace(',', '.') : null, issued_on: value.issued_on || null, notes: value.notes || null };
    try {
      const saved = await firstValueFrom(this.data ? this.api.put<WasteAuthorization>(`waste-authorizations/${this.data.ulid}`, body) : this.api.post<WasteAuthorization>('waste-authorizations', body));
      const file = this.file();
      if (file) {
        const upload = new FormData();
        upload.append('file', file);
        await firstValueFrom(this.http.post(`/api/v1/waste-authorizations/${saved.ulid}/file`, upload));
      }
      this.toast.success('authorizations.saved');
      this.ref.close(saved);
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
 * Authorisations to have waste leave the site (declarations signed by customs). Each one shows how much of it the
 * pickups have used and what is left; a pickup of a waste type that needs one is refused when nothing covers it.
 */
@Component({
  imports: [HelpComponent, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, ButtonDirective, IconComponent, DatePipe, KgPipe, CodePipe, LimitsComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('authorizations.title')" [subtitle]="t('authorizations.subtitle')">
        @if (canManage) { <button pButton type="button" (click)="edit(null)"><dw-icon>add</dw-icon>{{ t('authorizations.new') }}</button> }
      </dw-page-header>
      <dw-help topic="authorizations" />

      <dw-state [loading]="loading()" [error]="error()" [empty]="rows().length === 0" icon="verified" [emptyTitle]="t('authorizations.emptyTitle')" [emptyHint]="t('authorizations.emptyHint')" (retry)="load()">
        <div class="dw-panel">
          <table class="dw-table">
            <thead><tr><th>{{ t('authorizations.reference') }}</th><th>{{ t('fields.wasteType') }}</th><th>{{ t('authorizations.period') }}</th><th>{{ t('authorizations.quantity') }}</th><th>{{ t('authorizations.used') }}</th><th>{{ t('fields.status') }}</th><th></th></tr></thead>
            <tbody>
              @for (row of rows(); track row.ulid) {
                <tr [class.off]="row.state !== 'valid' && row.state !== 'upcoming'">
                  <td><strong class="dw-mono">{{ row.reference }}</strong>@if (row.notes) { <div class="dw-faint notes">{{ row.notes }}</div> }</td>
                  <td>{{ row.waste_type.name }}</td>
                  <td>{{ row.valid_from | dwDate }} → {{ row.valid_until | dwDate }}</td>
                  <td>
                    @if (row.max_kg) { <div>{{ row.max_kg | dwKg: 'auto' }}</div> }
                    @if (row.max_lots) { <div>{{ t('dashboard.lots', { count: row.max_lots }) }}</div> }
                    @for (line of row.limits; track line.packaging_type; let first = $first) { <div>@if (row.shared_packaging_allowance && !first) { {{ t('common.or') }} } {{ line.max_count }} × {{ line.packaging_type | dwCode: 'packaging' }}</div> }
                    @if (row.shared_packaging_allowance) { <div class="dw-faint">{{ t('authorizations.sharedTag') }}</div> }
                  </td>
                  <td>
                    <dw-limits [limits]="row.usage.constraints" />
                    @if (row.usage.blocked && row.state === 'valid') { <div class="blocked">{{ t('authorizations.blocked') }}</div> }
                  </td>
                  <td><dw-status kind="accreditation" [value]="tone(row)" [label]="t('authorizations.states.' + row.state)" /></td>
                  <td class="dw-num">
                    @if (row.has_file) { <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="file(row)" [attr.aria-label]="t('providers.viewFile')" [title]="t('providers.viewFile')"><dw-icon>description</dw-icon></button> }
                    @if (canManage) { <button pButton text type="button" (click)="edit(row)">{{ t('common.edit') }}</button> }
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      </dw-state>
    </ng-container>
  `,
  styles: `
    tr.off td { color: var(--dw-ink-3); }
    .notes { max-inline-size: 260px; white-space: normal; }
    .blocked { color: var(--dw-danger); font-weight: 600; font-size: 12.5px; margin-block-start: var(--dw-1); }
    .meter { flex: 0 0 120px; height: 8px; border-radius: 4px; background: var(--dw-line-soft); overflow: hidden; }
    .bar { display: block; height: 100%; max-inline-size: 100%; background: var(--dw-success); }
    .bar.warning { background: #d68a00; } .bar.exceeded { background: var(--dw-danger); }
  `,
})
export class AuthorizationsPage {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly toast = inject(Toast);

  protected readonly canManage = inject(Session).can('waste_types.manage');
  protected readonly rows = signal<WasteAuthorization[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);

  constructor() {
    this.load();
  }

  protected tone(row: WasteAuthorization): string {
    return row.state === 'valid' ? (row.usage.outcome === 'exceeded' ? 'expired' : row.usage.outcome === 'warning' || row.usage.outcome === 'unverifiable' ? 'expiring' : 'valid') : row.state === 'upcoming' ? 'pending' : 'expired';
  }

  protected edit(row: WasteAuthorization | null): void {
    this.dialog.open<AuthorizationDialog, WasteAuthorization | null, WasteAuthorization>(AuthorizationDialog, { data: row }).afterClosed().subscribe((saved) => saved && this.load());
  }

  protected async file(row: WasteAuthorization): Promise<void> {
    try {
      openBlob(await firstValueFrom(this.api.blob(`waste-authorizations/${row.ulid}/file`)));
    } catch (error) {
      this.toast.error(error);
    }
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get<WasteAuthorization[]>('waste-authorizations').subscribe({
      next: (rows) => {
        this.rows.set(rows);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }
}
