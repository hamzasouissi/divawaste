import { HttpClient } from '@angular/common/http';
import { DOCUMENT, Injectable, effect, inject, signal } from '@angular/core';
import { Translation, TranslocoLoader, TranslocoService } from '@jsverse/transloco';
import { Observable } from 'rxjs';

import { Localized } from '../api/models';

/** Languages of the product. French ships in V1; English and Arabic (right-to-left) are V2 (cahier §1). */
export const LANGUAGES = [
  { code: 'fr', label: 'Français', dir: 'ltr', locale: 'fr-TN' },
  { code: 'en', label: 'English', dir: 'ltr', locale: 'en-GB' },
  { code: 'ar', label: 'العربية', dir: 'rtl', locale: 'ar-TN' },
] as const;

export const AVAILABLE_LANGUAGES = ['fr'];

@Injectable({ providedIn: 'root' })
export class TranslationLoader implements TranslocoLoader {
  private readonly http = inject(HttpClient);

  getTranslation(lang: string): Observable<Translation> {
    return this.http.get<Translation>(`/i18n/${lang}.json`);
  }
}

/**
 * Active language, text direction and number/date locale. Keeps `<html lang dir>` in sync so the whole
 * layout mirrors when a right-to-left language is selected.
 */
@Injectable({ providedIn: 'root' })
export class Locale {
  private readonly transloco = inject(TranslocoService);
  private readonly document = inject(DOCUMENT);

  readonly lang = signal(this.transloco.getActiveLang());

  constructor() {
    this.transloco.langChanges$.subscribe((lang) => this.lang.set(lang));
    effect(() => {
      const language = this.definition();
      this.document.documentElement.lang = language.code;
      this.document.documentElement.dir = language.dir;
    });
  }

  /** BCP 47 locale for Intl formatting (dates, numbers, currency follow the country). */
  get intl(): string {
    return this.definition().locale;
  }

  use(lang: string): void {
    if (AVAILABLE_LANGUAGES.includes(lang)) {
      this.transloco.setActiveLang(lang);
    }
  }

  /** Text of a reference value sent by the API in every language. */
  pick(value: Localized | null | undefined, fallback = ''): string {
    if (!value) {
      return fallback;
    }

    return value[this.lang()] ?? value['fr'] ?? Object.values(value)[0] ?? fallback;
  }

  private definition(): (typeof LANGUAGES)[number] {
    return LANGUAGES.find((language) => language.code === this.lang()) ?? LANGUAGES[0];
  }
}
