import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';
import { Tab, TabList, TabPanel, TabPanels, Tabs } from 'primeng/tabs';
import { AutoFocus } from 'primeng/autofocus';

import { Api } from '../../core/api/api';
import { AccessSite, Invitation, Member, RoleDefinition } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ActivatedRoute } from '@angular/router';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, LocalizedPipe, TermPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { StateComponent } from '../../ui/state';
import { CheckboxComponent } from '../../ui/checkbox';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { SegmentedComponent } from '../../ui/more';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { MenuAction, MenuComponent } from '../../ui/menu';
import { SELECT } from '../../ui/select';
import { FilterBarComponent } from '../../ui/filter-bar';

interface Permission {
  name: string;
  module: string;
  scope: string;
}

interface RolesPayload {
  roles: RoleDefinition[];
  permissions: Permission[];
}

interface RolesDialogData {
  member: Member;
  roles: RoleDefinition[];
  sites: AccessSite[];
}

/** M0-08: the roles of a member, site by site. "All sites" is a company-wide assignment. */
@Component({
  imports: [FormsModule, TranslocoDirective, LocalizedPipe, IconComponent, ButtonDirective, ...FIELD, ...SELECT, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('admin.users.editRoles') }} — {{ data.member.first_name }} {{ data.member.last_name }}</dw-dialog-title>
      <div class="dw-dialog-body">
        @for (row of rows(); track $index; let index = $index) {
          <div class="row">
            <dw-field [label]="t('fields.role')">
              <dw-select [ngModel]="row.role" (ngModelChange)="patch(index, { role: $event })">
                @for (role of data.roles; track role.name) { <dw-option [value]="role.name" [label]="role.label | dwLocalized: role.name" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('fields.site')">
              <dw-select [ngModel]="row.site" (ngModelChange)="patch(index, { site: $event })">
                <dw-option value="" [label]="t('admin.users.allSites')" />
                @for (site of data.sites; track site.ulid) { <dw-option [value]="site.ulid" [label]="site.name" /> }
              </dw-select>
            </dw-field>
            <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="remove(index)" [attr.aria-label]="t('common.remove')" [title]="t('common.remove')"><dw-icon>delete</dw-icon></button>
          </div>
        }
        <button pButton text type="button" (click)="add()"><dw-icon>add</dw-icon>{{ t('admin.users.addAssignment') }}</button>
      </div>
      <div class="dw-dialog-foot">
        <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
        <button pButton type="button" [disabled]="busy() || !valid()" (click)="save()">{{ t('common.save') }}</button>
      </div>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { min-width: min(560px, 80vw); }
    .row { display: grid; grid-template-columns: 1fr 1fr auto; gap: var(--dw-2); align-items: start; }
  `,
})
export class MemberRolesDialog {
  protected readonly data = dialogData<RolesDialogData>();
  private readonly ref = inject(DialogRef);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);

  protected readonly rows = signal(this.data.member.assignments.map((assignment) => ({ role: assignment.role, site: assignment.site ?? '' })));
  protected readonly busy = signal(false);
  protected readonly valid = computed(() => this.rows().every((row) => row.role !== ''));

  protected patch(index: number, change: Partial<{ role: string; site: string }>): void {
    this.rows.update((rows) => rows.map((row, position) => (position === index ? { ...row, ...change } : row)));
  }

  protected add(): void {
    this.rows.update((rows) => [...rows, { role: '', site: '' }]);
  }

  protected remove(index: number): void {
    this.rows.update((rows) => rows.filter((_, position) => position !== index));
  }

  protected async save(): Promise<void> {
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.put(`users/${this.data.member.ulid}/roles`, { assignments: this.rows().map((row) => ({ role: row.role, site: row.site || null })) }));
      this.toast.success('admin.users.rolesSaved');
      this.ref.close(true);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, LocalizedPipe, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...DIALOG_PARTS, AutoFocus],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('admin.users.invite') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="send()">
        <div class="dw-dialog-body">
          <p class="dw-muted">{{ t('admin.users.inviteHint') }}</p>
          <div class="dw-form">
            <dw-field class="dw-span-2" [label]="t('fields.email')">
              <input pInputText type="email" formControlName="email" autocomplete="off" [pAutoFocus]="true" />
            </dw-field>
            <dw-field [label]="t('fields.firstName')"><input pInputText formControlName="first_name" /></dw-field>
            <dw-field [label]="t('fields.lastName')"><input pInputText formControlName="last_name" /></dw-field>
            <dw-field [label]="t('fields.role')">
              <dw-select formControlName="role">
                @for (role of data.roles; track role.name) { <dw-option [value]="role.name" [label]="role.label | dwLocalized: role.name" /> }
              </dw-select>
              @if (systemRole(); as name) { <small dwHint>{{ t('admin.users.roleHints.' + name) }}</small> }
            </dw-field>
            @if (data.sites.length > 0) {
              <dw-field [label]="t('admin.users.sites')">
                <dw-select formControlName="sites" multiple [placeholder]="t('admin.users.allSites')">
                  @for (site of data.sites; track site.ulid) { <dw-option [value]="site.ulid" [label]="site.name" /> }
                </dw-select>
                <small dwHint>{{ t('admin.users.allSites') }} — {{ t('common.optional') }}</small>
              </dw-field>
            }
            <dw-field class="dw-span-2">
              <span dwLabel>{{ t('admin.users.message') }} ({{ t('common.optional') }})</span>
              <textarea pTextarea rows="3" maxlength="1000" formControlName="message"></textarea>
            </dw-field>
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('admin.users.invite') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: '.dw-dialog-body { min-width: min(560px, 80vw); }',
})
export class InviteDialog {
  /** U-02: a standard role is explained in one sentence under the selector. */
  protected systemRole(): string | null {
    const name = this.form.controls.role.value;
    return this.data.roles.find((role) => role.name === name)?.is_system ? name : null;
  }

  protected readonly data = dialogData<{ roles: RoleDefinition[]; sites: AccessSite[] }>();
  private readonly ref = inject(DialogRef);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);

  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    first_name: [''],
    last_name: [''],
    role: ['', Validators.required],
    sites: [[] as string[]],
    message: [''],
  });

  protected async send(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    try {
      const value = this.form.getRawValue();
      await firstValueFrom(this.api.post('invitations', { ...value, first_name: value.first_name || null, last_name: value.last_name || null, message: value.message || null }));
      this.toast.success('admin.users.invited');
      this.ref.close(true);
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
 * M0-08 and the role × permission matrix (cahier §2). Members, pending invitations and roles are three views
 * of the same question — who can do what, where — so they share one screen.
 */
@Component({
  imports: [
    TermPipe, FormsModule, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, SearchComponent, StateComponent, DatePipe, LocalizedPipe, IconComponent, ButtonDirective, InputText, ...FIELD, CheckboxComponent, SegmentedComponent, FilterBarComponent, MenuComponent, Tabs, TabList, Tab, TabPanels, TabPanel,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('admin.users.title')" [subtitle]="t('admin.users.subtitle')">
        @if (canInvite) { <button pButton type="button" (click)="invite()"><dw-icon>person_add</dw-icon>{{ t('admin.users.invite') }}</button> }
      </dw-page-header>

      <div class="dw-panel">
        <p-tabs [value]="0">
          <p-tablist>
            <p-tab [value]="0">{{ t('admin.users.members') }}</p-tab>
            <p-tab [value]="2">{{ t('admin.users.roles') }}</p-tab>
          </p-tablist>
          <p-tabpanels>
            <p-tabpanel [value]="0">
              <!-- U-01: who was invited and has not joined yet, above the members, with what can be done. -->
              @if (pending().length > 0) {
                <div class="pending">
                  <h3 class="dw-label">{{ t('admin.users.pendingInvitations', { count: pending().length }) }}</h3>
                  @for (i of pending(); track i.ulid) {
                    <div class="invite-row">
                      <span class="ico"><dw-icon>mail</dw-icon></span>
                      <span class="who"><strong>{{ i.email }}</strong><span class="dw-faint">{{ roleLabel(i.role) | dwLocalized: i.role }} · {{ t('admin.users.expires', { date: (i.expires_at | dwDate) }) }}</span></span>
                      <dw-status kind="invitation" [value]="i.status" />
                      @if (canInvite) {
                        <button pButton severity="secondary" outlined size="small" type="button" [disabled]="busy()" (click)="resend(i)">{{ t('admin.users.resend') }}</button>
                        <button pButton text size="small" type="button" [disabled]="busy()" (click)="revoke(i)">{{ t('admin.users.revoke') }}</button>
                      }
                    </div>
                  }
                </div>
              }
              <dw-filter-bar [active]="members.activeCount()" (cleared)="members.resetFilter()"><dw-search [placeholder]="t('common.search')" [query]="members.filter().search ?? ''" (queryChange)="members.setFilter({ search: $event })" /></dw-filter-bar>
              <dw-data-table [columns]="memberColumns" [rows]="members.rows()" [loading]="members.loading()" [error]="members.error()" [total]="members.total()"
                [pageIndex]="members.page() - 1" [pageSize]="members.perPage()" emptyIcon="group" [emptyTitle]="t('common.noResult')"
                [clickable]="canManage" (rowClick)="editRoles($event)"
                (page)="members.setPage($event.pageIndex + 1, $event.pageSize)" (retry)="members.reload()">
                <ng-template dwCell="name" let-m>
                  <strong>{{ m.first_name }} {{ m.last_name }}</strong><div class="dw-faint">{{ m.email }}</div>
                </ng-template>
                <ng-template dwCell="roles" let-m>
                  @for (assignment of m.assignments; track $index) {
                    <span class="chip">{{ assignment.label | dwLocalized: assignment.role }}<span class="dw-faint"> · {{ assignment.site_name ?? t('admin.users.allSites') }}</span></span>
                  } @empty { <span class="dw-faint">{{ t('common.none') }}</span> }
                </ng-template>
                <ng-template dwCell="twoFactor" let-m>{{ m.two_factor_enabled ? t('common.yes') : t('common.no') }}</ng-template>
                <ng-template dwCell="login" let-m>{{ m.last_login_at | dwDate: 'datetime' }}</ng-template>
                <ng-template dwCell="status" let-m><dw-status kind="member" [value]="m.status" /></ng-template>
                <ng-template dwCell="actions" let-m>
                  @if (canManage) {
                    <button pButton text rounded severity="secondary" size="small" class="dw-icon-btn" type="button" (click)="$event.stopPropagation(); menu.open($event, memberActions(m))" [attr.aria-label]="t('common.actions') + ' — ' + m.first_name + ' ' + m.last_name" aria-haspopup="menu" [title]="t('common.actions') + ' — ' + m.first_name + ' ' + m.last_name"><dw-icon>more_horiz</dw-icon></button>
                  }
                </ng-template>
              </dw-data-table>
            </p-tabpanel>
  

  
            <p-tabpanel [value]="2">
              <dw-state [loading]="!payload() && !rolesError()" [error]="rolesError()" (retry)="loadRoles()">
                @if (payload(); as data) {
                  <div class="roles">
                    <nav class="role-list" [attr.aria-label]="t('admin.users.roles')">
                      @for (role of data.roles; track role.name) {
                        <button type="button" [class.on]="role.name === selectedName()" (click)="select(role)">
                          <span class="dw-spacer">{{ role.label | dwLocalized: role.name }}</span>
                          <span class="dw-faint">{{ t(role.is_system ? 'admin.users.systemRole' : 'admin.users.customRole') }}</span>
                        </button>
                      }
                      @if (canRoles) { <button type="button" class="new" [class.on]="creating()" (click)="startCreate()"><dw-icon>add</dw-icon>{{ t('admin.users.newRole') }}</button> }
                    </nav>
  
                    <div class="role-detail">
                      @if (creating()) {
                        <!-- The identifier follows the name given; it is shown, not asked. -->
                        <dw-field class="label" [label]="t('admin.users.roleLabel')">
                          <input pInputText [ngModel]="draftLabel()" (ngModelChange)="nameRole($event)" maxlength="100" />
                          @if (draftName()) { <small dwHint>{{ t('admin.users.roleNameIs', { name: draftName() }) }}</small> }
                        </dw-field>
                      } @else if (selected(); as role) {
                        @if (role.is_system) {
                          <p class="role-says">{{ t('admin.users.roleHints.' + role.name) }}</p>
                          <p class="dw-muted">{{ t('admin.users.systemHint') }}</p>
                        } @else if (canRoles) {
                          <dw-field class="label" [label]="t('admin.users.roleLabel')"><input pInputText [ngModel]="draftLabel()" (ngModelChange)="draftLabel.set($event)" maxlength="100" /></dw-field>
                        }
                      }
  
                      <h3 class="dw-label">{{ t('admin.users.permissions') }}</h3>
                      <!-- U-02: one choice per area (none, see, manage); the single permissions stay one click away. -->
                      <div class="areas">
                        @for (group of modules(); track group.module) {
                          @let current = level(group);
                          <div class="area" [class.open]="opened().has(group.module)">
                            <div class="area-row">
                              <span class="area-name">{{ group.module | dwTerm: 'terms.modules' }}</span>
                              @if (editable()) {
                                <dw-segmented [options]="levelOptions(group, t)" [value]="current" (valueChange)="setLevel(group, $event)" [ariaLabel]="group.module | dwTerm: 'terms.modules'" />
                              } @else {
                                <span class="level" [class]="current">{{ t('admin.users.levels.' + current) }}</span>
                              }
                              <button type="button" class="detail" (click)="toggleOpen(group.module)" [attr.aria-expanded]="opened().has(group.module)">
                                {{ current === 'custom' ? t('admin.users.levels.customCount', { count: countIn(group), total: group.permissions.length }) : t('admin.users.detail') }}
                                <dw-icon aria-hidden="true">expand_more</dw-icon>
                              </button>
                            </div>
                            @if (opened().has(group.module)) {
                              <div class="area-perms">
                                @for (permission of group.permissions; track permission.name) {
                                  <dw-checkbox [checked]="draft().has(permission.name)" [disabled]="!editable()" (checkedChange)="toggle(permission.name, $event)">
                                    {{ permission.name | dwTerm: 'permissions' }}
                                  </dw-checkbox>
                                }
                              </div>
                            }
                          </div>
                        }
                      </div>

                      @if (editable()) {
                        <div class="dw-form-actions">
                          @if (!creating()) { <button pButton text type="button" [disabled]="busy()" (click)="deleteRole()">{{ t('common.delete') }}</button> }
                          <button pButton type="button" [disabled]="busy() || draftLabel().trim() === '' || (creating() && !nameValid())" (click)="saveRole()">{{ t('common.save') }}</button>
                        </div>
                      }
                    </div>
                  </div>
                }
              </dw-state>
            </p-tabpanel>
          </p-tabpanels>
        </p-tabs>
      </div>
      <dw-menu #menu />
    </ng-container>
  `,
  styles: `
    .pending { display: grid; gap: 6px; padding: 14px 16px; border-block-end: 1px solid var(--dw-line-soft); background: var(--dw-surface-2); }
    .invite-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 6px 0; }
    .invite-row .ico { display: grid; place-items: center; width: 32px; height: 32px; border-radius: 9px; background: var(--dw-warning-soft); color: var(--dw-warning); }
    .invite-row .who { display: flex; flex-direction: column; flex: 1; min-width: 180px; font-size: 13px; }
    .role-says { margin: 0 0 8px; padding: 12px 14px; border-radius: 10px; background: var(--dw-primary-soft); font-size: 13.5px; }
    .chip { display: inline-block; margin: 1px 6px 1px 0; padding: 1px 8px; border-radius: 999px; background: var(--dw-neutral-soft); font-size: 12px; white-space: nowrap; }
    .roles { display: grid; grid-template-columns: 260px minmax(0, 1fr); min-height: 420px; }
    .role-list { display: flex; flex-direction: column; border-inline-end: 1px solid var(--dw-line-soft); padding: var(--dw-2); gap: 2px; }
    .role-list button { all: unset; cursor: pointer; display: flex; flex-direction: column; padding: 8px 10px; border-radius: var(--dw-radius); font-size: 13px; }
    .role-list button .dw-faint { font-size: 11.5px; }
    .role-list button:hover { background: var(--dw-surface-2); } .role-list button.on { background: var(--dw-primary-soft); color: var(--dw-primary-strong); font-weight: 600; }
    .role-list button:focus-visible { outline: 2px solid var(--dw-primary); }
    .role-list .new { flex-direction: row; align-items: center; gap: 6px; color: var(--dw-primary); }
    .role-detail { padding: var(--dw-4); display: grid; gap: var(--dw-3); align-content: start; }
    .role-detail .label { max-width: 360px; }
    .areas { display: grid; border: 1px solid var(--dw-line-soft); border-radius: var(--dw-radius-lg); overflow: hidden; }
    .area + .area { border-block-start: 1px solid var(--dw-line-soft); }
    .area-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 8px 14px; min-height: 48px; }
    .area-name { flex: 1; min-width: 160px; font-weight: 600; font-size: 13.5px; }
    .area.open { background: var(--dw-surface-2); }
    .detail { all: unset; cursor: pointer; display: inline-flex; align-items: center; gap: 2px; font-size: 12.5px; color: var(--dw-ink-3); border-radius: 6px; padding: 2px 4px; }
    .detail:hover { color: var(--dw-ink); } .detail:focus-visible { outline: 2px solid var(--dw-focus); }
    .detail dw-icon { font-size: 18px; transition: transform 160ms var(--dw-ease); } .area.open .detail dw-icon { transform: rotate(180deg); }
    .area-perms { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 2px 16px; padding: 0 14px 12px; animation: dw-rise 160ms var(--dw-ease); }
    .level { font-size: 12.5px; padding: 2px 10px; border-radius: 999px; background: var(--dw-neutral-soft); color: var(--dw-ink-2); }
    .level.manage { background: var(--dw-primary-soft); color: var(--dw-primary-strong); }
    .level.none { color: var(--dw-ink-3); }
    @media (max-width: 900px) { .roles { grid-template-columns: minmax(0, 1fr); } .role-list { border-inline-end: 0; border-block-end: 1px solid var(--dw-line-soft); } }
  `,
})
export class UsersPage {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);
  private readonly dialog = inject(Dialogs);

  protected readonly canInvite = this.session.can('users.invite');
  private inviteAsked = !!inject(ActivatedRoute).snapshot.queryParamMap.get('invite');
  protected readonly canManage = this.session.can('users.manage');
  protected readonly canRoles = this.session.can('roles.manage');
  protected readonly busy = signal(false);

  protected readonly members = new ListStore<Member, { search?: string }>((query) =>
    this.api.list<Member>('users', { search: (query['filter'] as { search?: string }).search, page: query['page'] as number, per_page: query['per_page'] as number }));
  protected readonly memberColumns: Column<Member>[] = [
    { key: 'name', header: 'fields.name' },
    { key: 'roles', header: 'admin.users.assignments' },
    { key: 'twoFactor', header: 'admin.users.twoFactor', hideBelow: 1200 },
    { key: 'login', header: 'admin.users.lastLogin', hideBelow: 900 },
    { key: 'status', header: 'fields.status' },
    { key: 'actions', header: 'common.actions', align: 'end' },
  ];

  protected memberActions(member: Member): MenuAction[] {
    return [
      { label: this.toast.text('admin.users.editRoles'), icon: 'badge', run: () => this.editRoles(member) },
      member.status === 'active'
        ? { label: this.toast.text('admin.users.suspend'), icon: 'pause_circle', run: () => void this.act(member, 'suspend') }
        : { label: this.toast.text('admin.users.reactivate'), icon: 'play_circle', run: () => void this.act(member, 'reactivate') },
      { separator: true },
      { label: this.toast.text('admin.users.removeMember'), icon: 'person_remove', danger: true, run: () => void this.removeMember(member) },
    ];
  }

  protected readonly invitations = signal<Invitation[]>([]);
  protected readonly pending = computed(() => this.invitations().filter((invitation) => invitation.status === 'pending' || invitation.status === 'expired'));
  protected readonly invitationsLoading = signal(false);
  protected readonly invitationsError = signal<unknown>(null);
  protected readonly invitationColumns: Column<Invitation>[] = [
    { key: 'who', header: 'fields.email' },
    { key: 'role', header: 'fields.role' },
    { key: 'expires', header: 'admin.users.expiresAt', hideBelow: 640 },
    { key: 'status', header: 'fields.status' },
    { key: 'actions', header: 'common.actions' },
  ];

  protected readonly payload = signal<RolesPayload | null>(null);
  protected readonly rolesError = signal<unknown>(null);
  protected readonly selectedName = signal<string | null>(null);
  protected readonly creating = signal(false);
  protected readonly draft = signal<Set<string>>(new Set());
  protected readonly draftName = signal('');
  protected readonly draftLabel = signal('');
  protected readonly selected = computed(() => this.payload()?.roles.find((role) => role.name === this.selectedName()) ?? null);
  protected readonly editable = computed(() => this.canRoles && (this.creating() || this.selected()?.is_system === false));
  protected readonly nameValid = computed(() => /^[a-z][a-z0-9_]*$/.test(this.draftName()));
  protected readonly modules = computed(() => {
    const groups = new Map<string, Permission[]>();
    for (const permission of this.payload()?.permissions ?? []) {
      groups.set(permission.module, [...(groups.get(permission.module) ?? []), permission]);
    }

    return [...groups.entries()].map(([module, permissions]) => ({ module, permissions }));
  });

  /** Areas whose single permissions are shown. */
  protected readonly opened = signal<Set<string>>(new Set());

  /** "Voir" is every permission of the area that only reads (…view); "Gérer" is all of them. */
  private static reads(permission: Permission): boolean {
    return permission.name.endsWith('.view');
  }

  protected level(group: { permissions: Permission[] }): 'none' | 'view' | 'manage' | 'custom' {
    const held = group.permissions.filter((permission) => this.draft().has(permission.name));
    if (held.length === 0) {
      return 'none';
    }
    if (held.length === group.permissions.length) {
      return 'manage';
    }
    const reads = group.permissions.filter(UsersPage.reads);
    return reads.length > 0 && held.length === reads.length && held.every(UsersPage.reads) ? 'view' : 'custom';
  }

  protected countIn(group: { permissions: Permission[] }): number {
    return group.permissions.filter((permission) => this.draft().has(permission.name)).length;
  }

  protected levelOptions(group: { permissions: Permission[] }, t: (key: string) => string): { value: string; label: string }[] {
    const reads = group.permissions.filter(UsersPage.reads).length;
    const options = [{ value: 'none', label: t('admin.users.levels.none') }];
    // "Voir" only where it differs from both ends: an area that has reads and other permissions.
    if (reads > 0 && reads < group.permissions.length) {
      options.push({ value: 'view', label: t('admin.users.levels.view') });
    }
    options.push({ value: 'manage', label: t(reads === group.permissions.length ? 'admin.users.levels.view' : 'admin.users.levels.manage') });
    if (this.level(group) === 'custom') {
      options.push({ value: 'custom', label: t('admin.users.levels.custom') });
    }

    return options;
  }

  protected setLevel(group: { module: string; permissions: Permission[] }, level: string | null): void {
    if (level === 'custom' || level === null) {
      this.toggleOpen(group.module, true);
      return;
    }
    this.draft.update((current) => {
      const next = new Set(current);
      for (const permission of group.permissions) {
        if (level === 'manage' || (level === 'view' && UsersPage.reads(permission))) {
          next.add(permission.name);
        } else {
          next.delete(permission.name);
        }
      }

      return next;
    });
  }

  protected toggleOpen(module: string, open?: boolean): void {
    this.opened.update((current) => {
      const next = new Set(current);
      if (open ?? !next.has(module)) {
        next.add(module);
      } else {
        next.delete(module);
      }

      return next;
    });
  }

  constructor() {
    this.loadRoles();
    if (this.canInvite || this.session.can('users.view')) {
      this.loadInvitations();
    }
  }

  protected roleLabel(name: string): RoleDefinition['label'] {
    return this.payload()?.roles.find((role) => role.name === name)?.label ?? null;
  }

  protected loadRoles(): void {
    this.rolesError.set(null);
    this.api.get<RolesPayload>('roles').subscribe({
      next: (payload) => {
        this.payload.set(payload);
        const current = payload.roles.find((role) => role.name === this.selectedName()) ?? payload.roles[0];
        if (current && !this.creating()) {
          this.select(current);
        }
        // Arrived from the start-up guide: the invitation opens at once.
        if (this.inviteAsked && this.canInvite) {
          this.inviteAsked = false;
          void this.invite();
        }
      },
      error: (error: unknown) => this.rolesError.set(error),
    });
  }

  protected loadInvitations(): void {
    this.invitationsLoading.set(true);
    this.invitationsError.set(null);
    this.api.get<Invitation[]>('invitations').subscribe({
      next: (rows) => {
        this.invitations.set(rows);
        this.invitationsLoading.set(false);
      },
      error: (error: unknown) => {
        this.invitationsError.set(error);
        this.invitationsLoading.set(false);
      },
    });
  }

  protected async invite(): Promise<void> {
    const sent = await firstValueFrom(this.dialog.open(InviteDialog, { data: { roles: this.payload()?.roles ?? [], sites: this.session.sites() } }).afterClosed());
    if (sent) {
      this.loadInvitations();
    }
  }

  protected async editRoles(member: Member): Promise<void> {
    const data: RolesDialogData = { member, roles: this.payload()?.roles ?? [], sites: this.session.sites() };
    if (await firstValueFrom(this.dialog.open(MemberRolesDialog, { data }).afterClosed())) {
      this.members.reload();
    }
  }

  protected async act(member: Member, action: 'suspend' | 'reactivate'): Promise<void> {
    await this.run(() => firstValueFrom(this.api.post(`users/${member.ulid}/${action}`)), 'common.saved', () => this.members.reload());
  }

  protected async removeMember(member: Member): Promise<void> {
    const name = `${member.first_name} ${member.last_name}`;
    if (await this.confirm.ask({ title: this.toast.text('admin.users.removeConfirm', { name }), message: this.toast.text('admin.users.removeHint'), danger: true })) {
      await this.run(() => firstValueFrom(this.api.delete(`users/${member.ulid}`)), 'common.saved', () => this.members.reload());
    }
  }

  protected async resend(invitation: Invitation): Promise<void> {
    await this.run(() => firstValueFrom(this.api.post(`invitations/${invitation.ulid}/resend`)), 'admin.users.invited', () => this.loadInvitations());
  }

  protected async revoke(invitation: Invitation): Promise<void> {
    if (await this.confirm.ask({ title: `${this.toast.text('admin.users.revoke')} — ${invitation.email}`, danger: true })) {
      await this.run(() => firstValueFrom(this.api.delete(`invitations/${invitation.ulid}`)), 'common.saved', () => this.loadInvitations());
    }
  }

  protected select(role: RoleDefinition): void {
    this.creating.set(false);
    this.selectedName.set(role.name);
    this.draft.set(new Set(role.permissions));
    this.draftLabel.set(role.label?.['fr'] ?? role.name);
  }

  protected nameRole(label: string): void {
    this.draftLabel.set(label);
    const slug = label.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 50);
    this.draftName.set(slug === '' ? '' : /^[a-z]/.test(slug) ? slug : `role_${slug}`);
  }

  protected startCreate(): void {
    this.creating.set(true);
    this.selectedName.set(null);
    this.draft.set(new Set());
    this.draftName.set('');
    this.draftLabel.set('');
  }

  protected toggle(permission: string, checked: boolean): void {
    this.draft.update((current) => {
      const next = new Set(current);
      if (checked) {
        next.add(permission);
      } else {
        next.delete(permission);
      }

      return next;
    });
  }

  protected async saveRole(): Promise<void> {
    const body = { label: this.draftLabel().trim(), permissions: [...this.draft()] };
    const creating = this.creating();
    await this.run(
      () => firstValueFrom(creating ? this.api.post<RoleDefinition>('roles', { ...body, name: this.draftName() }) : this.api.put<RoleDefinition>(`roles/${this.selectedName()}`, body)),
      'admin.users.roleSaved',
      () => {
        if (creating) {
          this.selectedName.set(this.draftName());
          this.creating.set(false);
        }
        this.loadRoles();
      },
    );
  }

  protected async deleteRole(): Promise<void> {
    const role = this.selected();
    if (role && (await this.confirm.ask({ title: `${this.toast.text('common.delete')} — ${role.label?.['fr'] ?? role.name}`, danger: true }))) {
      await this.run(() => firstValueFrom(this.api.delete(`roles/${role.name}`)), 'admin.users.roleDeleted', () => {
        this.selectedName.set(null);
        this.loadRoles();
      });
    }
  }

  private async run(action: () => Promise<unknown>, done: string, then: () => void): Promise<void> {
    this.busy.set(true);
    try {
      await action();
      this.toast.success(done);
      then();
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
