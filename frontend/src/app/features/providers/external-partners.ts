import { ChangeDetectionStrategy, Component, inject, output, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';

import { Api } from '../../core/api/api';
import { ExternalPartner } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Confirm } from '../../ui/confirm';
import { Toast } from '../../core/feedback/toast';
import { DatePipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { DateComponent } from '../../ui/date';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { StatusComponent } from '../../ui/page';
import { SELECT } from '../../ui/select';

/** A transporter or a receiver that has no account on the platform: the company records it for its own use. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...DIALOG_PARTS, CheckboxComponent, DateComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'partners.edit' : 'partners.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <p class="dw-muted intro">{{ t('partners.intro') }}</p>
          <div class="dw-form">
            <dw-field class="dw-span-2" [label]="t('partners.legalName')"><input pInputText formControlName="legal_name" maxlength="191" required /></dw-field>
            <dw-field [label]="t('partners.roles')">
              <dw-select formControlName="roles" multiple required>
                @for (role of roles; track role) { <dw-option [value]="role" [label]="t('providers.role.' + role)" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('fields.taxId')"><input pInputText formControlName="tax_id" maxlength="40" /></dw-field>
            <dw-field class="dw-span-2" [label]="t('fields.address')"><input pInputText formControlName="address_line1" maxlength="191" /></dw-field>
            <dw-field [label]="t('fields.postalCode')"><input pInputText formControlName="postal_code" maxlength="20" /></dw-field>
            <dw-field [label]="t('fields.city')"><input pInputText formControlName="city" maxlength="100" /></dw-field>
            <dw-field [label]="t('providers.contact')"><input pInputText formControlName="contact_name" maxlength="150" /></dw-field>
            <dw-field [label]="t('fields.phone')"><input pInputText formControlName="phone" maxlength="30" /></dw-field>
            <dw-field class="dw-span-2" [label]="t('fields.email')"><input pInputText type="email" formControlName="email" maxlength="191" /></dw-field>

            <dw-field [label]="t('partners.accreditation')">
              <input pInputText formControlName="accreditation_reference" maxlength="100" />
              <small dwHint>{{ t('partners.accreditationHint') }}</small>
            </dw-field>
            <dw-field [label]="t('providers.expiresOn')"><dw-date formControlName="accreditation_expires_on" /></dw-field>
            <dw-checkbox class="dw-span-2" formControlName="covers_hazardous">{{ t('partners.coversHazardous') }}</dw-checkbox>
            <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="1000"></textarea></dw-field>
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
  styles: '.dw-dialog-body { inline-size: min(640px, 82vw); } .intro { margin: 0 0 var(--dw-3); font-size: 13px; }',
})
export class ExternalPartnerDialog {
  protected readonly data = dialogData<ExternalPartner | null>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly roles = ['transporter', 'recycler', 'eliminator'] as const;
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

/**
 * The company's partners without an account, above the directory of the providers of the platform. For them, the
 * company fills in each stage of a pickup itself and the tracking slip is signed by hand.
 */
@Component({
  selector: 'dw-external-partners',
  imports: [TranslocoDirective, ButtonDirective, IconComponent, StatusComponent, DatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <section class="dw-panel">
        <div class="dw-panel-head">
          <div class="dw-spacer"><h2 class="dw-h2">{{ t('partners.title') }}</h2><p class="dw-muted hint">{{ t('partners.subtitle') }}</p></div>
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
                  <td class="dw-num">@if (canManage && !partner.linked_company) { <button pButton text type="button" (click)="edit(partner)">{{ t('common.edit') }}</button> }</td>
                </tr>
              }
            </tbody>
          </table>
        }
      </section>
    </ng-container>
  `,
  styles: `
    :host { display: block; margin-block-end: var(--dw-4); }
    .hint { margin: 2px 0 0; font-size: 13px; }
    tr.off td { color: var(--dw-ink-3); }
    .match { display: flex; align-items: center; gap: 6px; margin: 6px 0 0; padding: 4px 8px; border-radius: var(--dw-radius); background: var(--dw-primary-soft); font-size: 12.5px; }
    .match dw-icon { font-size: 16px; color: var(--dw-primary); }
    .late { color: var(--dw-danger); font-weight: 600; }
  `,
})
export class ExternalPartnersComponent {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);

  /** A partner was tied to its account: the directory below has one more partner of the platform. */
  readonly linked = output<void>();
  protected readonly canManage = inject(Session).can('providers.partnerships.manage');
  protected readonly rows = signal<ExternalPartner[]>([]);

  constructor() {
    this.load();
  }

  protected expired(partner: ExternalPartner): boolean {
    return partner.accreditation_expires_on !== null && partner.accreditation_expires_on < new Date().toISOString().slice(0, 10);
  }

  protected edit(partner: ExternalPartner | null): void {
    this.dialog.open<ExternalPartnerDialog, ExternalPartner | null, ExternalPartner>(ExternalPartnerDialog, { data: partner }).afterClosed().subscribe((saved) => saved && this.load());
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

  private load(): void {
    this.api.get<ExternalPartner[]>('external-partners', { with_inactive: true }).subscribe({ next: (rows) => this.rows.set(rows), error: (error: unknown) => this.toast.error(error) });
  }
}
