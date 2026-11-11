import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { CodePipe, NumberPipe } from '../../core/format/pipes';
import { Dialogs } from '../../ui/dialog';
import { IconComponent } from '../../ui/icon';
import { KpiComponent, PageHeaderComponent, StatusComponent } from '../../ui/page';
import { StateComponent } from '../../ui/state';
import { ArticleDialog } from './materials';
import { Article, ArticleLine, KIND_ICON, WasteLine, describeWaste } from './production-data';

/** How a quantity per piece is written: metres, kilograms, pieces. */
const UNIT: Record<string, string> = { m: 'm', kg: 'kg', piece: 'pc' };

/**
 * An article: what one piece consumes of each raw material and, for its fabrics, the cutting waste the placement is
 * gives; how much it leaves is computed on an OF, from its spreads and their efficiency, never from the article.
 * The fabrics come first, in full; the supplies (labels, buttons, packaging) leave no cutting waste and are listed
 * apart, folded.
 */
@Component({
  imports: [TranslocoDirective, RouterLink, ButtonDirective, IconComponent, PageHeaderComponent, StatusComponent, KpiComponent, StateComponent, NumberPipe, CodePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (article(); as a) {
          <dw-page-header [title]="a.reference" [subtitle]="a.name !== a.reference ? a.name : ''" back="/production/catalog" [backLabel]="t('production.articles.title')">
            <dw-status dwTitleBadge kind="member" [value]="a.is_active ? 'active' : 'suspended'" [label]="t(a.is_active ? 'common.active' : 'common.inactive')" />
            @if (canManage) { <button pButton severity="secondary" outlined type="button" (click)="edit(a)"><dw-icon>edit</dw-icon>{{ t('common.edit') }}</button> }
          </dw-page-header>

          <div class="dw-grid-3 kpis">
            <dw-kpi [label]="t('production.articles.summaryTitle')"
              [value]="a.cutting_waste.waste_kg_per_piece === null ? '—' : t('production.articles.cuttingWastePerPiece', { g: (grams(a.cutting_waste.waste_kg_per_piece) | dwNumber: 1) })"
              [hint]="fabrics().length > 0 ? t('production.articles.summaryHint') : (a.cutting_waste.waste_kg_per_piece === null ? t('production.articles.summaryEmpty') : '')" />
            <dw-kpi [label]="t('production.articles.wasteProduced')"
              [value]="a.cutting_waste.by_type.length === 0 ? '—' : a.cutting_waste.by_type.length === 1 ? (a.cutting_waste.by_type[0].waste_type?.name ?? t('production.waste.typeUnknown')) : t('production.articles.typesCount', { count: a.cutting_waste.by_type.length })"
              [hint]="a.cutting_waste.by_type.length > 1 ? t('production.articles.heaviest', { name: a.cutting_waste.by_type[0].waste_type?.name ?? t('production.waste.typeUnknown'), pct: (typeShare(a, 0) | dwNumber: 0) }) : typesHint(a, t)" />
            <dw-kpi [label]="t('production.articles.materialsHeader')" [value]="t('production.articles.fabricsCount', { count: fabrics().length })"
              [hint]="t('production.articles.othersCount', { count: others().length + supplies().length })" />
          </div>

          <!-- The waste of one piece by type: one bar, each type its share, the heaviest first. -->
          @if (a.cutting_waste.by_type.length > 1) {
            <section class="dw-panel split">
              <div class="split-head"><h2 class="dw-h3">{{ t('production.articles.byTypeTitle') }}</h2>
                <span class="dw-faint">{{ t('production.articles.cuttingWastePerPiece', { g: (grams(a.cutting_waste.waste_kg_per_piece) | dwNumber: 1) }) }}</span></div>
              <div class="stack" role="img" [attr.aria-label]="typesHint(a, t)">
                @for (g of a.cutting_waste.by_type; track $index; let i = $index) { <span [style.flex-grow]="grams(g.waste_kg_per_piece)" [style.background]="palette[i % palette.length]"></span> }
              </div>
              <ul class="legend">
                @for (g of a.cutting_waste.by_type; track $index; let i = $index) {
                  <li><i [style.background]="palette[i % palette.length]"></i><span class="lname">{{ g.waste_type?.name ?? t('production.waste.typeUnknown') }}</span>
                    <b>{{ grams(g.waste_kg_per_piece) | dwNumber: (grams(g.waste_kg_per_piece) < 1 ? 2 : 1) }} g</b><span class="dw-faint">{{ typeShare(a, i) | dwNumber: (typeShare(a, i) < 1 ? 1 : 0) }} %</span></li>
                }
              </ul>
            </section>
          }

          <section class="dw-panel">
            <div class="dw-panel-head"><h2 class="dw-h2">{{ t('production.articles.fabricsTitle') }}</h2></div>
            <p class="dw-muted intro">{{ t('production.articles.fabricsIntro') }}</p>
            @if (uncounted().length > 0) {
              <p class="missing"><dw-icon>info</dw-icon><span>{{ t('production.articles.missingData', { names: uncounted().join(', ') }) }}</span></p>
            }
            @if (fabrics().length === 0) {
              <p class="empty">{{ t('production.articles.noFabric') }}</p>
            } @else {
              <div class="scroll">
                <table class="dw-table">
                  <thead><tr>
                    <th>{{ t('production.articles.fabric') }}</th><th class="num">{{ t('production.articles.perPieceShort') }}</th>
                    <th class="num">{{ t('production.materials.width') }}</th><th class="num" [title]="t('lots.grammage')">g/m²</th>
                    <th class="num">{{ t('production.articles.fabricUsed') }}</th><th>{{ t('production.materials.wasteType') }}</th>
                  </tr></thead>
                  <tbody>
                    @for (line of fabrics(); track line.material) {
                      <tr>
                        <td class="material"><a class="dw-mono name" [routerLink]="['/production/catalog', line.material]">{{ line.reference }}</a>
                          @if (line.composition.length) { <div>@for (fibre of line.composition; track fibre.material) { <span class="chip">{{ fibre.material | dwCode: 'material' }} {{ +fibre.pct }} %</span> }</div> }</td>
                        <td class="num">{{ +line.quantity_per_piece | dwNumber: 3 }} {{ unit(line) }}</td>
                        <td class="num">{{ +line.width_cm! | dwNumber: 0 }}</td>
                        <td class="num">{{ +line.grammage_gsm! | dwNumber: 0 }}</td>
                        <td class="num"><strong>{{ grams(line.fabric_kg_per_piece) | dwNumber: 1 }} g</strong></td>
                        <td class="type">
                          @for (w of line.waste_types; track $index) {
                            <div>{{ w.waste_type?.name ?? t('production.waste.typeUnknown') }}@if (line.waste_types.length > 1 || w.method !== 'cutting') { <span class="dw-faint"> · {{ w.method === 'cutting' ? (+w.share_pct! | dwNumber: 1) + ' %' : describe(w, line.unit, t) }}</span> }</div>
                          }
                          @if (line.waste_type_inherited) { <span class="tag" [title]="t('production.materials.wasteTypeInherited')">{{ t('production.articles.default') }}</span> }</td>
                      </tr>
                    }
                  </tbody>
                  <tfoot><tr><td colspan="4">{{ t('production.articles.perPieceTotal') }}</td>
                    <td class="num"><strong>{{ grams(a.cutting_waste.fabric_kg_per_piece) | dwNumber: 1 }} g</strong></td><td></td></tr></tfoot>
                </table>
              </div>
              <p class="formula"><dw-icon>info</dw-icon><span>{{ t('production.articles.estimateNote') }}</span></p>
            }
          </section>

          @if (others().length > 0) {
            <!-- Threads, packaging, chemicals…: each waste by its own calculation (a rate, a weight per unit, all of it). -->
            <section class="dw-panel others">
              <div class="dw-panel-head"><h2 class="dw-h2">{{ t('production.articles.othersTitle') }}</h2></div>
              <p class="dw-muted intro">{{ t('production.articles.othersIntro') }}</p>
              <div class="scroll">
                <table class="dw-table">
                  <thead><tr><th>{{ t('production.materials.one') }}</th><th class="num">{{ t('production.articles.perPieceShort') }}</th>
                    <th>{{ t('production.materials.wasteProduced') }}</th><th class="num">{{ t('production.articles.wastePerPiece') }}</th></tr></thead>
                  <tbody>
                    @for (line of others(); track line.material) {
                      <tr>
                        <td class="material"><a class="dw-mono name" [routerLink]="['/production/catalog', line.material]" [title]="line.name">{{ line.reference }}</a>
                          @if (line.kind) { <span class="nature"><dw-icon>{{ kindIcon[line.kind] }}</dw-icon>{{ t('production.materials.kinds.' + line.kind) }}</span> }</td>
                        <td class="num">{{ +line.quantity_per_piece | dwNumber: 4 }} {{ unit(line) }}</td>
                        <td class="type">
                          @for (w of line.waste_types; track $index) {
                            <div>{{ w.waste_type?.name ?? t('production.waste.typeUnknown') }} <span class="dw-faint">· {{ describe(w, line.unit, t) }}</span>
                              @if (w.waste_kg_per_piece === null) { <span class="tag warn" [title]="t('production.articles.cannotCount')">{{ t('production.articles.toComplete') }}</span> }</div>
                          }
                        </td>
                        <td class="num"><strong>{{ line.waste_kg_per_piece === null ? '—' : (grams(line.waste_kg_per_piece) | dwNumber: 2) + ' g' }}</strong></td>
                      </tr>
                    }
                  </tbody>
                  @if (+(a.cutting_waste.other_kg_per_piece ?? 0) > 0) {
                    <tfoot><tr><td colspan="3">{{ t('production.articles.perPieceTotal') }}</td><td class="num"><strong>{{ grams(a.cutting_waste.other_kg_per_piece) | dwNumber: 2 }} g</strong></td></tr></tfoot>
                  }
                </table>
              </div>
            </section>
          }

          @if (supplies().length > 0) {
            <section class="dw-panel">
              <button type="button" class="fold" (click)="showSupplies.set(!showSupplies())" [attr.aria-expanded]="showSupplies()">
                <span><strong>{{ t('production.articles.suppliesTitle', { count: supplies().length }) }}</strong>
                  <span class="dw-faint"> · {{ t('production.articles.suppliesNoWaste') }}</span></span>
                <dw-icon>{{ showSupplies() ? 'expand_less' : 'expand_more' }}</dw-icon>
              </button>
              @if (showSupplies()) {
                <ul class="supplies">
                  @for (line of supplies(); track line.material) {
                    <li><a class="dw-mono" [routerLink]="['/production/catalog', line.material]" [title]="line.name">{{ line.reference }}</a><span class="qty">{{ +line.quantity_per_piece | dwNumber: 3 }} {{ unit(line) }}</span></li>
                  }
                </ul>
              }
            </section>
          }
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .kpis { margin-block-end: var(--dw-4); }
    .split { padding: var(--dw-4) var(--dw-4) var(--dw-3); }
    .split-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: var(--dw-2); margin-block-end: var(--dw-3); } .split-head h2 { margin: 0; }
    .stack { display: flex; gap: 2px; block-size: 12px; border-radius: 6px; overflow: hidden; background: var(--dw-surface-2); }
    .stack span { flex-basis: 0; min-inline-size: 3px; }
    .legend { list-style: none; display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 6px var(--dw-5); margin: var(--dw-3) 0 0; padding: 0; }
    .legend li { display: flex; align-items: center; gap: 8px; font-size: 13px; font-variant-numeric: tabular-nums; }
    .legend i { flex: none; inline-size: 10px; block-size: 10px; border-radius: 3px; }
    .legend .lname { flex: 1; min-inline-size: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .legend b { font-weight: 650; }
    .legend .dw-faint { inline-size: 40px; text-align: end; }
    .dw-panel + .dw-panel { margin-block-start: var(--dw-4); }
    .intro { margin: 0; padding: var(--dw-2) var(--dw-4) var(--dw-3); font-size: 13px; }
    .missing { display: flex; align-items: flex-start; gap: var(--dw-2); margin: 0 var(--dw-4) var(--dw-3); font-size: 13px; color: var(--dw-ink-2); }
    .missing dw-icon { flex: none; color: var(--dw-warning, var(--dw-ink-3)); }
    .empty { margin: 0; padding: var(--dw-5) var(--dw-4); text-align: center; color: var(--dw-ink-3); }
    .scroll { overflow-x: auto; } table { inline-size: 100%; }
    .num { text-align: end; white-space: nowrap; }
    .material { white-space: normal; } .material .name { display: block; max-inline-size: 240px; overflow-wrap: anywhere; }
    .type { white-space: normal; min-inline-size: 240px; } .type > div { white-space: nowrap; }
    .chip { display: inline-block; margin: 4px 4px 0 0; padding: 1px 8px; border-radius: 999px; background: var(--dw-surface-2); font-size: 12px; white-space: nowrap; }
    .material { min-inline-size: 170px; }
    .num .tag { display: block; inline-size: fit-content; margin: 2px 0 0 auto; } .tag { display: inline-block; margin-inline-start: 6px; padding: 0 6px; border-radius: 4px; background: var(--dw-surface-2); color: var(--dw-ink-3); font-size: 11px; vertical-align: 1px; }
    .formula { display: flex; align-items: flex-start; gap: 6px; margin: 0; padding: var(--dw-3) var(--dw-4); font-size: 12.5px; color: var(--dw-ink-2); border-block-start: 1px solid var(--dw-line-soft); }
    .formula dw-icon { flex: none; font-size: 16px; color: var(--dw-ink-3); }
    .nature { display: inline-flex; align-items: center; gap: 4px; margin-block-start: 2px; font-size: 12px; color: var(--dw-ink-3); } .nature dw-icon { font-size: 15px; }
    .tag.warn { background: var(--dw-warning-soft); color: var(--dw-warning); }
    .others .type { white-space: normal; }
    .fold { display: flex; justify-content: space-between; align-items: center; gap: var(--dw-3); inline-size: 100%; padding: var(--dw-3) var(--dw-4);
      background: none; border: 0; font: inherit; color: inherit; text-align: start; cursor: pointer; }
    .supplies { list-style: none; margin: 0; padding: 0 var(--dw-4) var(--dw-3); display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); column-gap: var(--dw-5); }
    .supplies li { display: flex; justify-content: space-between; gap: var(--dw-3); padding: 6px 0; border-block-end: 1px solid var(--dw-line-soft); font-size: 13px; }
    .supplies a { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .qty { flex: none; color: var(--dw-ink-2); }
  `,
})
export class ArticleDetailPage {
  readonly ulid = input.required<string>();

  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly session = inject(Session);

  protected get canManage(): boolean {
    return this.session.canEditProduction();
  }
  protected readonly article = signal<Article | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly showSupplies = signal(false);
  protected readonly kindIcon = KIND_ICON;
  /** One colour per type of waste, the heaviest the strongest. */
  protected readonly palette = ['#0f5c56', '#3f9c8f', '#8cc9bd', '#c79a3a', '#1b5b9e', '#b9c3ca'];
  /** The fabrics: what is cut from a placement (metres with laize and grammage), the heaviest first. */
  protected readonly fabrics = computed<ArticleLine[]>(() => (this.article()?.materials ?? []).filter((line) => line.fabric_kg_per_piece !== null)
    .sort((a, b) => +(b.fabric_kg_per_piece ?? 0) - +(a.fabric_kg_per_piece ?? 0)));
  /** The rest that says a waste: threads, packaging, chemicals… */
  protected readonly others = computed<ArticleLine[]>(() => (this.article()?.materials ?? []).filter((line) => line.fabric_kg_per_piece === null && line.waste_types.length > 0)
    .sort((a, b) => +(b.waste_kg_per_piece ?? -1) - +(a.waste_kg_per_piece ?? -1)));
  /** What leaves no waste estimate: buttons, labels… */
  protected readonly supplies = computed<ArticleLine[]>(() => (this.article()?.materials ?? []).filter((line) => line.fabric_kg_per_piece === null && line.waste_types.length === 0));
  /** In metres but without laize or grammage: a fabric the estimate cannot count yet, or a tape that is not one. */
  protected readonly uncounted = computed(() => this.supplies().filter((line) => line.unit === 'm').map((line) => line.reference));

  constructor() {
    queueMicrotask(() => this.load());
  }

  protected describe(line: WasteLine, unit: string, t: (key: string, params?: Record<string, unknown>) => string): string {
    return describeWaste(line, unit, t);
  }

  protected grams(kg: string | null): number {
    return kg === null ? 0 : Number(kg) * 1000;
  }

  protected unit(line: ArticleLine): string {
    return UNIT[line.unit] ?? line.unit;
  }

  /** The share of a type in the waste of one piece, in %. */
  protected typeShare(article: Article, index: number): number {
    const total = Number(article.cutting_waste.waste_kg_per_piece ?? 0);

    return total > 0 ? (Number(article.cutting_waste.by_type[index]?.waste_kg_per_piece ?? 0) / total) * 100 : 0;
  }

  protected typesHint(article: Article, t: (key: string, params?: Record<string, unknown>) => string): string {
    const types = article.cutting_waste.by_type;
    if (types.length === 0) {
      return t('production.articles.summaryEmpty');
    }

    return types.map((group) => `${types.length > 1 ? (group.waste_type?.name ?? t('production.waste.typeUnknown')) + ' : ' : ''}${t('production.articles.cuttingWastePerPiece', { g: (Number(group.waste_kg_per_piece) * 1000).toLocaleString('fr-FR', { maximumFractionDigits: 1 }) })}`).join(' · ');
  }

  protected load(): void {
    this.loading.set(this.article() === null);
    this.error.set(null);
    this.api.get<Article>(`production/articles/${this.ulid()}`).subscribe({
      next: (article) => {
        this.article.set(article);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected edit(article: Article): void {
    this.dialog.open<ArticleDialog, Article | null, Article>(ArticleDialog, { data: article }).afterClosed().subscribe((saved) => saved && this.load());
  }
}
