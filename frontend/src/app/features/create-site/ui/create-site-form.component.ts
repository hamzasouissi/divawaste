import { ChangeDetectionStrategy, Component, inject, output, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { SiteDto } from '../../../entities/site';
import { ApiError } from '../../../shared/api';
import { LocaleService } from '../../../shared/i18n/locale.service';
import { CreateSiteApi, CreateSiteInput } from '../api/create-site.api';

@Component({
  selector: 'app-create-site-form',
  imports: [ReactiveFormsModule, RouterLink],
  template: `
    <section class="page" aria-labelledby="create-site-title">
      <header class="page-header">
        <div>
          <h1 id="create-site-title">{{ locale.t('createSite') }}</h1>
          <p>{{ locale.t('createSiteDescription') }}</p>
        </div>
        <a class="button secondary" routerLink="/sites">{{ locale.t('backToSites') }}</a>
      </header>

      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        @if (formError()) {
          <p class="form-error" role="alert">{{ locale.t(formError()!) }}</p>
        }
        <div class="form-grid">
          <label>
            <span>{{ locale.t('siteCode') }} <span aria-hidden="true">*</span></span>
            <input formControlName="code" maxlength="20" autocomplete="off" required [attr.aria-invalid]="showFieldError('code')" aria-describedby="site-code-error" />
            @if (showFieldError('code')) { <span id="site-code-error" class="field-error">{{ fieldError('code') }}</span> }
          </label>
          <label>
            <span>{{ locale.t('siteName') }} <span aria-hidden="true">*</span></span>
            <input formControlName="name" maxlength="150" autocomplete="organization" required [attr.aria-invalid]="showFieldError('name')" aria-describedby="site-name-error" />
            @if (showFieldError('name')) { <span id="site-name-error" class="field-error">{{ fieldError('name') }}</span> }
          </label>
          <label>
            <span>{{ locale.t('siteCountry') }} <span aria-hidden="true">*</span></span>
            <input formControlName="country" maxlength="2" minlength="2" autocapitalize="characters" autocomplete="country" required [attr.aria-invalid]="showFieldError('country')" aria-describedby="site-country-help site-country-error" />
            <span id="site-country-help" class="field-help">{{ locale.t('siteCountryHelp') }}</span>
            @if (showFieldError('country')) { <span id="site-country-error" class="field-error">{{ fieldError('country') }}</span> }
          </label>
          <label>
            <span>{{ locale.t('siteAddress') }}</span>
            <input formControlName="address_line1" maxlength="191" autocomplete="address-line1" />
          </label>
          <label>
            <span>{{ locale.t('siteCity') }}</span>
            <input formControlName="city" maxlength="100" autocomplete="address-level2" />
          </label>
          <label>
            <span>{{ locale.t('sitePostalCode') }}</span>
            <input formControlName="postal_code" maxlength="20" autocomplete="postal-code" />
          </label>
          <label>
            <span>{{ locale.t('siteRegion') }}</span>
            <input formControlName="region" maxlength="100" autocomplete="address-level1" />
          </label>
          <label>
            <span>{{ locale.t('siteTimezone') }}</span>
            <input formControlName="timezone" autocomplete="off" />
          </label>
          <label>
            <span>{{ locale.t('regulatoryIdentifier') }}</span>
            <input formControlName="regulatory_identifier" maxlength="64" />
          </label>
          <label>
            <span>{{ locale.t('capacityKg') }}</span>
            <input formControlName="capacity_kg" inputmode="decimal" autocomplete="off" [attr.aria-invalid]="showFieldError('capacity_kg')" aria-describedby="site-capacity-error" />
            @if (showFieldError('capacity_kg')) { <span id="site-capacity-error" class="field-error">{{ fieldError('capacity_kg') }}</span> }
          </label>
        </div>
        <p class="form-note">{{ locale.t('siteKindDefault') }}</p>
        <footer class="form-actions">
          <button class="button" type="submit" [disabled]="form.invalid || submitting()">
            {{ locale.t(submitting() ? 'creating' : 'createSite') }}
          </button>
        </footer>
      </form>
    </section>
  `,
  styles: `
    form { max-width: 58rem; padding: 1.5rem; border: 1px solid var(--border); border-radius: .5rem; background: var(--surface); }
    .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.1rem 1.25rem; }
    label { display: grid; align-content: start; gap: .4rem; min-width: 0; font-weight: 600; }
    input { width: 100%; min-height: 2.75rem; padding: .65rem .75rem; border: 1px solid var(--border); border-radius: .35rem; background: var(--surface); color: var(--text); }
    input[aria-invalid="true"] { border-color: var(--danger); }
    .field-help, .form-note { color: var(--text-muted); font-size: .875rem; font-weight: 400; }
    .field-error, .form-error { color: var(--danger); font-size: .875rem; font-weight: 500; }
    .form-note { margin: 1rem 0 0; }
    .form-actions { display: flex; justify-content: flex-end; margin-top: 1.5rem; }
    @media (max-width: 640px) { form { padding: 1rem; } .form-grid { grid-template-columns: minmax(0, 1fr); } }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CreateSiteFormComponent {
  private readonly formBuilder = inject(FormBuilder);
  private readonly createSite = inject(CreateSiteApi);
  readonly locale = inject(LocaleService);
  readonly created = output<SiteDto>();
  readonly submitting = signal(false);
  readonly formError = signal<string | null>(null);
  readonly serverErrors = signal<Record<string, string[]> | null>(null);

  readonly form = this.formBuilder.nonNullable.group({
    code: ['', [Validators.required, Validators.maxLength(20), Validators.pattern(/^[A-Za-z0-9_-]+$/)]],
    name: ['', [Validators.required, Validators.maxLength(150)]],
    country: ['', [Validators.required, Validators.pattern(/^[A-Za-z]{2}$/)]],
    address_line1: ['', [Validators.maxLength(191)]],
    city: ['', [Validators.maxLength(100)]],
    postal_code: ['', [Validators.maxLength(20)]],
    region: ['', [Validators.maxLength(100)]],
    timezone: [''],
    regulatory_identifier: ['', [Validators.maxLength(64)]],
    capacity_kg: ['', [Validators.pattern(/^\d+(\.\d{1,3})?$/)]],
  });

  showFieldError(field: string): boolean {
    const control = this.form.get(field);
    return Boolean(control && (control.dirty || control.touched) && (control.invalid || this.serverErrors()?.[field]));
  }

  fieldError(field: string): string {
    const serverError = this.serverErrors()?.[field]?.[0];
    if (serverError) return serverError;
    const control = this.form.get(field);
    if (control?.hasError('required')) return this.locale.t('fieldRequired');
    if (control?.hasError('maxlength')) return this.locale.t('fieldTooLong');
    if (field === 'code' && control?.hasError('pattern')) return this.locale.t('siteCodeFormat');
    if (field === 'country' && control?.hasError('pattern')) return this.locale.t('siteCountryFormat');
    if (field === 'capacity_kg' && control?.hasError('pattern')) return this.locale.t('capacityFormat');
    return this.locale.t('invalidField');
  }

  async submit(): Promise<void> {
    this.form.controls.code.setValue(this.form.controls.code.value.trim());
    this.form.controls.name.setValue(this.form.controls.name.value.trim());
    this.form.controls.country.setValue(this.form.controls.country.value.trim().toUpperCase());
    if (this.form.invalid || this.submitting()) {
      this.form.markAllAsTouched();
      return;
    }

    this.submitting.set(true);
    this.formError.set(null);
    this.serverErrors.set(null);
    try {
      const values = this.form.getRawValue();
      const input: CreateSiteInput = {
        code: values.code.trim(),
        name: values.name.trim(),
        country: values.country.trim().toUpperCase(),
        address_line1: this.optional(values.address_line1),
        city: this.optional(values.city),
        postal_code: this.optional(values.postal_code),
        region: this.optional(values.region),
        timezone: this.optional(values.timezone),
        regulatory_identifier: this.optional(values.regulatory_identifier),
        capacity_kg: this.optional(values.capacity_kg),
      };
      const response = await this.createSite.execute(input);
      this.created.emit(response.data);
    } catch (error) {
      if (error instanceof ApiError && error.kind === 'validation') {
        this.serverErrors.set(error.problem.errors ?? null);
        this.formError.set('siteCreateValidationError');
      } else if (error instanceof ApiError && error.kind === 'forbidden') {
        this.formError.set('siteCreateForbidden');
      } else if (error instanceof ApiError && error.kind === 'conflict') {
        this.formError.set('siteCreateConflict');
      } else {
        this.formError.set('siteCreateError');
      }
    } finally {
      this.submitting.set(false);
    }
  }

  private optional(value: string | null): string | null {
    const trimmed = value?.trim() ?? '';
    return trimmed || null;
  }
}