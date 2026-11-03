import { ChangeDetectionStrategy, Component, OnInit, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';

import { Api, PageMeta } from '../../core/api/api';
import { RegistryLists, RegistryOperator } from '../../core/api/models';
import { Toast } from '../../core/feedback/toast';
import { DatePipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { DIALOG_PARTS, DialogRef, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SELECT } from '../../ui/select';

/**
 * The lists of authorised waste operators published by ANGed, searched page by page. Each entry is the agency's word:
 * the platform does not check it. `pick` turns each entry into a button (fill a partner from it).
 */
@Component({
  selector: 'dw-registry-browser',
  imports: [FormsModule, TranslocoDirective, ButtonDirective, InputText, CheckboxComponent, IconComponent, StatusComponent, DatePipe, ...FIELD, ...SELECT],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <div class="filters">
        <dw-field class="search" [label]="t('registry.search')">
          <input pInputText type="search" [placeholder]="t('registry.searchHint')" [(ngModel)]="search" (ngModelChange)="soon()" />
        </dw-field>
        <dw-field [label]="t('registry.list')">
          <dw-select [(ngModel)]="list" (ngModelChange)="reload()">
            <dw-option value="" [label]="t('registry.allLists')" />
            @for (item of lists()?.lists ?? []; track item.code) { <dw-option [value]="item.code" [label]="item.title + ' (' + item.entries + ')'" /> }
          </dw-select>
        </dw-field>
        <dw-field [label]="t('registry.role')">
          <dw-select [(ngModel)]="role" (ngModelChange)="reload()">
            <dw-option value="" [label]="t('registry.anyRole')" />
            @for (item of roles; track item) { <dw-option [value]="item" [label]="t('registry.roles.' + item)" /> }
          </dw-select>
        </dw-field>
        <dw-field [label]="t('registry.governorate')">
          <dw-select [(ngModel)]="governorate" (ngModelChange)="reload()">
            <dw-option value="" [label]="t('registry.allGovernorates')" />
            @for (item of lists()?.governorates ?? []; track item) { <dw-option [value]="item" [label]="item" /> }
          </dw-select>
        </dw-field>
        <dw-checkbox class="hazardous" [(ngModel)]="hazardous" (ngModelChange)="reload()">{{ t('registry.hazardousOnly') }}</dw-checkbox>
      </div>
      <p class="dw-faint source">
        {{ t('registry.source', { total: lists()?.total ?? 0 }) }}
        @if (lists()?.extracted_on; as on) { {{ t('registry.readOn', { date: (on | dwDate) }) }} }
      </p>

      @if (rows().length === 0 && !busy()) {
        <p class="dw-muted empty">{{ t((lists()?.total ?? 0) === 0 ? 'registry.notLoaded' : 'registry.none') }}</p>
      }
      <ul class="entries" [attr.aria-busy]="busy()">
        @for (row of rows(); track row.ulid) {
          <li>
            <div class="head">
              <strong>{{ row.legal_name }}</strong>
              <span class="dw-faint">{{ row.list_title }}</span>
              @if (row.hazardous) { <dw-status kind="limit" value="warning" [label]="t('registry.hazardous')" /> }
              @if (row.expired) { <dw-status kind="limit" value="exceeded" [label]="t('registry.expired')" /> }
            </div>
            <div class="facts">
              <span>@for (item of row.roles; track item; let last = $last) { {{ t('registry.roles.' + item) }}@if (!last) { · } } @if (row.roles.length === 0) { {{ row.activity ?? '—' }} }</span>
              @if (row.governorate) { <span><dw-icon aria-hidden="true">place</dw-icon>{{ row.governorate }}</span> }
              @if (row.phone) { <span><dw-icon aria-hidden="true">call</dw-icon>{{ row.phone }}</span> }
              @if (row.contact_name) { <span><dw-icon aria-hidden="true">person</dw-icon>{{ row.contact_name }}</span> }
              @if (row.authorization_number) {
                <span><dw-icon aria-hidden="true">verified</dw-icon>{{ t('registry.authorization', { number: row.authorization_number }) }}
                  @if (row.valid_until) { — {{ t('registry.until', { date: (row.valid_until | dwDate) }) }} }</span>
              }
              @if (row.annual_tonnage && row.annual_tonnage.length <= 16) { <span>{{ numeric(row.annual_tonnage) ? t('registry.tonnage', { value: row.annual_tonnage }) : row.annual_tonnage }}</span> }
            </div>
            @if (row.waste && row.waste !== row.list_title) { <p class="waste" [title]="row.waste">{{ row.waste }}</p> }
            @if (row.head_office || row.plant_address) { <p class="dw-faint address">{{ row.plant_address ?? row.head_office }}</p> }
            @if (pick()) {
              <button pButton size="small" severity="secondary" outlined type="button" (click)="picked.emit(row)">{{ t(pickLabel()) }}</button>
            }
          </li>
        }
      </ul>
      @if (meta(); as page) {
        @if (page.last_page > 1) {
          <div class="pager">
            <button pButton text type="button" [disabled]="busy() || page.current_page <= 1" (click)="go(page.current_page - 1)"><dw-icon>chevron_left</dw-icon></button>
            <span>{{ t('registry.page', { page: page.current_page, pages: page.last_page, total: page.total }) }}</span>
            <button pButton text type="button" [disabled]="busy() || page.current_page >= page.last_page" (click)="go(page.current_page + 1)"><dw-icon>chevron_right</dw-icon></button>
          </div>
        }
      }
    </ng-container>
  `,
  styles: `
    :host { display: grid; gap: var(--dw-2); min-width: 0; }
    .filters { display: grid; grid-template-columns: minmax(200px, 2fr) repeat(3, minmax(140px, 1fr)); gap: var(--dw-2); align-items: end; }
    .hazardous { grid-column: 1 / -1; }
    .source { margin: 0; font-size: 12.5px; } .empty { margin: var(--dw-3) 0; }
    .entries { list-style: none; margin: 0; padding: 0; display: grid; }
    .entries li { display: grid; gap: 4px; justify-items: start; padding-block: 12px; border-block-end: 1px solid var(--dw-line-soft); }
    .head { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; }
    .facts { display: flex; flex-wrap: wrap; gap: 2px 16px; font-size: 13px; color: var(--dw-ink-2); }
    .facts span { display: inline-flex; align-items: center; gap: 4px; } .facts dw-icon { font-size: 15px; color: var(--dw-ink-3); }
    .waste, .address { margin: 0; font-size: 12.5px; }
    .waste { color: var(--dw-ink-2); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .pager { display: flex; align-items: center; justify-content: center; gap: var(--dw-2); font-size: 13px; }
    @media (max-width: 760px) { .filters { grid-template-columns: 1fr 1fr; } .search { grid-column: 1 / -1; } }
  `,
})
export class RegistryBrowserComponent implements OnInit {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);

  /** `registry` for a company, `platform/registry` for the back-office. */
  readonly base = input('registry');
  readonly pick = input(false);
  readonly pickLabel = input('registry.use');
  /** Words to start from (the name typed in a partner form). */
  readonly initial = input('');
  readonly picked = output<RegistryOperator>();

  protected readonly roles = ['collector', 'transporter', 'recycler', 'sorter', 'storer'] as const;
  protected readonly lists = signal<RegistryLists | null>(null);
  protected readonly rows = signal<RegistryOperator[]>([]);
  protected readonly meta = signal<PageMeta | null>(null);
  protected readonly busy = signal(false);
  protected search = '';
  protected list = '';
  protected role = '';
  protected governorate = '';
  protected hazardous = false;
  private timer: ReturnType<typeof setTimeout> | undefined;

  ngOnInit(): void {
    this.search = this.initial();
    this.api.get<RegistryLists>(`${this.base()}/operators/lists`).subscribe({ next: (lists) => this.lists.set(lists), error: (error: unknown) => this.toast.error(error) });
    void this.go(1);
  }

  /** A bare number is tonnes a year; a cell with its own unit ("1800 L", "36000T/an") is shown as printed. */
  protected numeric(value: string): boolean {
    return /^[\d\s.,]+$/.test(value);
  }

  /** Typing: the list follows once the user pauses. */
  protected soon(): void {
    clearTimeout(this.timer);
    this.timer = setTimeout(() => this.reload(), 300);
  }

  protected reload(): void {
    void this.go(1);
  }

  protected async go(page: number): Promise<void> {
    this.busy.set(true);
    try {
      const result = await firstValueFrom(this.api.list<RegistryOperator>(`${this.base()}/operators`, {
        'filter[search]': this.search.trim() || undefined, 'filter[list]': this.list || undefined, 'filter[role]': this.role || undefined,
        'filter[governorate]': this.governorate || undefined, 'filter[hazardous]': this.hazardous ? 1 : undefined, per_page: 20, page,
      }));
      this.rows.set(result.data);
      this.meta.set(result.meta);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

/** Pick an entry of the ANGed lists: the dialog closes with it. */
@Component({
  imports: [TranslocoDirective, ButtonDirective, RegistryBrowserComponent, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('registry.title') }}</dw-dialog-title>
      <div class="dw-dialog-body">
        <p class="dw-muted intro">{{ t(pick ? 'registry.pickIntro' : 'registry.intro') }}</p>
        <dw-registry-browser [pick]="pick" [pickLabel]="pickLabel" [initial]="initial" (picked)="ref.close($event)" />
      </div>
      <div class="dw-dialog-foot"><button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.close') }}</button></div>
    </ng-container>
  `,
  styles: '.dw-dialog-body { inline-size: min(900px, 94vw); } .intro { margin: 0 0 var(--dw-2); font-size: 13px; }',
})
export class RegistryDialog {
  protected readonly ref = inject(DialogRef);
  private readonly options = dialogData<{ initial?: string; pickLabel?: string } | null>();
  protected readonly initial = this.options?.initial ?? '';
  /** Without a way to use an entry (a user who cannot add partners), the dialog only shows the lists. */
  protected readonly pick = this.options !== null && this.options !== undefined;
  protected readonly pickLabel = this.options?.pickLabel ?? 'registry.use';
}

/** Back-office: the same lists, to see who the platform could invite. */
@Component({
  imports: [TranslocoDirective, PageHeaderComponent, RegistryBrowserComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('registry.title')" [subtitle]="t('registry.platformSubtitle')" />
      <section class="dw-panel"><div class="dw-panel-body"><dw-registry-browser base="platform/registry" /></div></section>
    </ng-container>
  `,
})
export class PlatformRegistryPage {}
