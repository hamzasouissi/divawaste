import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslocoDirective } from '@jsverse/transloco';

import { Toast } from '../core/feedback/toast';

/**
 * The three states every data region has besides its content: loading, failed, empty. Used the same way
 * everywhere so a user always knows whether to wait, retry or create something.
 *
 *   <dw-state [loading]="…" [error]="…" [empty]="…" emptyTitle="…" (retry)="…">content</dw-state>
 */
@Component({
  selector: 'dw-state',
  imports: [MatButtonModule, MatIconModule, MatProgressBarModule, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (loading()) {
        <mat-progress-bar mode="indeterminate" class="bar" [attr.aria-label]="t('common.loading')" />
      }
      @if (error()) {
        <div class="box" role="alert">
          <mat-icon class="icon danger" aria-hidden="true">error_outline</mat-icon>
          <p class="dw-h2">{{ t('common.loadFailed') }}</p>
          <p class="dw-muted">{{ message() }}</p>
          <button mat-stroked-button type="button" (click)="retry.emit()">
            <mat-icon>refresh</mat-icon>
            {{ t('common.retry') }}
          </button>
        </div>
      } @else if (empty() && !loading()) {
        <div class="box">
          <mat-icon class="icon" aria-hidden="true">{{ icon() }}</mat-icon>
          <p class="dw-h2">{{ emptyTitle() || t('common.empty') }}</p>
          @if (emptyHint()) {
            <p class="dw-muted">{{ emptyHint() }}</p>
          }
          <ng-content select="[dwEmptyAction]" />
        </div>
      } @else if (!loading() || keepContent()) {
        <ng-content />
      } @else {
        <div class="skeleton" aria-hidden="true"><span></span><span></span><span></span></div>
      }
    </ng-container>
  `,
  styles: `
    :host { display: block; position: relative; min-height: 4px; }
    .bar { position: absolute; inset-block-start: 0; inset-inline: 0; height: 2px; z-index: 1; }
    .box { display: flex; flex-direction: column; align-items: center; gap: var(--dw-2); padding: var(--dw-7) var(--dw-4); text-align: center; }
    .icon { width: 36px; height: 36px; font-size: 36px; color: var(--dw-ink-3); }
    .icon.danger { color: var(--dw-danger); }
    .skeleton { display: grid; gap: var(--dw-3); padding: var(--dw-4); }
    .skeleton span { height: 14px; border-radius: 4px; background: linear-gradient(90deg, var(--dw-line-soft), var(--dw-surface-2), var(--dw-line-soft)); background-size: 200% 100%; animation: shimmer 1.4s linear infinite; }
    .skeleton span:nth-child(2) { width: 82%; }
    .skeleton span:nth-child(3) { width: 64%; }
    @keyframes shimmer { to { background-position: -200% 0; } }
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
