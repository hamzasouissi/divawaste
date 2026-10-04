import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';

import { Locale } from './core/i18n/i18n';

@Component({
  selector: 'dw-root',
  imports: [RouterOutlet],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<router-outlet />',
})
export class App {
  // Instantiated here so <html lang dir> follows the active language from the first render.
  protected readonly locale = inject(Locale);
}
