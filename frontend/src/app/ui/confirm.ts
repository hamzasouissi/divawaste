import { ChangeDetectionStrategy, Component, Injectable, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { ConfirmationService } from 'primeng/api';
import { AutoFocus } from 'primeng/autofocus';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';
import { firstValueFrom } from 'rxjs';

import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from './dialog';
import { FieldComponent } from './field';

export interface ConfirmOptions {
  title: string;
  message?: string;
  confirmLabel?: string;
  danger?: boolean;
  /** Asks for a free-text reason (refusal, cancellation, suspension…) and returns it. */
  reasonLabel?: string;
  /** Asks for the user's password (electronic signature, removing two-factor). */
  password?: boolean;
}

/** Confirmation that needs something typed: a reason or the password. */
@Component({
  imports: [FormsModule, AutoFocus, ButtonDirective, InputText, Textarea, FieldComponent, ...DIALOG_PARTS, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ data.title }}</dw-dialog-title>
      <div class="dw-dialog-body">
        @if (data.message) {
          <p class="dw-muted">{{ data.message }}</p>
        }
        @if (data.reasonLabel) {
          <dw-field [label]="data.reasonLabel">
            <textarea pTextarea rows="3" maxlength="1000" required [pAutoFocus]="true" [ngModel]="text()" (ngModelChange)="text.set($event)"></textarea>
          </dw-field>
        }
        @if (data.password) {
          <dw-field [label]="t('auth.password')">
            <input pInputText type="password" autocomplete="current-password" required [pAutoFocus]="true" [ngModel]="text()" (ngModelChange)="text.set($event)" (keydown.enter)="confirm()" />
          </dw-field>
        }
      </div>
      <div class="dw-dialog-foot">
        <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
        <button pButton type="button" [severity]="data.danger ? 'danger' : null" [disabled]="text().trim() === ''" (click)="confirm()">
          {{ data.confirmLabel || t('common.confirm') }}
        </button>
      </div>
    </ng-container>
  `,
  styles: '.dw-dialog-body { display: grid; gap: var(--dw-3); width: min(440px, 86vw); }',
})
export class ConfirmDialogComponent {
  protected readonly data = dialogData<ConfirmOptions>();
  private readonly ref = inject(DialogRef);

  protected readonly text = signal('');

  protected confirm(): void {
    if (this.text().trim() !== '') {
      this.ref.close(this.data.password ? this.text() : this.text().trim());
    }
  }
}

/**
 * Confirmation before anything that cannot be undone or that commits the company towards a third party.
 * Resolves to `null` when the user backs out, `true` or the typed text otherwise. A plain question uses the
 * PrimeNG ConfirmDialog of the application root; a question that needs typing opens its own dialog.
 */
@Injectable({ providedIn: 'root' })
export class Confirm {
  private readonly dialogs = inject(Dialogs);
  private readonly confirmation = inject(ConfirmationService);
  private readonly transloco = inject(TranslocoService);

  async ask(options: ConfirmOptions): Promise<string | true | null> {
    if (options.reasonLabel || options.password) {
      const result = await firstValueFrom(this.dialogs.open<ConfirmDialogComponent, ConfirmOptions, string>(ConfirmDialogComponent, { data: options }).afterClosed());

      return result ?? null;
    }

    return new Promise((resolve) => {
      this.confirmation.confirm({
        header: options.title,
        message: options.message ?? '',
        acceptLabel: options.confirmLabel || this.transloco.translate('common.confirm'),
        rejectLabel: this.transloco.translate('common.cancel'),
        acceptButtonProps: { severity: options.danger ? 'danger' : undefined },
        rejectButtonProps: { severity: 'secondary', outlined: true },
        closeOnEscape: true,
        defaultFocus: options.danger ? 'reject' : 'accept',
        accept: () => resolve(true),
        reject: () => resolve(null),
      });
    });
  }
}
