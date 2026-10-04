import { Injectable, inject } from '@angular/core';
import { AbstractControl, FormGroup } from '@angular/forms';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoService } from '@jsverse/transloco';

import { ApiError } from '../api/api';

/**
 * One way to tell the user what happened. Success messages are short and disappear; errors stay until
 * dismissed and always say something actionable — a translated business message when the API code is known,
 * the request reference otherwise.
 */
@Injectable({ providedIn: 'root' })
export class Toast {
  private readonly snack = inject(MatSnackBar);
  private readonly transloco = inject(TranslocoService);

  success(key: string, params?: Record<string, unknown>): void {
    this.snack.open(this.transloco.translate(key, params), undefined, { duration: 3500, panelClass: 'dw-toast-success' });
  }

  info(key: string, params?: Record<string, unknown>): void {
    this.snack.open(this.transloco.translate(key, params), undefined, { duration: 4500 });
  }

  error(error: unknown): void {
    this.snack.open(this.describe(error), this.transloco.translate('common.close'), { duration: 12000, panelClass: 'dw-toast-error' });
  }

  /** Translated text, for the few places that build a sentence outside a template (dialog titles…). */
  text(key: string, params?: Record<string, unknown>): string {
    return this.transloco.translate(key, params);
  }

  /** Human text for any failure. */
  describe(error: unknown): string {
    if (!(error instanceof ApiError)) {
      return this.transloco.translate('errors.UNEXPECTED');
    }

    const key = `errors.${error.code}`;
    const known = this.transloco.translate(key);
    if (known !== key) {
      return known;
    }
    if (error.isValidation) {
      return Object.values(error.errors)[0]?.[0] ?? this.transloco.translate('errors.VALIDATION_FAILED');
    }

    // Business rules without a dedicated translation still carry a readable explanation from the API.
    return error.status === 409 && error.detail
      ? error.detail
      : this.transloco.translate('errors.GENERIC', { status: error.status, reference: error.requestId ?? '—' });
  }

  /**
   * Puts the field messages of a 422 on the form controls (`contact.email`, `lots.0.weight`…) and returns
   * true when it could place at least one; the caller shows a toast otherwise.
   */
  applyValidation(form: FormGroup, error: unknown): boolean {
    if (!(error instanceof ApiError) || !error.isValidation) {
      return false;
    }

    let placed = false;
    for (const [path, messages] of Object.entries(error.errors)) {
      const control: AbstractControl | null = form.get(path) ?? form.get(path.split('.').pop() ?? path);
      if (control) {
        control.setErrors({ server: messages[0] });
        control.markAsTouched();
        placed = true;
      }
    }

    return placed;
  }
}
