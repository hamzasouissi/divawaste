import { ChangeDetectionStrategy, Component, DestroyRef, Directive, ElementRef, afterNextRender, computed, contentChild, contentChildren, effect, inject, input, signal } from '@angular/core';
import { NgControl, Validators } from '@angular/forms';
import { TranslocoService } from '@jsverse/transloco';
import { Subscription } from 'rxjs';

let sequence = 0;

/** `<span dwLabel>…</span>`: label of a field when it needs markup; plain text goes in `[label]`. */
@Directive({ selector: '[dwLabel]' })
export class FieldLabelDirective {}

/** `<small dwHint>…</small>`: help text under the control, replaced by the error message when there is one. */
@Directive({ selector: '[dwHint]', host: { class: 'dw-field-hint' } })
export class FieldHintDirective {}

/** `<small dwError>…</small>`: a message written by the screen; it then replaces the automatic one. */
@Directive({ selector: '[dwError]', host: { class: 'dw-field-error', role: 'alert' } })
export class FieldErrorDirective {}

/**
 * One form field: label above, control, then help or error. It reads the state of the control it contains, so
 * every field of the product marks "required", turns red and explains what is wrong in the same way — including
 * the messages returned by the API (`server` error set by `Toast.applyValidation`).
 *
 *   <dw-field [label]="t('fields.email')"><input pInputText formControlName="email" /></dw-field>
 */
@Component({
  selector: 'dw-field',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <label class="dw-field-label" [id]="labelId" [attr.for]="controlId()">{{ label() }}<ng-content select="[dwLabel]" /></label>
    <div class="dw-field-control"><ng-content /></div>
    <ng-content select="[dwError]" />
    @if (message(); as text) {
      <small class="dw-field-error" role="alert">{{ text }}</small>
    } @else {
      <ng-content select="[dwHint]" />
    }
  `,
  host: { class: 'dw-field', '[class.invalid]': 'invalid()', '[class.is-required]': 'required()' },
})
export class FieldComponent {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly transloco = inject(TranslocoService);

  readonly label = input('');

  readonly labelId = `dw-field-${++sequence}`;
  protected readonly controlId = signal<string | null>(null);

  private readonly ngControl = contentChild(NgControl, { descendants: true });
  private readonly custom = contentChildren(FieldErrorDirective, { descendants: true });
  /** Bumped at every status or touched change of the control: forms are not signals. */
  private readonly tick = signal(0);

  protected readonly invalid = computed(() => {
    this.tick();
    const control = this.ngControl()?.control;

    return Boolean(control && control.invalid && control.touched);
  });

  protected readonly required = computed(() => {
    this.tick();

    return Boolean(this.ngControl()?.control?.hasValidator(Validators.required));
  });

  protected readonly message = computed(() => {
    if (!this.invalid() || this.custom().length > 0) {
      return '';
    }
    const errors = this.ngControl()?.control?.errors ?? {};
    if (typeof errors['server'] === 'string') {
      return errors['server'];
    }
    const [name, detail] = Object.entries(errors)[0] ?? ['invalid', null];
    const key = `validation.${name}`;
    const text = this.transloco.translate(key, typeof detail === 'object' && detail !== null ? detail : {});

    return text === key ? this.transloco.translate('validation.invalid') : text;
  });

  constructor() {
    let subscription: Subscription | null = null;
    effect(() => {
      const control = this.ngControl()?.control;
      subscription?.unsubscribe();
      subscription = control?.events.subscribe(() => this.tick.update((value) => value + 1)) ?? null;
      this.tick.update((value) => value + 1);
    });
    inject(DestroyRef).onDestroy(() => subscription?.unsubscribe());

    // Ties the label to the control so a click on the label focuses it and assistive technologies read it.
    afterNextRender(() => {
      const control = this.host.nativeElement.querySelector<HTMLElement>('.dw-field-control input:not([type=hidden]), .dw-field-control textarea');
      if (control) {
        control.id ||= `${this.labelId}-control`;
        this.controlId.set(control.id);
      }
    });
  }
}

export const FIELD = [FieldComponent, FieldLabelDirective, FieldHintDirective, FieldErrorDirective] as const;
