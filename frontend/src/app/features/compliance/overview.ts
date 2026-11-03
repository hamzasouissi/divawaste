import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';

import { Api } from '../../core/api/api';
import { AttentionComponent, AttentionItem } from '../../ui/attention';
import { PageHeaderComponent } from '../../ui/page';
import { StateComponent } from '../../ui/state';

/**
 * Compliance at a glance (CR-01): what is missing to be in order — slips to sign, signed copies, documents of
 * pickups that left, waste codes, last year's declarations, customs permits — each leading to the page that settles it.
 * The register, slips, documents, declarations and permits are the tabs of this section.
 */
@Component({
  imports: [TranslocoDirective, PageHeaderComponent, StateComponent, AttentionComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('compliance.overview.title')" [subtitle]="t('compliance.overview.subtitle')" />
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        <section class="dw-panel">
          <div class="dw-panel-head"><h2 class="dw-h2">{{ t('compliance.overview.missing') }}</h2>@if (items().length) { <span class="count">{{ items().length }}</span> }</div>
          <dw-attention [items]="items()" [emptyText]="t('compliance.overview.clear')" />
        </section>
      </dw-state>
    </ng-container>
  `,
  styles: `.count { display: inline-grid; place-items: center; min-width: 22px; height: 22px; padding-inline: 7px; border-radius: 11px; background: var(--dw-warning-soft); color: var(--dw-warning); font-size: 12px; font-weight: 650; }`,
})
export class ComplianceOverviewPage {
  private readonly api = inject(Api);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  private readonly all = signal<AttentionItem[]>([]);
  protected readonly items = computed(() => this.all().filter((item) => item.area === 'compliance'));

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get<AttentionItem[]>('attention').subscribe({
      next: (items) => {
        this.all.set(items);
        this.loading.set(false);
      },
      error: (error) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }
}
