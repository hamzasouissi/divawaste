import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, input, signal } from '@angular/core';
import { Router } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';

import { Davi, DaviStep } from '../core/davi/davi';
import { DaviAvatarComponent } from '../ui/davi-avatar';
import { IconComponent } from '../ui/icon';

const KEY = 'dw.davi.hidden';

function hidden(): string[] {
  try {
    return JSON.parse(sessionStorage.getItem(KEY) ?? '[]') as string[];
  } catch {
    return [];
  }
}

/**
 * DAVI's one card on the page, under its title — the page's only guide (its explanation folds inside it):
 * - while the company's start is not finished: what was just done (✔), the current step and why, the one button that
 *   does it (lit up on its page, or that page opened), and the steps, each ✔, skipped, current or to do;
 * - else what waits on this page and the button that settles it (or "Le faire avec DAVI");
 * - else, in one quiet line, that nothing waits here.
 * Questions are not here: they are in DAVI's panel. Everything in it comes from DAVI's code (the user's rights and
 * data), never from the model. It updates itself when something is saved (Api.changed).
 */
@Component({
  selector: 'dw-davi-hint',
  imports: [TranslocoDirective, IconComponent, DaviAvatarComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (shown(); as c) {
        <aside class="card" [class]="'card k-' + c.kind" role="note" [attr.aria-label]="t('davi.hint.label')">
          @if (c.kind === 'clear') { <span class="mark" aria-hidden="true"><dw-icon>check</dw-icon></span> } @else { <dw-davi-avatar [size]="38" /> }
          <div class="main">
            @if (c.kind === 'clear') {
              <p class="quiet"><strong>{{ t('davi.short') }}</strong>{{ c.title }}</p>
            } @else {
              @if (c.done) { <p class="done"><dw-icon aria-hidden="true">check_circle</dw-icon>{{ c.done }}</p> }
              <p class="title"><strong>{{ t('davi.short') }}</strong>{{ c.title }}</p>
              @if (c.text) { <p class="text">{{ c.text }}</p> }
              @if (c.action || c.alt) {
                <div class="acts">
                  @if (c.action; as a) {
                    <button type="button" class="act" (click)="davi.act(a)"><dw-icon aria-hidden="true">{{ a.here ? 'ads_click' : 'arrow_forward' }}</dw-icon>{{ a.label }}</button>
                  }
                  @if (c.alt; as alt) {
                    <button type="button" class="alt" (click)="davi.show(alt.draft, false)"><dw-icon aria-hidden="true">auto_awesome</dw-icon>{{ alt.label }}</button>
                  }
                </div>
              }
              @if (c.progress; as p) {
                <ol class="steps" [attr.aria-label]="t('davi.hint.steps', { done: p.done, total: p.total })">
                  @for (s of p.steps; track s.key) {
                    <li [class]="s.state">
                      <button type="button" (click)="go(s)" [attr.aria-current]="s.state === 'current' ? 'step' : null" [title]="t('davi.hint.state.' + s.state)">
                        <span class="dot" aria-hidden="true">@if (s.state === 'done') { <dw-icon>check</dw-icon> } @else if (s.state === 'skipped') { <dw-icon>remove</dw-icon> }</span>
                        <span class="lbl">{{ s.label }}</span>
                      </button>
                    </li>
                  }
                </ol>
              }
            }
            @if (davi.helpTopic(); as topic) {
              @if (helpOpen()) {
                <div class="help">
                  <strong>{{ t('help.' + topic + '.title') }}</strong>
                  <ul>@for (line of lines(t('help.' + topic + '.text')); track $index) { <li>{{ line }}</li> }</ul>
                </div>
              }
            }
          </div>
          <div class="side">
            @if (davi.helpTopic()) {
              <button type="button" class="link" (click)="helpOpen.set(!helpOpen())" [attr.aria-expanded]="helpOpen()">
                <dw-icon aria-hidden="true">{{ helpOpen() ? 'expand_less' : 'help_outline' }}</dw-icon>{{ t(helpOpen() ? 'davi.hint.helpHide' : 'davi.hint.help') }}
              </button>
            }
            @if (c.kind !== 'clear') {
              <button type="button" class="link later" (click)="hide()">{{ t('davi.hint.later') }}</button>
            }
          </div>
        </aside>
      }
    </ng-container>
  `,
  styles: `
    :host { display: block; flex-basis: 100%; }
    .card { display: flex; align-items: flex-start; gap: 14px; margin-block: 4px 4px; padding: 16px 16px 16px 18px; border-radius: var(--dw-radius-lg);
      background: var(--dw-surface); box-shadow: var(--dw-shadow), inset 4px 0 0 var(--accent); animation: dw-rise 200ms var(--dw-ease); --accent: var(--dw-primary); }
    .k-start { background: linear-gradient(120deg, var(--dw-primary-soft), var(--dw-surface) 60%); }
    .k-todo { --accent: var(--dw-warning); } .k-advice { --accent: var(--dw-info, var(--dw-primary)); }
    .k-clear { padding-block: 10px; box-shadow: inset 0 0 0 1px var(--dw-line-soft); --accent: transparent; background: var(--dw-surface); align-items: center; }
    .mark { display: grid; place-items: center; flex: none; width: 36px; height: 36px; border-radius: 11px; color: #fff; background: linear-gradient(135deg, var(--dw-primary), #3a9d8f); }
    .k-todo .mark { background: var(--dw-warning); } .k-clear .mark { width: 26px; height: 26px; border-radius: 8px; background: var(--dw-success-soft); color: var(--dw-success); }
    .mark dw-icon { font-size: 20px; } .k-clear .mark dw-icon { font-size: 16px; }
    .main { flex: 1; min-inline-size: 0; display: grid; gap: 6px; }
    p { margin: 0; }
    strong { margin-inline-end: 8px; font-size: 11.5px; font-weight: 700; letter-spacing: 0.06em; color: var(--dw-primary); }
    .done { display: inline-flex; align-items: center; gap: 6px; justify-self: start; padding: 3px 10px 3px 6px; border-radius: 999px; background: var(--dw-success-soft);
      color: var(--dw-success); font-size: 13px; font-weight: 600; }
    .done dw-icon { font-size: 17px; }
    .title { font-size: 16px; line-height: 23px; font-weight: 620; color: var(--dw-ink); }
    .text { font-size: 14px; line-height: 21px; color: var(--dw-ink-2); }
    .quiet { font-size: 14px; color: var(--dw-ink-2); }
    .acts { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-block-start: 6px; }
    .act, .alt, .link, .steps button { all: unset; box-sizing: border-box; cursor: pointer; }
    .act { display: inline-flex; align-items: center; gap: 8px; min-height: 42px; padding: 9px 16px; border-radius: 10px; background: var(--dw-primary); color: #fff;
      font-size: 14.5px; font-weight: 620; transition: background-color 140ms; }
    .act:hover { background: var(--dw-primary-strong, var(--dw-primary)); } .act dw-icon { font-size: 19px; }
    .alt { display: inline-flex; align-items: center; gap: 6px; min-height: 42px; padding: 9px 12px; border-radius: 10px; color: var(--dw-primary); font-size: 14px; font-weight: 560; }
    .alt:hover { background: var(--dw-primary-soft); } .alt dw-icon { font-size: 17px; }
    .steps { list-style: none; display: flex; flex-wrap: wrap; gap: 4px 0; margin: 10px 0 0; padding: 0; }
    .steps li { display: flex; align-items: center; }
    .steps li + li::before { content: ''; inline-size: 18px; block-size: 2px; margin-inline: 2px; background: var(--dw-line); }
    .steps li.done + li::before, .steps li.skipped + li::before { background: var(--dw-success); }
    .steps button { display: inline-flex; align-items: center; gap: 6px; padding: 4px 6px; border-radius: 8px; font-size: 12.5px; color: var(--dw-ink-3); }
    .steps button:hover { background: var(--dw-surface-2); }
    .dot { display: grid; place-items: center; inline-size: 20px; block-size: 20px; border-radius: 50%; box-shadow: inset 0 0 0 2px var(--dw-line); }
    .dot dw-icon { font-size: 14px; }
    .done .dot { background: var(--dw-success); box-shadow: none; color: #fff; } .done .lbl { color: var(--dw-ink-2); }
    .skipped .dot { box-shadow: inset 0 0 0 2px var(--dw-line); color: var(--dw-ink-3); } .skipped .lbl { text-decoration: line-through; }
    .current .dot { background: var(--dw-primary); box-shadow: 0 0 0 4px var(--dw-primary-soft); } .current .lbl { color: var(--dw-ink); font-weight: 650; }
    .help { margin-block-start: 8px; padding: 12px 14px; border-radius: 10px; background: var(--dw-surface-2); font-size: 13.5px; line-height: 21px; }
    .help strong { display: block; margin: 0 0 4px; font-size: 13.5px; letter-spacing: 0; color: var(--dw-ink); }
    .help ul { margin: 0; padding-inline-start: 18px; display: grid; gap: 3px; color: var(--dw-ink-2); }
    .side { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex: none; }
    .link { display: inline-flex; align-items: center; gap: 4px; padding: 5px 8px; border-radius: 8px; font-size: 13px; color: var(--dw-primary); font-weight: 540; }
    .link dw-icon { font-size: 17px; } .link:hover { background: var(--dw-primary-soft); }
    .later { color: var(--dw-ink-3); font-weight: 400; } .later:hover { background: var(--dw-surface-2); color: var(--dw-ink); }
    .act:focus-visible, .alt:focus-visible, .link:focus-visible, .steps button:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    @media (max-width: 640px) { .card { flex-wrap: wrap; } .main { flex-basis: calc(100% - 50px); } .side { flex-direction: row; margin-inline-start: auto; } .act { flex: 1 1 100%; justify-content: center; } }
    @media (prefers-reduced-motion: reduce) { .card { animation: none; } }
  `,
})
export class DaviHintComponent implements OnInit {
  protected readonly davi = inject(Davi);
  private readonly router = inject(Router);
  private readonly hiddenKeys = signal(hidden());
  protected readonly helpOpen = signal(false);
  /** In the shell, for a page without a title component: it steps aside when a page's title shows the card. */
  readonly fallback = input(false);

  private readonly destroy = inject(DestroyRef);

  private readonly id = Symbol('dw-davi-hint');

  ngOnInit(): void {
    if (!this.fallback()) {
      // the first title of the page shows the card; a second title in the same page (a section) does not repeat it
      this.davi.headers.update((owners) => [...owners, this.id]);
      this.destroy.onDestroy(() => this.davi.headers.update((owners) => owners.filter((owner) => owner !== this.id)));
    }
  }

  protected readonly shown = computed(() => {
    const card = this.davi.card();
    const owners = this.davi.headers();
    if (!this.davi.available() || !card || (this.fallback() ? owners.length > 0 : owners[0] !== this.id)) {
      return null;
    }
    return this.hiddenKeys().includes(this.key(card)) ? null : card;
  });

  /** What "Later" hides: a step of the company's start is the same card on every page, so it is put off everywhere
   * (for the session); anything else only on its page. */
  private key(card: { kind: string; title: string }): string {
    return `${card.kind === 'start' ? '*' : this.router.url.split(/[?#]/)[0]}|${card.title}`;
  }

  protected go(step: DaviStep): void {
    void this.router.navigateByUrl(step.path);
  }

  protected lines(text: string): string[] {
    return text.split('\n').filter((line) => line.trim() !== '');
  }

  protected hide(): void {
    const card = this.davi.card();
    if (!card) {
      return;
    }
    const keys = [...this.hiddenKeys(), this.key(card)].slice(-50);
    this.hiddenKeys.set(keys);
    try {
      sessionStorage.setItem(KEY, JSON.stringify(keys));
    } catch {
      // Private browsing: hidden for the page.
    }
  }
}
