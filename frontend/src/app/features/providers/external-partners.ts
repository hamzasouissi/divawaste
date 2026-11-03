import { ChangeDetectionStrategy, Component, computed, inject, output, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';

import { Api } from '../../core/api/api';
import { ExternalPartner, LimitResult, PartnerRequirement, RegistryOperator, RequirementRule, StockLimitUnit, WasteType } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Confirm } from '../../ui/confirm';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { LimitText } from '../../core/format/limit-text';
import { CodePipe, DatePipe, LocalizedPipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { DateComponent } from '../../ui/date';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { MenuAction, MenuComponent } from '../../ui/menu';
import { MoreComponent } from '../../ui/more';
import { StatusComponent } from '../../ui/page';
import { RouterLink } from '@angular/router';
import { PartyContracts } from '../contracts/party-contracts';
import { SELECT } from '../../ui/select';
import { RegistryDialog } from './registry';

/** A transporter or a receiver that has no account on the platform: the company records it for its own use. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...DIALOG_PARTS, CheckboxComponent, DateComponent, MoreComponent, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'partners.edit' : 'partners.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <p class="dw-muted intro">{{ t('partners.intro') }}</p>
          @if (!data) {
            <p class="registry" role="note">
              @if (source(); as entry) { <dw-icon aria-hidden="true">fact_check</dw-icon><span>{{ t('registry.filledFrom', { list: entry.list_title }) }}</span> }
              @else { <dw-icon aria-hidden="true">travel_explore</dw-icon><span>{{ t('registry.fillHint') }}</span> }
              <button pButton text size="small" type="button" (click)="fromRegistry()">{{ t('registry.fill') }}</button>
            </p>
          }
          <div class="dw-form">
            <dw-field class="dw-span-2" [label]="t('partners.legalName')"><input pInputText formControlName="legal_name" maxlength="191" required /></dw-field>
            <dw-field [label]="t('partners.roles')">
              <dw-select formControlName="roles" multiple required>
                @for (role of roles; track role) { <dw-option [value]="role" [label]="t('providers.role.' + role)" /> }
              </dw-select>
            </dw-field>
            <!-- PR-03: a name and what it does are enough to start; the rest is folded. -->
            <dw-checkbox class="dw-span-2" formControlName="covers_hazardous">{{ t('partners.coversHazardous') }}</dw-checkbox>
            @if (form.controls.covers_hazardous.value) {
              <dw-field [label]="t('partners.accreditation')">
                <input pInputText formControlName="accreditation_reference" maxlength="100" />
                <small dwHint>{{ t('partners.accreditationHint') }}</small>
              </dw-field>
              <dw-field [label]="t('providers.expiresOn')"><dw-date formControlName="accreditation_expires_on" /></dw-field>
            }
            <dw-more [label]="t('partners.contactDetails')" [summary]="t('partners.contactSummary')" [open]="!!data">
              <dw-field [label]="t('fields.taxId')"><input pInputText formControlName="tax_id" maxlength="40" /></dw-field>
              <dw-field [label]="t('fields.city')"><input pInputText formControlName="city" maxlength="100" /></dw-field>
              <dw-field class="dw-span-2" [label]="t('fields.address')"><input pInputText formControlName="address_line1" maxlength="191" /></dw-field>
              <dw-field [label]="t('fields.postalCode')"><input pInputText formControlName="postal_code" maxlength="20" /></dw-field>
              <dw-field [label]="t('providers.contact')"><input pInputText formControlName="contact_name" maxlength="150" /></dw-field>
              <dw-field [label]="t('fields.phone')"><input pInputText formControlName="phone" maxlength="30" /></dw-field>
              <dw-field [label]="t('fields.email')"><input pInputText type="email" formControlName="email" maxlength="191" /></dw-field>
              @if (!form.controls.covers_hazardous.value) {
                <dw-field [label]="t('partners.accreditation')"><input pInputText formControlName="accreditation_reference" maxlength="100" /></dw-field>
                <dw-field [label]="t('providers.expiresOn')"><dw-date formControlName="accreditation_expires_on" /></dw-field>
              }
              <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="1000"></textarea></dw-field>
            </dw-more>
            @if (data) { <dw-checkbox class="dw-span-2" formControlName="is_active">{{ t('partners.inUse') }}</dw-checkbox> }
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
    .dw-dialog-body { inline-size: min(640px, 82vw); } .intro { margin: 0 0 var(--dw-3); font-size: 13px; }
    .registry { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 4px 8px; margin: 0 0 var(--dw-3); padding: 6px 10px; border-radius: var(--dw-radius); background: var(--dw-surface-2); font-size: 12.5px; }
    .registry dw-icon { font-size: 18px; color: var(--dw-primary); }
    @media (max-width: 560px) { .registry { grid-template-columns: auto 1fr; } .registry button { grid-column: 1 / -1; justify-self: start; } }
  `,
})
export class ExternalPartnerDialog {
  /** A partner to edit, or a new one — empty, or filled from an entry of the ANGed lists. */
  private readonly given = dialogData<ExternalPartner | { from: RegistryOperator } | null>();
  protected readonly data = this.given && 'ulid' in this.given ? this.given : null;
  private readonly dialogs = inject(Dialogs);
  /** The ANGed entry the form was filled from. */
  protected readonly source = signal<RegistryOperator | null>(null);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly roles = ['collector', 'transporter', 'recycler', 'sorter'] as const;
  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    legal_name: [this.data?.legal_name ?? '', Validators.required],
    roles: [this.data?.roles ?? ([] as string[]), Validators.required],
    tax_id: [this.data?.tax_id ?? ''],
    address_line1: [this.data?.address_line1 ?? ''],
    postal_code: [this.data?.postal_code ?? ''],
    city: [this.data?.city ?? ''],
    contact_name: [this.data?.contact_name ?? ''],
    phone: [this.data?.phone ?? ''],
    email: [this.data?.email ?? '', Validators.email],
    accreditation_reference: [this.data?.accreditation_reference ?? ''],
    accreditation_expires_on: [this.data?.accreditation_expires_on ?? ''],
    covers_hazardous: [this.data?.covers_hazardous ?? false],
    notes: [this.data?.notes ?? ''],
    is_active: [this.data?.is_active ?? true],
  });

  constructor() {
    if (this.given && 'from' in this.given) {
      this.fill(this.given.from);
    }
  }

  protected fromRegistry(): void {
    this.dialogs.open<RegistryDialog, { initial: string }, RegistryOperator>(RegistryDialog, { data: { initial: this.form.controls.legal_name.value } })
      .afterClosed().subscribe((entry) => entry && this.fill(entry));
  }

  /** What the list says of the operator: its name, what it does, how to reach it, its authorisation. */
  private fill(entry: RegistryOperator): void {
    const roles = entry.roles.filter((role) => role !== 'storer');
    const phone = (entry.phone ?? '').split(/[/,;]/)[0].trim().slice(0, 30);
    this.form.patchValue({
      legal_name: entry.legal_name,
      roles: roles.length > 0 ? roles : this.form.controls.roles.value,
      contact_name: (entry.contact_name ?? '').slice(0, 150),
      phone,
      email: entry.email ?? '',
      address_line1: (entry.plant_address ?? entry.head_office ?? '').slice(0, 191),
      city: (entry.governorate ?? '').slice(0, 100),
      accreditation_reference: entry.authorization_number ? `ANGed ${entry.authorization_number}`.slice(0, 100) : '',
      accreditation_expires_on: entry.valid_until ?? '',
      covers_hazardous: entry.hazardous,
      notes: [this.toast.text('registry.noteFrom', { list: entry.list_title }), entry.waste !== entry.list_title ? entry.waste : null].filter(Boolean).join(' — ').slice(0, 1000),
    });
    this.source.set(entry);
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    // An empty field is "not given", not an empty text.
    const body = Object.fromEntries(Object.entries(this.form.getRawValue()).map(([key, value]) => [key, value === '' ? null : value]));
    try {
      this.ref.close(await firstValueFrom(this.data ? this.api.put<ExternalPartner>(`external-partners/${this.data.ulid}`, body) : this.api.post<ExternalPartner>('external-partners', body)));
      this.toast.success('partners.saved');
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

/** Tie a partner to the account it opened, when the tax IDs do not match: the provider is chosen in the directory. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, ...FIELD, ...SELECT, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('partners.linkTitle', { name: data.legal_name }) }}</dw-dialog-title>
      <div class="dw-dialog-body">
        <p class="dw-muted">{{ t('partners.chooseHint') }}</p>
        <dw-field [label]="t('partners.platformProvider')">
          <dw-select [formControl]="provider" required>
            @for (item of providers(); track item.ulid) { <dw-option [value]="item.ulid" [label]="item.legal_name + (item.city ? ' — ' + item.city : '')" /> }
          </dw-select>
        </dw-field>
      </div>
      <div class="dw-dialog-foot">
        <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
        <button pButton type="button" [disabled]="busy() || provider.invalid" (click)="submit()">{{ t('partners.link') }}</button>
      </div>
    </ng-container>
  `,
  styles: '.dw-dialog-body { display: grid; gap: var(--dw-3); inline-size: min(480px, 86vw); } .dw-muted { margin: 0; font-size: 13px; }',
})
export class LinkPartnerDialog {
  protected readonly data = dialogData<ExternalPartner>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly providers = signal<{ ulid: string; legal_name: string; city: string | null }[]>([]);
  protected readonly provider = inject(FormBuilder).nonNullable.control('', Validators.required);
  protected readonly busy = signal(false);

  constructor() {
    this.api.get<{ ulid: string; legal_name: string; city: string | null }[]>('providers/directory').subscribe({ next: (rows) => this.providers.set(rows), error: (error: unknown) => this.toast.error(error) });
  }

  protected async submit(): Promise<void> {
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.post(`external-partners/${this.data.ulid}/link`, { provider: this.provider.value }));
      this.toast.success('partners.linked');
      this.ref.close(true);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

interface RequirementRow {
  ulid: string | null;
  family: string;
  /** One of the company's own waste types of the family, or empty for the whole family. */
  waste_type: string;
  /** A line of one type that also counts its lots in the family's line, instead of replacing it for them. */
  adds_to_family: boolean;
  channel: string;
  min_quantity: string;
  min_unit: StockLimitUnit;
  min_packaging_type: string;
  required_packaging_type: string;
  is_blocking: boolean;
  contract_reference: string;
  notes: string;
  needs_review: boolean;
}

/**
 * What a partner without an account asks of a collection, as the company knows it from a contract or an exchange. It is
 * the company's own record — the partner has not confirmed it — and it warns unless the company makes it blocking.
 * Once the partner has an account, each line is looked at again: kept as the company's own requirement, or removed.
 */
@Component({
  imports: [FormsModule, TranslocoDirective, ButtonDirective, InputText, ...FIELD, ...SELECT, ...DIALOG_PARTS, CheckboxComponent, IconComponent, LocalizedPipe, CodePipe, DatePipe, MoreComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('partners.requirements.title', { name: partner().legal_name }) }}</dw-dialog-title>
      <div class="dw-dialog-body">
        <p class="note" role="note"><dw-icon aria-hidden="true">edit_note</dw-icon><span>{{ t(partner().linked_company ? 'partners.requirements.internalNoteLinked' : 'partners.requirements.internalNote') }}</span></p>
        @if (partner().linked_company; as account) {
          <div class="note review" role="note">
            <dw-icon aria-hidden="true">how_to_reg</dw-icon>
            <div>
              <p>{{ t('partners.requirements.linkedNote', { name: account.legal_name }) }}</p>
              @if ((partner().provider_requirements ?? []).length > 0) {
                <p class="dw-label">{{ t('partners.requirements.published', { name: account.legal_name }) }}</p>
                <ul>
                  @for (rule of partner().provider_requirements ?? []; track $index) {
                    <li>{{ rule.catalog_item ? (rule.catalog_item | dwCode: 'catalog') : (rule.family | dwCode: 'family') }} · {{ t('channels.' + rule.channel) }} — {{ summary(rule, t) }} <span class="mode">{{ t(rule.is_blocking ? 'requirements.blocking' : 'requirements.warningOnly') }}</span></li>
                  }
                </ul>
              } @else { <p class="dw-faint">{{ t('partners.requirements.publishedNone', { name: account.legal_name }) }}</p> }
            </div>
          </div>
        }
        <div class="rows">
          @for (row of rows(); track $index; let index = $index) {
            <div class="row" [class.to-review]="row.needs_review">
<!-- PR-02: what it is about, then the requirement as a sentence; the rest folded. -->
              <div class="what">
                <dw-field [label]="t('providers.family')">
                <dw-select [ngModel]="row.family" (ngModelChange)="patch(index, { family: $event, waste_type: '' })" [ngModelOptions]="{ standalone: true }">
                  @for (family of vocabulary()?.waste_families ?? []; track family.code) { <dw-option [value]="family.code" [label]="family.name | dwLocalized" /> }
                </dw-select>
              </dw-field>
                <dw-field [label]="t('providers.wasteTypeOptional')">
                <dw-select [ngModel]="row.waste_type" (ngModelChange)="patch(index, { waste_type: $event })" [ngModelOptions]="{ standalone: true }">
                  <dw-option value="" [label]="t('providers.wholeFamily')" />
                  @for (type of typesOf(row.family); track type.ulid) { <dw-option [value]="type.ulid" [label]="type.name" /> }
                </dw-select>
              </dw-field>
                <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="remove(index)" [title]="t('common.remove')" [attr.aria-label]="t('common.remove')"><dw-icon>delete</dw-icon></button>
              </div>
              <p class="sentence">
                <span>{{ t('providers.sentence.min') }}</span>
                <input pInputText class="qty" inputmode="decimal" placeholder="—" [ngModel]="row.min_quantity" (ngModelChange)="patch(index, { min_quantity: $event })" [ngModelOptions]="{ standalone: true }" [attr.aria-label]="t('providers.minQuantity')" />
                <dw-select class="unit" [ngModel]="row.min_unit" (ngModelChange)="patch(index, { min_unit: $event })" [ngModelOptions]="{ standalone: true }" [ariaLabel]="t('providers.minUnit')">
                  @for (unit of units; track unit) { <dw-option [value]="unit" [label]="t('stock.unit.' + unit)" /> }
                </dw-select>
                @if (row.min_unit === 'package') {
                  <dw-select class="pack" [ngModel]="row.min_packaging_type" (ngModelChange)="patch(index, { min_packaging_type: $event })" [ngModelOptions]="{ standalone: true }" [ariaLabel]="t('providers.minPackaging')">
                    @for (packaging of vocabulary()?.packaging_types ?? []; track packaging.code) { <dw-option [value]="packaging.code" [label]="packaging.name | dwLocalized" /> }
                  </dw-select>
                }
                <span>{{ t('providers.sentence.perPickup') }}</span>
                <dw-select class="pack" [ngModel]="row.required_packaging_type" (ngModelChange)="patch(index, { required_packaging_type: $event })" [ngModelOptions]="{ standalone: true }" [ariaLabel]="t('providers.requiredPackaging')">
                  <dw-option value="" [label]="t('providers.sentence.anyPackaging')" />
                  @for (packaging of vocabulary()?.packaging_types ?? []; track packaging.code) { <dw-option [value]="packaging.code" [label]="t('providers.sentence.inPackaging', { name: (packaging.name | dwLocalized) })" /> }
                </dw-select>
              </p>
              <dw-more [summary]="t(row.is_blocking ? 'partners.requirements.blockingOn' : 'partners.requirements.blockingOff')">
                <dw-field [label]="t('pickups.channel')">
                <dw-select [ngModel]="row.channel" (ngModelChange)="patch(index, { channel: $event })" [ngModelOptions]="{ standalone: true }">
                  @for (channel of vocabulary()?.treatment_channels ?? []; track channel.code) { <dw-option [value]="channel.code" [label]="channel.name | dwLocalized" /> }
                </dw-select>
              </dw-field>
                <dw-field class="reference" [label]="t('partners.requirements.reference')">
                <input pInputText maxlength="191" [placeholder]="t('partners.requirements.referenceHint')" [ngModel]="row.contract_reference" (ngModelChange)="patch(index, { contract_reference: $event })" [ngModelOptions]="{ standalone: true }" />
              </dw-field>
                <dw-field class="notes dw-span-2" [label]="t('fields.notes')">
                <input pInputText maxlength="500" [ngModel]="row.notes" (ngModelChange)="patch(index, { notes: $event })" [ngModelOptions]="{ standalone: true }" />
              </dw-field>
                <div class="consequence dw-span-2">
                <dw-checkbox [checked]="row.is_blocking" (checkedChange)="patch(index, { is_blocking: $event })">{{ t('partners.requirements.blocking') }}</dw-checkbox>
                <span class="dw-faint">{{ t(row.is_blocking ? 'partners.requirements.blockingOn' : 'partners.requirements.blockingOff') }}</span>
              </div>
                @if (row.waste_type) {
                <div class="consequence dw-span-2">
                  <dw-checkbox [checked]="row.adds_to_family" (checkedChange)="patch(index, { adds_to_family: $event })">{{ t('providers.addsToFamily') }}</dw-checkbox>
                  <span class="dw-faint">{{ t(row.adds_to_family ? 'providers.addsToFamilyOn' : 'providers.addsToFamilyOff') }}</span>
                </div>
              }
              </dw-more>
                            @if (row.needs_review && row.ulid) {
                <div class="consequence ask">
                  <strong>{{ t('partners.requirements.toReview') }}</strong>
                  <button pButton size="small" severity="secondary" outlined type="button" [disabled]="busy()" (click)="review(row.ulid, 'keep')">{{ t('partners.requirements.keep') }}</button>
                  <button pButton size="small" severity="danger" outlined type="button" [disabled]="busy()" (click)="review(row.ulid, 'remove')">{{ t('partners.requirements.removeLine') }}</button>
                </div>
              }
              @if (saved(row.ulid); as line) { <div class="consequence dw-faint">{{ t('partners.requirements.lastChange', { by: line.updated_by ?? '—', date: (line.updated_at | dwDate: 'datetime') }) }}</div> }
            </div>
          } @empty { <p class="dw-faint">{{ t('partners.requirements.none') }}</p> }
        </div>
        <button pButton severity="secondary" outlined type="button" class="add" (click)="add()"><dw-icon>add</dw-icon>{{ t('partners.requirements.add') }}</button>
      </div>
      <div class="dw-dialog-foot">
        <button pButton type="button" severity="secondary" outlined (click)="ref.close(changed())">{{ t('common.close') }}</button>
        <button pButton type="button" [disabled]="busy() || !valid()" (click)="save()">{{ t('common.save') }}</button>
      </div>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { display: grid; gap: var(--dw-3); inline-size: min(860px, 94vw); }
    .what { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; gap: 10px; align-items: end; }
    .sentence { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 10px 0 0; font-size: 13.5px; color: var(--dw-ink-2); }
    .sentence .qty { width: 90px; text-align: end; } .sentence .unit { width: 140px; } .sentence .pack { width: 190px; }
    .note { display: flex; gap: 8px; margin: 0; padding: 8px 10px; border-radius: var(--dw-radius); background: var(--dw-surface-2); font-size: 13px; } .note dw-icon { flex: none; font-size: 18px; }
    .note.review { background: var(--dw-primary-soft); } .note p { margin: 0 0 4px; } .note ul { margin: 0; padding-inline-start: 18px; }
    .rows { display: grid; gap: var(--dw-2); }
    .row { display: grid; gap: 4px; padding-block: 14px; border-block-end: 1px solid var(--dw-line-soft); }
    .row.to-review { padding: var(--dw-2); border: 1px solid var(--dw-warning); border-radius: var(--dw-radius); }

    .consequence { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: var(--dw-1) var(--dw-3); align-items: center; font-size: 13px; }
    .mode { font-size: 12px; font-weight: 600; } .add { justify-self: start; }
    @media (max-width: 560px) { .what { grid-template-columns: 1fr auto; } }
  `,
})
export class PartnerRequirementsDialog {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly limitText = inject(LimitText);
  private readonly code = new CodePipe();
  protected readonly ref = inject(DialogRef);

  protected readonly vocabulary = toSignal(inject(ReferenceData).vocabulary());
  /** The company's own waste types: a line may be about one of them rather than the whole family. */
  private readonly types = toSignal(inject(ReferenceData).wasteTypes(), { initialValue: [] as WasteType[] });
  protected readonly units: StockLimitUnit[] = ['kg', 't', 'piece', 'l', 'm3', 'package'];
  protected readonly partner = signal(dialogData<ExternalPartner>());
  protected readonly rows = signal<RequirementRow[]>(this.fromPartner(this.partner()));
  protected readonly busy = signal(false);
  /** Whether anything was saved: the list behind the dialog is then read again. */
  protected readonly changed = signal(false);
  /** A line asks something — a minimum in a unit, a packaging, or both — of one waste for one treatment. */
  protected readonly valid = computed(() => this.rows().every((row) => row.family && row.channel && (this.minimum(row) !== null || row.required_packaging_type !== '')
    && (row.min_unit === 'package' ? /^\d*$/.test(row.min_quantity.trim()) && (this.minimum(row) === null || !!row.min_packaging_type) : /^(\d+([.,]\d{1,3})?)?$/.test(row.min_quantity.trim()))));

  protected add(): void {
    this.rows.update((rows) => [...rows, { ulid: null, family: '', waste_type: '', adds_to_family: false, channel: '', min_quantity: '', min_unit: 'kg', min_packaging_type: '', required_packaging_type: '', is_blocking: false, contract_reference: '', notes: '', needs_review: false }]);
  }

  protected patch(index: number, change: Partial<RequirementRow>): void {
    this.rows.update((rows) => rows.map((row, position) => (position === index ? { ...row, ...change } : row)));
  }

  protected typesOf(family: string): WasteType[] {
    return this.types().filter((type) => type.waste_family === family);
  }

  protected remove(index: number): void {
    this.rows.update((rows) => rows.filter((_, position) => position !== index));
  }

  protected saved(ulid: string | null): PartnerRequirement | undefined {
    return ulid ? (this.partner().requirements ?? []).find((line) => line.ulid === ulid) : undefined;
  }

  /** "au moins 500 kg par enlèvement ; conditionnement demandé : Balle". */
  protected summary(rule: RequirementRule, t: (key: string, params?: Record<string, unknown>) => string): string {
    return [
      rule.min_quantity === null ? null : t('requirements.atLeast', { min: this.limitText.quantity({ unit: rule.min_unit ?? 'kg', packaging_type: rule.min_packaging_type ?? undefined } as LimitResult, rule.min_quantity) }),
      rule.required_packaging_type ? t('requirements.inPackaging', { packaging: this.code.transform(rule.required_packaging_type, 'packaging') }) : null,
    ].filter(Boolean).join(' ; ');
  }

  protected async save(): Promise<void> {
    this.busy.set(true);
    try {
      this.show(await firstValueFrom(this.api.put<ExternalPartner>(`external-partners/${this.partner().ulid}/requirements`, {
        requirements: this.rows().map((row) => {
          const minimum = this.minimum(row);

          return {
            ulid: row.ulid, family: row.family, waste_type: row.waste_type || null, adds_to_family: !!row.waste_type && row.adds_to_family, channel: row.channel, min_quantity: minimum, min_unit: minimum ? row.min_unit : null,
            min_packaging_type: minimum && row.min_unit === 'package' ? row.min_packaging_type : null, required_packaging_type: row.required_packaging_type || null,
            is_blocking: row.is_blocking, contract_reference: row.contract_reference.trim() || null, notes: row.notes.trim() || null,
          };
        }),
      })));
      this.toast.success('partners.requirements.saved');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  /** The partner has an account: a line the company wrote down is kept as its own requirement, or removed. */
  protected async review(ulid: string, decision: 'keep' | 'remove'): Promise<void> {
    this.busy.set(true);
    try {
      this.show(await firstValueFrom(this.api.post<ExternalPartner>(`external-partners/${this.partner().ulid}/requirements/${ulid}/review`, { decision })));
      this.toast.success(decision === 'keep' ? 'partners.requirements.kept' : 'partners.requirements.removed');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  private minimum(row: RequirementRow): string | null {
    return Number(row.min_quantity.replace(',', '.')) > 0 ? row.min_quantity.trim().replace(',', '.') : null;
  }

  private show(partner: ExternalPartner): void {
    this.partner.set(partner);
    this.rows.set(this.fromPartner(partner));
    this.changed.set(true);
  }

  private fromPartner(partner: ExternalPartner): RequirementRow[] {
    return (partner.requirements ?? []).map((line) => ({
      ulid: line.ulid, family: line.family ?? '', waste_type: line.waste_type?.ulid ?? '', adds_to_family: line.adds_to_family ?? false, channel: line.channel, min_quantity: line.min_quantity === null ? '' : String(Number(line.min_quantity)).replace('.', ','), min_unit: line.min_unit ?? 'kg',
      min_packaging_type: line.min_packaging_type ?? '', required_packaging_type: line.required_packaging_type ?? '', is_blocking: line.is_blocking,
      contract_reference: line.contract_reference ?? '', notes: line.notes ?? '', needs_review: line.needs_review,
    }));
  }
}

/**
 * The company's partners without an account, above the directory of the providers of the platform. For them, the
 * company fills in each stage of a pickup itself and the tracking slip is signed by hand.
 */
@Component({
  selector: 'dw-external-partners',
  imports: [TranslocoDirective, ButtonDirective, IconComponent, StatusComponent, DatePipe, MenuComponent, RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <section class="dw-panel">
        <div class="dw-panel-head">
          <div class="dw-spacer"><h2 class="dw-h2">{{ t('partners.title') }}</h2><p class="dw-muted hint">{{ t('partners.subtitle') }}</p></div>
          <button pButton text type="button" (click)="registry()"><dw-icon>travel_explore</dw-icon>{{ t('registry.open') }}</button>
          @if (canManage) { <button pButton severity="secondary" outlined type="button" (click)="edit(null)"><dw-icon>add</dw-icon>{{ t('partners.new') }}</button> }
        </div>
        @if (rows().length === 0) {
          <p class="dw-panel-body dw-muted">{{ t('partners.empty') }}</p>
        } @else {
          <table class="dw-table">
            <thead><tr><th>{{ t('partners.legalName') }}</th><th>{{ t('partners.roles') }}</th><th>{{ t('providers.contact') }}</th><th>{{ t('partners.accreditation') }}</th><th>{{ t('fields.status') }}</th><th></th></tr></thead>
            <tbody>
              @for (partner of rows(); track partner.ulid) {
                <tr [class.off]="!partner.is_active">
                  <td>
                    <strong>{{ partner.legal_name }}</strong><div class="dw-faint">{{ partner.city }} @if (partner.tax_id) { · {{ partner.tax_id }} }</div>
                    @if ((partner.requirements ?? []).length > 0) {
                      <div class="asks">{{ t('partners.requirements.count', { count: (partner.requirements ?? []).length }) }}
                        @if ((partner.requirements_to_review ?? 0) > 0) { <dw-status kind="limit" value="warning" [label]="t('partners.requirements.toReviewCount', { count: partner.requirements_to_review })" /> }</div>
                    }
                    @if (partner.suggested_company; as match) {
                      <p class="match" role="note"><dw-icon aria-hidden="true">how_to_reg</dw-icon><span>{{ t('partners.hasAccount', { name: match.legal_name }) }}</span>
                        @if (canManage) { <button pButton text size="small" type="button" (click)="link(partner)">{{ t('partners.link') }}</button> }</p>
                    }
                  </td>
                  <td>@for (role of partner.roles; track role; let last = $last) { {{ t('providers.role.' + role) }}@if (!last) { · } }</td>
                  <td>{{ partner.contact_name ?? '—' }}<div class="dw-faint">{{ partner.phone }}</div></td>
                  <td>
                    @if (partner.accreditation_reference) {
                      <span class="dw-mono">{{ partner.accreditation_reference }}</span>
                      @if (partner.accreditation_expires_on) { <div class="dw-faint" [class.late]="expired(partner)">{{ t('providers.expiresOn') }} {{ partner.accreditation_expires_on | dwDate }}</div> }
                    } @else { <span class="dw-faint">{{ t('partners.noAccreditation') }}</span> }
                  </td>
                  <td>
                    @if (partner.linked_company; as account) { <dw-status kind="accreditation" value="approved" [label]="t('partners.linkedTo', { name: account.legal_name })" /> }
                    @else { <dw-status kind="member" [value]="partner.is_active ? 'active' : 'suspended'" [label]="t(partner.is_active ? 'partners.inUse' : 'common.inactive')" /> }
                  </td>
                  <td class="dw-num row-actions">
                    <!-- One action in sight (a contract, or what the partner asks), the rest under "…". -->
                    @if (contracts.byParty().get(partner.ulid); as current) {
                      <a pButton text [routerLink]="['/contracts', current.ulid]"><dw-icon>description</dw-icon>{{ t('providers.viewContract', { number: current.contract_number }) }}</a>
                    } @else if (canContract(partner)) {
                      <button pButton text type="button" (click)="makeContract(partner)">{{ t('providers.makeContract') }}</button>
                    } @else if (canManage && asks(partner)) {
                      <button pButton text type="button" (click)="requirements(partner)">{{ t('partners.requirements.open') }}</button>
                    }
                    @if (actionsOf(partner, t).length > 0) {
                      <button pButton text rounded severity="secondary" size="small" class="dw-icon-btn" type="button" (click)="menu.open($event, actionsOf(partner, t))"
                        [attr.aria-label]="t('common.actions') + ' — ' + partner.legal_name" aria-haspopup="menu" [title]="t('common.actions') + ' — ' + partner.legal_name"><dw-icon>more_horiz</dw-icon></button>
                    }
                  </td>
                </tr>
              }
            </tbody>
          </table>
        }
      </section>
      <dw-menu #menu />
    </ng-container>
  `,
  styles: `
    :host { display: block; margin-block-end: var(--dw-4); }
    /* the title takes the room left; the two buttons keep theirs and go under it on a narrow screen */
    .dw-panel-head { flex-wrap: wrap; } .dw-panel-head > button { flex: none; white-space: nowrap; }
    .hint { margin: 2px 0 0; font-size: 13px; } .asks { display: flex; align-items: center; gap: 6px; margin-block-start: 4px; font-size: 12.5px; color: var(--dw-ink-2); }
    tr.off td { color: var(--dw-ink-3); }
    .match { display: flex; align-items: center; gap: 6px; margin: 6px 0 0; padding: 4px 8px; border-radius: var(--dw-radius); background: var(--dw-primary-soft); font-size: 12.5px; }
    .match dw-icon { font-size: 16px; color: var(--dw-primary); }
    .late { color: var(--dw-danger); font-weight: 600; }
    .row-actions { white-space: nowrap; } .row-actions > * { vertical-align: middle; }
  `,
})
export class ExternalPartnersComponent {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);

  /** A partner was tied to its account: the directory below has one more partner of the platform. */
  readonly linked = output<void>();
  /** PR-05: make a contract with this partner. */
  readonly contract = output<{ party: string; role: 'receiver' | 'transporter' }>();
  protected readonly session = inject(Session);
  protected readonly canManage = this.session.can('providers.partnerships.manage');
  protected readonly rows = signal<ExternalPartner[]>([]);
  protected readonly contracts = inject(PartyContracts);

  constructor() {
    this.load();
  }

  protected canContract(partner: ExternalPartner): boolean {
    return !partner.linked_company && partner.is_active && this.session.uses('contracts') && this.session.can('contracts.manage');
  }

  /** Those who receive the waste (collectors, recyclers, sorters) may ask things of a collection. */
  protected asks(partner: ExternalPartner): boolean {
    return partner.roles.some((role) => role !== 'transporter');
  }

  protected makeContract(partner: ExternalPartner): void {
    this.contract.emit({ party: 'x:' + partner.ulid, role: partner.roles.length === 1 && partner.roles[0] === 'transporter' ? 'transporter' : 'receiver' });
  }

  /** What is not the action in sight. */
  protected actionsOf(partner: ExternalPartner, t: (key: string) => string): MenuAction[] {
    const actions: MenuAction[] = [];
    // under contract already: a new one (another role, another site, the next period) stays one click away
    if (this.contracts.byParty().has(partner.ulid) && this.canContract(partner)) {
      actions.push({ label: t('providers.anotherContract'), icon: 'add', run: () => this.makeContract(partner) });
    }
    if (this.canManage && this.asks(partner) && (this.canContract(partner) || this.contracts.byParty().has(partner.ulid))) {
      actions.push({ label: t('partners.requirements.open'), icon: 'checklist', run: () => this.requirements(partner) });
    }
    if (this.canManage && !partner.linked_company) {
      actions.push({ label: t('common.edit'), icon: 'edit', run: () => this.edit(partner) });
      if (!partner.suggested_company) {
        actions.push({ label: t('partners.choose'), icon: 'link', run: () => this.choose(partner) });
      }
    }

    return actions;
  }

  protected expired(partner: ExternalPartner): boolean {
    return partner.accreditation_expires_on !== null && partner.accreditation_expires_on < new Date().toISOString().slice(0, 10);
  }

  /** The ANGed lists: an entry picked becomes a new partner, its form filled from the list. */
  protected registry(): void {
    this.dialog.open<RegistryDialog, { pickLabel: string } | null, RegistryOperator>(RegistryDialog, { data: this.canManage ? { pickLabel: 'registry.addAsPartner' } : null })
      .afterClosed().subscribe((entry) => {
        if (entry && this.canManage) {
          this.dialog.open<ExternalPartnerDialog, { from: RegistryOperator }, ExternalPartner>(ExternalPartnerDialog, { data: { from: entry } }).afterClosed().subscribe((saved) => saved && this.load());
        }
      });
  }

  protected edit(partner: ExternalPartner | null): void {
    this.dialog.open<ExternalPartnerDialog, ExternalPartner | null, ExternalPartner>(ExternalPartnerDialog, { data: partner }).afterClosed().subscribe((saved) => saved && this.load());
  }

  /** What the partner asks of a collection, as the company knows it. */
  protected requirements(partner: ExternalPartner): void {
    this.dialog.open<PartnerRequirementsDialog, ExternalPartner, boolean>(PartnerRequirementsDialog, { data: partner }).afterClosed().subscribe(() => this.load());
  }

  /**
   * The partner has opened an account (same tax ID as a published provider): tie them. Past pickups stay as they
   * were recorded; the next ones go to the provider through the platform.
   */
  protected async link(partner: ExternalPartner): Promise<void> {
    const match = partner.suggested_company;
    if (!match || !(await this.confirm.ask({ title: this.toast.text('partners.linkTitle', { name: partner.legal_name }), message: this.toast.text('partners.linkMessage', { name: match.legal_name }), confirmLabel: this.toast.text('partners.link') }))) {
      return;
    }
    try {
      await firstValueFrom(this.api.post(`external-partners/${partner.ulid}/link`, { provider: match.ulid }));
      this.toast.success('partners.linked');
      this.load();
      this.linked.emit();
    } catch (error) {
      this.toast.error(error);
    }
  }

  /** No tax ID in common: the user says himself which provider of the platform this partner has become. */
  protected choose(partner: ExternalPartner): void {
    this.dialog.open<LinkPartnerDialog, ExternalPartner, boolean>(LinkPartnerDialog, { data: partner }).afterClosed().subscribe((done) => {
      if (done) {
        this.load();
        this.linked.emit();
      }
    });
  }

  private load(): void {
    this.api.get<ExternalPartner[]>('external-partners', { with_inactive: true }).subscribe({ next: (rows) => this.rows.set(rows), error: (error: unknown) => this.toast.error(error) });
  }
}
