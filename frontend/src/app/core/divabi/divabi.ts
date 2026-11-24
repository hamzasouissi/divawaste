import { Injectable, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';

import { Api } from '../api/api';
import { Session } from '../auth/session';

/** What GET custom-views/session answers: a short-lived token for DivaBI, signed by this API for the current user. */
export interface DivaBiSession {
  token: string;
  expires_at: string;
  url: string;
  can_build: boolean;
}

interface DivaBiGlobal {
  /** Merges what it is given; a new tokenProvider drops the token DivaBI held. */
  configure(options: { app?: string; baseUrl?: string; locale?: string; tokenProvider?: () => Promise<string> }): void;
}

/**
 * Custom views run on DivaBI, a separate service reached under /divabi (same origin: the dev server and the reverse
 * proxy forward it). This service loads DivaBI's web components once, gives them the user's token — asked from our
 * own API, which signs it with the user's real permissions — and lets DivaBI's links use the Angular router.
 *
 * DivaBI's components then work anywhere in a template: <divabi-studio>, <divabi-view>, <divabi-slot>.
 */
@Injectable({ providedIn: 'root' })
export class DivaBi {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly session = inject(Session);
  private readonly transloco = inject(TranslocoService);

  private loading: Promise<boolean> | null = null;
  private firstToken: string | null = null;
  /** User and company DivaBI was started for ("user|company"). */
  private startedFor: string | null = null;
  /** Bumped by each reset: a start begun for a previous user or company writes nothing once it ends. */
  private generation = 0;
  private listening = false;
  /** null while loading, false when DivaBI cannot be reached or is not configured. */
  readonly ready = signal<boolean | null>(null);
  readonly canBuild = signal(false);
  /** Why DivaBI could not start (shown by the custom views pages). */
  readonly error = signal<unknown>(null);

  /** Whether the user may open custom views at all (the API checks it again). */
  allowed(): boolean {
    return this.session.can('custom_views.view') || this.session.can('custom_views.manage');
  }

  load(): Promise<boolean> {
    // Signing out, signing in as someone else or switching company does not reload the page: DivaBI must not keep the
    // token, the rights or the views of the previous user or company. It starts again for the new one.
    const who = this.who();
    if (who !== this.startedFor) {
      this.reset();
    }
    if (!this.allowed()) {
      return Promise.resolve(false);
    }
    this.startedFor = who;
    const generation = this.generation;
    this.loading ??= this.start(generation).then(
      () => {
        if (generation !== this.generation) {
          return false;
        }
        this.ready.set(true);
        this.error.set(null);
        return true;
      },
      (error: unknown) => {
        if (generation !== this.generation) {
          return false;
        }
        console.warn('DivaBI unavailable', error);
        this.loading = null;
        this.error.set(error);
        this.ready.set(false);
        return false;
      },
    );

    return this.loading;
  }

  private async start(generation: number): Promise<void> {
    const first = await this.newSession();
    if (generation !== this.generation) {
      return;
    }
    this.firstToken = first.token;
    this.canBuild.set(first.can_build);
    const base = first.url.replace(/\/$/, '');
    await this.loadScript(`${base}/static/divabi.js`);
    if (generation !== this.generation) {
      return;
    }
    const divabi = (window as unknown as { DivaBI?: DivaBiGlobal }).DivaBI;
    if (!divabi) {
      throw new Error('DivaBI script loaded without its API');
    }
    divabi.configure({ app: 'divawaste', baseUrl: base, locale: this.transloco.getActiveLang(), tokenProvider: () => this.token() });
    if (this.listening) {
      return;
    }
    this.listening = true;
    // Links of a view lead to our own screens (/lots/…): the router opens them without reloading the application.
    document.addEventListener('divabi-navigate', (event) => {
      const path = (event as CustomEvent<{ path: string }>).detail?.path;
      if (path?.startsWith('/')) {
        event.preventDefault();
        void this.router.navigateByUrl(path);
      }
    });
  }

  private who(): string | null {
    const user = this.session.me()?.ulid;
    const company = this.session.company()?.ulid;

    return user && company ? `${user}|${company}` : null;
  }

  /** Forgets the previous session: its token (also inside DivaBI, which drops it when given a provider again) and its rights. */
  private reset(): void {
    this.generation++;
    this.startedFor = null;
    this.loading = null;
    this.firstToken = null;
    this.ready.set(null);
    this.canBuild.set(false);
    this.error.set(null);
    (window as unknown as { DivaBI?: DivaBiGlobal }).DivaBI?.configure({ tokenProvider: () => this.token() });
  }

  private newSession(): Promise<DivaBiSession> {
    return firstValueFrom(this.api.get<DivaBiSession>('custom-views/session'));
  }

  /** The first token is used once; later ones are asked again (DivaBI asks when its token expires). */
  private async token(): Promise<string> {
    if (this.firstToken) {
      const token = this.firstToken;
      this.firstToken = null;
      return token;
    }

    return (await this.newSession()).token;
  }

  private loadScript(src: string): Promise<void> {
    return new Promise((resolve, reject) => {
      if ((window as unknown as { DivaBI?: unknown }).DivaBI) {
        resolve();
        return;
      }
      const timer = setTimeout(() => reject(new Error('DivaBI did not load in time')), 15000);
      window.addEventListener('divabi-ready', () => { clearTimeout(timer); resolve(); }, { once: true });
      const script = document.createElement('script');
      script.type = 'module';
      script.src = src;
      script.onerror = () => { clearTimeout(timer); reject(new Error(`Cannot load ${src}`)); };
      document.head.append(script);
    });
  }
}
