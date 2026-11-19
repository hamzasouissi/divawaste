import { CUSTOM_ELEMENTS_SCHEMA, ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';

import { DivaBi } from '../../core/divabi/divabi';
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
          <divabi-studio class="studio" [attr.tab]="tab()"></divabi-studio>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .studio { display: block; border: 1px solid var(--dw-line-soft); border-radius: var(--dw-radius-lg, 12px); overflow: hidden;
      --divabi-font: inherit; --divabi-primary: var(--dw-primary); --divabi-primary-soft: var(--dw-primary-soft); --divabi-text: var(--dw-ink);
      --divabi-muted: var(--dw-ink-3); --divabi-border: var(--dw-line-soft); --divabi-surface: var(--dw-surface); --divabi-soft: var(--dw-surface-2); }
  `,
})
export class CustomViewsPage implements OnInit {
  protected readonly divabi = inject(DivaBi);
  /** From the route: 'views' or 'builder'. */
  readonly tab = input<string>('views');
  private readonly failed = signal(false);
  protected readonly unavailable = computed(() => (this.failed() ? new Error('DivaBI') : null));

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
  imports: [TranslocoDirective, PageHeaderComponent, StateComponent],
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('customViews.title')" back="/custom-views" [backLabel]="t('customViews.all')" />
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
  protected readonly unavailable = computed(() => (this.failed() ? new Error('DivaBI') : null));

  ngOnInit(): void {
    this.start();
  }

  start(): void {
    this.failed.set(false);
    void this.divabi.load().then((ok) => this.failed.set(!ok));
  }
}
