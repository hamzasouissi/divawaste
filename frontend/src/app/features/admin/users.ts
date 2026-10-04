import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MAT_DIALOG_DATA, MatDialog, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatMenuModule } from '@angular/material/menu';
import { MatSelectModule } from '@angular/material/select';
import { MatTabsModule } from '@angular/material/tabs';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';

import { Api } from '../../core/api/api';
import { AccessSite, Invitation, Member, RoleDefinition } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, LocalizedPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { StateComponent } from '../../ui/state';

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
  imports: [FormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatIconModule, MatSelectModule, TranslocoDirective, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ t('admin.users.editRoles') }} — {{ data.member.first_name }} {{ data.member.last_name }}</h2>
      <mat-dialog-content>
        @for (row of rows(); track $index; let index = $index) {
          <div class="row">
            <mat-form-field>
              <mat-label>{{ t('fields.role') }}</mat-label>
              <mat-select [ngModel]="row.role" (ngModelChange)="patch(index, { role: $event })">
                @for (role of data.roles; track role.name) { <mat-option [value]="role.name">{{ role.label | dwLocalized: role.name }}</mat-option> }
              </mat-select>
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ t('fields.site') }}</mat-label>
              <mat-select [ngModel]="row.site" (ngModelChange)="patch(index, { site: $event })">
                <mat-option value="">{{ t('admin.users.allSites') }}</mat-option>
                @for (site of data.sites; track site.ulid) { <mat-option [value]="site.ulid">{{ site.name }}</mat-option> }
              </mat-select>
            </mat-form-field>
            <button mat-icon-button type="button" (click)="remove(index)" [attr.aria-label]="t('common.remove')"><mat-icon>delete</mat-icon></button>
          </div>
        }
        <button mat-button type="button" (click)="add()"><mat-icon>add</mat-icon>{{ t('admin.users.addAssignment') }}</button>
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ t('common.cancel') }}</button>
        <button mat-flat-button type="button" [disabled]="busy() || !valid()" (click)="save()">{{ t('common.save') }}</button>
      </mat-dialog-actions>
    </ng-container>
  `,
  styles: `
    mat-dialog-content { min-width: min(560px, 80vw); }
    .row { display: grid; grid-template-columns: 1fr 1fr auto; gap: var(--dw-2); align-items: start; }
  `,
})
export class MemberRolesDialog {
  protected readonly data = inject<RolesDialogData>(MAT_DIALOG_DATA);
  private readonly ref = inject(MatDialogRef<MemberRolesDialog, boolean>);
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
  imports: [ReactiveFormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoDirective, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ t('admin.users.invite') }}</h2>
      <form [formGroup]="form" (ngSubmit)="send()">
        <mat-dialog-content>
          <p class="dw-muted">{{ t('admin.users.inviteHint') }}</p>
          <div class="dw-form">
            <mat-form-field class="dw-span-2">
              <mat-label>{{ t('fields.email') }}</mat-label>
              <input matInput type="email" formControlName="email" autocomplete="off" cdkFocusInitial />
              @if (form.controls.email.errors?.['server']; as message) { <mat-error>{{ message }}</mat-error> }
            </mat-form-field>
            <mat-form-field><mat-label>{{ t('fields.firstName') }}</mat-label><input matInput formControlName="first_name" /></mat-form-field>
            <mat-form-field><mat-label>{{ t('fields.lastName') }}</mat-label><input matInput formControlName="last_name" /></mat-form-field>
            <mat-form-field>
              <mat-label>{{ t('fields.role') }}</mat-label>
              <mat-select formControlName="role">
                @for (role of data.roles; track role.name) { <mat-option [value]="role.name">{{ role.label | dwLocalized: role.name }}</mat-option> }
              </mat-select>
            </mat-form-field>
            @if (data.sites.length > 0) {
              <mat-form-field>
                <mat-label>{{ t('admin.users.sites') }}</mat-label>
                <mat-select formControlName="sites" multiple [placeholder]="t('admin.users.allSites')">
                  @for (site of data.sites; track site.ulid) { <mat-option [value]="site.ulid">{{ site.name }}</mat-option> }
                </mat-select>
                <mat-hint>{{ t('admin.users.allSites') }} — {{ t('common.optional') }}</mat-hint>
              </mat-form-field>
            }
            <mat-form-field class="dw-span-2">
              <mat-label>{{ t('admin.users.message') }} ({{ t('common.optional') }})</mat-label>
              <textarea matInput rows="3" maxlength="1000" formControlName="message"></textarea>
            </mat-form-field>
          </div>
        </mat-dialog-content>
        <mat-dialog-actions align="end">
          <button mat-button type="button" mat-dialog-close>{{ t('common.cancel') }}</button>
          <button mat-flat-button type="submit" [disabled]="busy()">{{ t('admin.users.invite') }}</button>
        </mat-dialog-actions>
      </form>
    </ng-container>
  `,
  styles: 'mat-dialog-content { min-width: min(560px, 80vw); }',
})
export class InviteDialog {
  protected readonly data = inject<{ roles: RoleDefinition[]; sites: AccessSite[] }>(MAT_DIALOG_DATA);
  private readonly ref = inject(MatDialogRef<InviteDialog, boolean>);
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
  imports: [FormsModule, MatButtonModule, MatCheckboxModule, MatFormFieldModule, MatIconModule, MatInputModule, MatMenuModule, MatSelectModule, MatTabsModule, TranslocoDirective, ...TABLE,
    PageHeaderComponent, StatusComponent, SearchComponent, StateComponent, DatePipe, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('admin.users.title')" [subtitle]="t('admin.users.subtitle')">
        @if (canInvite) { <button mat-flat-button type="button" (click)="invite()"><mat-icon>person_add</mat-icon>{{ t('admin.users.invite') }}</button> }
      </dw-page-header>

      <div class="dw-panel">
        <mat-tab-group animationDuration="0" (selectedIndexChange)="$event === 1 && loadInvitations()">
          <mat-tab [label]="t('admin.users.members')">
            <div class="filters"><dw-search [placeholder]="t('common.search')" (search)="members.setFilter({ search: $event })" /></div>
            <dw-data-table [columns]="memberColumns" [rows]="members.rows()" [loading]="members.loading()" [error]="members.error()" [total]="members.total()"
              [pageIndex]="members.page() - 1" [pageSize]="members.perPage()" emptyIcon="group" [emptyTitle]="t('common.noResult')"
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
                  <button mat-icon-button type="button" [matMenuTriggerFor]="menu" [attr.aria-label]="t('common.actions') + ' — ' + m.first_name + ' ' + m.last_name"><mat-icon>more_vert</mat-icon></button>
                  <mat-menu #menu="matMenu">
                    <button mat-menu-item type="button" (click)="editRoles(m)"><mat-icon>badge</mat-icon>{{ t('admin.users.editRoles') }}</button>
                    @if (m.status === 'active') {
                      <button mat-menu-item type="button" (click)="act(m, 'suspend')"><mat-icon>pause_circle</mat-icon>{{ t('admin.users.suspend') }}</button>
                    } @else {
                      <button mat-menu-item type="button" (click)="act(m, 'reactivate')"><mat-icon>play_circle</mat-icon>{{ t('admin.users.reactivate') }}</button>
                    }
                    <button mat-menu-item type="button" (click)="removeMember(m)"><mat-icon>person_remove</mat-icon>{{ t('admin.users.removeMember') }}</button>
                  </mat-menu>
                }
              </ng-template>
            </dw-data-table>
          </mat-tab>

