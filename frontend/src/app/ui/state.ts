import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';
import { ProgressBar } from 'primeng/progressbar';
import { Skeleton } from 'primeng/skeleton';

import { Toast } from '../core/feedback/toast';
import { IconComponent } from './icon';

/**
 * The three states every data region has besides its content: loading, failed, empty. Used the same way
 * everywhere so a user always knows whether to wait, retry or create something.
 *
 *   <dw-state [loading]="…" [error]="…" [empty]="…" emptyTitle="…" (retry)="…">content</dw-state>
 */
@Component({
  selector: 'dw-state',
  imports: [ButtonDirective, ProgressBar, Skeleton, IconComponent, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (loading()) {
        <p-progressbar mode="indeterminate" class="bar" [attr.aria-label]="t('common.loading')" />
      }
      @if (error()) {
        <div class="box" role="alert">
          <span class="badge danger"><dw-icon>error_outline</dw-icon></span>
          <p class="title">{{ t('common.loadFailed') }}</p>
          <p class="hint">{{ message() }}</p>
          <button pButton type="button" severity="secondary" outlined size="small" class="action" (click)="retry.emit()">
            <dw-icon>refresh</dw-icon>
            {{ t('common.retry') }}
          </button>
        </div>
      } @else if (empty() && !loading()) {
        <div class="box">
          <span class="badge"><dw-icon>{{ icon() }}</dw-icon></span>
          <p class="title">{{ emptyTitle() || t('common.empty') }}</p>
          @if (emptyHint()) {
            <p class="hint">{{ emptyHint() }}</p>
          }
          <div class="action"><ng-content select="[dwEmptyAction]" /></div>
        </div>
      } @else if (!loading() || keepContent()) {
        <ng-content />
      } @else {
        <div class="skeleton" aria-hidden="true">
          <p-skeleton width="38%" height="12px" />
          <p-skeleton width="86%" height="12px" />
          <p-skeleton width="72%" height="12px" />
          <p-skeleton width="54%" height="12px" />
        </div>
      }
    </ng-container>
  `,
  styles: `
    :host { display: block; position: relative; min-height: 3px; }
    .bar { position: absolute; inset-block-start: 0; inset-inline: 0; z-index: 2; }
    .box { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 40px var(--dw-4); text-align: center; }
    .badge { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 10px; background: var(--dw-surface-2); border: 1px solid var(--dw-line-soft);
      color: var(--dw-ink-3); font-size: 20px; margin-block-end: 8px; }
    .badge.danger { background: var(--dw-danger-soft); border-color: transparent; color: var(--dw-danger); }
    .title { font-size: 14px; font-weight: 600; color: var(--dw-ink); }
    .hint { font-size: 13px; color: var(--dw-ink-2); max-width: 46ch; }
    .action { margin-block-start: 12px; }
    .action:empty { display: none; }
    .skeleton { display: grid; gap: 14px; padding: var(--dw-4); }
  `,
})
export class StateComponent {
  private readonly toast = inject(Toast);

  readonly loading = input(false);
  readonly error = input<unknown>(null);
  readonly empty = input(false);
  /** Keep showing the previous content while reloading (tables), instead of a skeleton. */
  readonly keepContent = input(false);
  readonly icon = input('inbox');
  readonly emptyTitle = input('');
  readonly emptyHint = input('');
  readonly retry = output<void>();

  protected readonly message = computed(() => this.toast.describe(this.error()));
}
