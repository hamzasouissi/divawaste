import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';

import { Api, openBlob } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { IconComponent } from '../../ui/icon';
import { PageHeaderComponent } from '../../ui/page';
import { SELECT } from '../../ui/select';

type Target = 'clients' | 'suppliers' | 'materials' | 'received_lots' | 'articles' | 'orders' | 'manufacturing_orders' | 'consumptions' | 'produced_lots' | 'expenses' | 'invoices';
/** Targets that are nothing but money: offered to who may see amounts. */
const MONEY_TARGETS: Target[] = ['expenses', 'invoices'];

interface Field {
  field: string;
  label: string;
  type: string;
  required: boolean;
  /** What a cell of this column looks like. */
  example: string | null;
  money: boolean;
}

interface Preview {
  headers: string[];
  sample: (string | number | null)[][];
  lines: number;
  fields: Field[];
  mapping: Record<string, number | null>;
}

interface Result {
  dry_run: boolean;
  total: number;
  created: number;
  updated: number;
  unchanged: number;
  also_created: Record<string, number>;
  errors: { line: number; message: string }[];
}

/**
 * Import of the production data from any spreadsheet, in three steps: choose what the file contains, say in which
 * column each piece of information is (a first guess is made from the headings), check, then import. Nothing is
 * written before the last step, and a line in error never stops the others.
 */