          <mat-tab [label]="t('admin.users.invitations')">
            <dw-data-table [columns]="invitationColumns" [rows]="invitations()" [loading]="invitationsLoading()" [error]="invitationsError()" emptyIcon="mail"
              [emptyTitle]="t('common.empty')" (retry)="loadInvitations()">
              <ng-template dwCell="who" let-i><strong>{{ i.email }}</strong><div class="dw-faint">{{ i.first_name }} {{ i.last_name }}</div></ng-template>
              <ng-template dwCell="role" let-i>{{ roleLabel(i.role) | dwLocalized: i.role }}</ng-template>
              <ng-template dwCell="expires" let-i>{{ i.expires_at | dwDate: 'datetime' }}<div class="dw-faint">{{ t('admin.users.sent', { count: i.send_count }) }}</div></ng-template>
              <ng-template dwCell="status" let-i><dw-status kind="invitation" [value]="i.status" /></ng-template>
              <ng-template dwCell="actions" let-i>
                @if (canInvite && i.status !== 'accepted') {
                  <div class="dw-row-tight">
                    <button mat-stroked-button type="button" [disabled]="busy()" (click)="resend(i)">{{ t('admin.users.resend') }}</button>
                    <button mat-button type="button" [disabled]="busy()" (click)="revoke(i)">{{ t('admin.users.revoke') }}</button>
                  </div>
                }
              </ng-template>
            </dw-data-table>
          </mat-tab>

