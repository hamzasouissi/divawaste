import { HttpClient } from '@angular/common/http';
import { DOCUMENT, Injectable, computed, effect, inject, signal } from '@angular/core';
import { Translation, TranslocoLoader, TranslocoService } from '@jsverse/transloco';
import { Observable } from 'rxjs';

import { Localized } from '../api/models';

/** Languages of the product. French and English are available; Arabic (right-to-left) is prepared for later (cahier §1). */
export const LANGUAGES = [
  { code: 'fr', label: 'Français', dir: 'ltr', locale: 'fr-TN' },
  { code: 'en', label: 'English', dir: 'ltr', locale: 'en-GB' },
  { code: 'ar', label: 'العربية', dir: 'rtl', locale: 'ar-TN' },
] as const;

export const AVAILABLE_LANGUAGES = ['fr', 'en'];

/** The language chosen on this device, kept between visits. */
const STORAGE_KEY = 'dw.language';
/** Language of the generated documents when it differs from the screen ("auto" = same as the screen). */
const DOCUMENTS_KEY = 'dw.document-language';

function rememberedDocuments(value?: string): string {
  try {
    if (value !== undefined) {
      localStorage.setItem(DOCUMENTS_KEY, value);
    }
    const stored = localStorage.getItem(DOCUMENTS_KEY);

    return stored !== null && AVAILABLE_LANGUAGES.includes(stored) ? stored : 'auto';
  } catch {
    return 'auto';
  }
}

function remembered(value?: string): string | null {
  try {
    if (value !== undefined) {
      localStorage.setItem(STORAGE_KEY, value);
    }
    const stored = localStorage.getItem(STORAGE_KEY);

    return stored !== null && AVAILABLE_LANGUAGES.includes(stored) ? stored : null;
  } catch {
    // Private browsing: the choice simply does not survive a reload.
    return null;
  }
}

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
  readonly available = LANGUAGES.filter((language) => AVAILABLE_LANGUAGES.includes(language.code));
  /** What the user picked explicitly on this device, if anything. */
  readonly chosen = signal(remembered());
  /** Preference for generated documents (PDF, Excel): "auto" follows the screen, or a fixed language. */
  readonly documentsChoice = signal(rememberedDocuments());
  /** The language documents are produced in right now. */
  readonly documents = computed(() => (this.documentsChoice() === 'auto' ? this.lang() : this.documentsChoice()));

  constructor() {
    const initial = this.chosen();
    if (initial !== null && initial !== this.transloco.getActiveLang()) {
      this.transloco.setActiveLang(initial);
    }
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

  /** Applies a language without recording it as the user's own choice (language of the profile). */
  use(lang: string): void {
    if (AVAILABLE_LANGUAGES.includes(lang)) {
      this.transloco.setActiveLang(lang);
    }
  }

  /** Documents in a fixed language, or "auto" to follow the screen. */
  chooseDocuments(lang: string): void {
    this.documentsChoice.set(rememberedDocuments(lang === 'auto' || AVAILABLE_LANGUAGES.includes(lang) ? lang : 'auto'));
  }

  /** The user picks a language: applied at once and remembered on this device. */
  choose(lang: string): void {
    if (AVAILABLE_LANGUAGES.includes(lang)) {
      this.chosen.set(remembered(lang));
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
