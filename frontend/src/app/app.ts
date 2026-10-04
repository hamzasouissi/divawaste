import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { ApplicationShellComponent } from './widgets/application-shell';
import { SessionStore } from './entities/session';
import { LocaleService } from './shared/i18n/locale.service';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, ApplicationShellComponent],
  templateUrl: './app.html',
  styleUrl: './app.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class App {
  protected readonly session = inject(SessionStore);
  protected readonly locale = inject(LocaleService);

  constructor() {
    this.locale.set(this.locale.locale());
    void this.session.bootstrap();
  }
}