          <mat-tab [label]="t('admin.users.roles')">
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
                    @if (canRoles) { <button type="button" class="new" [class.on]="creating()" (click)="startCreate()"><mat-icon>add</mat-icon>{{ t('admin.users.newRole') }}</button> }
                  </nav>

                  <div class="role-detail">
                    @if (creating()) {
                      <div class="dw-form">
                        <mat-form-field><mat-label>{{ t('admin.users.roleName') }}</mat-label><input matInput [ngModel]="draftName()" (ngModelChange)="draftName.set($event)" pattern="[a-z][a-z0-9_]*" maxlength="60" /></mat-form-field>
                        <mat-form-field><mat-label>{{ t('admin.users.roleLabel') }}</mat-label><input matInput [ngModel]="draftLabel()" (ngModelChange)="draftLabel.set($event)" maxlength="100" /></mat-form-field>
                      </div>
                    } @else if (selected(); as role) {
                      @if (role.is_system) {
                        <p class="dw-muted">{{ t('admin.users.systemHint') }}</p>
                      } @else if (canRoles) {
                        <mat-form-field class="label"><mat-label>{{ t('admin.users.roleLabel') }}</mat-label><input matInput [ngModel]="draftLabel()" (ngModelChange)="draftLabel.set($event)" maxlength="100" /></mat-form-field>
                      }
                    }

                    <h3 class="dw-label">{{ t('admin.users.permissions') }}</h3>
                    <div class="modules">
                      @for (group of modules(); track group.module) {
                        <fieldset>
                          <legend>{{ group.module }}</legend>
                          @for (permission of group.permissions; track permission.name) {
                            <mat-checkbox [checked]="draft().has(permission.name)" [disabled]="!editable()" (change)="toggle(permission.name, $event.checked)">
                              <span class="dw-mono">{{ permission.name }}</span>
                            </mat-checkbox>
                          }
                        </fieldset>
                      }
                    </div>

                    @if (editable()) {
                      <div class="dw-form-actions">
                        @if (!creating()) { <button mat-button type="button" [disabled]="busy()" (click)="deleteRole()">{{ t('common.delete') }}</button> }
                        <button mat-flat-button type="button" [disabled]="busy() || draftLabel().trim() === '' || (creating() && !nameValid())" (click)="saveRole()">{{ t('common.save') }}</button>
                      </div>
                    }
                  </div>
                </div>
              }
            </dw-state>
          </mat-tab>
        </mat-tab-group>
      </div>
    </ng-container>
  `,
  styles: `
    .filters { padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }
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
    .modules { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: var(--dw-3); }
    fieldset { border: 1px solid var(--dw-line-soft); border-radius: var(--dw-radius); padding: 4px 8px 8px; margin: 0; display: grid; }
    legend { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dw-ink-2); padding-inline: 4px; }
    @media (max-width: 900px) { .roles { grid-template-columns: minmax(0, 1fr); } .role-list { border-inline-end: 0; border-block-end: 1px solid var(--dw-line-soft); } }
  `,
})
export class UsersPage {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);
  private readonly dialog = inject(MatDialog);

  protected readonly canInvite = this.session.can('users.invite');
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

  protected readonly invitations = signal<Invitation[]>([]);
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

  constructor() {
    this.loadRoles();
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
