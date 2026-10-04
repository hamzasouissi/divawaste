import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';

import { Api, ApiError, openBlob } from '../../core/api/api';
import { Localized, ProviderAccreditation } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, LocalizedPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { StateComponent } from '../../ui/state';

interface AcceptedWaste {
  channel: string;
  family: string;
  min_quantity_kg: string;
  notes: string;
}

interface Profile {
  roles: string[];
  description: string | null;
  service_area: string | null;
  public_contact_name: string | null;
  public_contact_email: string | null;
  public_contact_phone: string | null;
  is_published: boolean;
  accepted_wastes: { channel: string; family: string | null; catalog_item: string | null; min_quantity_kg: string | null; notes: string | null }[];
}

/** What industrials read in the directory: activities, contact, and which waste goes to which channel. */
@Component({
  imports: [FormsModule, ReactiveFormsModule, MatButtonModule, MatCheckboxModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, TranslocoDirective, PageHeaderComponent,
    StatusComponent, StateComponent, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('provider.profile.title')" [subtitle]="t('provider.profile.subtitle')">
        @if (profile(); as p) {
          <dw-status dwTitleBadge kind="accreditation" [value]="p.is_published ? 'approved' : 'pending'" [label]="t(p.is_published ? 'provider.profile.published' : 'provider.profile.unpublished')" />
        }
      </dw-page-header>
      <dw-state [loading]="!profile() && !error()" [error]="error()" (retry)="load()">
        @if (profile()) {
          <form class="dw-stack" [formGroup]="form" (ngSubmit)="save()">
            <section class="dw-panel">
              <div class="dw-panel-body">
                <h2 class="dw-h2">{{ t('providers.roles') }}</h2>
                <div class="dw-row roles">
                  @for (role of roleOptions; track role) {
                    <mat-checkbox [checked]="roles().includes(role)" (change)="toggleRole(role, $event.checked)">{{ t('providers.role.' + role) }}</mat-checkbox>
                  }
                </div>
                <div class="dw-form">
                  <mat-form-field class="dw-span-2"><mat-label>{{ t('fields.description') }}</mat-label><textarea matInput rows="3" maxlength="2000" formControlName="description"></textarea></mat-form-field>
                  <mat-form-field class="dw-span-2"><mat-label>{{ t('providers.serviceArea') }}</mat-label><input matInput maxlength="500" formControlName="service_area" /></mat-form-field>
                  <mat-form-field><mat-label>{{ t('providers.contact') }}</mat-label><input matInput formControlName="public_contact_name" /></mat-form-field>
                  <mat-form-field><mat-label>{{ t('fields.phone') }}</mat-label><input matInput type="tel" formControlName="public_contact_phone" /></mat-form-field>
                  <mat-form-field>
                    <mat-label>{{ t('fields.email') }}</mat-label><input matInput type="email" formControlName="public_contact_email" />
                    @if (form.controls.public_contact_email.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
                  </mat-form-field>
                </div>
              </div>
            </section>

            <section class="dw-panel">
              <header class="dw-panel-head">
                <h2 class="dw-h2 dw-spacer">{{ t('providers.accepted') }}</h2>
                <button mat-stroked-button type="button" (click)="addWaste()"><mat-icon>add</mat-icon>{{ t('provider.profile.addWaste') }}</button>
              </header>
              <div class="dw-panel-body wastes">
                @for (waste of wastes(); track $index; let index = $index) {
                  <div class="waste">
                    <mat-form-field>
                      <mat-label>{{ t('providers.family') }}</mat-label>
                      <mat-select [ngModel]="waste.family" (ngModelChange)="patchWaste(index, { family: $event })" [ngModelOptions]="{ standalone: true }">
                        @for (family of vocabulary()?.waste_families ?? []; track family.code) { <mat-option [value]="family.code">{{ family.name | dwLocalized }}</mat-option> }
                      </mat-select>
                    </mat-form-field>
                    <mat-form-field>
                      <mat-label>{{ t('pickups.channel') }}</mat-label>
                      <mat-select [ngModel]="waste.channel" (ngModelChange)="patchWaste(index, { channel: $event })" [ngModelOptions]="{ standalone: true }">
                        @for (channel of vocabulary()?.treatment_channels ?? []; track channel.code) { <mat-option [value]="channel.code">{{ channel.name | dwLocalized }}</mat-option> }
                      </mat-select>
                    </mat-form-field>
                    <mat-form-field class="min">
                      <mat-label>{{ t('providers.minQuantity') }}</mat-label>
                      <input matInput inputmode="decimal" [ngModel]="waste.min_quantity_kg" (ngModelChange)="patchWaste(index, { min_quantity_kg: $event })" [ngModelOptions]="{ standalone: true }" />
                    </mat-form-field>
                    <mat-form-field>
                      <mat-label>{{ t('fields.notes') }}</mat-label>
                      <input matInput maxlength="500" [ngModel]="waste.notes" (ngModelChange)="patchWaste(index, { notes: $event })" [ngModelOptions]="{ standalone: true }" />
                    </mat-form-field>
                    <button mat-icon-button type="button" (click)="removeWaste(index)" [attr.aria-label]="t('common.remove')"><mat-icon>delete</mat-icon></button>
                  </div>
                } @empty {
                  <p class="dw-faint">{{ t('common.none') }}</p>
                }
              </div>
            </section>
            <div class="dw-form-actions"><button mat-flat-button type="submit" [disabled]="busy() || !valid()">{{ t('common.save') }}</button></div>
          </form>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .roles { margin-block: var(--dw-2) var(--dw-4); }
    .wastes { display: grid; gap: var(--dw-1); }
    .waste { display: grid; grid-template-columns: 1.2fr 1.2fr 0.8fr 1.6fr auto; gap: var(--dw-2); align-items: start; }
    @media (max-width: 900px) { .waste { grid-template-columns: 1fr 1fr; padding-block-end: var(--dw-3); border-block-end: 1px solid var(--dw-line-soft); } }
  `,
})
export class ProviderProfilePage {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);

  protected readonly roleOptions = ['collector', 'transporter', 'recycler', 'eliminator'];
  protected readonly vocabulary = toSignal(inject(ReferenceData).vocabulary());
  protected readonly profile = signal<Profile | null>(null);
  protected readonly error = signal<unknown>(null);
  protected readonly busy = signal(false);
  protected readonly roles = signal<string[]>([]);
  protected readonly wastes = signal<AcceptedWaste[]>([]);
  protected readonly valid = computed(() => this.roles().length > 0 && this.wastes().every((waste) => waste.channel && waste.family && /^(\d+([.,]\d{1,3})?)?$/.test(waste.min_quantity_kg)));
  protected readonly form = inject(FormBuilder).nonNullable.group({
    description: [''], service_area: [''], public_contact_name: [''], public_contact_phone: [''], public_contact_email: ['', Validators.email],
  });

  constructor() {
    this.load();
  }

  protected load(): void {
    this.error.set(null);
    this.api.get<Profile>('provider/profile').subscribe({ next: (profile) => this.show(profile), error: (error: unknown) => this.error.set(error) });
  }

  protected toggleRole(role: string, checked: boolean): void {
    this.roles.update((roles) => (checked ? [...roles, role] : roles.filter((item) => item !== role)));
  }

  protected addWaste(): void {
    this.wastes.update((rows) => [...rows, { channel: '', family: '', min_quantity_kg: '', notes: '' }]);
  }

  protected patchWaste(index: number, change: Partial<AcceptedWaste>): void {
    this.wastes.update((rows) => rows.map((row, position) => (position === index ? { ...row, ...change } : row)));
  }

  protected removeWaste(index: number): void {
    this.wastes.update((rows) => rows.filter((_, position) => position !== index));
  }

  protected async save(): Promise<void> {
    if (this.form.invalid || !this.valid()) {
      this.form.markAllAsTouched();
      this.toast.info('common.formInvalid');

      return;
    }
    this.busy.set(true);
    try {
      const value = this.form.getRawValue();
      this.show(await firstValueFrom(this.api.put<Profile>('provider/profile', {
        ...Object.fromEntries(Object.entries(value).map(([key, text]) => [key, text.trim() || null])),
        roles: this.roles(),
        accepted_wastes: this.wastes().map((waste) => ({ channel: waste.channel, family: waste.family, min_quantity_kg: waste.min_quantity_kg ? waste.min_quantity_kg.replace(',', '.') : null, notes: waste.notes || null })),
      })));
      this.toast.success('provider.profile.saved');
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }

  private show(profile: Profile): void {
    this.profile.set(profile);
    this.roles.set(profile.roles);
    // Entries tied to a precise catalogue item are kept as their family here (the form works by family).
    this.wastes.set(profile.accepted_wastes.filter((waste) => waste.family).map((waste) => ({ channel: waste.channel, family: waste.family ?? '', min_quantity_kg: waste.min_quantity_kg ?? '', notes: waste.notes ?? '' })));
    this.form.reset({
      description: profile.description ?? '', service_area: profile.service_area ?? '', public_contact_name: profile.public_contact_name ?? '',
      public_contact_phone: profile.public_contact_phone ?? '', public_contact_email: profile.public_contact_email ?? '',
    });
  }
}

interface AccreditationType {
  country: string;
  code: string;
  name: Localized;
  covers_hazardous: boolean;
}

/** M0-05: accreditations, each with its document, verified by the platform. A renewal is a new entry; the old one stays. */
@Component({
  imports: [ReactiveFormsModule, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent,
    DatePipe, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('provider.accreditations.title')" [subtitle]="t('provider.accreditations.subtitle')">
        @if (!adding()) { <button mat-flat-button type="button" (click)="adding.set(true)"><mat-icon>add</mat-icon>{{ t('provider.accreditations.add') }}</button> }
      </dw-page-header>

      <div class="dw-stack">
        @if (adding()) {
          <form class="dw-panel" [formGroup]="form" (ngSubmit)="add(file)">
            <div class="dw-panel-body">
              <div class="dw-form">
                <mat-form-field>
                  <mat-label>{{ t('providers.accreditationType') }}</mat-label>
                  <mat-select formControlName="type">
                    @for (type of types(); track type.code) { <mat-option [value]="type.code">{{ type.name | dwLocalized }}</mat-option> }
                  </mat-select>
                </mat-form-field>
                <mat-form-field>
                  <mat-label>{{ t('providers.reference') }}</mat-label><input matInput formControlName="reference_number" maxlength="100" />
                  @if (form.controls.reference_number.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
                </mat-form-field>
                <mat-form-field><mat-label>{{ t('providers.authority') }}</mat-label><input matInput formControlName="issuing_authority" maxlength="191" /></mat-form-field>
                <span></span>
                <mat-form-field>
                  <mat-label>{{ t('providers.validFrom') }}</mat-label><input matInput type="date" formControlName="valid_from" />
                  @if (form.controls.valid_from.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
                </mat-form-field>
                <mat-form-field>
                  <mat-label>{{ t('providers.expiresOn') }}</mat-label><input matInput type="date" formControlName="expires_on" />
                  @if (form.controls.expires_on.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
                </mat-form-field>
                <label class="dw-span-2 file">
                  <span class="dw-label">{{ t('providers.accreditationFile') }}</span>
                  <input #file type="file" accept="application/pdf" (change)="hasFile.set(!!file.files?.length)" />
                </label>
              </div>
              <div class="dw-form-actions">
                <button mat-button type="button" (click)="adding.set(false)">{{ t('common.cancel') }}</button>
                <button mat-flat-button type="submit" [disabled]="busy() || !hasFile()">{{ t('provider.accreditations.add') }}</button>
              </div>
            </div>
          </form>
        }

        <section class="dw-panel">
          <dw-state [loading]="loading()" [error]="error()" [empty]="rows().length === 0" [keepContent]="rows().length > 0" icon="verified"
            [emptyTitle]="t('provider.accreditations.emptyTitle')" [emptyHint]="t('provider.accreditations.emptyHint')" (retry)="load()">
            <ul class="list">
              @for (row of rows(); track row.ulid) {
                <li>
                  <div class="dw-spacer">
                    <strong>{{ row.name | dwLocalized }}</strong> <span class="dw-mono dw-faint">{{ row.reference_number }}</span>
                    <div class="dw-muted">{{ row.valid_from | dwDate }} → {{ row.expires_on | dwDate }}@if (row.issuing_authority) { · {{ row.issuing_authority }} }</div>
                    @if (row.rejection_reason) { <div class="reason">{{ row.rejection_reason }}</div> }
                  </div>
                  @if (row.expired) { <dw-status kind="accreditation" value="expired" /> } @else { <dw-status kind="accreditation" [value]="row.review_status ?? 'pending'" /> }
                  <button mat-icon-button type="button" (click)="open(row)" [attr.aria-label]="t('providers.viewFile')" [title]="t('providers.viewFile')"><mat-icon>description</mat-icon></button>
                  <button mat-button type="button" [disabled]="busy()" (click)="withdraw(row)">{{ t('provider.accreditations.withdraw') }}</button>
                </li>
              }
            </ul>
          </dw-state>
        </section>
      </div>
    </ng-container>
  `,
  styles: `
    .file { display: grid; gap: 4px; margin-block-end: var(--dw-3); }
    .list { list-style: none; margin: 0; padding: 0; }
    .list li { display: flex; align-items: center; gap: var(--dw-3); padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); flex-wrap: wrap; }
    .list li:last-child { border-block-end: 0; } .reason { color: var(--dw-danger); font-size: 12.5px; }
  `,
})
export class ProviderAccreditationsPage {
  private readonly api = inject(Api);
  private readonly http = inject(HttpClient);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);

  protected readonly rows = signal<ProviderAccreditation[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly busy = signal(false);
  protected readonly adding = signal(false);
  protected readonly hasFile = signal(false);
  private readonly allTypes = signal<AccreditationType[]>([]);
  protected readonly types = computed(() => this.allTypes().filter((type) => type.country === this.session.company()?.country));
  protected readonly form = inject(FormBuilder).nonNullable.group({
    type: ['', Validators.required], reference_number: ['', Validators.required], issuing_authority: [''], valid_from: ['', Validators.required], expires_on: ['', Validators.required],
  });

  constructor() {
    this.load();
    this.api.get<{ accreditation_types: AccreditationType[] }>('registration/options').subscribe({ next: (options) => this.allTypes.set(options.accreditation_types), error: () => undefined });
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get<ProviderAccreditation[]>('provider/accreditations').subscribe({
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

  protected async add(input: HTMLInputElement): Promise<void> {
    const file = input.files?.[0];
    if (this.form.invalid || !file) {
      this.form.markAllAsTouched();

      return;
    }
    const body = new FormData();
    Object.entries(this.form.getRawValue()).forEach(([key, value]) => value && body.append(key, value));
    body.append('file', file);
    this.busy.set(true);
    try {
      await firstValueFrom(this.http.post('/api/v1/provider/accreditations', body));
      this.toast.success('provider.accreditations.added');
      this.form.reset();
      this.hasFile.set(false);
      this.adding.set(false);
      this.load();
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }

  protected async open(row: ProviderAccreditation): Promise<void> {
    try {
      openBlob(await firstValueFrom(this.api.blob(`provider/accreditations/${row.ulid}/file`)));
    } catch (error) {
      this.toast.error(error instanceof ApiError ? error : null);
    }
  }

  protected async withdraw(row: ProviderAccreditation): Promise<void> {
    if (!(await this.confirm.ask({ title: this.toast.text('provider.accreditations.withdrawConfirm'), message: row.reference_number, danger: true }))) {
      return;
    }
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.delete(`provider/accreditations/${row.ulid}`));
      this.toast.success('provider.accreditations.withdrawn');
      this.load();
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
