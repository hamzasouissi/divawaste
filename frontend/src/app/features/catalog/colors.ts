import { ChangeDetectionStrategy, Component, computed, inject, output, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Paginator, PaginatorState } from 'primeng/paginator';

import { Api, PageMeta } from '../../core/api/api';
import { CatalogueColor, Color, ColorMatch } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { hexToRgb, normalizeHex, rgbToHex } from '../../core/format/color';
import { LocalizedPipe } from '../../core/format/pipes';
import { Toast } from '../../core/feedback/toast';
import { Locale } from '../../core/i18n/i18n';
import { CheckboxComponent } from '../../ui/checkbox';
import { Confirm } from '../../ui/confirm';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { MenuAction, MenuComponent } from '../../ui/menu';
import { SearchComponent } from '../../ui/search';
import { SELECT } from '../../ui/select';
import { StateComponent } from '../../ui/state';
import { SwatchComponent } from '../../ui/swatch';

const COLLECTIONS = ['coated', 'uncoated', 'metallic', 'pastels_neons', 'skin', 'color_of_the_year'];

/** Name of a collection: translated when the application knows it, as the import named it otherwise. */
function collectionName(transloco: TranslocoService, collection: string | null): string {
  if (!collection) {
    return '';
  }

  return COLLECTIONS.includes(collection) ? transloco.translate('colors.collections.' + collection) : collection.replace(/_/g, ' ');
}

interface CataloguePage {
  meta: PageMeta & { collections: { collection: string; colors: number }[] };
}

/**
 * The reference catalogue, read page by page. A colour is taken into the company's list from here; the catalogue
 * itself is never changed. Closes with `true` when the company's list changed.
 */
