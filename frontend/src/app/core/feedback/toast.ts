import { Injectable, inject } from '@angular/core';
import { AbstractControl, FormGroup } from '@angular/forms';
import { TranslocoService } from '@jsverse/transloco';
import { MessageService } from 'primeng/api';

import { ApiError } from '../api/api';
import { LimitText } from '../format/limit-text';

/**
 * One way to tell the user what happened (PrimeNG Toast, rendered by the application root). Success messages
 * are short and disappear; errors stay longer and always say something actionable — a translated business message when the API code is known,
 * the request reference otherwise.
 */
@Injectable({ providedIn: 'root' })
export class Toast {
  private readonly messages = inject(MessageService);
  private readonly transloco = inject(TranslocoService);
  private readonly limits = inject(LimitText);

  success(key: string, params?: Record<string, unknown>): void {
    this.messages.add({ severity: 'success', detail: this.transloco.translate(key, params), life: 3500 });
  }

  info(key: string, params?: Record<string, unknown>): void {
    this.messages.add({ severity: 'info', detail: this.transloco.translate(key, params), life: 4500 });
  }

  error(error: unknown): void {
    this.messages.add({ severity: 'error', detail: this.describe(error), life: 12000 });
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

    // A refused field says what is wrong ("Le mot de passe est incorrect") better than any generic sentence.
    if (error.isValidation) {
      return Object.values(error.errors)[0]?.[0] ?? this.transloco.translate('errors.VALIDATION_FAILED');
    }
    // An authorisation that refuses says which of its limits, with the figures the API answered with.
    const refusal = this.limits.refusal(error.context);
    if (refusal) {
      return refusal;
    }
    const key = `errors.${error.code}`;
    const known = this.transloco.translate(key);
    if (known !== key) {
      return known;
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
