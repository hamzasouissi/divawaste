import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { AutoFocus } from 'primeng/autofocus';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';

import { Api } from '../../core/api/api';
import { Manifest } from '../../core/api/models';
import { Toast } from '../../core/feedback/toast';
import { DateComponent } from '../../ui/date';
import { DIALOG_PARTS, DialogRef, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';

const today = (): string => new Date().toISOString().slice(0, 10);

/**
 * Signature of a tracking slip by the party whose turn it is: electronically (the user's password), or recorded
 * from the paper — the party signed by hand on the printed slip, and who signed and when is typed in. A partner
 * without an account can only sign on paper: the choice is then not offered.
 */
@Component({
  imports: [FormsModule, TranslocoDirective, ButtonDirective, InputText, AutoFocus, ...FIELD, ...DIALOG_PARTS, DateComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('compliance.manifests.signTitle', { number: manifest.manifest_number }) }}</dw-dialog-title>
      <div class="dw-dialog-body">
        <p class="who"><strong>{{ t('compliance.manifests.' + manifest.awaiting_party) }}</strong> — {{ partyName }}</p>
        @if (manifest.awaiting_external) {
          <p class="dw-muted">{{ t('compliance.manifests.externalHint') }}</p>
        } @else {
          <div class="methods" role="radiogroup" [attr.aria-label]="t('compliance.manifests.method')">
            @for (option of methods; track option) {
              <button type="button" role="radio" class="method" [class.on]="method() === option" [attr.aria-checked]="method() === option" (click)="method.set(option)">
                <strong>{{ t('compliance.manifests.methods.' + option) }}</strong><span class="dw-faint">{{ t('compliance.manifests.methodHints.' + option) }}</span>
              </button>
            }
          </div>
        }
        @if (method() === 'paper') {
          <dw-field [label]="t('compliance.manifests.signerName')"><input pInputText maxlength="150" [ngModel]="signerName()" (ngModelChange)="signerName.set($event)" required /></dw-field>
          <dw-field [label]="t('compliance.manifests.signedOn')"><dw-date [ngModel]="signedOn()" (ngModelChange)="signedOn.set($event)" [max]="max" required /></dw-field>
        }
        <dw-field [label]="t('auth.password')">
          <input pInputText type="password" autocomplete="current-password" [pAutoFocus]="true" [ngModel]="password()" (ngModelChange)="password.set($event)" (keydown.enter)="submit()" />
          <small dwHint>{{ t(method() === 'paper' ? 'compliance.manifests.recordMessage' : 'compliance.manifests.signMessage') }}</small>
        </dw-field>
      </div>
      <div class="dw-dialog-foot">
        <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
        <button pButton type="button" [disabled]="busy() || !ready()" (click)="submit()">{{ t(method() === 'paper' ? 'compliance.manifests.record' : 'compliance.manifests.sign') }}</button>
      </div>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { display: grid; gap: var(--dw-3); inline-size: min(480px, 86vw); }
    .who, .dw-muted { margin: 0; }
    .methods { display: grid; grid-template-columns: 1fr 1fr; gap: var(--dw-2); }
    .method { all: unset; cursor: pointer; display: grid; gap: 2px; padding: 10px 12px; border: 1px solid var(--dw-line); border-radius: var(--dw-radius); font-size: 13px; }
    .method.on { border-color: var(--dw-primary); background: var(--dw-primary-soft); } .method:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: 2px; }
  `,
})
export class SignManifestDialog {
  protected readonly manifest = dialogData<Manifest>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly methods = ['electronic', 'paper'] as const;
  protected readonly max = today();
  protected readonly partyName = this.manifest.signatures.find((signature) => signature.party === this.manifest.awaiting_party)?.company.legal_name ?? '';
  protected readonly method = signal<'electronic' | 'paper'>(this.manifest.awaiting_external ? 'paper' : 'electronic');
  protected readonly password = signal('');
  protected readonly signerName = signal('');
  protected readonly signedOn = signal(today());
  protected readonly busy = signal(false);

  protected ready(): boolean {
    return this.password() !== '' && (this.method() === 'electronic' || (this.signerName().trim() !== '' && this.signedOn() !== '' && this.signedOn() <= this.max));
  }

  protected async submit(): Promise<void> {
    if (!this.ready()) {
      return;
    }
    this.busy.set(true);
    const paper = this.method() === 'paper' ? { method: 'paper', signer_name: this.signerName().trim(), signed_on: this.signedOn() } : {};
    try {
      this.ref.close(await firstValueFrom(this.api.post<Manifest>(`manifests/${this.manifest.ulid}/sign`, { password: this.password(), ...paper })));
      this.toast.success(this.method() === 'paper' ? 'compliance.manifests.recorded' : 'compliance.manifests.signed');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

/** The scan of the slip signed by hand: attaching it closes the slip, and the scan becomes the archived document. */
@Component({
  imports: [TranslocoDirective, ButtonDirective, IconComponent, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('compliance.manifests.copyTitle', { number: manifest.manifest_number }) }}</dw-dialog-title>
      <div class="dw-dialog-body">
        <p class="dw-muted">{{ t('compliance.manifests.copyHint') }}</p>
        <label class="file">
          <input type="file" accept="application/pdf,image/jpeg,image/png" (change)="pick($event)" />
          <dw-icon aria-hidden="true">upload_file</dw-icon><span>{{ file()?.name || t('compliance.manifests.copyFile') }}</span>
        </label>
      </div>
      <div class="dw-dialog-foot">
        <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
        <button pButton type="button" [disabled]="busy() || !file()" (click)="submit()">{{ t('compliance.manifests.attachCopy') }}</button>
      </div>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { display: grid; gap: var(--dw-3); inline-size: min(440px, 86vw); } .dw-muted { margin: 0; }
    .file { display: flex; align-items: center; gap: 8px; padding: 12px; border: 1px dashed var(--dw-line); border-radius: var(--dw-radius); cursor: pointer; font-size: 13px; }
    .file:focus-within { outline: 2px solid var(--dw-primary); outline-offset: 2px; } .file input { position: absolute; opacity: 0; width: 1px; height: 1px; }
  `,
})
export class SignedCopyDialog {
  protected readonly manifest = dialogData<Manifest>();
  private readonly http = inject(HttpClient);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly file = signal<File | null>(null);
  protected readonly busy = signal(false);

  protected pick(event: Event): void {
    this.file.set((event.target as HTMLInputElement).files?.[0] ?? null);
  }

  protected async submit(): Promise<void> {
    const file = this.file();
    if (!file) {
      return;
    }
    const body = new FormData();
    body.append('file', file);
    this.busy.set(true);
    try {
      await firstValueFrom(this.http.post(`/api/v1/manifests/${this.manifest.ulid}/signed-copy`, body));
      this.toast.success('compliance.manifests.copyAttached');
      this.ref.close(true);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