@Component({
  imports: [TranslocoDirective, ButtonDirective, Paginator, SearchComponent, StateComponent, SwatchComponent, IconComponent, ...SELECT, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('colors.catalogue.title') }}</dw-dialog-title>
      <div class="dw-dialog-body">
        <div class="filters">
          <dw-search [placeholder]="t('colors.catalogue.search')" [query]="search()" (queryChange)="search.set($event); load(1)" />
          <dw-select class="collection" [value]="collection()" (valueChange)="pick($event)" [ariaLabel]="t('colors.collection')">
            <dw-option [value]="null" [label]="t('colors.catalogue.allCollections')" />
            @for (item of collections(); track item.collection) { <dw-option [value]="item.collection" [label]="name(item.collection) + ' (' + item.colors + ')'" /> }
          </dw-select>
        </div>
        <dw-state [loading]="loading() && colors().length === 0" [error]="error()" [empty]="!loading() && colors().length === 0" icon="palette"
          [emptyTitle]="t('colors.catalogue.none')" [emptyHint]="t('colors.catalogue.noneHint')" (retry)="load(page())">
          <ul class="grid" [class.busy]="loading()" [attr.aria-busy]="loading()">
            @for (color of colors(); track color.code) {
              <li>
                <dw-swatch size="large" [hex]="color.hex_color" />
                <div class="what">
                  <strong class="dw-mono" [title]="color.reference_code">{{ color.reference_code }}</strong>
                  <span class="dw-faint">{{ name(color.collection) }} · <span class="dw-mono">{{ color.hex_color }}</span></span>
                </div>
                @if (color.taken_as) {
                  <span class="taken"><dw-icon>check</dw-icon>{{ t('colors.catalogue.taken') }}</span>
                } @else if (canManage) {
                  <button pButton text size="small" type="button" [disabled]="taking() === color.code" (click)="take(color)" [title]="t('colors.catalogue.take')"><dw-icon>add</dw-icon>{{ t('common.add') }}</button>
                }
              </li>
            }
          </ul>
        </dw-state>
        @if (total() > perPage) {
          <p-paginator [first]="(page() - 1) * perPage" [rows]="perPage" [totalRecords]="total()" [pageLinkSize]="5" (onPageChange)="turn($event)" [attr.aria-label]="t('common.pagination')" />
        }
        <p class="dw-faint legal">{{ t('colors.catalogue.count', { total: total() }) }} {{ t('colors.disclaimer') }}</p>
      </div>
      <div class="dw-dialog-foot">
        <button pButton type="button" (click)="ref.close(changed)">{{ t('common.close') }}</button>
      </div>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { width: min(920px, 92vw); height: min(640px, 72vh); display: flex; flex-direction: column; gap: var(--dw-3); overflow: hidden; }
    dw-state { display: block; flex: 1 1 auto; min-height: 0; overflow: auto; padding-inline-end: 4px; }
    p-paginator, .legal, .filters { flex: none; }
    .filters { display: flex; gap: var(--dw-3); flex-wrap: wrap; } .filters dw-search { flex: 1 1 220px; } .collection { flex: 0 1 240px; }
    .grid { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--dw-3); grid-template-columns: repeat(auto-fill, minmax(132px, 1fr)); }
    .grid.busy { opacity: 0.55; }
    .grid li { display: flex; flex-direction: column; min-width: 0; border: 1px solid var(--dw-line); border-radius: var(--dw-radius); background: var(--dw-surface); overflow: hidden; }
    .what { display: grid; gap: 1px; padding: 8px 10px 4px; font-size: 12px; min-width: 0; }
    .what strong { font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .grid li button, .taken { margin: 0 6px 6px; align-self: flex-start; }
    .taken { display: inline-flex; align-items: center; gap: 4px; padding: 5px 6px; font-size: 12px; color: var(--dw-success); }
    .legal { margin: 0; font-size: 12px; }
  `,
})
export class ColorCatalogueDialog {
  protected readonly ref = inject(DialogRef);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly transloco = inject(TranslocoService);

  protected readonly canManage = inject(Session).can('waste_types.manage');
  protected readonly perPage = 60;
  protected readonly search = signal('');
  protected readonly collection = signal<string | null>(null);
  protected readonly page = signal(1);
  protected readonly total = signal(0);
  protected readonly colors = signal<CatalogueColor[]>([]);
  protected readonly collections = signal<{ collection: string; colors: number }[]>([]);
  protected readonly loading = signal(false);
  protected readonly error = signal<unknown>(null);
  protected readonly taking = signal<string | null>(null);
  protected changed = false;
  private request = 0;

  constructor() {
    this.load(1);
  }

  protected name(collection: string | null): string {
    return collectionName(this.transloco, collection);
  }

  protected pick(collection: string | null): void {
    // The selector also announces its initial value: only a real change reloads.
    if (collection !== this.collection()) {
      this.collection.set(collection);
      this.load(1);
    }
  }

  protected turn(state: PaginatorState): void {
    this.load((state.page ?? 0) + 1);
  }

  protected load(page: number): void {
    const request = ++this.request;
    this.page.set(page);
    this.loading.set(true);
    this.error.set(null);
    this.api.list<CatalogueColor, CataloguePage>('colors/catalogue', { search: this.search(), collection: this.collection(), page, per_page: this.perPage }).subscribe({
      next: (result) => {
        // A slower answer to an earlier search must not replace the latest one.
        if (request !== this.request) {
          return;
        }
        this.colors.set(result.data);
        this.total.set(result.meta.total);
        this.collections.set(result.meta.collections);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        if (request === this.request) {
          this.error.set(error);
          this.loading.set(false);
        }
      },
    });
  }

  protected async take(color: CatalogueColor): Promise<void> {
    this.taking.set(color.code);
    try {
      const mine = await firstValueFrom(this.api.post<Color>(`colors/catalogue/${color.code}/take`));
      this.colors.update((list) => list.map((item) => (item.code === color.code ? { ...item, taken_as: mine.code } : item)));
      this.changed = true;
      this.toast.success('colors.catalogue.added');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.taking.set(null);
    }
  }
}

/**
 * A colour of the company. A custom colour has a name, an optional code and an optional tint, typed as HEX or RGB
 * or chosen with the picker. A colour taken from the catalogue keeps its reference and tint: only its name changes.
 * Closes with `true` when the company's list changed.
 */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, SwatchComponent, ...FIELD, ...DIALOG_PARTS, CheckboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'colors.edit' : 'colors.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="save()" novalidate>
        <div class="dw-dialog-body">
          <dw-field [label]="t('fields.name')"><input pInputText formControlName="name" maxlength="60" required /></dw-field>
          @if (locked) {
            <div class="locked">
              <dw-swatch [hex]="data?.hex_color" />
              <span><strong class="dw-mono">{{ data?.reference_code }}</strong> · {{ name(data?.collection ?? null) }} · <span class="dw-mono">{{ data?.hex_color }}</span></span>
            </div>
            <p class="dw-faint note">{{ t('colors.lockedHint') }}</p>
          } @else {
            <dw-field [label]="t('colors.code')">
              <input pInputText formControlName="reference_code" maxlength="60" />
              <small dwHint>{{ t('colors.codeHint') }}</small>
            </dw-field>
            <dw-checkbox formControlName="tinted">{{ t('colors.withTint') }}</dw-checkbox>
            @if (form.controls.tinted.value) {
              <div class="tint">
                <input type="color" class="picker" [value]="hex() ?? '#000000'" (input)="fromPicker($any($event.target).value)" [attr.aria-label]="t('colors.tint')" />
                <dw-field class="hex" label="HEX"><input pInputText formControlName="hex_color" maxlength="7" placeholder="#0F4C81" class="dw-mono" /></dw-field>
                <dw-field class="channel" label="R"><input pInputText formControlName="r" inputmode="numeric" maxlength="3" /></dw-field>
                <dw-field class="channel" label="G"><input pInputText formControlName="g" inputmode="numeric" maxlength="3" /></dw-field>
                <dw-field class="channel" label="B"><input pInputText formControlName="b" inputmode="numeric" maxlength="3" /></dw-field>
              </div>
              @if (!hex()) { <p class="bad" role="alert">{{ t('colors.tintInvalid') }}</p> }
            }
            <dw-field [label]="t('colors.pantone')">
              <input pInputText formControlName="pantone_reference" maxlength="40" placeholder="19-4052 TCX" />
              <small dwHint>{{ t('colors.pantoneHint') }}</small>
            </dw-field>

            @if (form.controls.tinted.value && hex()) {
              <section class="matches">
                <div class="dw-row">
                  <strong>{{ t('colors.match.title') }}</strong>
                  <button pButton text size="small" type="button" [disabled]="matching()" (click)="match()">{{ t('colors.match.find') }}</button>
                </div>
                @if (matches(); as found) {
                  @if (found.for !== hex()) {
                    <p class="dw-faint note">{{ t('colors.match.stale') }}</p>
                  } @else if (found.colors.length === 0) {
                    <p class="dw-faint note">{{ t('colors.match.none') }}</p>
                  } @else {
                    <ul>
                      @for (color of found.colors; track color.code) {
                        <li>
                          <dw-swatch [hex]="color.hex_color" />
                          <span class="ref"><strong class="dw-mono">{{ color.reference_code }}</strong> <span class="dw-faint">{{ name(color.collection) }} · {{ color.hex_color }}</span></span>
                          <span class="dw-faint delta" [title]="t('colors.match.deltaHint')">ΔE {{ color.delta_e.toFixed(1) }}</span>
                          <button pButton text size="small" type="button" [disabled]="busy()" (click)="take(color)">{{ t('colors.catalogue.take') }}</button>
                        </li>
                      }
                    </ul>
                    <p class="dw-faint note">{{ t('colors.match.approximate') }}</p>
                  }
                }
              </section>
            }
          }
        </div>
        <div class="dw-dialog-foot">
          <button pButton severity="secondary" outlined type="button" (click)="ref.close(changed)">{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy() || (!locked && form.controls.tinted.value && !hex())">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { display: grid; gap: var(--dw-3); width: min(520px, 88vw); }
    .tint { display: flex; align-items: flex-end; gap: var(--dw-2); flex-wrap: wrap; }
    .picker { width: 56px; height: var(--dw-control); padding: 2px; border: 1px solid var(--dw-line); border-radius: var(--dw-radius); background: var(--dw-surface); cursor: pointer; }
    .hex { width: 112px; } .channel { width: 64px; }
    .locked { display: flex; align-items: center; gap: var(--dw-2); padding: var(--dw-2) var(--dw-3); border: 1px solid var(--dw-line); border-radius: var(--dw-radius); background: var(--dw-surface-2); }
    .note { margin: 0; font-size: 12px; } .bad { margin: 0; color: var(--dw-danger); font-size: 12px; }
    .matches { display: grid; gap: var(--dw-2); padding-block-start: var(--dw-3); border-block-start: 1px solid var(--dw-line-soft); }
    .matches .dw-row { justify-content: space-between; }
    .matches ul { list-style: none; margin: 0; padding: 0; }
    .matches li { display: flex; align-items: center; gap: var(--dw-2); padding: 4px 0; font-size: 13px; }
    .ref { flex: 1; min-width: 0; } .ref strong { margin-inline-end: 6px; } .delta { font-variant-numeric: tabular-nums; white-space: nowrap; }
  `,
})
export class ColorDialog {
  protected readonly data = dialogData<Color | null>();
  protected readonly ref = inject(DialogRef);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly transloco = inject(TranslocoService);
  private readonly locale = inject(Locale);

