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
import { Article, ArticleLine } from './production-data';

/** How a quantity per piece is written: metres, kilograms, pieces. */
const UNIT: Record<string, string> = { m: 'm', kg: 'kg', piece: 'pc' };

/**
 * An article: what one piece consumes of each raw material and, for its fabrics, the cutting waste the placement is
 * expected to leave and the type of waste it gives. Consumption (m) × laize × grammage × (100 − efficiency) %.
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
              [hint]="a.cutting_waste.fabric_kg_per_piece === null ? t('production.articles.summaryNone')
                : t('production.articles.ofFabric', { g: (grams(a.cutting_waste.fabric_kg_per_piece) | dwNumber: 0), pct: (share(a) | dwNumber: 0) })" />
            <dw-kpi [label]="t('production.articles.wasteProduced')"
              [value]="a.cutting_waste.by_type.length === 0 ? '—' : a.cutting_waste.by_type.length === 1 ? (a.cutting_waste.by_type[0].waste_type?.name ?? t('production.waste.typeUnknown')) : t('production.articles.typesCount', { count: a.cutting_waste.by_type.length })"
              [hint]="typesHint(a, t)" />
            <dw-kpi [label]="t('production.articles.materialsHeader')" [value]="t('production.articles.fabricsCount', { count: fabrics().length })"
              [hint]="t('production.articles.suppliesCount', { count: supplies().length })" />
          </div>

          <section class="dw-panel">
            <div class="dw-panel-head"><h2 class="dw-h2">{{ t('production.articles.fabricsTitle') }}</h2></div>
            <p class="dw-muted intro">{{ t('production.articles.fabricsIntro') }}</p>
            @if (uncounted().length > 0) {
              <p class="missing"><dw-icon>info</dw-icon><span>{{ t('production.articles.missingData', { names: uncounted().join(', ') }) }}</span></p>
            }
            @if (fabrics().length === 0) {
              <p class="empty">{{ t('production.articles.summaryNone') }}</p>
            } @else {
              <div class="scroll">
                <table class="dw-table">
                  <thead><tr>
                    <th>{{ t('production.articles.fabric') }}</th><th class="num">{{ t('production.articles.perPieceShort') }}</th>
                    <th class="num">{{ t('production.materials.width') }}</th><th class="num" [title]="t('lots.grammage')">g/m²</th><th class="num">{{ t('production.articles.efficiency') }}</th>
                    <th class="num used">{{ t('production.articles.fabricUsed') }}</th><th class="num">{{ t('production.articles.wastePerPiece') }}</th><th>{{ t('production.materials.wasteType') }}</th>
                  </tr></thead>
                  <tbody>
                    @for (line of fabrics(); track line.material) {
                      <tr>
                        <td class="material"><a class="dw-mono name" [routerLink]="['/production/catalog', line.material]">{{ line.reference }}</a>
                          @if (line.composition.length) { <div>@for (fibre of line.composition; track fibre.material) { <span class="chip">{{ fibre.material | dwCode: 'material' }} {{ +fibre.pct }} %</span> }</div> }</td>
                        <td class="num">{{ +line.quantity_per_piece | dwNumber: 3 }} {{ unit(line) }}</td>
                        <td class="num">{{ +line.width_cm! | dwNumber: 0 }}</td>
                        <td class="num">{{ +line.grammage_gsm! | dwNumber: 0 }}</td>
                        <td class="num">{{ +line.efficiency_applied! | dwNumber: 0 }} %@if (line.efficiency_pct === null) { <span class="tag">{{ t('production.articles.default') }}</span> }</td>
                        <td class="num used">{{ grams(line.fabric_kg_per_piece) | dwNumber: 1 }} g</td>
                        <td class="num"><strong>{{ grams(line.waste_kg_per_piece) | dwNumber: 1 }} g</strong></td>
                        <td class="type">{{ line.waste_type?.name ?? t('production.waste.typeUnknown') }}@if (line.waste_type_inherited) { <span class="tag" [title]="t('production.materials.wasteTypeInherited')">{{ t('production.articles.default') }}</span> }</td>
                      </tr>
                    }
                  </tbody>
                  <tfoot><tr><td colspan="5">{{ t('production.articles.perPieceTotal') }}</td>
                    <td class="num used">{{ grams(a.cutting_waste.fabric_kg_per_piece) | dwNumber: 1 }} g</td><td class="num"><strong>{{ grams(a.cutting_waste.waste_kg_per_piece) | dwNumber: 1 }} g</strong></td><td></td></tr></tfoot>
                </table>
              </div>
              <p class="formula dw-faint">{{ t('production.articles.cuttingFormula') }}</p>
            }
          </section>

          @if (supplies().length > 0) {
            <section class="dw-panel">
              <button type="button" class="fold" (click)="showSupplies.set(!showSupplies())" [attr.aria-expanded]="showSupplies()">
                <span><strong>{{ t('production.articles.suppliesTitle', { count: supplies().length }) }}</strong>
                  <span class="dw-faint"> · {{ t('production.articles.suppliesHint') }}</span></span>
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
    .dw-panel + .dw-panel { margin-block-start: var(--dw-4); }
    .intro { margin: 0; padding: var(--dw-2) var(--dw-4) var(--dw-3); font-size: 13px; }
    .missing { display: flex; align-items: flex-start; gap: var(--dw-2); margin: 0 var(--dw-4) var(--dw-3); font-size: 13px; color: var(--dw-ink-2); }
    .missing dw-icon { flex: none; color: var(--dw-warning, var(--dw-ink-3)); }
    .empty { margin: 0; padding: var(--dw-5) var(--dw-4); text-align: center; color: var(--dw-ink-3); }
    .scroll { overflow-x: auto; } table { inline-size: 100%; }
    .num { text-align: end; white-space: nowrap; }
    .material { white-space: normal; } .material .name { display: block; max-inline-size: 240px; overflow-wrap: anywhere; }
    .type { white-space: nowrap; }
    /* The fabric weight is also in the summary above: the first column to go when the screen is narrower. */
    @media (max-width: 1400px) { .used { display: none; } }
    .chip { display: inline-block; margin: 4px 4px 0 0; padding: 1px 8px; border-radius: 999px; background: var(--dw-surface-2); font-size: 12px; }
    .num .tag { display: block; inline-size: fit-content; margin: 2px 0 0 auto; } .tag { display: inline-block; margin-inline-start: 6px; padding: 0 6px; border-radius: 4px; background: var(--dw-surface-2); color: var(--dw-ink-3); font-size: 11px; vertical-align: 1px; }
    .formula { margin: 0; padding: var(--dw-2) var(--dw-4) var(--dw-3); font-size: 12px; }
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
  /** The fabrics: what is cut from a placement, the heaviest waste first. */
  protected readonly fabrics = computed<ArticleLine[]>(() => (this.article()?.materials ?? []).filter((line) => line.waste_kg_per_piece !== null)
    .sort((a, b) => +b.waste_kg_per_piece! - +a.waste_kg_per_piece!));
  protected readonly supplies = computed<ArticleLine[]>(() => (this.article()?.materials ?? []).filter((line) => line.waste_kg_per_piece === null));
  /** In metres but without laize or grammage: a fabric the estimate cannot count yet, or a tape that is not one. */
  protected readonly uncounted = computed(() => this.supplies().filter((line) => line.unit === 'm').map((line) => line.reference));

  constructor() {
    queueMicrotask(() => this.load());
  }

  protected grams(kg: string | null): number {
    return kg === null ? 0 : Number(kg) * 1000;
  }

  protected unit(line: ArticleLine): string {
    return UNIT[line.unit] ?? line.unit;
  }

  /** The share of the fabric consumed that becomes waste. */
  protected share(article: Article): number {
    const fabric = Number(article.cutting_waste.fabric_kg_per_piece ?? 0);

    return fabric > 0 ? (Number(article.cutting_waste.waste_kg_per_piece ?? 0) / fabric) * 100 : 0;
  }

  protected typesHint(article: Article, t: (key: string, params?: Record<string, unknown>) => string): string {
    const types = article.cutting_waste.by_type;
    if (types.length === 0) {
      return t('production.articles.summaryNone');
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
