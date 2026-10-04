import { DestroyRef, Signal, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Observable, Subject, catchError, debounceTime, of, startWith, switchMap, tap } from 'rxjs';

import { Page, Query } from '../core/api/api';

export interface ListState<F> {
  filter: F;
  sort: string | null;
  page: number;
  perPage: number;
}

/**
 * State of a server-driven list: filters, sort and page live here, the server does the work.
 * A new request cancels the previous one, so a slow answer can never overwrite a newer one.
 * Must be created in an injection context (component field initialiser).
 */
export class ListStore<T, F extends object = Record<string, never>, Extra = unknown> {
  private readonly trigger = new Subject<void>();
  private readonly state = signal<ListState<F>>({ filter: {} as F, sort: null, page: 1, perPage: 25 });

  readonly rows = signal<T[]>([]);
  readonly total = signal(0);
  readonly extra = signal<Extra | null>(null);
  readonly loading = signal(true);
  readonly error = signal<unknown>(null);

  readonly filter: Signal<F> = computed(() => this.state().filter);
  readonly sort = computed(() => this.state().sort);
  readonly page = computed(() => this.state().page);
  readonly perPage = computed(() => this.state().perPage);
  /** True when the list is empty because of the filters, not because nothing exists. */
  readonly activeCount = computed(() => Object.values(this.state().filter).filter((value) => value !== null && value !== undefined && value !== '' && !(Array.isArray(value) && value.length === 0)).length);
  readonly filtered = computed(() => this.activeCount() > 0);

  constructor(
    private readonly loader: (query: Query) => Observable<Page<T> & Extra>,
    initial: Partial<ListState<F>> = {},
  ) {
    this.state.update((state) => ({ ...state, ...initial }));

    this.trigger
      .pipe(
        startWith(undefined),
        debounceTime(0),
        tap(() => {
          this.loading.set(true);
          this.error.set(null);
        }),
        switchMap(() =>
          this.loader(this.query()).pipe(
            catchError((error: unknown) => {
              this.error.set(error);

              return of(null);
            }),
          ),
        ),
        takeUntilDestroyed(inject(DestroyRef)),
      )
      .subscribe((page) => {
        this.loading.set(false);
        if (page) {
          this.rows.set(page.data);
          this.total.set(page.meta?.total ?? page.data.length);
          this.extra.set(page);
        }
      });
  }

  setFilter(patch: Partial<F>): void {
    this.state.update((state) => ({ ...state, filter: { ...state.filter, ...patch }, page: 1 }));
    this.trigger.next();
  }

  resetFilter(): void {
    this.state.update((state) => ({ ...state, filter: {} as F, page: 1 }));
    this.trigger.next();
  }

  setSort(sort: string | null): void {
    this.state.update((state) => ({ ...state, sort, page: 1 }));
    this.trigger.next();
  }

  setPage(page: number, perPage: number): void {
    this.state.update((state) => ({ ...state, page, perPage }));
    this.trigger.next();
  }

  reload(): void {
    this.trigger.next();
  }

  private query(): Query {
    const { filter, sort, page, perPage } = this.state();

    return { filter: filter as Record<string, unknown>, sort, page, per_page: perPage };
  }
}
