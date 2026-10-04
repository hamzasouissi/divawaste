import { ChangeDetectionStrategy, Component, input, output, signal } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';

import { IconComponent } from './icon';

/**
 * Filters of a list, on one line above the table: search first, then the selectors, then what is active and
 * the way to clear it. Controls placed inside take the compact filter look (no label above; the placeholder
 * names the filter and a set filter is tinted). On a phone the controls fold behind a "Filters" button.
 *
 *   <dw-filter-bar [active]="list.activeCount()" (reset)="list.resetFilter()">
 *     <dw-search … /> <dw-select … /> <dw-checkbox … />
 *     <button dwFilterEnd …>Export</button>
 *   </dw-filter-bar>
 */
@Component({
  selector: 'dw-filter-bar',
  imports: [ButtonDirective, IconComponent, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <button pButton type="button" severity="secondary" outlined class="toggle" (click)="open.set(!open())" [attr.aria-expanded]="open()">
        <dw-icon>filter_list</dw-icon>
        {{ t('common.filters') }}
        @if (active() > 0) { <span class="count">{{ active() }}</span> }
      </button>
      <div class="controls" [class.open]="open()" role="search">
        <ng-content />
        @if (active() > 0) {
          <button pButton type="button" text size="small" class="reset" (click)="reset.emit()">
            <dw-icon>filter_alt_off</dw-icon>
            {{ t('common.resetFilters') }}
            <span class="count">{{ active() }}</span>
          </button>
        }
      </div>
      <div class="end"><ng-content select="[dwFilterEnd]" /></div>
    </ng-container>
  `,
  styles: `
    :host { display: flex; align-items: center; gap: var(--dw-2); padding: 10px var(--dw-3); border-block-end: 1px solid var(--dw-line); min-width: 0; }
    .controls { display: flex; align-items: center; gap: var(--dw-2); flex-wrap: wrap; flex: 1 1 auto; min-width: 0; }
    .end { display: flex; align-items: center; gap: var(--dw-2); margin-inline-start: auto; }
    .end:empty { display: none; }
    .toggle { display: none; }
    .count { display: inline-grid; place-items: center; min-width: 16px; height: 16px; padding-inline: 4px; border-radius: 8px; background: var(--dw-primary); color: #fff;
      font-size: 10.5px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .reset { white-space: nowrap; }

    @media (max-width: 720px) {
      :host { flex-wrap: wrap; }
      .toggle { display: inline-flex; }
      .controls { display: none; flex-basis: 100%; order: 3; }
      .controls.open { display: grid; grid-template-columns: minmax(0, 1fr); }
    }
  `,
  host: { class: 'dw-filter-bar' },
})
export class FilterBarComponent {
  /** Number of filters currently set. */
  readonly active = input(0);
  readonly reset = output<void>();

  protected readonly open = signal(false);
}
