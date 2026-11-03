import { ChangeDetectionStrategy, Component, computed, effect, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { InputText } from 'primeng/inputtext';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { Locale } from '../../core/i18n/i18n';
import { SELECT } from '../../ui/select';
import { StateComponent } from '../../ui/state';

interface Volume {
  site: string;
  month: string;
  pieces_produced: number;
}

/**
 * Pieces produced per site and month (R-02): what "waste per piece" is computed from. It is production data, so it
 * sits with production (Analyse) when the company uses that module, and with the reports otherwise.
 */
@Component({
  selector: 'dw-produced-pieces',
  imports: [FormsModule, TranslocoDirective, InputText, StateComponent, ...SELECT],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (sites.length > 0) {
        <section class="dw-panel">
          <header class="dw-panel-head">
            <div class="dw-spacer"><h2 class="dw-h2">{{ t('reports.production') }}</h2><p class="dw-muted">{{ t('reports.productionHint') }}</p></div>
            <dw-select class="year" [ariaLabel]="t('compliance.declarations.year')" [placeholder]="t('compliance.declarations.year')" [ngModel]="year()" (ngModelChange)="year.set($event)">
              @for (item of years; track item) { <dw-option [value]="item" [label]="item" /> }
            </dw-select>
          </header>
          <dw-state [loading]="loading()" [error]="error()" [keepContent]="true" (retry)="load()">
            <div class="scroll">
              <table class="dw-table volumes">
                <thead><tr><th scope="col">{{ t('fields.site') }}</th>@for (month of months(); track month.key) { <th scope="col">{{ month.label }}</th> }</tr></thead>
                <tbody>
                  @for (site of sites; track site.ulid) {
                    <tr>
                      <th scope="row">{{ site.name }}</th>
                      @for (month of months(); track month.key) {
                        <td>
                          <input pInputText inputmode="numeric" [disabled]="month.future || !canEnter(site.ulid)" [value]="volumes()[site.ulid + month.key] ?? ''"
                            (change)="save(site.ulid, month.key, $any($event.target))" [attr.aria-label]="site.name + ' — ' + month.label" />
                        </td>
                      }
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          </dw-state>
        </section>
      }
    </ng-container>
  `,
  styles: `
    .year { width: 120px; } .scroll { overflow-x: auto; }
    .volumes thead th { text-align: end; } .volumes thead th:first-child { text-align: start; }
    .volumes tbody th[scope='row'] { text-align: start; font-weight: 500; padding: 4px var(--dw-3); border-block-end: 1px solid var(--dw-line-soft); white-space: nowrap; }
    .volumes td { padding: 4px; }
    .volumes input { width: 100%; min-width: 72px; min-height: 30px; padding: 4px 6px; text-align: end; font-variant-numeric: tabular-nums; }
  `,
})
export class ProducedPiecesComponent {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);
  private readonly locale = inject(Locale);

  protected readonly sites = this.session.sitesFor('dashboard.view');
  protected readonly years = Array.from({ length: 4 }, (_, index) => new Date().getFullYear() - index);
  protected readonly year = signal(this.years[0]);
  protected readonly volumes = signal<Record<string, number | undefined>>({});
  protected readonly loading = signal(false);
  protected readonly error = signal<unknown>(null);
  protected readonly months = computed(() => {
    const current = new Date().toISOString().slice(0, 7);

    return Array.from({ length: 12 }, (_, index) => {
      const key = `${this.year()}-${String(index + 1).padStart(2, '0')}`;

      return { key, label: new Intl.DateTimeFormat(this.locale.intl, { month: 'short' }).format(new Date(this.year(), index, 1)), future: key > current };
    });
  });

  constructor() {
    effect(() => {
      this.year();
      this.load();
    });
  }

  protected canEnter(site: string): boolean {
    return this.session.can('production_volumes.manage', site);
  }

  protected load(): void {
    if (this.sites.length === 0) {
      return;
    }
    this.loading.set(true);
    this.error.set(null);
    this.api.get<Volume[]>('production-volumes', { year: this.year() }).subscribe({
      next: (rows) => {
        this.volumes.set(Object.fromEntries(rows.map((row) => [row.site + row.month, row.pieces_produced])));
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  /** Saved cell by cell when the field is left: there is no form to submit and nothing to lose. */
  protected async save(site: string, month: string, input: HTMLInputElement): Promise<void> {
    const key = site + month;
    const text = input.value.replace(/\s/g, '');
    if (!/^\d{1,10}$/.test(text)) {
      input.value = String(this.volumes()[key] ?? '');

      return;
    }
    try {
      await firstValueFrom(this.api.put('production-volumes', { site, month, pieces_produced: Number(text) }));
      this.volumes.update((volumes) => ({ ...volumes, [key]: Number(text) }));
      this.toast.success('reports.productionSaved');
    } catch (error) {
      input.value = String(this.volumes()[key] ?? '');
      this.toast.error(error);
    }
  }
}
