import { CUSTOM_ELEMENTS_SCHEMA, ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';

import { DivaBi } from '../../core/divabi/divabi';
import { IconComponent } from '../../ui/icon';
import { PageHeaderComponent } from '../../ui/page';
import { StateComponent } from '../../ui/state';

/**
 * "Vues personnalisées": tables built by the company itself on DivaBI, from any data it may read. The builder (for
 * custom_views.manage) and the list of views live in DivaBI's studio component; this page only hosts it.
 */
@Component({
  imports: [TranslocoDirective, PageHeaderComponent, StateComponent],
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t(tab() === 'builder' ? 'customViews.builderTitle' : 'customViews.title')" [subtitle]="t('customViews.subtitle')" />
      <dw-state [loading]="divabi.ready() === null" [error]="unavailable()" (retry)="start()">
        @if (divabi.ready()) {
          <divabi-studio class="studio" embedded view-route="/custom-views/{id}" [attr.tab]="tab()" [attr.edit]="view()"></divabi-studio>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    /* overflow: clip keeps the rounded frame without becoming a scroll container (the builder's bar stays sticky). */
    .studio { border: 1px solid var(--dw-line); border-radius: var(--dw-radius-lg, 12px); overflow: clip; min-width: 0; }
  `,
})
export class CustomViewsPage implements OnInit {
  protected readonly divabi = inject(DivaBi);
  /** From the route: 'views' or 'builder'. */
  readonly tab = input<string>('views');
  /** From the route custom-views/:view/edit: the saved view the builder opens. */
  readonly view = input<string | null>(null);
  private readonly failed = signal(false);
  protected readonly unavailable = computed(() => (this.failed() ? (this.divabi.error() ?? new Error('DivaBI')) : null));

  ngOnInit(): void {
    this.start();
  }

  start(): void {
    this.failed.set(false);
    void this.divabi.load().then((ok) => this.failed.set(!ok));
  }
}

/** One saved custom view, full page (also what a link from a menu or a notification opens). */
@Component({
  imports: [TranslocoDirective, PageHeaderComponent, StateComponent, ButtonDirective, IconComponent, RouterLink],
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('customViews.title')" back="/custom-views" [backLabel]="t('customViews.all')">
        @if (divabi.canBuild()) {
          <a pButton [routerLink]="['/custom-views', view(), 'edit']"><dw-icon>edit</dw-icon>{{ t('customViews.edit') }}</a>
        }
      </dw-page-header>
      <dw-state [loading]="divabi.ready() === null" [error]="unavailable()" (retry)="start()">
        @if (divabi.ready()) {
          <section class="dw-panel dw-panel-body"><divabi-view [attr.view]="view()"></divabi-view></section>
        }
      </dw-state>
    </ng-container>
  `,
})
export class CustomViewPage implements OnInit {
  protected readonly divabi = inject(DivaBi);
  readonly view = input.required<string>();
  private readonly failed = signal(false);
  protected readonly unavailable = computed(() => (this.failed() ? (this.divabi.error() ?? new Error('DivaBI')) : null));

  ngOnInit(): void {
    this.start();
  }

  start(): void {
    this.failed.set(false);
    void this.divabi.load().then((ok) => this.failed.set(!ok));
  }
}
