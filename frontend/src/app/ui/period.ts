import { ChangeDetectionStrategy, Component, computed, inject, model, output } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoDirective } from '@jsverse/transloco';

import { Session } from '../core/auth/session';

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
  imports: [FormsModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <mat-form-field class="preset">
        <mat-label>{{ t('period.label') }}</mat-label>
        <mat-select [ngModel]="preset()" (ngModelChange)="applyPreset($event)">
          @for (option of presets; track option) { <mat-option [value]="option">{{ t('period.' + option) }}</mat-option> }
          <mat-option value="custom">{{ t('period.custom') }}</mat-option>
        </mat-select>
      </mat-form-field>
      <mat-form-field class="date">
        <mat-label>{{ t('period.from') }}</mat-label>
        <input matInput type="date" [ngModel]="value().from" (ngModelChange)="patch({ from: $event })" [max]="value().to" />
      </mat-form-field>
      <mat-form-field class="date">
        <mat-label>{{ t('period.to') }}</mat-label>
        <input matInput type="date" [ngModel]="value().to" (ngModelChange)="patch({ to: $event })" [min]="value().from" />
      </mat-form-field>
      @if (sites().length > 1) {
        <mat-form-field class="site">
          <mat-label>{{ t('fields.site') }}</mat-label>
          <mat-select [ngModel]="value().site ?? ''" (ngModelChange)="patch({ site: $event || null })">
            <mat-option value="">{{ t('common.allSites') }}</mat-option>
            @for (site of sites(); track site.ulid) { <mat-option [value]="site.ulid">{{ site.name }}</mat-option> }
          </mat-select>
        </mat-form-field>
      }
    </ng-container>
  `,
  styles: `
    :host { display: flex; gap: var(--dw-2); flex-wrap: wrap; align-items: center; }
    .preset { width: 190px; } .date { width: 160px; } .site { width: 220px; }
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