@Component({
  imports: [TranslocoDirective, PageHeaderComponent, ButtonDirective, IconComponent, ...SELECT],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('production.import.title')" [subtitle]="t('production.import.subtitle')" />

      <div class="dw-stack">
        <section class="dw-panel dw-panel-body">
          <h2 class="dw-h2">{{ t('production.import.step1') }}</h2>
          <div class="targets" role="radiogroup" [attr.aria-label]="t('production.import.step1')">
            @for (item of targets; track item) {
              <button type="button" role="radio" class="target" [class.on]="target() === item" [attr.aria-checked]="target() === item" (click)="choose(item)">
                <strong>{{ t('production.import.targets.' + item) }}</strong><span class="dw-faint">{{ t('production.import.hints.' + item) }}</span>
              </button>
            }
          </div>
          <div class="pick">
            <label class="file">
              <input type="file" accept=".xlsx,.xls,.csv" (change)="pick($event)" />
              <dw-icon aria-hidden="true">upload_file</dw-icon><span>{{ file()?.name || t('production.import.file') }}</span>
            </label>
            <button pButton text type="button" (click)="template()"><dw-icon>download</dw-icon>{{ t('production.import.template') }}</button>
          </div>
        </section>

        @if (preview(); as p) {
          <section class="dw-panel dw-panel-body">
            <h2 class="dw-h2">{{ t('production.import.step2') }}</h2>
            <p class="dw-muted">{{ t('production.import.mappingHint', { lines: p.lines }) }}</p>
            <div class="mapping">
              @for (field of visible(p.fields); track field.field) {
                <div class="map" [class.missing]="field.required && mapping()[field.field] === null">
                  <span>{{ field.label }} @if (field.required) { <abbr class="req" [title]="t('validation.required')">*</abbr> }</span>
                  <dw-select [value]="mapping()[field.field]" (valueChange)="map(field.field, $event)" [ariaLabel]="field.label" [placeholder]="t('production.import.noColumn')">
                    <dw-option [value]="null" [label]="t('production.import.noColumn')" />
                    @for (header of p.headers; track $index) { <dw-option [value]="$index" [label]="header || t('production.import.column', { n: $index + 1 })" /> }
                  </dw-select>
                  <span class="dw-faint sample">{{ sampleOf(field.field) || (field.example ? t('production.import.expected', { example: field.example }) : '') }}</span>
                </div>
              }
            </div>
            <div class="dw-form-actions">
              <button pButton severity="secondary" outlined type="button" [disabled]="busy() || !mapped()" (click)="run(true)"><dw-icon>fact_check</dw-icon>{{ t('production.import.check') }}</button>
              <button pButton type="button" [disabled]="busy() || !mapped() || !checked()" (click)="run(false)"><dw-icon>publish</dw-icon>{{ t('production.import.run') }}</button>
            </div>
          </section>
        }

        @if (result(); as r) {
          <section class="dw-panel dw-panel-body" role="status">
            <h2 class="dw-h2">{{ t(r.dry_run ? 'production.import.checkResult' : 'production.import.done') }}</h2>
            <p class="summary" [class.ok]="r.errors.length === 0">
              {{ t(r.dry_run ? 'production.import.wouldWrite' : 'production.import.wrote', { created: r.created, updated: r.updated, unchanged: r.unchanged, total: r.total }) }}
              @if (also(r)) { <span class="dw-faint">{{ t('production.import.also') }} {{ also(r) }}.</span> }
            </p>
            @if (r.errors.length > 0) {
              <p class="dw-muted">{{ t('production.import.errorsHint', { count: r.errors.length }) }}</p>
              <table class="dw-table errors">
                <thead><tr><th>{{ t('production.import.line') }}</th><th>{{ t('production.import.problem') }}</th></tr></thead>
                <tbody>@for (error of r.errors.slice(0, 100); track error.line) { <tr><td class="dw-mono">{{ error.line }}</td><td>{{ error.message }}</td></tr> }</tbody>
              </table>
            }
          </section>
        }
      </div>
    </ng-container>
  `,
  styles: `
    .targets { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: var(--dw-2); margin-block: var(--dw-3); }
    .target { all: unset; cursor: pointer; display: grid; gap: 2px; padding: 10px 12px; border: 1px solid var(--dw-line); border-radius: var(--dw-radius); font-size: 13px; }
    .target.on { border-color: var(--dw-primary); background: var(--dw-primary-soft); } .target:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: 2px; }
    .pick { display: flex; align-items: center; gap: var(--dw-3); flex-wrap: wrap; }
    .file { flex: 1 1 320px; display: flex; align-items: center; gap: 8px; padding: 12px; border: 1px dashed var(--dw-line); border-radius: var(--dw-radius); cursor: pointer; font-size: 13px; }
    .file:focus-within { outline: 2px solid var(--dw-primary); outline-offset: 2px; } .file input { position: absolute; opacity: 0; width: 1px; height: 1px; }
    .mapping { display: grid; gap: var(--dw-2); margin-block: var(--dw-3); }
    .map { display: grid; grid-template-columns: minmax(160px, 240px) minmax(180px, 320px) minmax(0, 1fr); gap: var(--dw-3); align-items: center; font-size: 13px; }
    .map.missing > span:first-child { color: var(--dw-danger); font-weight: 600; } .req { color: var(--dw-danger); text-decoration: none; }
    .sample { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .summary { font-weight: 600; } .summary.ok { color: var(--dw-success); }
    .errors { margin-block-start: var(--dw-2); } .errors td:first-child { width: 90px; }
    @media (max-width: 720px) { .map { grid-template-columns: minmax(0, 1fr); gap: 4px; } }
  `,
})
export class ImportPage {
  private readonly api = inject(Api);
  private readonly http = inject(HttpClient);
  private readonly toast = inject(Toast);

  private readonly money = inject(Session).can('production.finance.view');
  /** In the order they are best imported: what is referred to first, what refers to it after. */
  protected readonly targets: Target[] = (['materials', 'received_lots', 'articles', 'orders', 'manufacturing_orders', 'consumptions', 'produced_lots', 'expenses', 'invoices', 'clients', 'suppliers'] as Target[])
    .filter((target) => this.money || !MONEY_TARGETS.includes(target));
  protected readonly target = signal<Target>('materials');
  protected readonly file = signal<File | null>(null);
  protected readonly preview = signal<Preview | null>(null);
  protected readonly mapping = signal<Record<string, number | null>>({});
  protected readonly result = signal<Result | null>(null);
  protected readonly busy = signal(false);
  /** The import is offered once the same file, with the same columns, has been checked. */
  protected readonly checked = signal(false);
  protected readonly mapped = computed(() => (this.preview()?.fields ?? []).every((field) => !field.required || (this.mapping()[field.field] ?? null) !== null));

  /** Columns of amounts are offered only to who may see money. */
  protected visible(fields: Field[]): Field[] {
    return fields.filter((field) => this.money || !field.money);
  }

  protected choose(target: Target): void {
    this.target.set(target);
    void this.read();
  }

  protected pick(event: Event): void {
    this.file.set((event.target as HTMLInputElement).files?.[0] ?? null);
    void this.read();
  }

  protected map(field: string, column: number | null): void {
    this.mapping.update((mapping) => ({ ...mapping, [field]: column }));
    this.checked.set(false);
    this.result.set(null);
  }

  /** First value found in the chosen column, so the user sees what he is pointing at. */
  protected sampleOf(field: string): string {
    const column = this.mapping()[field];
    const value = column === null || column === undefined ? null : this.preview()?.sample.map((row) => row[column]).find((cell) => cell !== null && cell !== '');

    return value === null || value === undefined ? '' : `${this.toast.text('production.import.example')} ${value}`;
  }

  protected also(result: Result): string {
    return Object.entries(result.also_created).map(([kind, count]) => `${count} ${this.toast.text(`production.import.targets.${kind}`).toLowerCase()}`).join(', ');
  }

  protected async template(): Promise<void> {
    try {
      openBlob(await firstValueFrom(this.api.blob(`production/imports/targets/${this.target()}/template`)), `${this.target()}.xlsx`);
    } catch (error) {
      this.toast.error(error);
    }
  }

  private body(): FormData | null {
    const file = this.file();
    if (!file) {
      return null;
    }
    const body = new FormData();
    body.append('file', file);
    body.append('target', this.target());

    return body;
  }

  private async read(): Promise<void> {
    this.preview.set(null);
    this.result.set(null);
    this.checked.set(false);
    const body = this.body();
    if (!body) {
      return;
    }
    this.busy.set(true);
    try {
      const preview = (await firstValueFrom(this.http.post<{ data: Preview }>('/api/v1/production/imports/preview', body))).data;
      this.preview.set(preview);
      this.mapping.set(preview.mapping);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected async run(dryRun: boolean): Promise<void> {
    const body = this.body();
    if (!body) {
      return;
    }
    for (const [field, column] of Object.entries(this.mapping())) {
      if (column !== null) {
        body.append(`mapping[${field}]`, String(column));
      }
    }
    if (dryRun) {
      body.append('dry_run', '1');
    }
    this.busy.set(true);
    try {
      const result = (await firstValueFrom(this.http.post<{ data: Result }>('/api/v1/production/imports/run', body))).data;
      this.result.set(result);
      this.checked.set(dryRun);
      if (!dryRun) {
        this.toast.success('production.import.done');
      }
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
