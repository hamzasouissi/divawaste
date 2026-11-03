import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { ToggleSwitch } from 'primeng/toggleswitch';

import { Api, openBlob } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Davi } from '../../core/davi/davi';
import { Toast } from '../../core/feedback/toast';
import { DatePipe } from '../../core/format/pipes';
import { IconComponent } from '../../ui/icon';
import { PageHeaderComponent } from '../../ui/page';
import { SELECT } from '../../ui/select';

type Target = 'clients' | 'suppliers' | 'materials' | 'received_lots' | 'articles' | 'orders' | 'manufacturing_orders' | 'consumptions' | 'produced_lots' | 'expenses' | 'invoices' | 'waste_lots';
/** Targets that are nothing but money: offered to who may see amounts. */
const MONEY_TARGETS: Target[] = ['expenses', 'invoices'];
/** Fields of a waste lot that tie it to production: offered only to a company that follows its production. */
const PRODUCTION_FIELDS = ['manufacturing_order', 'step'];

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

/** G-03: an export the ERP publishes at an address, read every night. The address itself never comes back. */
interface Feed {
  target: Target;
  host: string | null;
  enabled: boolean;
  last_run_at: string | null;
  last_result: { total: number; created: number; updated: number; unchanged: number; errors: number; first_errors: { line: number; message: string }[] } | null;
  last_error: 'address' | 'download' | 'too_large' | 'unreadable' | 'columns' | 'rights' | null;
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
 *
 * Under Lots (route data `lots`), the same page imports waste lots only: a register kept in Excel or an ERP export,
 * one line per lot, for anyone who creates lots (not only production companies).
 */
@Component({
  imports: [FormsModule, RouterLink, TranslocoDirective, PageHeaderComponent, ButtonDirective, IconComponent, InputText, ToggleSwitch, DatePipe, ...SELECT],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (lots) {
        <dw-page-header [title]="t('lots.import.title')" [subtitle]="t('lots.import.subtitle')" back="/lots" [backLabel]="t('lots.title')" />
      } @else {
        <dw-page-header [title]="t(erpWays ? 'production.import.erpTitle' : 'production.import.title')" [subtitle]="t('production.import.subtitle')" />
      }

      <!-- G-03: two ways in for ERP data — a file whose columns are remembered, or the API the ERP calls itself.
           Not for a company without an ERP: it types its data or fills in its own spreadsheet. -->
      @if (erpWays) {
      <section class="dw-panel dw-panel-body erp-ways">
        <div><span class="ico"><dw-icon>upload_file</dw-icon></span><span><strong>{{ t('production.import.byFile') }}</strong><small class="dw-faint">{{ t('production.import.byFileHint') }}</small></span></div>
        <div><span class="ico"><dw-icon>hub</dw-icon></span><span><strong>{{ t('production.import.byApi') }}</strong><small class="dw-faint">{{ t('production.import.byApiHint') }}</small>
          <a href="/docs" target="_blank" rel="noopener">{{ t('production.import.apiDocs') }}</a></span></div>
      </section>
      }


      <div class="dw-stack">
        <section class="dw-panel dw-panel-body">
          @if (lots) {
            <!-- One kind of file here: what a line must hold, and that DAVI takes the same file. -->
            <h2 class="dw-h2">{{ t('lots.import.step1') }}</h2>
            <p class="dw-muted lead">{{ t('lots.import.lead') }}</p>
            @if (davi.available()) { <p class="davi-tip"><dw-icon aria-hidden="true">auto_awesome</dw-icon>{{ t('lots.import.daviTip') }}</p> }
          } @else {
            <h2 class="dw-h2">{{ t('production.import.step1') }}</h2>
            <div class="targets" role="radiogroup" [attr.aria-label]="t('production.import.step1')">
              @for (item of targets; track item) {
                <button type="button" role="radio" class="target" [class.on]="target() === item" [attr.aria-checked]="target() === item" (click)="choose(item)">
                  <strong>{{ t('production.import.targets.' + item) }}</strong><span class="dw-faint">{{ t('production.import.hints.' + item) }}</span>
                </button>
              }
            </div>
          }
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
            @if (remembered()) { <p class="remembered"><dw-icon aria-hidden="true">bookmark_added</dw-icon>{{ t('production.import.remembered') }}</p> }
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
            @if (!r.dry_run && target() === 'waste_lots' && r.created > 0) {
              <a pButton outlined routerLink="/lots"><dw-icon>inventory_2</dw-icon>{{ t('lots.import.seeLots') }}</a>
            }
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

      <!-- G-03 / PRD-05: exports read every night, with the columns of the last import of the same kind of file. -->
      @if (erpWays) {
      <section class="dw-panel feeds">
        <div class="dw-panel-head">
          <div class="dw-spacer"><h2 class="dw-h2">{{ t('production.import.feeds.title') }}</h2><p class="dw-muted head-hint">{{ t('production.import.feeds.lead') }}</p></div>
        </div>
        @for (feed of shownFeeds(); track feed.target) {
          <div class="feed">
            <div class="feed-what">
              <strong>{{ t('production.import.targets.' + feed.target) }}</strong>
              <span class="dw-faint dw-mono">{{ feed.host }}</span>
            </div>
            <div class="feed-last">
              @if (feed.last_error; as error) {
                <span class="bad"><dw-icon aria-hidden="true">error</dw-icon>{{ t('production.import.feeds.errors.' + error) }}</span>
              } @else if (feed.last_result; as r) {
                <span [class.bad]="r.errors > 0">{{ t('production.import.feeds.lastResult', { created: r.created, updated: r.updated, errors: r.errors }) }}</span>
              } @else {
                <span class="dw-faint">{{ t('production.import.feeds.never') }}</span>
              }
              @if (feed.last_run_at) { <small class="dw-faint">{{ feed.last_run_at | dwDate: 'datetime' }}</small> }
              @if (!saved()[feed.target]) { <small class="dw-faint">{{ t('production.import.feeds.guessed') }}</small> }
            </div>
            <p-toggleswitch [ngModel]="feed.enabled" (ngModelChange)="switchFeed(feed, $event)" [ariaLabel]="t('production.import.feeds.nightly')" />
            <button pButton text type="button" [disabled]="busy()" (click)="runFeed(feed)">{{ t('production.import.feeds.runNow') }}</button>
            <button pButton text rounded severity="secondary" size="small" class="dw-icon-btn" type="button" [disabled]="busy()" (click)="removeFeed(feed)"
              [attr.aria-label]="t('common.delete')" [title]="t('common.delete')"><dw-icon>delete</dw-icon></button>
          </div>
        }
        <form class="feed-new" (submit)="$event.preventDefault(); addFeed()">
          <dw-select [value]="feedTarget()" (valueChange)="feedTarget.set($event)" [ariaLabel]="t('production.import.step1')">
            @for (item of targets; track item) { <dw-option [value]="item" [label]="t('production.import.targets.' + item)" /> }
          </dw-select>
          <input pInputText type="url" name="feedUrl" inputmode="url" [ngModel]="feedUrl()" (ngModelChange)="feedUrl.set($event)" placeholder="https://erp.example.com/export/clients.csv"
            [attr.aria-label]="t('production.import.feeds.address')" />
          <button pButton type="submit" severity="secondary" outlined [disabled]="busy() || !feedUrl().startsWith('https://')"><dw-icon>add</dw-icon>{{ t('production.import.feeds.add') }}</button>
        </form>
      </section>
      }
    </ng-container>
  `,
  styles: `
    .lead { margin: 6px 0 10px; }
    .davi-tip { display: flex; gap: 8px; align-items: center; margin: 0 0 12px; padding: 9px 12px; border-radius: 10px; font-size: 13.5px; background: var(--dw-primary-soft); color: var(--dw-ink); }
    .davi-tip dw-icon { color: var(--dw-primary); font-size: 18px; }
    .targets { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: var(--dw-2); margin-block: var(--dw-3); }
    .target { all: unset; cursor: pointer; display: grid; align-content: start; gap: 2px; padding: 10px 12px; border: 1px solid var(--dw-line); border-radius: var(--dw-radius); font-size: 13px; }
    .target.on { border-color: var(--dw-primary); background: var(--dw-primary-soft); } .target:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: 2px; }
    .pick { display: flex; align-items: center; gap: var(--dw-3); flex-wrap: wrap; }
    .file { flex: 1 1 320px; display: flex; align-items: center; gap: 8px; padding: 12px; border: 1px dashed var(--dw-line); border-radius: var(--dw-radius); cursor: pointer; font-size: 13px; }
    .file:focus-within { outline: 2px solid var(--dw-primary); outline-offset: 2px; } .file input { position: absolute; opacity: 0; width: 1px; height: 1px; }
    .mapping { display: grid; gap: var(--dw-2); margin-block: var(--dw-3); }
    .erp-ways { margin-block-end: var(--dw-4); display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
    .erp-ways > div { display: flex; gap: 12px; align-items: flex-start; } .erp-ways span:last-child { display: grid; gap: 2px; font-size: 13.5px; }
    .erp-ways .ico { display: grid; place-items: center; flex: none; width: 38px; height: 38px; border-radius: 10px; background: var(--dw-primary-soft); color: var(--dw-primary); }
    .feeds { margin-block-start: var(--dw-4); } .head-hint { margin: 2px 0 0; font-size: 13px; }
    .feed { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 12px 20px; border-block-start: 1px solid var(--dw-line-soft); }
    .feed-what { display: grid; min-width: 200px; flex: 1; font-size: 13.5px; } .feed-what .dw-mono { font-size: 12px; }
    .feed-last { display: grid; gap: 1px; min-width: 220px; flex: 1.4; font-size: 13px; }
    .bad { display: inline-flex; align-items: center; gap: 4px; color: var(--dw-danger); } .bad dw-icon { font-size: 16px; }
    .feed-new { display: grid; grid-template-columns: minmax(180px, 240px) minmax(0, 1fr) auto; gap: 10px; padding: 14px 20px 18px; border-block-start: 1px solid var(--dw-line-soft); }
    @media (max-width: 720px) { .feed-new { grid-template-columns: minmax(0, 1fr); } }
    .remembered { display: flex; align-items: center; gap: 6px; margin: 0; font-size: 13px; color: var(--dw-success); }
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

  private readonly session = inject(Session);
  protected readonly davi = inject(Davi);
  private readonly money = this.session.can('production.finance.view');
  /** Under Lots: waste lots only. */
  protected readonly lots = inject(ActivatedRoute).snapshot.data['lots'] === true;
  private readonly production = this.session.uses('production');
  /** The API and the nightly exports are ways in for an ERP: not offered to a company that said it has none. */
  protected readonly erpWays = !this.session.noErp();
  /** In the order they are best imported: what is referred to first, what refers to it after; the waste lots last. */
  protected readonly targets: Target[] = this.lots ? ['waste_lots']
    : (['materials', 'received_lots', 'articles', 'orders', 'manufacturing_orders', 'consumptions', 'produced_lots', 'expenses', 'invoices', 'clients', 'suppliers', 'waste_lots'] as Target[])
      .filter((target) => this.money || !MONEY_TARGETS.includes(target))
      .filter((target) => target !== 'waste_lots' || this.session.sitesFor('lots.create').length > 0);
  protected readonly target = signal<Target>(this.targets[0]);
  protected readonly file = signal<File | null>(null);
  protected readonly preview = signal<Preview | null>(null);
  protected readonly mapping = signal<Record<string, number | null>>({});
  protected readonly result = signal<Result | null>(null);
  protected readonly busy = signal(false);
  /** The import is offered once the same file, with the same columns, has been checked. */
  protected readonly checked = signal(false);
  /** Column chosen per field, by header, for each kind of file: kept for the company. */
  protected readonly saved = signal<Record<string, Record<string, string>>>({});
  protected readonly remembered = signal(false);

  protected readonly feeds = signal<Feed[]>([]);
  protected readonly feedTarget = signal<Target>(this.lots ? 'waste_lots' : 'manufacturing_orders');
  protected readonly shownFeeds = computed(() => this.feeds().filter((feed) => this.targets.includes(feed.target)));
  protected readonly feedUrl = signal('');

  constructor() {
    this.api.get<Record<string, Record<string, string>>>('company/import-mappings').subscribe({ next: (saved) => this.saved.set(saved ?? {}), error: () => undefined });
    this.api.get<Feed[]>('production/erp-feeds').subscribe({ next: (feeds) => this.feeds.set(feeds), error: () => undefined });
    // A file given to DAVI, whose columns the user wants to choose here.
    const handed = this.davi.handoff();
    if (handed && this.targets.includes('waste_lots')) {
      this.davi.handoff.set(null);
      this.target.set('waste_lots');
      this.file.set(handed);
      void this.read();
    }
  }

  private putFeed(feed: Feed): void {
    this.feeds.update((feeds) => [...feeds.filter((item) => item.target !== feed.target), feed].sort((a, b) => this.targets.indexOf(a.target) - this.targets.indexOf(b.target)));
  }

  /** A new address is read at once: whoever gives it sees right away whether it works. */
  protected async addFeed(): Promise<void> {
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.put<Feed>(`production/erp-feeds/${this.feedTarget()}`, { url: this.feedUrl().trim(), enabled: true }));
      this.feedUrl.set('');
      this.putFeed(await firstValueFrom(this.api.post<Feed>(`production/erp-feeds/${this.feedTarget()}/run`)));
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected async switchFeed(feed: Feed, enabled: boolean): Promise<void> {
    try {
      this.putFeed(await firstValueFrom(this.api.put<Feed>(`production/erp-feeds/${feed.target}`, { enabled })));
    } catch (error) {
      this.toast.error(error);
    }
  }

  protected async runFeed(feed: Feed): Promise<void> {
    this.busy.set(true);
    try {
      this.putFeed(await firstValueFrom(this.api.post<Feed>(`production/erp-feeds/${feed.target}/run`)));
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  /** G-09: removed at once; the address is not kept, so it is asked again to undo. */
  protected async removeFeed(feed: Feed): Promise<void> {
    try {
      await firstValueFrom(this.api.delete(`production/erp-feeds/${feed.target}`));
      this.feeds.update((feeds) => feeds.filter((item) => item.target !== feed.target));
      this.feedTarget.set(feed.target);
      this.toast.success('production.import.feeds.removed');
    } catch (error) {
      this.toast.error(error);
    }
  }

  private remember(): void {
    const headers = this.preview()?.headers ?? [];
    const mapping = Object.fromEntries(Object.entries(this.mapping()).filter(([, column]) => column !== null && headers[column as number]).map(([field, column]) => [field, headers[column as number]]));
    this.api.put(`company/import-mappings/${this.target()}`, { mapping }).subscribe({
      next: () => this.saved.update((saved) => ({ ...saved, [this.target()]: mapping })),
      error: () => undefined,
    });
  }
  protected readonly mapped = computed(() => (this.preview()?.fields ?? []).every((field) => !field.required || (this.mapping()[field.field] ?? null) !== null));

  /** Columns of amounts are offered only to who may see money; those of production only to a company that follows it. */
  protected visible(fields: Field[]): Field[] {
    return fields.filter((field) => (this.money || !field.money) && (this.production || !PRODUCTION_FIELDS.includes(field.field)));
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
      // PRD-05: the columns chosen last time for this kind of file, found again by their header.
      const saved = this.saved()[this.target()] ?? {};
      const restored = Object.fromEntries(Object.entries(saved).map(([field, header]) => [field, preview.headers.indexOf(header)]).filter(([, index]) => (index as number) >= 0));
      this.remembered.set(Object.keys(restored).length > 0);
      this.mapping.set({ ...preview.mapping, ...restored });
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
    const shown = this.visible(this.preview()?.fields ?? []).map((field) => field.field);
    for (const [field, column] of Object.entries(this.mapping())) {
      if (column !== null && shown.includes(field)) {
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
        this.remember();
      }
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
