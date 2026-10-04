import { ChangeDetectionStrategy, Component, Directive, Injectable, Type, inject } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';
import { DialogService, DynamicDialogConfig, DynamicDialogRef } from 'primeng/dynamicdialog';
import { Observable, take } from 'rxjs';

import { IconComponent } from './icon';

export { DynamicDialogRef as DialogRef } from 'primeng/dynamicdialog';

export interface DialogOptions<D> {
  data?: D;
  /** CSS width; by default the dialog takes the width its content asks for. */
  width?: string;
}

/**
 * Opens a component as a modal dialog (PrimeNG DynamicDialog). The component lays itself out with
 * `dw-dialog-title`, `.dw-dialog-body` and `.dw-dialog-foot`: compact header with the close button, body that
 * scrolls when long, footer with the cancel action then the primary one.
 */
@Injectable({ providedIn: 'root' })
export class Dialogs {
  private readonly service = inject(DialogService);

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  open<C, D = unknown, R = any>(component: Type<C>, options: DialogOptions<D> = {}): { afterClosed(): Observable<R | undefined> } {
    const ref = this.service.open(component, {
      data: options.data,
      width: options.width,
      showHeader: false,
      modal: true,
      closable: true,
      closeOnEscape: true,
      draggable: false,
      resizable: false,
      focusOnShow: true,
      styleClass: 'dw-dialog',
    }) as DynamicDialogRef;

    return { afterClosed: () => ref.onClose.pipe(take(1)) as Observable<R | undefined> };
  }
}

/** Data given to `Dialogs.open`, read from inside the dialog component. */
export function dialogData<D>(): D {
  return inject(DynamicDialogConfig).data as D;
}

/** Header of a dialog: title on one line, close button at the end. */
@Component({
  selector: 'dw-dialog-title',
  imports: [ButtonDirective, IconComponent, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2><ng-content /></h2>
    <button *transloco="let t" pButton type="button" text rounded severity="secondary" size="small" class="dw-icon-btn" (click)="ref.close()" [attr.aria-label]="t('common.close')">
      <dw-icon>close</dw-icon>
    </button>
  `,
  host: { class: 'dw-dialog-head' },
})
export class DialogTitleComponent {
  protected readonly ref = inject(DynamicDialogRef);
}

/** `<button dwDialogClose>`: closes the dialog without a result (cancel). */
@Directive({ selector: '[dwDialogClose]', host: { '(click)': 'ref.close()' } })
export class DialogCloseDirective {
  protected readonly ref = inject(DynamicDialogRef);
}

export const DIALOG_PARTS = [DialogTitleComponent, DialogCloseDirective] as const;