  /** Taken from the catalogue: the reference and the tint are the catalogue's. */
  protected readonly locked = this.data !== null && this.data.color_system !== 'custom';
  protected readonly busy = signal(false);
  protected readonly matching = signal(false);
  protected readonly matches = signal<{ for: string; colors: ColorMatch[] } | null>(null);
  protected changed = false;

  private readonly start = this.data?.hex_color ?? (this.data ? null : '#0F766E');
  protected readonly form = inject(FormBuilder).nonNullable.group({
    name: [this.data ? this.locale.pick(this.data.name) : '', [Validators.required, Validators.maxLength(60)]],
    reference_code: [this.data?.reference_code ?? ''],
    tinted: [this.start !== null],
    hex_color: [this.start ?? '#0F766E'],
    r: [String(hexToRgb(this.start ?? '#0F766E').r)],
    g: [String(hexToRgb(this.start ?? '#0F766E').g)],
    b: [String(hexToRgb(this.start ?? '#0F766E').b)],
    pantone_reference: [this.data?.pantone_reference ?? ''],
  });
  /** The tint as it will be saved; null while what is typed is not a colour. */
  protected readonly hex = signal<string | null>(normalizeHex(this.form.controls.hex_color.value));

  constructor() {
    const { hex_color, r, g, b } = this.form.controls;
    // HEX and RGB are two writings of the same tint: typing one rewrites the other.
    hex_color.valueChanges.subscribe((value) => {
      const hex = normalizeHex(value);
      this.hex.set(hex);
      if (hex) {
        const rgb = hexToRgb(hex);
        r.setValue(String(rgb.r), { emitEvent: false });
        g.setValue(String(rgb.g), { emitEvent: false });
        b.setValue(String(rgb.b), { emitEvent: false });
      }
    });
    [r, g, b].forEach((channel) => channel.valueChanges.subscribe(() => {
      const hex = rgbToHex({ r: r.value.trim(), g: g.value.trim(), b: b.value.trim() });
      this.hex.set(hex);
      if (hex) {
        hex_color.setValue(hex, { emitEvent: false });
      }
    }));
  }

