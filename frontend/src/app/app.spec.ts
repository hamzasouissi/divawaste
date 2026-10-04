import { DOCUMENT } from '@angular/common';
import { TestBed } from '@angular/core/testing';
import { LocaleService } from './shared/i18n/locale.service';

describe('LocaleService', () => {
  it('sets Arabic document direction and language', () => {
    const fakeDocument = { documentElement: { lang: '', dir: '' } } as Document;
    TestBed.configureTestingModule({ providers: [{ provide: DOCUMENT, useValue: fakeDocument }] });
    const service = TestBed.inject(LocaleService);
    service.set('ar');
    expect(service.direction()).toBe('rtl');
    expect(fakeDocument.documentElement.lang).toBe('ar');
    expect(fakeDocument.documentElement.dir).toBe('rtl');
  });

  it('translates dotted backend notification types', () => {
    const service = TestBed.inject(LocaleService);
    service.set('en');
    expect(service.t('notificationType.pickup.requested')).toBe('Pickup requested');
  });
});
