import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Tab, TabList, TabPanel, TabPanels, Tabs } from 'primeng/tabs';
import { Textarea } from 'primeng/textarea';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { CountryPipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { Column, TABLE } from '../../ui/data-table';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { FilterBarComponent } from '../../ui/filter-bar';
import { IconComponent } from '../../ui/icon';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { Party, blankToNull } from './production-data';

type Kind = 'clients' | 'suppliers';

interface PartyDialogData {
  kind: Kind;
  party: Party | null;
}

@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, Textarea, ...FIELD, ...DIALOG_PARTS, CheckboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('production.' + data.kind + '.' + (data.party ? 'edit' : 'new')) }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field [label]="t('fields.code')"><input pInputText formControlName="code" maxlength="40" required /></dw-field>
            <dw-field [label]="t('production.country')"><input pInputText formControlName="country_code" maxlength="2" placeholder="FR" class="upper" /></dw-field>
            <dw-field class="dw-span-2" [label]="t('fields.name')"><input pInputText formControlName="name" maxlength="191" required /></dw-field>
            <dw-field [label]="t('providers.contact')"><input pInputText formControlName="contact_name" maxlength="150" /></dw-field>
            <dw-field [label]="t('fields.phone')"><input pInputText formControlName="phone" maxlength="30" /></dw-field>
            <dw-field class="dw-span-2" [label]="t('fields.email')"><input pInputText type="email" formControlName="email" maxlength="191" /></dw-field>
            <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="1000"></textarea></dw-field>
            @if (data.party) { <dw-checkbox class="dw-span-2" formControlName="is_active">{{ t('common.active') }}</dw-checkbox> }
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: '.dw-dialog-body { inline-size: min(560px, 82vw); } .upper { text-transform: uppercase; }',
})
export class PartyDialog {
  protected readonly data = dialogData<PartyDialogData>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    code: [this.data.party?.code ?? '', Validators.required],
    name: [this.data.party?.name ?? '', Validators.required],
    country_code: [this.data.party?.country_code ?? '', Validators.pattern(/^[A-Za-z]{2}$/)],
    contact_name: [this.data.party?.contact_name ?? ''],
    phone: [this.data.party?.phone ?? ''],
    email: [this.data.party?.email ?? '', Validators.email],
    notes: [this.data.party?.notes ?? ''],
    is_active: [this.data.party?.is_active ?? true],
  });

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const body = blankToNull({ ...value, country_code: value.country_code.toUpperCase() });
    const path = `production/${this.data.kind}`;
    try {
      this.ref.close(await firstValueFrom(this.data.party ? this.api.put<Party>(`${path}/${this.data.party.ulid}`, body) : this.api.post<Party>(path, body)));
      this.toast.success('common.saved');
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

/** One list of parties (clients or suppliers), used as a tab. */
@Component({
  selector: 'dw-party-list',
  imports: [TranslocoDirective, ...TABLE, StatusComponent, SearchComponent, FilterBarComponent, CheckboxComponent, ButtonDirective, IconComponent, CountryPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-filter-bar [active]="(search() ? 1 : 0) + (inactive() ? 1 : 0)" (cleared)="search.set(''); inactive.set(false); list.reload()">
        <dw-search [placeholder]="t('common.search')" [query]="search()" (queryChange)="search.set($event); list.reload()" />
        <dw-checkbox [checked]="inactive()" (checkedChange)="inactive.set($event); list.reload()">{{ t('production.showInactive') }}</dw-checkbox>
        <span class="dw-spacer"></span>
        @if (canManage) { <button pButton type="button" (click)="edit(null)"><dw-icon>add</dw-icon>{{ t('production.' + kind() + '.new') }}</button> }
      </dw-filter-bar>
      <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1" [pageSize]="list.perPage()"
        [clickable]="canManage" [emptyTitle]="t('production.' + kind() + '.empty')" [emptyHint]="t('production.emptyHint')" emptyIcon="groups"
        (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="edit($event)" (retry)="list.reload()">
        <ng-template dwCell="code" let-party><span class="dw-mono">{{ party.code }}</span></ng-template>
        <ng-template dwCell="country" let-party>{{ party.country_code | dwCountry }}</ng-template>
        <ng-template dwCell="contact" let-party>{{ party.contact_name ?? '—' }}<div class="dw-faint">{{ party.phone }} {{ party.email }}</div></ng-template>
        <ng-template dwCell="status" let-party><dw-status kind="member" [value]="party.is_active ? 'active' : 'suspended'" [label]="t(party.is_active ? 'common.active' : 'common.inactive')" /></ng-template>
      </dw-data-table>
    </ng-container>
  `,
})
export class PartyListComponent {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);

  readonly kind = input.required<Kind>();
  protected readonly canManage = inject(Session).can('production.manage');
  protected readonly search = signal('');
  protected readonly inactive = signal(false);
  protected readonly list = new ListStore<Party>((query) => this.api.list<Party>(`production/${this.kind()}`, { ...query, search: this.search(), with_inactive: this.inactive() }));
  protected readonly columns: Column<Party>[] = [
    { key: 'code', header: 'fields.code', width: '140px' },
    { key: 'name', header: 'fields.name', value: (party) => party.name },
    { key: 'country', header: 'production.country', hideBelow: 900 },
    { key: 'contact', header: 'providers.contact', hideBelow: 700 },
    { key: 'status', header: 'fields.status' },
  ];

  protected edit(party: Party | null): void {
    this.dialog.open<PartyDialog, PartyDialogData, Party>(PartyDialog, { data: { kind: this.kind(), party } }).afterClosed().subscribe((saved) => saved && this.list.reload());
  }
}

/** Who the company produces for, and who supplies its raw materials. */
@Component({
  imports: [TranslocoDirective, PageHeaderComponent, PartyListComponent, Tabs, TabList, Tab, TabPanels, TabPanel],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('production.partners.title')" [subtitle]="t('production.partners.subtitle')" />
      <div class="dw-panel">
        <p-tabs [value]="0" [lazy]="true">
          <p-tablist><p-tab [value]="0">{{ t('production.clients.title') }}</p-tab><p-tab [value]="1">{{ t('production.suppliers.title') }}</p-tab></p-tablist>
          <p-tabpanels>
            <p-tabpanel [value]="0"><dw-party-list kind="clients" /></p-tabpanel>
            <p-tabpanel [value]="1"><dw-party-list kind="suppliers" /></p-tabpanel>
          </p-tabpanels>
        </p-tabs>
      </div>
    </ng-container>
  `,
})
export class PartnersPage {}
