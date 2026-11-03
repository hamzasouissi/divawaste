import { ChangeDetectionStrategy, Component, ElementRef, HostListener, computed, inject, signal, viewChild } from '@angular/core';
import { Router } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { Subject, debounceTime, distinctUntilChanged, of, switchMap, catchError } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import { Api } from '../core/api/api';
import { IconComponent } from '../ui/icon';

export interface SearchResult {
  kind: 'lot' | 'pickup' | 'contract' | 'listing' | 'manifest' | 'provider' | 'partner' | 'waste_type' | 'site';
  ulid: string;
  label: string;
  sublabel: string | null;
  link: string;
}

const ICONS: Record<SearchResult['kind'], string> = {
  lot: 'inventory_2', pickup: 'local_shipping', contract: 'handshake', listing: 'storefront', manifest: 'assignment',
  provider: 'recycling', partner: 'recycling', waste_type: 'category', site: 'factory',
};

/**
 * One search box for every number and name (SH-01): type L-2026-…, ENL-…, CTR-…, ANN-…, a provider or a waste type and
 * go straight to its page. Ctrl+K (or /) focuses it from anywhere; arrows and Enter choose without the mouse.
 */
@Component({
  selector: 'dw-global-search',
  imports: [TranslocoDirective, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <div class="box" [class.open]="open()">
        <dw-icon class="lead" aria-hidden="true">search</dw-icon>
        <input #input type="search" role="combobox" [attr.aria-expanded]="open()" aria-controls="dw-search-results" aria-autocomplete="list"
          [attr.aria-activedescendant]="open() && results().length ? 'dw-search-' + active() : null"
          [placeholder]="t('search.placeholder')" [value]="query()" (input)="type($any($event.target).value)" (focus)="focused.set(true)"
          (blur)="blurSoon()" (keydown)="key($event)" [attr.aria-label]="t('search.label')" autocomplete="off" spellcheck="false" />
        <kbd aria-hidden="true">Ctrl K</kbd>
      </div>
      @if (open()) {
        <div class="panel" id="dw-search-results" role="listbox" [attr.aria-label]="t('search.label')">
          @if (loading() && results().length === 0) {
            <div class="empty">{{ t('common.loading') }}</div>
          } @else {
            @for (result of results(); track result.kind + result.ulid; let i = $index) {
              <button type="button" class="row" role="option" [id]="'dw-search-' + i" [class.active]="i === active()" [attr.aria-selected]="i === active()"
                (mousedown)="$event.preventDefault()" (click)="go(result)" (mouseenter)="active.set(i)">
                <span class="ico"><dw-icon>{{ icon(result.kind) }}</dw-icon></span>
                <span class="text"><span class="label">{{ result.label }}</span>@if (result.sublabel) { <span class="sub">{{ result.sublabel }}</span> }</span>
                <span class="kind">{{ t('search.kinds.' + result.kind) }}</span>
              </button>
            } @empty {
              <div class="empty">{{ t('search.empty', { query: query() }) }}</div>
            }
          }
        </div>
      }
    </ng-container>
  `,
  styles: `
    :host { position: relative; display: block; width: min(440px, 100%); }
    .box { display: flex; align-items: center; gap: 8px; height: 38px; padding-inline: 12px 8px; border-radius: 10px; background: var(--dw-surface-3);
      border: 1px solid transparent; transition: background-color 140ms, border-color 140ms, box-shadow 140ms; }
    .box:hover { background: #e9eeef; }
    .box:focus-within { background: var(--dw-surface); border-color: var(--dw-primary-line); box-shadow: 0 0 0 3px var(--dw-focus-soft); }
    .lead { font-size: 18px; color: var(--dw-ink-3); }
    input { all: unset; flex: 1; min-width: 0; font-size: 13.5px; color: var(--dw-ink); }
    input::placeholder { color: var(--dw-ink-3); }
    input::-webkit-search-cancel-button { display: none; }
    kbd { font: 600 10.5px/1 var(--dw-font); color: var(--dw-ink-3); padding: 4px 6px; border-radius: 5px; background: var(--dw-surface); border: 1px solid var(--dw-line); white-space: nowrap; }
    .box:focus-within kbd { display: none; }
    .panel { position: absolute; inset-inline: 0; inset-block-start: calc(100% + 6px); z-index: 40; max-height: min(460px, 70vh); overflow-y: auto; padding: 6px;
      background: var(--dw-surface); border-radius: 12px; box-shadow: var(--dw-shadow-pop); animation: dw-rise 140ms var(--dw-ease); }
    .row { all: unset; box-sizing: border-box; display: flex; align-items: center; gap: 10px; width: 100%; padding: 8px 10px; border-radius: 8px; cursor: pointer; }
    .row.active { background: var(--dw-primary-soft); }
    .ico { display: grid; place-items: center; flex: none; width: 30px; height: 30px; border-radius: 8px; background: var(--dw-surface-3); color: var(--dw-ink-2); }
    .row.active .ico { background: var(--dw-surface); color: var(--dw-primary); }
    .ico dw-icon { font-size: 17px; }
    .text { display: flex; flex-direction: column; min-width: 0; flex: 1; }
    .label { font-weight: 600; font-size: 13.5px; color: var(--dw-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sub { font-size: 12px; color: var(--dw-ink-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .kind { flex: none; font-size: 11px; font-weight: 600; color: var(--dw-ink-3); text-transform: uppercase; letter-spacing: 0.05em; }
    .empty { padding: 14px 12px; color: var(--dw-ink-3); font-size: 13px; }
    @media (max-width: 720px) { kbd { display: none; } }
  `,
})
export class GlobalSearchComponent {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly input = viewChild.required<ElementRef<HTMLInputElement>>('input');

  protected readonly query = signal('');
  protected readonly results = signal<SearchResult[]>([]);
  protected readonly loading = signal(false);
  protected readonly focused = signal(false);
  protected readonly active = signal(0);
  protected readonly open = computed(() => this.focused() && this.query().trim().length >= 2);

  private readonly terms = new Subject<string>();

  constructor() {
    this.terms.pipe(
      debounceTime(180),
      distinctUntilChanged(),
      switchMap((term) => {
        if (term.trim().length < 2) {
          return of([] as SearchResult[]);
        }
        this.loading.set(true);
        return this.api.get<SearchResult[]>('search', { q: term.trim() }).pipe(catchError(() => of([] as SearchResult[])));
      }),
      takeUntilDestroyed(),
    ).subscribe((results) => {
      this.loading.set(false);
      this.results.set(results);
      this.active.set(0);
    });
  }

  @HostListener('document:keydown', ['$event'])
  protected shortcut(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    const typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable);
    if ((event.key === 'k' && (event.ctrlKey || event.metaKey)) || (event.key === '/' && !typing)) {
      event.preventDefault();
      this.input().nativeElement.focus();
      this.input().nativeElement.select();
    }
  }

  protected icon(kind: SearchResult['kind']): string {
    return ICONS[kind];
  }

  protected type(value: string): void {
    this.query.set(value);
    this.terms.next(value);
  }

  protected key(event: KeyboardEvent): void {
    const count = this.results().length;
    if (event.key === 'ArrowDown' && count) {
      event.preventDefault();
      this.active.set((this.active() + 1) % count);
    } else if (event.key === 'ArrowUp' && count) {
      event.preventDefault();
      this.active.set((this.active() - 1 + count) % count);
    } else if (event.key === 'Enter' && count) {
      event.preventDefault();
      this.go(this.results()[this.active()]);
    } else if (event.key === 'Escape') {
      this.input().nativeElement.blur();
    }
  }

  protected blurSoon(): void {
    this.focused.set(false);
  }

  protected go(result: SearchResult): void {
    this.query.set('');
    this.results.set([]);
    this.input().nativeElement.blur();
    void this.router.navigateByUrl(result.link);
  }
}
