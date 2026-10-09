import { provideZonelessChangeDetection } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { DynamicDialogConfig, DynamicDialogRef } from 'primeng/dynamicdialog';
import { of } from 'rxjs';

import { Api } from '../../core/api/api';
import { Color } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { Locale } from '../../core/i18n/i18n';
import { Confirm } from '../../ui/confirm';
import { Dialogs } from '../../ui/dialog';
import { ColorCatalogueDialog, ColorDialog, ColorsComponent } from './colors';

const color = (over: Partial<Color>): Color => ({
  code: 'c_1', name: { fr: 'Bleu maison', en: 'Bleu maison' }, color_system: 'custom', collection: null, reference_code: null, hex_color: '#0F4C81', rgb: { r: 15, g: 76, b: 129 },
  lab: null, pantone_reference: null, source: 'user', verification_status: 'unverified', is_custom: true, is_catalogue: false, is_active: true, ...over,
});

describe('colours of the company', () => {
  let api: { get: ReturnType<typeof vi.fn>; list: ReturnType<typeof vi.fn>; post: ReturnType<typeof vi.fn>; put: ReturnType<typeof vi.fn>; delete: ReturnType<typeof vi.fn> };
  let ref: { close: ReturnType<typeof vi.fn> };
  let vocabulary: { refreshVocabulary: ReturnType<typeof vi.fn> };

  // The classes are exercised without their templates: what is under test is what they send and what they keep.
  const create = <T>(type: new () => T, data: unknown = null): T => {
    TestBed.overrideProvider(DynamicDialogConfig, { useValue: { data } });

    return TestBed.runInInjectionContext(() => new type());
  };
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const inner = (instance: unknown): any => instance;

  beforeEach(() => {
    api = { get: vi.fn(() => of([])), list: vi.fn(() => of({ data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 60, collections: [] } })), post: vi.fn(() => of(color({}))), put: vi.fn(() => of(color({}))), delete: vi.fn(() => of(undefined)) };
    ref = { close: vi.fn() };
    vocabulary = { refreshVocabulary: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideZonelessChangeDetection(),
        { provide: Api, useValue: api }, { provide: DynamicDialogRef, useValue: ref }, { provide: DynamicDialogConfig, useValue: { data: null } },
        { provide: Toast, useValue: { success: vi.fn(), error: vi.fn(), applyValidation: () => false } },
        { provide: TranslocoService, useValue: { translate: (key: string) => key } },
        { provide: Locale, useValue: { pick: (value: Record<string, string> | null, fallback = '') => value?.['fr'] ?? fallback } },
        { provide: Session, useValue: { can: () => true } }, { provide: ReferenceData, useValue: vocabulary },
        { provide: Dialogs, useValue: { open: vi.fn() } }, { provide: Confirm, useValue: { ask: vi.fn(async () => true) } },
      ],
    });
  });

  it('keeps HEX and RGB in step while a custom colour is typed', () => {
    const dialog = inner(create(ColorDialog));
    const { hex_color, r, g, b } = dialog.form.controls;

    hex_color.setValue('#0f4c81');
    expect([r.value, g.value, b.value]).toEqual(['15', '76', '129']);
    expect(dialog.hex()).toBe('#0F4C81');

    b.setValue('255');
    expect(hex_color.value).toBe('#0F4CFF');

    // Something that is not a colour gives no tint, and leaves the other writing as it was.
    hex_color.setValue('#12');
    expect(dialog.hex()).toBeNull();
    expect(b.value).toBe('255');
    g.setValue('300');
    expect(dialog.hex()).toBeNull();

    dialog.fromPicker('#aabbcc');
    expect([dialog.hex(), r.value]).toEqual(['#AABBCC', '170']);
  });

  it('creates a custom colour with its code and its normalised tint', async () => {
    const dialog = inner(create(ColorDialog));
    dialog.form.patchValue({ name: ' Bleu maison ', reference_code: ' BM-01 ', hex_color: 'abc' });
    await dialog.save();

    expect(api.post).toHaveBeenCalledWith('colors', { name: 'Bleu maison', reference_code: 'BM-01', hex_color: '#AABBCC', pantone_reference: null });
    expect(ref.close).toHaveBeenCalledWith(true);

    // Without a tint, none is sent; without a name, nothing is sent.
    api.post.mockClear();
    dialog.form.patchValue({ tinted: false });
    await dialog.save();
    expect(api.post.mock.calls[0][1]).toMatchObject({ hex_color: null });
    api.post.mockClear();
    dialog.form.patchValue({ name: '' });
    await dialog.save();
    expect(api.post).not.toHaveBeenCalled();
  });

  it('only renames a colour taken from the catalogue', async () => {
    const dialog = inner(create(ColorDialog, color({ code: 'c_9', color_system: 'pantone', collection: 'coated', reference_code: '300-c', pantone_reference: '300-c', source: 'import' })));
    expect(dialog.locked).toBe(true);
    dialog.form.patchValue({ name: 'Bleu client', hex_color: '#000000' });
    await dialog.save();

    expect(api.put).toHaveBeenCalledWith('colors/c_9', { name: 'Bleu client' });
  });

  it('suggests close catalogue colours without touching the colour being typed', async () => {
    api.get.mockReturnValue(of([{ ...color({ code: 'pc_1', reference_code: '300-c', is_catalogue: true }), delta_e: 0.4 }]));
    const dialog = inner(create(ColorDialog));
    dialog.form.patchValue({ hex_color: '#005EB9' });
    dialog.match();

    expect(api.get).toHaveBeenCalledWith('colors/match', { hex: '#005EB9', limit: 5 });
    expect(dialog.matches()).toMatchObject({ for: '#005EB9', colors: [{ reference_code: '300-c', delta_e: 0.4 }] });
    expect(dialog.form.controls.hex_color.value).toBe('#005EB9');

    // Taking a suggestion adds it beside: the form is still the user's.
    await dialog.take(dialog.matches().colors[0]);
    expect(api.post).toHaveBeenCalledWith('colors/catalogue/pc_1/take');
    expect([dialog.form.controls.hex_color.value, dialog.changed]).toEqual(['#005EB9', true]);
  });

  it('filters the company list by name, reference or HEX as the user types', () => {
    api.get.mockReturnValue(of([
      color({}), color({ code: 'c_2', name: { fr: 'Pantone 300-c', en: 'Pantone 300-c' }, color_system: 'pantone', reference_code: '300-c', pantone_reference: '300-c', hex_color: '#005EB8' }),
      color({ code: 'white', name: { fr: 'Blanc', en: 'White' }, hex_color: '#FFFFFF', is_custom: false, source: 'platform' }),
    ]));
    const list = inner(create(ColorsComponent));
    const shown = (search: string): string[] => {
      list.search.set(search);

      return list.shown().map((item: Color) => item.code);
    };

    expect(shown('')).toEqual(['c_1', 'c_2', 'white']);
    expect(shown('bleu')).toEqual(['c_1']);
    expect(shown('pantone 300')).toEqual(['c_2']);
    expect(shown('#005e')).toEqual(['c_2']);
    expect(shown('ffffff')).toEqual(['white']);
    expect(shown('zzz')).toEqual([]);
    // The catalogue is empty here: the way to it stays hidden.
    expect(list.catalogueSize()).toBe(0);
  });

  it('reads the catalogue page by page and marks what was taken', async () => {
    const tile = { ...color({ code: 'pc_1', reference_code: '300-c', collection: 'coated', is_catalogue: true, is_custom: false }), taken_as: null };
    api.list.mockReturnValue(of({ data: [tile], meta: { total: 130, current_page: 1, last_page: 3, per_page: 60, collections: [{ collection: 'coated', colors: 130 }] } }));
    const dialog = inner(create(ColorCatalogueDialog));
    expect(api.list).toHaveBeenLastCalledWith('colors/catalogue', { search: '', collection: null, page: 1, per_page: 60 });
    expect([dialog.total(), dialog.colors().length]).toEqual([130, 1]);

    dialog.search.set('300');
    dialog.pick('coated');
    expect(api.list).toHaveBeenLastCalledWith('colors/catalogue', { search: '300', collection: 'coated', page: 1, per_page: 60 });
    dialog.turn({ page: 2 });
    expect(api.list).toHaveBeenLastCalledWith('colors/catalogue', { search: '300', collection: 'coated', page: 3, per_page: 60 });
    // The selector announcing the value it already has does not reload.
    const calls = api.list.mock.calls.length;
    dialog.pick('coated');
    expect(api.list.mock.calls.length).toBe(calls);

    api.post.mockReturnValue(of(color({ code: 'c_7' })));
    await dialog.take(tile);
    expect(dialog.colors()[0].taken_as).toBe('c_7');
    expect(dialog.changed).toBe(true);
  });
});
