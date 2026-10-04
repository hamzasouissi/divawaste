import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormArray, FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';

import { Api, ApiError } from '../../core/api/api';
import { Localized } from '../../core/api/models';
import { Toast } from '../../core/feedback/toast';
import { LocalizedPipe } from '../../core/format/pipes';
import { StateComponent } from '../../ui/state';

interface RegistrationOptions {
  countries: { iso2: string; name: Localized; tax_id_label: Localized; phone_prefix: string }[];
  activities: { code: string; name: Localized }[];
  plans: { code: string; name: Localized; is_trial: boolean; trial_days: number | null }[];
  accreditation_types: { country: string; code: string; name: Localized; covers_hazardous: boolean }[];
  legal_documents: { type: string; version: string; title: string; content_html: string }[];
}

const PROVIDER_ROLES = ['collector', 'transporter', 'recycler', 'eliminator'] as const;

/**
 * M0-01 / M0-03 / M0-05 / M0-10: one form for both kinds of account. A provider attaches its accreditations
 * (PDF); an industrial declares its textile activities and chooses a formula. The account is usable once the
 * email is confirmed and the platform has approved it.
 */
@Component({
  imports: [ReactiveFormsModule, RouterLink, MatButtonModule, MatButtonToggleModule, MatCheckboxModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule,
    TranslocoDirective, LocalizedPipe, StateComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (done()) {
        <div class="dw-stack" role="status">
          <mat-icon class="ok" aria-hidden="true">mark_email_read</mat-icon>
          <h1 class="dw-h1">{{ t('auth.register.doneTitle') }}</h1>
          <p class="dw-muted">{{ t('auth.register.doneBody', { email: form.controls.contact.controls.email.value }) }}</p>
          <a mat-flat-button routerLink="/login">{{ t('auth.login.submit') }}</a>
        </div>
      } @else {
        <h1 class="dw-h1">{{ t('auth.register.title') }}</h1>
        <p class="dw-muted lead">{{ t('auth.register.lead') }} <a routerLink="/login">{{ t('auth.backToLogin') }}</a></p>

        <dw-state [loading]="options() === null && !loadError()" [error]="loadError()" (retry)="load()">
          @if (options(); as opts) {
            <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
              <mat-button-toggle-group formControlName="company_type" [attr.aria-label]="t('auth.register.accountType')" class="type">
                <mat-button-toggle value="industrial">{{ t('auth.register.industrial') }}</mat-button-toggle>
                <mat-button-toggle value="provider">{{ t('auth.register.provider') }}</mat-button-toggle>
              </mat-button-toggle-group>
              <p class="dw-muted hint">{{ t(provider() ? 'auth.register.providerHint' : 'auth.register.industrialHint') }}</p>

              <div class="dw-form" formGroupName="company">
                <h2 class="dw-h2 dw-form-section">{{ t('auth.register.company') }}</h2>
                <mat-form-field class="dw-span-2">
                  <mat-label>{{ t('fields.legalName') }}</mat-label>
                  <input matInput formControlName="legal_name" autocomplete="organization" required />
                  @if (error('company.legal_name'); as message) { <mat-error>{{ message }}</mat-error> }
                </mat-form-field>
                <mat-form-field>
                  <mat-label>{{ t('fields.country') }}</mat-label>
                  <mat-select formControlName="country" required>
                    @for (country of opts.countries; track country.iso2) { <mat-option [value]="country.iso2">{{ country.name | dwLocalized }}</mat-option> }
                  </mat-select>
                </mat-form-field>
                <mat-form-field>
                  <mat-label>{{ taxLabel() | dwLocalized: t('fields.taxId') }}</mat-label>
                  <input matInput formControlName="tax_id" required />
                  @if (error('company.tax_id'); as message) { <mat-error>{{ message }}</mat-error> }
                </mat-form-field>
                <mat-form-field><mat-label>{{ t('fields.city') }}</mat-label><input matInput formControlName="city" autocomplete="address-level2" /></mat-form-field>
                <mat-form-field><mat-label>{{ t('fields.phone') }}</mat-label><input matInput type="tel" formControlName="phone" autocomplete="tel" /></mat-form-field>
                @if (!provider()) {
                  <mat-form-field class="dw-span-2">
                    <mat-label>{{ t('auth.register.activities') }}</mat-label>
                    <mat-select formControlName="activities" multiple required>
                      @for (activity of opts.activities; track activity.code) { <mat-option [value]="activity.code">{{ activity.name | dwLocalized }}</mat-option> }
                    </mat-select>
                    @if (error('company.activities'); as message) { <mat-error>{{ message }}</mat-error> }
                  </mat-form-field>
                }
              </div>

              <div class="dw-form" formGroupName="contact">
                <h2 class="dw-h2 dw-form-section">{{ t('auth.register.contact') }}</h2>
                <mat-form-field><mat-label>{{ t('fields.firstName') }}</mat-label><input matInput formControlName="first_name" autocomplete="given-name" required /></mat-form-field>
                <mat-form-field><mat-label>{{ t('fields.lastName') }}</mat-label><input matInput formControlName="last_name" autocomplete="family-name" required /></mat-form-field>
                <mat-form-field>
                  <mat-label>{{ t('auth.email') }}</mat-label>
                  <input matInput type="email" formControlName="email" autocomplete="email" required />
                  @if (error('contact.email'); as message) { <mat-error>{{ message }}</mat-error> }
                </mat-form-field>
                <mat-form-field><mat-label>{{ t('fields.phone') }}</mat-label><input matInput type="tel" formControlName="phone" autocomplete="tel" required /></mat-form-field>
                <mat-form-field class="dw-span-2">
                  <mat-label>{{ t('auth.password') }}</mat-label>
                  <input matInput type="password" formControlName="password" autocomplete="new-password" required />
                  <mat-hint>{{ t('auth.passwordRule') }}</mat-hint>
                  @if (error('contact.password'); as message) { <mat-error>{{ message }}</mat-error> }
                </mat-form-field>
              </div>

              @if (provider()) {
                <div class="dw-form" formGroupName="provider">
                  <h2 class="dw-h2 dw-form-section">{{ t('auth.register.providerActivity') }}</h2>
                  <mat-form-field>
                    <mat-label>{{ t('providers.roles') }}</mat-label>
                    <mat-select formControlName="roles" multiple required>
                      @for (role of roles; track role) { <mat-option [value]="role">{{ t('providers.role.' + role) }}</mat-option> }
                    </mat-select>
                  </mat-form-field>
                  <mat-form-field><mat-label>{{ t('providers.serviceArea') }}</mat-label><input matInput formControlName="service_area" /></mat-form-field>

                  <h2 class="dw-h2 dw-form-section">{{ t('auth.register.accreditations') }}</h2>
                  <div class="dw-span-2 dw-stack-sm" formArrayName="accreditations">
                    @for (group of accreditations.controls; track group; let index = $index) {
                      <div class="accreditation" [formGroupName]="index">
                        <mat-form-field>
                          <mat-label>{{ t('providers.accreditationType') }}</mat-label>
                          <mat-select formControlName="type" required>
                            @for (type of accreditationTypes(); track type.code) { <mat-option [value]="type.code">{{ type.name | dwLocalized }}</mat-option> }
                          </mat-select>
                        </mat-form-field>
                        <mat-form-field><mat-label>{{ t('providers.reference') }}</mat-label><input matInput formControlName="reference_number" required /></mat-form-field>
                        <mat-form-field><mat-label>{{ t('providers.validFrom') }}</mat-label><input matInput type="date" formControlName="valid_from" required /></mat-form-field>
                        <mat-form-field>
                          <mat-label>{{ t('providers.expiresOn') }}</mat-label>
                          <input matInput type="date" formControlName="expires_on" required />
                          @if (error('provider.accreditations.' + index + '.expires_on'); as message) { <mat-error>{{ message }}</mat-error> }
                        </mat-form-field>
                        <div class="file">
                          <label class="file-pick">
                            <input type="file" accept="application/pdf" (change)="pick(index, $event)" [attr.aria-label]="t('providers.accreditationFile')" />
                            <mat-icon aria-hidden="true">upload_file</mat-icon>
                            <span>{{ files()[index]?.name || t('providers.accreditationFile') }}</span>
                          </label>
                          @if (accreditations.length > 1) {
                            <button mat-icon-button type="button" (click)="removeAccreditation(index)" [attr.aria-label]="t('common.remove')"><mat-icon>delete</mat-icon></button>
                          }
                        </div>
                        @if (fileErrors()[index]; as message) { <p class="field-error" role="alert">{{ message }}</p> }
                      </div>
                    }
                    <button mat-stroked-button type="button" (click)="addAccreditation()" class="add"><mat-icon>add</mat-icon>{{ t('auth.register.addAccreditation') }}</button>
                  </div>
                </div>
              } @else {
                <div class="dw-form">
                  <h2 class="dw-h2 dw-form-section">{{ t('auth.register.plan') }}</h2>
                  <mat-form-field class="dw-span-2">
                    <mat-label>{{ t('auth.register.plan') }}</mat-label>
                    <mat-select formControlName="plan" required>
                      @for (plan of opts.plans; track plan.code) { <mat-option [value]="plan.code">{{ plan.name | dwLocalized }}</mat-option> }
                    </mat-select>
                    <mat-hint>{{ t('auth.register.planHint') }}</mat-hint>
                  </mat-form-field>
                </div>
              }

              <div class="legal">
                <mat-checkbox formControlName="accept">{{ t('auth.acceptLegal', { documents: legalTitles() }) }}</mat-checkbox>
                <details>
                  <summary>{{ t('auth.register.readLegal') }}</summary>
                  @for (doc of legalDocuments(); track doc.type) {
                    <article><h3 class="dw-h3">{{ doc.title }} — v{{ doc.version }}</h3><div [innerHTML]="doc.content_html"></div></article>
                  }
                </details>
              </div>

              <div class="dw-form-actions">
                <button mat-flat-button type="submit" class="submit" [disabled]="busy() || !form.controls.accept.value">{{ busy() ? t('common.wait') : t('auth.register.submit') }}</button>
              </div>
            </form>
          }
        </dw-state>
      }
    </ng-container>
  `,
  styles: `
    .lead { margin-block: 4px var(--dw-4); }
    .type { margin-block-end: var(--dw-2); }
    .hint { font-size: 13px; }
    .accreditation { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--dw-1) var(--dw-4); padding: var(--dw-3); border: 1px solid var(--dw-line); border-radius: var(--dw-radius); }
    .file { grid-column: 1 / -1; display: flex; align-items: center; gap: var(--dw-2); }
    .file-pick { flex: 1; display: flex; align-items: center; gap: 8px; padding: 10px 12px; border: 1px dashed var(--dw-line); border-radius: var(--dw-radius); cursor: pointer; font-size: 13px; }
    .file-pick:focus-within { outline: 2px solid var(--dw-primary); outline-offset: 2px; }
    .file-pick input { position: absolute; opacity: 0; width: 1px; height: 1px; }
    .field-error { grid-column: 1 / -1; color: var(--dw-danger); font-size: 12px; }
    .add { justify-self: start; }
    .legal { margin-block: var(--dw-4) var(--dw-2); display: grid; gap: var(--dw-2); }
    details { font-size: 13px; }
    details article { padding: var(--dw-3); margin-block-start: var(--dw-2); background: var(--dw-surface-2); border-radius: var(--dw-radius); max-height: 220px; overflow: auto; }
    .submit { height: 44px; min-width: 220px; }
    .ok { font-size: 40px; width: 40px; height: 40px; color: var(--dw-success); }
    @media (max-width: 720px) { .accreditation { grid-template-columns: minmax(0, 1fr); } }
  `,
})
export class RegisterPage {
  private readonly api = inject(Api);
  private readonly http = inject(HttpClient);
  private readonly toast = inject(Toast);
  private readonly fb = inject(FormBuilder).nonNullable;

  protected readonly roles = PROVIDER_ROLES;
  protected readonly options = signal<RegistrationOptions | null>(null);
  protected readonly loadError = signal<unknown>(null);
  protected readonly busy = signal(false);
  protected readonly done = signal(false);
  protected readonly files = signal<(File | null)[]>([null]);
  protected readonly fileErrors = signal<Record<number, string>>({});
  private readonly serverErrors = signal<Record<string, string[]>>({});

  protected readonly form = this.fb.group({
    company_type: this.fb.control<'industrial' | 'provider'>('industrial'),
    company: this.fb.group({
      legal_name: ['', [Validators.required, Validators.maxLength(191)]],
      country: ['TN', Validators.required],
      tax_id: ['', [Validators.required, Validators.maxLength(50)]],
      city: [''],
      phone: [''],
      activities: this.fb.control<string[]>([]),
    }),
    contact: this.fb.group({
      first_name: ['', Validators.required],
      last_name: ['', Validators.required],
      email: ['', [Validators.required, Validators.email]],
      phone: ['', Validators.required],
      password: ['', [Validators.required, Validators.minLength(12)]],
    }),
    plan: ['trial'],
    provider: this.fb.group({
      roles: this.fb.control<string[]>([]),
      service_area: [''],
      accreditations: this.fb.array([this.accreditationGroup()]),
    }),
    accept: [false],
  });

  private readonly type = signal<'industrial' | 'provider'>('industrial');
  private readonly country = signal('TN');
  protected readonly provider = computed(() => this.type() === 'provider');
  protected readonly taxLabel = computed(() => this.options()?.countries.find((country) => country.iso2 === this.country())?.tax_id_label ?? null);
  protected readonly accreditationTypes = computed(() => (this.options()?.accreditation_types ?? []).filter((type) => type.country === this.country()));
  protected readonly legalDocuments = computed(() => (this.options()?.legal_documents ?? []).filter((doc) => doc.type === 'privacy' || doc.type === (this.provider() ? 'provider_terms' : 'terms')));
  protected readonly legalTitles = computed(() => this.legalDocuments().map((doc) => doc.title).join(', '));

  constructor() {
    this.form.controls.company_type.valueChanges.subscribe((value) => this.type.set(value));
    this.form.controls.company.controls.country.valueChanges.subscribe((value) => this.country.set(value));
    this.load();
  }

  protected get accreditations(): FormArray<FormGroup> {
    return this.form.controls.provider.controls.accreditations as FormArray<FormGroup>;
  }

  protected load(): void {
    this.loadError.set(null);
    this.api.get<RegistrationOptions>('registration/options').subscribe({ next: (options) => this.options.set(options), error: (error: unknown) => this.loadError.set(error) });
  }

  protected error(path: string): string | null {
    return this.serverErrors()[path]?.[0] ?? null;
  }

  protected addAccreditation(): void {
    this.accreditations.push(this.accreditationGroup());
    this.files.update((files) => [...files, null]);
  }

  protected removeAccreditation(index: number): void {
    this.accreditations.removeAt(index);
    this.files.update((files) => files.filter((_, position) => position !== index));
  }

  protected pick(index: number, event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    this.files.update((files) => files.map((current, position) => (position === index ? file : current)));
  }

  protected async submit(): Promise<void> {
    const value = this.form.getRawValue();
    const provider = value.company_type === 'provider';
    this.serverErrors.set({});
    this.fileErrors.set({});

    const missingFiles = provider ? this.files().map((file, index) => (file ? null : index)).filter((index): index is number => index !== null) : [];
    if (this.form.controls.company.invalid || this.form.controls.contact.invalid || (provider && (this.accreditations.invalid || value.provider.roles.length === 0)) || (!provider && value.company.activities.length === 0) || missingFiles.length > 0) {
      this.form.markAllAsTouched();
      this.fileErrors.set(Object.fromEntries(missingFiles.map((index) => [index, this.toast.describe(new ApiError(422, 'FILE_REQUIRED', null, {}, null))])));
      this.toast.info('common.formInvalid');

      return;
    }

    const payload: Record<string, unknown> = {
      company_type: value.company_type,
      company: { ...value.company, activities: provider ? [] : value.company.activities },
      contact: value.contact,
      plan: provider ? null : value.plan,
      accept_terms: value.accept,
      accept_privacy: value.accept,
      ...(provider ? { provider: { roles: value.provider.roles, service_area: value.provider.service_area, accreditations: value.provider.accreditations } } : {}),
    };

    this.busy.set(true);
    try {
      // Multipart only when files are attached; the API accepts both encodings of the same structure.
      await firstValueFrom<unknown>(provider ? this.http.post('/api/v1/auth/register', this.toFormData(payload)) : this.api.post('auth/register', payload));
      this.done.set(true);
    } catch (error) {
      if (error instanceof ApiError && error.isValidation) {
        this.serverErrors.set(error.errors);
        this.fileErrors.set(Object.fromEntries(Object.entries(error.errors).filter(([key]) => key.endsWith('.file')).map(([key, messages]) => [Number(key.split('.')[2]), messages[0]])));
        this.toast.applyValidation(this.form, error);
        this.toast.info('common.formInvalid');
      } else {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }

  private accreditationGroup(): FormGroup {
    return this.fb.group({ type: ['', Validators.required], reference_number: ['', Validators.required], valid_from: ['', Validators.required], expires_on: ['', Validators.required] });
  }

  private toFormData(payload: Record<string, unknown>): FormData {
    const data = new FormData();
    const append = (key: string, value: unknown): void => {
      if (value === null || value === undefined) {
        return;
      }
      if (Array.isArray(value)) {
        value.forEach((item, index) => append(`${key}[${index}]`, item));
      } else if (typeof value === 'object') {
        Object.entries(value as Record<string, unknown>).forEach(([child, childValue]) => append(`${key}[${child}]`, childValue));
      } else {
        data.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
      }
    };
    Object.entries(payload).forEach(([key, value]) => append(key, value));
    this.files().forEach((file, index) => file && data.append(`provider[accreditations][${index}][file]`, file));

    return data;
  }
}