  protected name(collection: string | null): string {
    return collectionName(this.transloco, collection);
  }

  protected fromPicker(value: string): void {
    this.form.controls.hex_color.setValue(value.toUpperCase());
  }

  /** Suggestions only: nothing in the form changes. */
  protected match(): void {
    const hex = this.hex();
    if (!hex) {
      return;
    }
    this.matching.set(true);
    this.api.get<ColorMatch[]>('colors/match', { hex, limit: 5 }).subscribe({
      next: (colors) => {
        this.matches.set({ for: hex, colors });
        this.matching.set(false);
      },
      error: (error: unknown) => {
        this.toast.error(error);
        this.matching.set(false);
      },
    });
  }

  /** Adds the suggested catalogue colour to the company's list, beside the one being typed. */
  protected async take(color: ColorMatch): Promise<void> {
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.post<Color>(`colors/catalogue/${color.code}/take`));
      this.changed = true;
      this.toast.success('colors.catalogue.added');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected async save(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const body = this.locked
      ? { name: value.name.trim() }
      : { name: value.name.trim(), reference_code: value.reference_code.trim() || null, hex_color: value.tinted ? this.hex() : null, pantone_reference: value.pantone_reference.trim() || null };
    try {
      await firstValueFrom(this.data ? this.api.put<Color>(`colors/${this.data.code}`, body) : this.api.post<Color>('colors', body));
      this.toast.success('colors.saved');
      this.ref.close(true);
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

/** The colours offered on lots, waste types, materials and zones: the defaults, the company's own, and the way to the catalogue. */
@Component({
  selector: 'dw-colors',
  imports: [TranslocoDirective, ButtonDirective, LocalizedPipe, SearchComponent, StateComponent, SwatchComponent, IconComponent, MenuComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <div class="head">
        <p class="dw-muted">{{ t('colors.hint') }}</p>
        <div class="dw-row">
          <dw-search [placeholder]="t('colors.search')" [query]="search()" (queryChange)="search.set($event)" />
          @if (catalogueSize() > 0) { <button pButton severity="secondary" outlined type="button" (click)="browse()"><dw-icon>palette</dw-icon>{{ t('colors.catalogue.open') }}</button> }
          @if (canManage) { <button pButton type="button" (click)="edit(null)"><dw-icon>add</dw-icon>{{ t('colors.new') }}</button> }
        </div>
      </div>
      <dw-state [loading]="loading()" [error]="error()" [empty]="!loading() && shown().length === 0" icon="palette" [emptyTitle]="t('colors.noneFound')" (retry)="load()">
        <ul class="grid">
          @for (color of shown(); track color.code) {
            <li [class.off]="!color.is_active">
              <dw-swatch size="large" [hex]="color.hex_color" />
              <div class="what">
                <strong [title]="color.name | dwLocalized">{{ color.name | dwLocalized }}</strong>
                <span class="dw-faint dw-mono">{{ detail(color) || t('colors.noTint') }}</span>
                <span class="dw-faint">{{ origin(color) }}@if (!color.is_active) { · {{ t('colors.withdrawn') }} }</span>
              </div>
              @if (color.is_custom && canManage) {
                <button pButton text rounded severity="secondary" size="small" class="dw-icon-btn more" type="button" (click)="menu.open($event, actions(color))" [attr.aria-label]="t('common.actions')"><dw-icon>more_vert</dw-icon></button>
              }
            </li>
          }
        </ul>
      </dw-state>
      <dw-menu #menu />
    </ng-container>
  `,
  styles: `
    .head { display: flex; align-items: center; justify-content: space-between; gap: var(--dw-3); flex-wrap: wrap; padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }
    .head p { margin: 0; flex: 1 1 320px; }
    .grid { list-style: none; margin: 0; padding: var(--dw-4); display: grid; gap: var(--dw-3); grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); }
    .grid li { position: relative; display: flex; flex-direction: column; border: 1px solid var(--dw-line); border-radius: var(--dw-radius); background: var(--dw-surface); overflow: hidden; }
    .what { display: grid; gap: 1px; padding: 8px 34px 10px 10px; font-size: 12px; min-width: 0; }
    .what strong { font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .more { position: absolute; inset-block-end: 4px; inset-inline-end: 2px; }
    li.off dw-swatch { opacity: 0.35; } li.off strong { color: var(--dw-ink-3); }
  `,
})
export class ColorsComponent {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);
  private readonly vocabulary = inject(ReferenceData);
  private readonly transloco = inject(TranslocoService);
  private readonly locale = inject(Locale);

  /** Emitted when the list changed, for whoever shows colours elsewhere on the page. */
  readonly changed = output<void>();

  protected readonly canManage = inject(Session).can('waste_types.manage');
  protected readonly search = signal('');
  protected readonly colors = signal<Color[]>([]);
  protected readonly loading = signal(false);
  protected readonly error = signal<unknown>(null);
  /** Colours in the reference catalogue; 0 hides the way to it. */
  protected readonly catalogueSize = signal(0);

  /** The list is short: it is filtered as the user types, on the name, the reference and the HEX value. */
  protected readonly shown = computed(() => {
    const term = this.search().trim().toLowerCase().replace(/^pantone\s+/, '');
    if (term === '') {
      return this.colors();
    }

    return this.colors().filter((color) => [this.locale.pick(color.name), color.reference_code, color.pantone_reference, color.hex_color]
      .some((value) => (value ?? '').toLowerCase().includes(term) || (value ?? '').toLowerCase().includes('#' + term)));
  });

  constructor() {
    this.load();
    this.api.list<CatalogueColor>('colors/catalogue', { per_page: 1 }).subscribe({ next: (page) => this.catalogueSize.set(page.meta.total), error: () => this.catalogueSize.set(0) });
  }

  /** Second line of a tile: the references the name does not already give, then the HEX value. */
  protected detail(color: Color): string {
    const name = this.locale.pick(color.name).toLowerCase();
    const references = color.color_system === 'pantone' ? [`Pantone ${color.reference_code}`] : [color.reference_code, color.pantone_reference ? `Pantone ${color.pantone_reference}` : null];

    return [...references.filter((reference) => reference && !name.includes(reference.toLowerCase())), color.hex_color].filter(Boolean).join(' · ');
  }

  protected origin(color: Color): string {
    if (!color.is_custom) {
      return this.transloco.translate('colors.default');
    }

    return color.color_system === 'custom' ? this.transloco.translate('colors.custom') : this.transloco.translate('colors.fromCatalogue', { collection: collectionName(this.transloco, color.collection) });
  }

  protected load(): void {
    this.loading.set(this.colors().length === 0);
    this.error.set(null);
    this.api.get<Color[]>('colors').subscribe({
      next: (colors) => {
        this.colors.set(colors);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected actions(color: Color): MenuAction[] {
    return [
      { label: this.transloco.translate('common.edit'), icon: 'edit', run: () => this.edit(color) },
      { label: this.transloco.translate(color.is_active ? 'colors.withdraw' : 'colors.restore'), icon: color.is_active ? 'visibility_off' : 'visibility', run: () => void this.toggle(color) },
      { separator: true },
      { label: this.transloco.translate('common.delete'), icon: 'delete', danger: true, run: () => void this.remove(color) },
    ];
  }

  protected edit(color: Color | null): void {
    this.dialog.open<ColorDialog, Color | null, boolean>(ColorDialog, { data: color }).afterClosed().subscribe((changed) => changed && this.refresh());
  }

  protected browse(): void {
    this.dialog.open<ColorCatalogueDialog, undefined, boolean>(ColorCatalogueDialog).afterClosed().subscribe((changed) => changed && this.refresh());
  }

  /** Withdrawn colours are no longer offered; what already carries one keeps it. */
  private async toggle(color: Color): Promise<void> {
    try {
      await firstValueFrom(this.api.put(`colors/${color.code}`, {
        name: this.locale.pick(color.name), reference_code: color.reference_code, hex_color: color.hex_color, pantone_reference: color.pantone_reference, is_active: !color.is_active,
      }));
      this.refresh();
    } catch (error) {
      this.toast.error(error);
    }
  }

  private async remove(color: Color): Promise<void> {
    const name = this.locale.pick(color.name);
    if (!(await this.confirm.ask({ title: this.transloco.translate('colors.deleteTitle', { name }), message: this.transloco.translate('colors.deleteMessage'), confirmLabel: this.transloco.translate('common.delete'), danger: true }))) {
      return;
    }
    try {
      await firstValueFrom(this.api.delete(`colors/${color.code}`));
      this.toast.success('colors.deleted');
      this.refresh();
    } catch (error) {
      // When something still carries it, the server says so: the colour stays and can be withdrawn instead.
      this.toast.error(error);
    }
  }

  private refresh(): void {
    // The forms (lot, waste type, material, zone) read colours from the shared vocabulary: drop it so they see the change.
    this.vocabulary.refreshVocabulary();
    this.load();
    this.changed.emit();
  }
}
