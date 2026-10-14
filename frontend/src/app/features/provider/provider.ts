import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';

import { Api, ApiError, openBlob } from '../../core/api/api';
import { AcceptedWasteRule, Localized, ProviderAccreditation, StockLimitUnit } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, LocalizedPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { StateComponent } from '../../ui/state';
import { CheckboxComponent } from '../../ui/checkbox';
import { DateComponent } from '../../ui/date';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';

interface AcceptedWaste {
  channel: string;
  family: string;
  /** What a collection of this waste must reach, in `min_unit`; packages name the packaging they count. */
  min_quantity: string;
  min_unit: StockLimitUnit;
  min_packaging_type: string;
  /** The packaging this waste is asked to arrive in; empty = none in particular. */
  required_packaging_type: string;
  /** Whether a collection that falls short is refused, or only warned about. */
  is_blocking: boolean;
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
  accepted_wastes: AcceptedWasteRule[];
}

/** What industrials read in the directory: activities, contact, and which waste goes to which channel. */
@Component({
  imports: [
    FormsModule, ReactiveFormsModule, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, LocalizedPipe, IconComponent, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, CheckboxComponent,
  ],
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
                    <dw-checkbox [checked]="roles().includes(role)" (checkedChange)="toggleRole(role, $event)">{{ t('providers.role.' + role) }}</dw-checkbox>
                  }
                </div>
                <div class="dw-form">
                  <dw-field class="dw-span-2" [label]="t('fields.description')"><textarea pTextarea rows="3" maxlength="2000" formControlName="description"></textarea></dw-field>
                  <dw-field class="dw-span-2" [label]="t('providers.serviceArea')"><input pInputText maxlength="500" formControlName="service_area" /></dw-field>
                  <dw-field [label]="t('providers.contact')"><input pInputText formControlName="public_contact_name" /></dw-field>
                  <dw-field [label]="t('fields.phone')"><input pInputText type="tel" formControlName="public_contact_phone" /></dw-field>
                  <dw-field [label]="t('fields.email')">
                    <input pInputText type="email" formControlName="public_contact_email" />
                  </dw-field>
                </div>
              </div>
            </section>

            <section class="dw-panel">
              <header class="dw-panel-head">
                <h2 class="dw-h2 dw-spacer">{{ t('providers.accepted') }}</h2>
                <button pButton severity="secondary" outlined type="button" (click)="addWaste()"><dw-icon>add</dw-icon>{{ t('provider.profile.addWaste') }}</button>
              </header>
              <div class="dw-panel-body wastes dw-rows">
                @for (waste of wastes(); track $index; let index = $index) {
                  <div class="waste">
                    <dw-field [label]="t('providers.family')">
                      <dw-select [ngModel]="waste.family" (ngModelChange)="patchWaste(index, { family: $event })" [ngModelOptions]="{ standalone: true }">
                        @for (family of vocabulary()?.waste_families ?? []; track family.code) { <dw-option [value]="family.code" [label]="family.name | dwLocalized" /> }
                      </dw-select>
                    </dw-field>
                    <dw-field [label]="t('pickups.channel')">
                      <dw-select [ngModel]="waste.channel" (ngModelChange)="patchWaste(index, { channel: $event })" [ngModelOptions]="{ standalone: true }">
                        @for (channel of vocabulary()?.treatment_channels ?? []; track channel.code) { <dw-option [value]="channel.code" [label]="channel.name | dwLocalized" /> }
                      </dw-select>
                    </dw-field>
                    <dw-field class="min" [label]="t('providers.minQuantity')">
                      <input pInputText inputmode="decimal" [ngModel]="waste.min_quantity" (ngModelChange)="patchWaste(index, { min_quantity: $event })" [ngModelOptions]="{ standalone: true }" />
                    </dw-field>
                    <dw-field [label]="t('providers.minUnit')">
                      <dw-select [ngModel]="waste.min_unit" (ngModelChange)="patchWaste(index, { min_unit: $event })" [ngModelOptions]="{ standalone: true }">
                        @for (unit of units; track unit) { <dw-option [value]="unit" [label]="t('stock.unit.' + unit)" /> }
                      </dw-select>
                    </dw-field>
                    <dw-field [label]="t('providers.minPackaging')">
                      @if (waste.min_unit === 'package') {
                        <dw-select [ngModel]="waste.min_packaging_type" (ngModelChange)="patchWaste(index, { min_packaging_type: $event })" [ngModelOptions]="{ standalone: true }">
                          @for (packaging of vocabulary()?.packaging_types ?? []; track packaging.code) { <dw-option [value]="packaging.code" [label]="packaging.name | dwLocalized" /> }
                        </dw-select>
                      } @else {
                        <input pInputText disabled [placeholder]="t('providers.minPackagingNone')" />
                      }
                    </dw-field>
                    <dw-field [label]="t('providers.requiredPackaging')">
                      <dw-select [ngModel]="waste.required_packaging_type" (ngModelChange)="patchWaste(index, { required_packaging_type: $event })" [ngModelOptions]="{ standalone: true }">
                        <dw-option value="" [label]="t('providers.anyPackaging')" />
                        @for (packaging of vocabulary()?.packaging_types ?? []; track packaging.code) { <dw-option [value]="packaging.code" [label]="packaging.name | dwLocalized" /> }
                      </dw-select>
                    </dw-field>
                    <dw-field class="notes" [label]="t('fields.notes')">
                      <input pInputText maxlength="500" [ngModel]="waste.notes" (ngModelChange)="patchWaste(index, { notes: $event })" [ngModelOptions]="{ standalone: true }" />
                    </dw-field>
                    <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="removeWaste(index)" [attr.aria-label]="t('common.remove')"><dw-icon>delete</dw-icon></button>
                    @if (asks(waste)) {
                      <div class="consequence">
                        <dw-checkbox [checked]="waste.is_blocking" (checkedChange)="patchWaste(index, { is_blocking: $event })">{{ t('providers.blocking') }}</dw-checkbox>
                        <span class="dw-faint">{{ t(waste.is_blocking ? 'providers.blockingOn' : 'providers.blockingOff') }}</span>
                      </div>
                    }
                  </div>
                } @empty {
                  <p class="dw-faint">{{ t('common.none') }}</p>
                }
              </div>
            </section>
            <div class="dw-form-actions"><button pButton type="submit" [disabled]="busy() || !valid()">{{ t('common.save') }}</button></div>
          </form>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .roles { margin-block: var(--dw-2) var(--dw-4); }
    .wastes { display: grid; gap: var(--dw-1); }
    .waste { display: grid; grid-template-columns: 1.6fr 1.1fr 0.7fr 1.1fr 0.9fr 0.9fr; gap: var(--dw-2); align-items: end; padding-block-end: var(--dw-3); border-block-end: 1px solid var(--dw-line-soft); }
    .waste .notes { grid-column: 1 / span 5; } .waste > button { justify-self: end; }
    .waste:last-child { border-block-end: 0; } .consequence { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: var(--dw-1) var(--dw-3); align-items: center; font-size: 13px; }
    @media (max-width: 900px) { .waste { grid-template-columns: 1fr 1fr; } .waste .notes { grid-column: 1 / -1; } }
    /* On a phone each field takes the width: a waste family or a unit is read in full, not cut off. */
    @media (max-width: 560px) { .waste { grid-template-columns: 1fr; } .dw-panel-head { flex-wrap: wrap; gap: var(--dw-2); } }
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
  protected readonly units: StockLimitUnit[] = ['kg', 't', 'piece', 'l', 'm3', 'package'];
  protected readonly valid = computed(() => this.roles().length > 0 && this.wastes().every((waste) => waste.channel && waste.family
    && (waste.min_unit === 'package' ? /^\d*$/.test(waste.min_quantity.trim()) && (waste.min_quantity.trim() === '' || !!waste.min_packaging_type) : /^(\d+([.,]\d{1,3})?)?$/.test(waste.min_quantity.trim()))));
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
    this.wastes.update((rows) => [...rows, { channel: '', family: '', min_quantity: '', min_unit: 'kg', min_packaging_type: '', required_packaging_type: '', is_blocking: false, notes: '' }]);
  }

  protected patchWaste(index: number, change: Partial<AcceptedWaste>): void {
    this.wastes.update((rows) => rows.map((row, position) => (position === index ? { ...row, ...change } : row)));
  }

  /** Whether the line asks anything of a collection: only then is there something to refuse or to warn about. */
  protected asks(waste: AcceptedWaste): boolean {
    return Number(waste.min_quantity.replace(',', '.')) > 0 || waste.required_packaging_type !== '';
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
        accepted_wastes: this.wastes().map((waste) => {
          const minimum = Number(waste.min_quantity.replace(',', '.')) > 0 ? waste.min_quantity.trim().replace(',', '.') : null;

          return {
            channel: waste.channel, family: waste.family, min_quantity: minimum, min_unit: minimum ? waste.min_unit : null,
            min_packaging_type: minimum && waste.min_unit === 'package' ? waste.min_packaging_type : null,
            required_packaging_type: waste.required_packaging_type || null, is_blocking: this.asks(waste) && waste.is_blocking, notes: waste.notes || null,
          };
        }),
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
    this.wastes.set(profile.accepted_wastes.filter((waste) => waste.family).map((waste) => ({
      channel: waste.channel, family: waste.family ?? '', min_quantity: waste.min_quantity === null ? '' : String(Number(waste.min_quantity)).replace('.', ','), min_unit: waste.min_unit ?? 'kg',
      min_packaging_type: waste.min_packaging_type ?? '', required_packaging_type: waste.required_packaging_type ?? '', is_blocking: waste.is_blocking, notes: waste.notes ?? '',
    })));
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
  imports: [
    ReactiveFormsModule, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, DatePipe, LocalizedPipe, IconComponent, ButtonDirective, InputText, ...FIELD, ...SELECT, DateComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('provider.accreditations.title')" [subtitle]="t('provider.accreditations.subtitle')">
        @if (!adding()) { <button pButton type="button" (click)="adding.set(true)"><dw-icon>add</dw-icon>{{ t('provider.accreditations.add') }}</button> }
      </dw-page-header>

      <div class="dw-stack">
        @if (adding()) {
          <form class="dw-panel" [formGroup]="form" (ngSubmit)="add(file)">
            <div class="dw-panel-body">
              <div class="dw-form">
                <dw-field [label]="t('providers.accreditationType')">
                  <dw-select formControlName="type">
                    @for (type of types(); track type.code) { <dw-option [value]="type.code" [label]="type.name | dwLocalized" /> }
                  </dw-select>
                </dw-field>
                <dw-field [label]="t('providers.reference')">
                  <input pInputText formControlName="reference_number" maxlength="100" />
                </dw-field>
                <dw-field [label]="t('providers.authority')"><input pInputText formControlName="issuing_authority" maxlength="191" /></dw-field>
                <span></span>
                <dw-field [label]="t('providers.validFrom')">
                  <dw-date formControlName="valid_from" />
                </dw-field>
                <dw-field [label]="t('providers.expiresOn')">
                  <dw-date formControlName="expires_on" />
                </dw-field>
                <label class="dw-span-2 file">
                  <span class="dw-label">{{ t('providers.accreditationFile') }}</span>
                  <input #file type="file" accept="application/pdf" (change)="hasFile.set(!!file.files?.length)" />
                </label>
              </div>
              <div class="dw-form-actions">
                <button pButton text type="button" (click)="adding.set(false)">{{ t('common.cancel') }}</button>
                <button pButton type="submit" [disabled]="busy() || !hasFile()">{{ t('provider.accreditations.add') }}</button>
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
                    <strong>{{ row.name | dwLocalized }}</strong><span class="dw-faint"> · {{ row.reference_number }}</span>
                    <div class="dw-muted">{{ row.valid_from | dwDate }} → {{ row.expires_on | dwDate }}@if (row.issuing_authority) { · {{ row.issuing_authority }} }</div>
                    @if (row.rejection_reason) { <div class="reason">{{ row.rejection_reason }}</div> }
                  </div>
                  @if (row.expired) { <dw-status kind="accreditation" value="expired" /> } @else { <dw-status kind="accreditation" [value]="row.review_status ?? 'pending'" /> }
                  <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="open(row)" [attr.aria-label]="t('providers.viewFile')" [title]="t('providers.viewFile')"><dw-icon>description</dw-icon></button>
                  <button pButton text type="button" [disabled]="busy()" (click)="withdraw(row)">{{ t('provider.accreditations.withdraw') }}</button>
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
