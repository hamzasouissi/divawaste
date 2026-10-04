import { ChangeDetectionStrategy, Component, Injectable, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialog, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';

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

@Component({
  imports: [FormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatInputModule, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h2 mat-dialog-title>{{ data.title }}</h2>
      <mat-dialog-content>
        @if (data.message) {
          <p class="dw-muted">{{ data.message }}</p>
        }
        @if (data.reasonLabel) {
          <mat-form-field appearance="outline">
            <mat-label>{{ data.reasonLabel }}</mat-label>
            <textarea matInput rows="3" maxlength="1000" required cdkFocusInitial [ngModel]="text()" (ngModelChange)="text.set($event)"></textarea>
          </mat-form-field>
        }
        @if (data.password) {
          <mat-form-field appearance="outline">
            <mat-label>{{ t('auth.password') }}</mat-label>
            <input matInput type="password" autocomplete="current-password" required cdkFocusInitial [ngModel]="text()" (ngModelChange)="text.set($event)" (keydown.enter)="confirm()" />
          </mat-form-field>
        }
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ t('common.cancel') }}</button>
        <button mat-flat-button type="button" [class.danger]="data.danger" [disabled]="needsText && text().trim() === ''" (click)="confirm()">
          {{ data.confirmLabel || t('common.confirm') }}
        </button>
      </mat-dialog-actions>
    </ng-container>
  `,
  styles: `
    mat-dialog-content { display: grid; gap: var(--dw-3); min-width: min(440px, 80vw); }
    .danger { --mdc-filled-button-container-color: var(--dw-danger); }
  `,
})
export class ConfirmDialogComponent {
  protected readonly data = inject<ConfirmOptions>(MAT_DIALOG_DATA);
  private readonly ref = inject<MatDialogRef<ConfirmDialogComponent, string | true>>(MatDialogRef);

  protected readonly text = signal('');
  protected readonly needsText = Boolean(this.data.reasonLabel || this.data.password);

  protected confirm(): void {
    if (!this.needsText) {
      this.ref.close(true);
    } else if (this.text().trim() !== '') {
      this.ref.close(this.data.password ? this.text() : this.text().trim());
    }
  }
}

/**
 * Confirmation before anything that cannot be undone or that commits the company towards a third party.
 * Resolves to `null` when the user backs out, `true` or the typed text otherwise.
 */
@Injectable({ providedIn: 'root' })
export class Confirm {
  private readonly dialog = inject(MatDialog);

  async ask(options: ConfirmOptions): Promise<string | true | null> {
    const result = await firstValueFrom(this.dialog.open<ConfirmDialogComponent, ConfirmOptions, string | true>(ConfirmDialogComponent, { data: options, autoFocus: 'first-tabbable' }).afterClosed());

    return result ?? null;
  }
}
