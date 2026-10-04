import { ChangeDetectionStrategy, Component, computed, inject, model, output } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';

import { Session } from '../core/auth/session';
import { DateComponent } from './date';
import { SELECT } from './select';

export interface Period {
  from: string;
  to: string;
  site: string | null;
}

function iso(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export type Preset = 'month' | 'quarter' | 'year' | '12m' | '36m';

export function presetPeriod(preset: Preset, site: string | null = null): Period {
  const now = new Date();
  const start = new Date(now.getFullYear(), now.getMonth(), 1);
  switch (preset) {
    case 'quarter':
      start.setMonth(start.getMonth() - 2);
      break;
    case 'year':
      start.setMonth(0);
      break;
    case '12m':
      start.setMonth(start.getMonth() - 11);
      break;
    case '36m':
      start.setMonth(start.getMonth() - 35);
      break;
  }

  return { from: iso(start), to: iso(now), site };
}

/**
 * Period and site filter shared by the dashboard, the register and the exports: same control, same meaning
 * everywhere. Only the sites the user may see for the given permission are offered.
 */
@Component({
  selector: 'dw-period-filter',
  imports: [FormsModule, DateComponent, ...SELECT, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-select class="preset" [ariaLabel]="t('period.label')" [value]="preset()" (valueChange)="applyPreset($any($event))">
        @for (option of presets; track option) { <dw-option [value]="option" [label]="t('period.' + option)" /> }
        <dw-option value="custom" [label]="t('period.custom')" />
      </dw-select>
      <div class="range">
        <dw-date class="date" [ariaLabel]="t('period.from')" [placeholder]="t('period.from')" [ngModel]="value().from" (ngModelChange)="patch({ from: $event })" [max]="value().to" />
        <span class="dash" aria-hidden="true">–</span>
        <dw-date class="date" [ariaLabel]="t('period.to')" [placeholder]="t('period.to')" [ngModel]="value().to" (ngModelChange)="patch({ to: $event })" [min]="value().from" />
      </div>
      @if (sites().length > 1) {
        <dw-select class="site" [ariaLabel]="t('fields.site')" [value]="value().site ?? ''" (valueChange)="patch({ site: $any($event) || null })">
          <dw-option value="" [label]="t('common.allSites')" />
          @for (site of sites(); track site.ulid) { <dw-option [value]="site.ulid" [label]="site.name" /> }
        </dw-select>
      }
    </ng-container>
  `,
  styles: `
    :host { display: flex; gap: var(--dw-2); flex-wrap: wrap; align-items: center; }
    .preset { width: 176px; } .date { width: 132px; } .site { width: 200px; }
    .range { display: flex; align-items: center; gap: 6px; }
    .dash { color: var(--dw-ink-3); }
  `,
})
export class PeriodFilterComponent {
  private readonly session = inject(Session);

  readonly value = model.required<Period>();
  readonly permission = model('dashboard.view');
  readonly changed = output<Period>();

  protected readonly presets: Preset[] = ['month', 'quarter', 'year', '12m', '36m'];
  protected readonly sites = computed(() => this.session.sitesFor(this.permission()));
  protected readonly preset = computed(() => this.presets.find((preset) => presetPeriod(preset).from === this.value().from && presetPeriod(preset).to === this.value().to) ?? 'custom');

  protected applyPreset(preset: Preset | 'custom'): void {
    if (preset !== 'custom') {
      this.patch(presetPeriod(preset, this.value().site));
    }
  }

  protected patch(change: Partial<Period>): void {
    const next = { ...this.value(), ...change };
    if (next.from && next.to && next.from <= next.to) {
      this.value.set(next);
      this.changed.emit(next);
    }
  }
}
