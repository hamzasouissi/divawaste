import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, KgPipe, NumberPipe } from '../../core/format/pipes';
import { Locale } from '../../core/i18n/i18n';
import { IconComponent } from '../../ui/icon';
import { KpiComponent } from '../../ui/page';
import { SELECT } from '../../ui/select';
import { CuttingFabric, CuttingSpread, ManufacturingOrder, ProductionLists, decimal } from './production-data';

type Field = 'length_m' | 'layers_planned' | 'layers_done' | 'efficiency_pct' | 'width_cm' | 'grammage_gsm';
type Row = Record<Field, string>;

/** "6,02" and "6.02" are the same number; an empty box is no number. */
const num = (value: string | null | undefined): number | null => (value === null || value === undefined || String(value).trim() === '' ? null : Number(String(value).replace(',', '.')));
const NEW = 'new:';

/**
 * The cutting of an OF, fabric by fabric: each fabric of the article is a card, each spread (matelas) a row typed in
 * place — length, layers planned, layers cut, efficiency, and the laize or grammage of the rolls laid when they differ
 * from the raw material's. The figures follow the typing; the row is saved when a box is left.
 *
 *     fabric (m) = layers × length · fabric (kg) = m × laize × grammage · waste = kg × (100 − efficiency) %
 */
@Component({
  selector: 'dw-cutting',
  imports: [TranslocoDirective, RouterLink, ButtonDirective, IconComponent, KpiComponent, ...SELECT, NumberPipe, KgPipe, DatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @let c = of().cutting;
      @let total = c?.totals?.done ?? c?.totals?.planned ?? null;
      <div class="dw-stats summary">
        <dw-kpi [label]="t('production.cutting.spreads')" [value]="(c?.totals?.spreads ?? 0) === 0 ? '0' : t('production.cutting.spreadsCut', { cut: (c!.totals.spreads - c!.totals.spreads_to_cut), count: c!.totals.spreads })"
          [hint]="(c?.totals?.spreads_to_cut ?? 0) > 0 ? t('production.cutting.stillToCut', { count: c!.totals.spreads_to_cut }) : ((c?.totals?.spreads ?? 0) > 0 ? t('production.cutting.allCut') : t('production.cutting.noneYet'))" />
        <dw-kpi [label]="t(c?.totals?.done ? 'production.cutting.fabricCut' : 'production.cutting.fabricPlanned')" [value]="total ? ((+total.metres | dwNumber: 1) + ' m') : '—'"
          [hint]="(total?.fabric_kg ? (total!.fabric_kg | dwKg) : '') + (c?.totals?.done && c?.totals?.planned && c!.totals.spreads_to_cut > 0 ? ' · ' + t('production.cutting.ofPlanned', { value: (+c!.totals.planned!.metres | dwNumber: 1) + ' m' }) : '')" />
        <dw-kpi [label]="t('production.cutting.expectedWaste')" [value]="total?.waste_kg ? (total!.waste_kg | dwKg) : '—'"
          [hint]="(total?.waste_pct ? t('production.cutting.ofFabric', { pct: (total?.waste_pct | dwNumber: 1) }) : '') + (c?.totals?.done && c?.totals?.planned?.waste_kg && c!.totals.spreads_to_cut > 0 ? ' · ' + t('production.cutting.ofPlanned', { value: (c!.totals.planned!.waste_kg | dwKg) }) : '')" />
        <dw-kpi [label]="t('production.cutting.weighedWaste')" [value]="weighed().kg === null ? '—' : (weighed().kg | dwKg)" [tone]="weighed().tone"
          [hint]="weighed().hint === 'over' ? t('production.waste.aboveEstimate', { kg: (weighed().gap | dwKg), pct: (weighed().pct | dwNumber: 0) })
            : weighed().hint === 'under' ? t('production.waste.belowEstimate', { kg: (weighed().gap | dwKg), pct: (weighed().pct | dwNumber: 0) })
            : weighed().hint === 'pending' ? t('production.waste.notWeighed', { count: weighed().unweighed })
            : weighed().hint === 'none' ? t('production.cutting.noWasteLot') : ''" />
      </div>

      @for (f of fabrics(); track f.material.ulid) {
        <section class="dw-panel fabric">
          <header class="head">
            <div class="who">
              <a class="ref" [routerLink]="['/production/catalog', f.material.ulid]">{{ f.material.reference }}</a>
              @if (f.material.name && f.material.name !== f.material.reference) { <span class="dw-faint name">{{ f.material.name }}</span> }
            </div>
            <div class="facts">
              @if (f.material.width_cm && f.material.grammage_gsm) {
                <span class="fact">{{ t('production.cutting.laize') }} <b>{{ +f.material.width_cm | dwNumber: 1 }} cm</b></span>
                <span class="fact"><b>{{ +f.material.grammage_gsm | dwNumber: 1 }} g/m²</b></span>
              } @else {
                <span class="fact warn"><dw-icon>warning</dw-icon>{{ t('production.cutting.noWidth') }}</span>
              }
              @if (f.article_m) { <span class="fact">{{ t('production.cutting.articleNeeds') }} <b>{{ +f.article_m | dwNumber: 1 }} m</b></span> }
              @for (w of f.waste_types; track $index) { <span class="fact">{{ w.waste_type?.name ?? t('production.waste.typeUnknown') }}@if (f.waste_types.length > 1) { <b>{{ +w.share_pct | dwNumber: 1 }} %</b> }</span> }
            </div>
          </header>

          <div class="scroll">
            <table class="dw-table grid">
              <thead><tr>
                <th class="n">#</th>
                <th class="num">{{ t('production.cutting.length') }}</th>
                <th class="num">{{ t('production.cutting.layersPlanned') }}</th>
                <th class="num">{{ t('production.cutting.layersDone') }}</th>
                <th class="num">{{ t('production.cutting.efficiency') }}</th>
                <th class="num" [title]="t('production.cutting.ownHint')">{{ t('production.cutting.laizeCm') }}</th>
                <th class="num" [title]="t('production.cutting.ownHint')">{{ t('production.cutting.grammage') }}</th>
                <th class="num">{{ t('production.cutting.fabric') }}</th>
                <th class="num">{{ t('production.cutting.waste') }}</th>
                <th>{{ t('production.cutting.state') }}</th>
                <th class="act"></th>
              </tr></thead>
              <tbody>
                @for (s of f.spreads; track s.ulid; let i = $index) {
                  @let r = row(s.ulid, base(s));
                  @let fig = live(r, f);
                  <tr [class.cut]="s.layers_done !== null" [class.saved]="flash() === s.ulid">
                    <td class="n dw-faint">{{ i + 1 }}</td>
                    @for (field of fields; track field) {
                      <td class="num">
                        @if (canManage()) {
                          <input class="cell" [class.own]="(field === 'width_cm' && s.own_width) || (field === 'grammage_gsm' && s.own_grammage)" [class.bad]="!ok(field, r[field])"
                            [value]="r[field]" [placeholder]="placeholder(field, f)" [inputMode]="field.startsWith('layers') ? 'numeric' : 'decimal'"
                            [attr.aria-label]="t('production.cutting.' + label(field)) + ' ' + (i + 1)"
                            (input)="type(s.ulid, field, $any($event.target).value)" (change)="save(f, s.ulid, s)" (keydown.enter)="$any($event.target).blur()" />
                        } @else { {{ r[field] || placeholder(field, f) || '—' }} }
                      </td>
                    }
                    <td class="num">{{ fig.metres === null ? '—' : (fig.metres | dwNumber: 1) + ' m' }}@if (fig.kg !== null) { <div class="dw-faint sub">{{ fig.kg | dwKg }}</div> }</td>
                    <td class="num"><strong>{{ fig.waste === null ? '—' : (fig.waste | dwKg) }}</strong></td>
                    <td>
                      @if (s.layers_done !== null) { <span class="state done"><dw-icon>check</dw-icon>{{ t('production.cutting.cut') }}</span>@if (s.cut_on) { <div class="dw-faint sub">{{ s.cut_on | dwDate }}</div> } }
                      @else { <span class="state">{{ t('production.cutting.toCut') }}</span> }
                    </td>
                    <td class="act">
                      @if (canManage()) {
                        <button pButton text rounded size="small" severity="secondary" class="dw-icon-btn" type="button" (click)="remove(f, s)" [attr.aria-label]="t('common.delete')" [title]="t('common.delete')"><dw-icon>delete</dw-icon></button>
                      }
                    </td>
                  </tr>
                  @if (fig.kg !== null) {
                    <tr class="how"><td></td><td colspan="10">{{ t('production.cutting.how', { layers: fig.layers, length: (fig.length | dwNumber: 2), metres: (fig.metres | dwNumber: 1), width: (fig.width / 100 | dwNumber: 2),
                      gsm: (fig.gsm | dwNumber: 0), kg: (fig.kg | dwNumber: 1), pct: (100 - fig.eff | dwNumber: 1), waste: (fig.waste | dwNumber: 2) }) }}</td></tr>
                  }
                }
                @if (adding().includes(f.material.ulid)) {
                  @let key = NEW + f.material.ulid;
                  @let r = row(key, blank(f));
                  @let fig = live(r, f);
                  <tr class="new">
                    <td class="n"><dw-icon class="dw-faint">add</dw-icon></td>
                    @for (field of fields; track field) {
                      <td class="num">
                        <input class="cell" [class.bad]="tried() === key && !ok(field, r[field], true)" [value]="r[field]" [placeholder]="placeholder(field, f)"
                          [inputMode]="field.startsWith('layers') ? 'numeric' : 'decimal'" [attr.aria-label]="t('production.cutting.' + label(field))"
                          (input)="type(key, field, $any($event.target).value)" (keydown.enter)="save(f, key, null)" />
                      </td>
                    }
                    <td class="num">{{ fig.metres === null ? '—' : (fig.metres | dwNumber: 1) + ' m' }}@if (fig.kg !== null) { <div class="dw-faint sub">{{ fig.kg | dwKg }}</div> }</td>
                    <td class="num"><strong>{{ fig.waste === null ? '—' : (fig.waste | dwKg) }}</strong></td>
                    <td colspan="2" class="act">
                      <button pButton size="small" type="button" (click)="save(f, key, null)" [disabled]="busy()">{{ t('production.cutting.add') }}</button>
                      <button pButton text rounded size="small" severity="secondary" class="dw-icon-btn" type="button" (click)="cancel(f)" [attr.aria-label]="t('common.cancel')" [title]="t('common.cancel')"><dw-icon>close</dw-icon></button>
                    </td>
                  </tr>
                }
                @if (f.spreads.length === 0 && !adding().includes(f.material.ulid)) {
                  <tr class="none"><td colspan="11">{{ t('production.cutting.noSpread') }}</td></tr>
                }
              </tbody>
              @if (f.spreads.length > 1) {
                <tfoot>
                  @for (line of [['totalPlanned', f.planned], ['totalCut', f.done]]; track $index) {
                    @if ($any(line[1]); as sum) {
                      <tr>
                        <td colspan="7">{{ t('production.cutting.' + line[0]) }}</td>
                        <td class="num">{{ +sum.metres | dwNumber: 1 }} m@if (sum.fabric_kg) { <div class="dw-faint sub">{{ sum.fabric_kg | dwKg }}</div> }</td>
                        <td class="num">{{ sum.waste_kg ? (sum.waste_kg | dwKg) : '—' }}</td><td colspan="2" class="dw-faint">@if (sum.waste_pct) { {{ +sum.waste_pct | dwNumber: 1 }} % }</td>
                      </tr>
                    }
                  }
                </tfoot>
              }
            </table>
          </div>
          @if (canManage() && !adding().includes(f.material.ulid)) {
            <div class="foot">
              <button pButton text size="small" type="button" (click)="open(f)"><dw-icon>add</dw-icon>{{ t('production.cutting.newSpread') }}</button>
              <span class="dw-faint">{{ t(f.efficiency_from === 'article' ? 'production.cutting.startsArticle' : 'production.cutting.startsCompany', { pct: (+f.efficiency_pct | dwNumber: 1) }) }}</span>
            </div>
          }
        </section>
      } @empty {
        <section class="dw-panel"><p class="empty">{{ t('production.cutting.noFabric') }}</p></section>
      }

      @if (canManage()) {
        <div class="other">
          <dw-select [placeholder]="t('production.cutting.otherFabric')" [value]="''" (valueChange)="addFabric($any($event))" [ariaLabel]="t('production.cutting.otherFabric')">
            @for (m of others(); track m.ulid) { <dw-option [value]="m.ulid" [label]="m.reference + (m.name && m.name !== m.reference ? ' — ' + m.name : '')" /> }
          </dw-select>
          <span class="dw-faint">{{ t('production.cutting.otherHint') }}</span>
        </div>
      }
      <p class="dw-faint formula">{{ t('production.cutting.formula') }}</p>
    </ng-container>
  `,
  styles: `
    :host { display: grid; gap: var(--dw-4); }
    .summary { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .fabric .head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--dw-2) var(--dw-4); padding: 14px 20px; border-block-end: 1px solid var(--dw-line-soft); }
    .who { display: flex; align-items: baseline; gap: var(--dw-2); min-width: 0; flex-wrap: wrap; }
    .ref { font-weight: 650; font-size: 15px; color: var(--dw-ink); text-decoration: none; overflow-wrap: anywhere; } .ref:hover { color: var(--dw-primary); text-decoration: underline; }
    .name { font-size: 13px; }
    .facts { display: flex; flex-wrap: wrap; gap: 6px; }
    .fact { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; background: var(--dw-surface-2); color: var(--dw-ink-2); font-size: 12.5px; white-space: nowrap; }
    .fact b { color: var(--dw-ink); font-weight: 600; }
    .fact.warn { background: var(--dw-warning-soft); color: var(--dw-warning); white-space: normal; } .fact dw-icon { font-size: 16px; }
    .scroll { overflow-x: auto; }
    table.grid { min-inline-size: 860px; }
    table.grid thead th { padding: 8px 10px; }
    table.grid tbody td { padding: 6px 10px; }
    .n { inline-size: 28px; text-align: center; }
    .act { text-align: end; white-space: nowrap; }
    .cell { inline-size: 72px; block-size: 32px; padding: 0 8px; border: 1px solid transparent; border-radius: var(--dw-radius-sm); background: var(--dw-surface-2);
      font: inherit; text-align: end; color: var(--dw-ink); font-variant-numeric: tabular-nums; transition: border-color 80ms, background 80ms; }
    .cell:hover { border-color: var(--dw-line-strong); }
    .cell:focus { outline: none; border-color: var(--dw-focus); background: var(--dw-surface); box-shadow: 0 0 0 3px var(--dw-focus-soft); }
    .cell::placeholder { color: var(--dw-ink-3); opacity: 0.8; }
    .cell.own { background: var(--dw-warning-soft); font-weight: 600; }
    .cell.bad { border-color: var(--dw-danger); background: var(--dw-danger-soft); }
    .sub { font-size: 12px; line-height: 16px; }
    .state { display: inline-flex; align-items: center; gap: 2px; padding: 1px 8px; border-radius: 999px; background: var(--dw-neutral-soft); color: var(--dw-ink-2); font-size: 12px; font-weight: 560; white-space: nowrap; }
    .state.done { background: var(--dw-success-soft); color: var(--dw-success); } .state dw-icon { font-size: 15px; }
    tr.saved td { background: var(--dw-success-soft); transition: background 400ms; }
    tr.how td { padding-block: 0 8px !important; border-block-start: 0; font-size: 12px; color: var(--dw-ink-3); white-space: normal; }
    tbody tr:has(+ tr.how) td { border-block-end: 0; }
    tr.new td { background: var(--dw-primary-soft); }
    tr.none td { padding: var(--dw-4) !important; color: var(--dw-ink-3); text-align: center; }
    .foot { display: flex; flex-wrap: wrap; align-items: center; gap: var(--dw-3); padding: 6px 12px; border-block-start: 1px solid var(--dw-line-soft); font-size: 12.5px; }
    .other { display: flex; flex-wrap: wrap; align-items: center; gap: var(--dw-3); } .other dw-select { inline-size: min(360px, 100%); }
    .other span { font-size: 12.5px; }
    .empty { margin: 0; padding: var(--dw-5) var(--dw-4); text-align: center; color: var(--dw-ink-3); }
    .formula { margin: 0; font-size: 12px; }
    @media (max-width: 1100px) { .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  `,
})
export class CuttingComponent {
  readonly of = input.required<ManufacturingOrder>();
  readonly canManage = input(false);
  readonly changed = output<ManufacturingOrder>();

  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly session = inject(Session);
  /** Numbers in the boxes as the user writes them: "6,02" in French. */
  private readonly comma = new Intl.NumberFormat(inject(Locale).intl).format(1.5).includes(',');

  protected readonly NEW = NEW;
  protected readonly fields: Field[] = ['length_m', 'layers_planned', 'layers_done', 'efficiency_pct', 'width_cm', 'grammage_gsm'];
  /** What is typed and not saved yet, by spread (or NEW + fabric for a spread being added). */
  private readonly edits = signal<Record<string, Partial<Row>>>({});
  protected readonly adding = signal<string[]>([]);
  protected readonly tried = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly flash = signal<string | null>(null);
  /** Fabrics added for this OF that no spread uses yet (an interlining, a lining…). */
  private readonly extra = signal<CuttingFabric[]>([]);

  private readonly materials = toSignal(inject(ProductionLists).materials(), { initialValue: [] });
  protected readonly fabrics = computed(() => {
    const listed = this.of().cutting?.fabrics ?? [];

    return [...listed, ...this.extra().filter((f) => !listed.some((l) => l.material.ulid === f.material.ulid))];
  });
  protected readonly others = computed(() => this.materials()
    .filter((m) => m.unit === 'm' && m.is_active && !this.fabrics().some((f) => f.material.ulid === m.ulid))
    .sort((a, b) => a.reference.localeCompare(b.reference)));

  /** The weighed cutting scraps of the OF against the estimate: above is red, below green once every lot is weighed. */
  protected readonly weighed = computed(() => {
    const e = this.of().waste?.estimated;
    const rows = (e?.by_type ?? []).filter((r) => r.lots > 0);
    const unweighed = rows.reduce((sum, r) => sum + r.unweighed_lots, 0);
    const kg = rows.some((r) => r.weighed_kg !== null) ? rows.reduce((sum, r) => sum + Number(r.weighed_kg ?? 0), 0) : null;
    const expected = rows.reduce((sum, r) => sum + Number(r.produced_kg ?? r.planned_kg ?? 0), 0);
    if (rows.length === 0 || kg === null || kg === 0) {
      return { kg: null, tone: 'neutral' as const, hint: rows.length === 0 ? 'none' : 'pending', gap: 0, pct: 0, unweighed };
    }
    const gap = Math.round((kg - expected) * 1000) / 1000;
    const hint = !(expected > 0) ? '' : gap > 0 ? 'over' : gap < 0 && unweighed === 0 ? 'under' : unweighed > 0 ? 'pending' : '';

    return { kg, tone: hint === 'over' ? ('danger' as const) : hint === 'under' ? ('success' as const) : ('neutral' as const), hint, gap: Math.abs(gap), pct: expected > 0 ? (Math.abs(gap) / expected) * 100 : 0, unweighed };
  });

  private shown(value: string | null): string {
    return value === null ? '' : this.comma ? String(+value).replace('.', ',') : String(+value);
  }

  protected base(s: CuttingSpread): Row {
    return { length_m: this.shown(s.length_m), layers_planned: String(s.layers_planned), layers_done: s.layers_done === null ? '' : String(s.layers_done),
      efficiency_pct: this.shown(s.efficiency_pct), width_cm: s.own_width ? this.shown(s.width_cm) : '', grammage_gsm: s.own_grammage ? this.shown(s.grammage_gsm) : '' };
  }

  protected blank(f: CuttingFabric): Row {
    return { length_m: '', layers_planned: '', layers_done: '', efficiency_pct: this.shown(f.efficiency_pct), width_cm: '', grammage_gsm: '' };
  }

  protected row(key: string, base: Row): Row {
    return { ...base, ...this.edits()[key] };
  }

  protected label(field: Field): string {
    return { length_m: 'length', layers_planned: 'layersPlanned', layers_done: 'layersDone', efficiency_pct: 'efficiency', width_cm: 'laizeCm', grammage_gsm: 'grammage' }[field];
  }

  /** The laize and grammage boxes show the raw material's in grey: typing one is the spread's own. */
  protected placeholder(field: Field, f: CuttingFabric): string {
    return field === 'width_cm' ? this.shown(f.material.width_cm) : field === 'grammage_gsm' ? this.shown(f.material.grammage_gsm) : '';
  }

  protected ok(field: Field, value: string, required = false): boolean {
    const n = num(value);
    if (n === null) {
      return !(required && ['length_m', 'layers_planned', 'efficiency_pct'].includes(field));
    }
    if (!Number.isFinite(n)) {
      return false;
    }
    if (field.startsWith('layers')) {
      return Number.isInteger(n) && n >= (field === 'layers_planned' ? 1 : 0);
    }

    return n > 0 && (field !== 'efficiency_pct' || n <= 100);
  }

  /** The figures of a row as typed: the layers cut when given, else those planned. */
  protected live(r: Row, f: CuttingFabric): { layers: number; length: number; metres: number | null; width: number; gsm: number; eff: number; kg: number | null; waste: number | null } {
    const length = num(r.length_m) ?? 0;
    const layers = num(r.layers_done) ?? num(r.layers_planned) ?? 0;
    const width = num(r.width_cm) ?? num(f.material.width_cm) ?? 0;
    const gsm = num(r.grammage_gsm) ?? num(f.material.grammage_gsm) ?? 0;
    const eff = num(r.efficiency_pct) ?? 0;
    const metres = length > 0 && layers > 0 ? length * layers : null;
    const kg = metres !== null && width > 0 && gsm > 0 ? (metres * width * gsm) / 100000 : null;

    return { layers, length, metres, width, gsm, eff, kg, waste: kg !== null && eff > 0 && eff <= 100 ? (kg * (100 - eff)) / 100 : null };
  }

  protected type(key: string, field: Field, value: string): void {
    this.edits.update((all) => ({ ...all, [key]: { ...all[key], [field]: value } }));
  }

  protected open(f: CuttingFabric): void {
    this.adding.update((list) => [...list, f.material.ulid]);
  }

  protected cancel(f: CuttingFabric): void {
    this.adding.update((list) => list.filter((ulid) => ulid !== f.material.ulid));
    this.forget(NEW + f.material.ulid);
  }

  protected addFabric(ulid: string): void {
    const m = this.materials().find((item) => item.ulid === ulid);
    if (!m) {
      return;
    }
    this.extra.update((list) => [...list, { material: { ulid: m.ulid, reference: m.reference, name: m.name, width_cm: m.width_cm, grammage_gsm: m.grammage_gsm },
      in_article: false, article_m: null, efficiency_pct: this.session.company()?.cutting_efficiency_pct ?? '80', efficiency_from: 'company', waste_types: [],
      spreads: [], planned: null, done: null, consumed_m: null }]);
    this.open(this.fabrics().find((f) => f.material.ulid === ulid)!);
  }

  /** Saved when a box is left (a spread) or on "Ajouter" (a new one); nothing is sent while a box is wrong. */
  protected async save(f: CuttingFabric, key: string, spread: CuttingSpread | null): Promise<void> {
    const typed = this.edits()[key];
    if (spread && (!typed || Object.entries(typed).every(([field, value]) => this.base(spread)[field as Field] === value))) {
      return;
    }
    const r = this.row(key, spread ? this.base(spread) : this.blank(f));
    if (!this.fields.every((field) => this.ok(field, r[field], true))) {
      this.tried.set(key);
      return;
    }
    const body = { material: f.material.ulid, length_m: decimal(r.length_m), layers_planned: num(r.layers_planned), layers_done: num(r.layers_done),
      efficiency_pct: decimal(r.efficiency_pct), width_cm: decimal(r.width_cm), grammage_gsm: decimal(r.grammage_gsm), cut_on: spread?.cut_on ?? null, notes: spread?.notes ?? null };
    this.busy.set(true);
    try {
      const of = await firstValueFrom(spread ? this.api.put<ManufacturingOrder>(`production/spreads/${spread.ulid}`, body)
        : this.api.post<ManufacturingOrder>(`production/manufacturing-orders/${this.of().ulid}/spreads`, body));
      this.forget(key);
      if (!spread) {
        this.adding.update((list) => list.filter((ulid) => ulid !== f.material.ulid));
      }
      this.changed.emit(of);
      const saved = spread?.ulid ?? of.cutting?.fabrics.find((item) => item.material.ulid === f.material.ulid)?.spreads.at(-1)?.ulid ?? null;
      this.flash.set(saved);
      setTimeout(() => this.flash() === saved && this.flash.set(null), 1200);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  /** Deleted at once; "Annuler" lays the same spread again. */
  protected async remove(f: CuttingFabric, s: CuttingSpread): Promise<void> {
    const of = this.of();
    try {
      this.changed.emit(await firstValueFrom(this.api.delete<ManufacturingOrder>(`production/spreads/${s.ulid}`)));
      this.forget(s.ulid);
      this.toast.undoable('production.cutting.deleted', () => void this.restore(of.ulid, f, s), { fabric: f.material.reference });
    } catch (error) {
      this.toast.error(error);
    }
  }

  private async restore(of: string, f: CuttingFabric, s: CuttingSpread): Promise<void> {
    try {
      this.changed.emit(await firstValueFrom(this.api.post<ManufacturingOrder>(`production/manufacturing-orders/${of}/spreads`, {
        material: f.material.ulid, length_m: s.length_m, layers_planned: s.layers_planned, layers_done: s.layers_done, efficiency_pct: s.efficiency_pct,
        width_cm: s.own_width ? s.width_cm : null, grammage_gsm: s.own_grammage ? s.grammage_gsm : null, cut_on: s.cut_on, notes: s.notes,
      })));
    } catch (error) {
      this.toast.error(error);
    }
  }

  private forget(key: string): void {
    this.edits.update((all) => Object.fromEntries(Object.entries(all).filter(([item]) => item !== key)));
    if (this.tried() === key) {
      this.tried.set(null);
    }
  }
}
