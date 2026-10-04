import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { ConfirmDialog } from 'primeng/confirmdialog';
import { Toast } from 'primeng/toast';

import { Locale } from './core/i18n/i18n';
import { IconComponent } from './ui/icon';

const ICONS: Record<string, string> = { success: 'check_circle', info: 'info', warn: 'warning_amber', error: 'error_outline' };

@Component({
  selector: 'dw-root',
  imports: [RouterOutlet, Toast, ConfirmDialog, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <router-outlet />
    <!-- One toast outlet and one confirmation dialog for the whole product (see Toast and Confirm). -->
    <p-toast position="bottom-right" styleClass="dw-toast">
      <ng-template #message let-message>
        <dw-icon class="dw-toast-icon">{{ icons[message.severity] ?? 'info' }}</dw-icon>
        <span class="dw-toast-text">{{ message.detail }}</span>
      </ng-template>
    </p-toast>
    <p-confirmdialog styleClass="dw-confirm" />
  `,
})
export class App {
  // Instantiated here so <html lang dir> follows the active language from the first render.
  protected readonly locale = inject(Locale);
  protected readonly icons = ICONS;
}
