import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { LocaleService, SupportedLocale } from '../../shared/i18n/locale.service';
import { SessionStore } from '../../entities/session';

@Component({
  selector: 'app-application-shell',
  imports: [RouterLink, RouterLinkActive],
  template: `
    <div class="shell">
      <aside class="sidebar" [attr.aria-label]="locale.t('applicationNavigation')">
        <div class="brand"><span>D</span><strong>Diva Waste</strong></div>
        <nav>
          <a routerLink="/sites" routerLinkActive="active">{{ locale.t('sites') }}</a>
          <a routerLink="/stock-thresholds" routerLinkActive="active">{{ locale.t('stockThresholds') }}</a>
          <a routerLink="/catalog/waste-types" routerLinkActive="active">{{ locale.t('catalog') }}</a>
          <a routerLink="/providers" routerLinkActive="active">{{ locale.t('providers') }}</a>
          <a routerLink="/documents" routerLinkActive="active">{{ locale.t('documents') }}</a>
          <a routerLink="/reports" routerLinkActive="active">{{ locale.t('reports') }}</a>
          <a routerLink="/notifications" routerLinkActive="active">{{ locale.t('notifications') }}</a>
        </nav>
        <label class="locale"><span>{{ locale.t('language') }}</span><select [value]="locale.locale()" (change)="changeLocale($any($event.target).value)"><option value="fr">Français</option><option value="en">English</option><option value="ar">العربية</option></select></label>
      </aside>
      <main class="main">
        @if (session.status() === 'loading') { <div class="app-state">{{ locale.t('loading') }}</div> }
        @else if (session.status() !== 'authenticated') { <section class="app-state"><h1>{{ locale.t('accessUnavailable') }}</h1><p>{{ locale.t('unavailable') }}</p><button class="button" type="button" (click)="session.retry()">{{ locale.t('retry') }}</button></section> }
        @else { <header class="topbar"><div><strong>{{ session.company()?.trade_name || session.company()?.legal_name }}</strong><small>{{ session.user()?.first_name }} {{ session.user()?.last_name }}</small></div></header><ng-content /> }
      </main>
    </div>
  `,
  styleUrl: './application-shell.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ApplicationShellComponent {
  readonly locale = inject(LocaleService);
  readonly session = inject(SessionStore);
  changeLocale(value: string): void { this.locale.set(value as SupportedLocale); }
}
