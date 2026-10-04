import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { ApiError, PaginatedEnvelope, PaginationMeta } from '../../api';
import { LocaleService } from '../../i18n/locale.service';
import { ResourceTableComponent, TableColumn } from '../resource-table';

@Component({
  selector: 'app-resource-collection',
  imports: [ResourceTableComponent],
  template: `
    <section class="page">
      <header class="page-header">
        <div><h1>{{ title() }}</h1><p>{{ description() }}</p></div>
        <div class="page-actions">
          <ng-content select="[collection-actions]" />
          <button class="button secondary" type="button" (click)="reload()" [disabled]="loading()">
            {{ locale.t('refresh') }}
          </button>
        </div>
      </header>
      @if (loading()) {
        <div class="card state" role="status">{{ locale.t('loading') }}</div>
      } @else if (error()) {
        <div class="card state error" role="alert">
          <p>{{ error() }}</p>
          <button class="button" type="button" (click)="reload()">{{ locale.t('retry') }}</button>
        </div>
      } @else if (rows().length === 0) {
        <div class="card state">{{ locale.t('noResults') }}</div>
      } @else {
        <app-resource-table [rows]="rows()" [columns]="columns()" />
        @if (pagination(); as page) {
          @if (page.last_page > 1) {
            <nav class="pagination" [attr.aria-label]="locale.t('pagination')">
              <button class="button secondary" type="button" [disabled]="loading() || page.current_page <= 1" (click)="reload(page.current_page - 1)">
                {{ locale.t('previous') }}
              </button>
              <span aria-live="polite">{{ pageSummary() }}</span>
              <button class="button secondary" type="button" [disabled]="loading() || page.current_page >= page.last_page" (click)="reload(page.current_page + 1)">
                {{ locale.t('next') }}
              </button>
            </nav>
          }
        }
      }
    </section>
  `,
  styles: `
    .page-actions { display: flex; align-items: center; gap: .6rem; }
    .pagination { display: flex; align-items: center; justify-content: center; gap: 1rem; margin-top: 1rem; }
    .pagination span { color: var(--text-muted); font-size: .9rem; }
    @media (max-width: 420px) { .pagination { gap: .5rem; } }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ResourceCollectionComponent<T extends Record<string,unknown>> implements OnInit {
  readonly title = input.required<string>();
  readonly description = input.required<string>();
  readonly columns = input.required<TableColumn<T>[]>();
  readonly load = input.required<(page: number) => Promise<PaginatedEnvelope<T> | { data: T[] }>>();
  readonly locale = inject(LocaleService);
  readonly rows = signal<T[]>([]);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly pagination = signal<PaginationMeta | null>(null);
  readonly pageSummary = computed(() => this.locale.t('pageOf')
    .replace('{page}', String(this.pagination()?.current_page ?? 1))
    .replace('{pages}', String(this.pagination()?.last_page ?? 1)));

  ngOnInit(): void {
    void this.reload(1);
  }

  async reload(page = this.pagination()?.current_page ?? 1): Promise<void> {
    this.loading.set(true);
    this.error.set(null);
    try {
      const response = await this.load()(page);
      this.rows.set(response.data);
      this.pagination.set('meta' in response ? response.meta : null);
    } catch (error) {
      this.error.set(error instanceof ApiError
        ? `Request failed (${error.status}${error.requestId ? ` · ${error.requestId}` : ''})`
        : 'Unable to load data.');
    } finally {
      this.loading.set(false);
    }
  }
}
