import { Injectable, inject } from '@angular/core';
import { Title } from '@angular/platform-browser';
import { RouterStateSnapshot, TitleStrategy } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';

/**
 * Browser tab title in the active language. Routes declare their title in French ("Enlèvements — DivaWaste");
 * the page name is looked up under `titles` in the translation file and falls back to the French text.
 */
@Injectable({ providedIn: 'root' })
export class PageTitle extends TitleStrategy {
  private readonly title = inject(Title);
  private readonly transloco = inject(TranslocoService);
  private current: string | undefined;

  constructor() {
    super();
    // Switching language retitles the page that is open.
    this.transloco.langChanges$.subscribe(() => this.apply());
    this.transloco.events$.subscribe((event) => event.type === 'translationLoadSuccess' && this.apply());
  }

  override updateTitle(snapshot: RouterStateSnapshot): void {
    this.current = this.buildTitle(snapshot);
    this.apply();
  }

  private apply(): void {
    if (this.current === undefined) {
      return;
    }
    const parts = this.current.split(' — ').map((part) => {
      const key = `titles.${part}`;
      const translated = this.transloco.translate(key);

      return translated === key ? part : translated;
    });
    this.title.setTitle(parts.join(' — '));
  }
}
