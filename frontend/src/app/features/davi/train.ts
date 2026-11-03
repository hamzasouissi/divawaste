import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';
import { firstValueFrom } from 'rxjs';

import { Api } from '../../core/api/api';
import { DaviReply } from '../../core/davi/davi';
import { Toast } from '../../core/feedback/toast';
import { DaviAvatarComponent } from '../../ui/davi-avatar';
import { IconComponent } from '../../ui/icon';
import { PageHeaderComponent } from '../../ui/page';

type Kind = 'all' | 'where' | 'action' | 'knowledge' | 'lookup';

interface Mission {
  key: string;
  kind: Exclude<Kind, 'all'>;
  goal: string;
  ask: string;
  shows: string;
  played: number;
}

interface Stats {
  questions: number;
  right: number;
  wrong: number;
  points: number;
  streak: number;
  today: number;
  by_kind: Record<string, { missions: number; covered: number }>;
}

/**
 * "Entraîner DAVI": a mission made by DAVI from what the platform really has (a button, an action, a fact, a figure),
 * the player's own question, DAVI's live answer, and the player's verdict. Each round is kept by DAVI as a training
 * question whose label is the mission's target, never DAVI's answer. A failure found is worth more points than a
 * success: it is what DAVI learns from. Nothing DAVI prepares here is ever confirmed.
 */
@Component({
  imports: [FormsModule, RouterLink, TranslocoDirective, ButtonDirective, IconComponent, PageHeaderComponent, DaviAvatarComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('daviTrain.title')" [subtitle]="t('daviTrain.subtitle')" />

      @if (stats(); as s) {
        <section class="scores" [attr.aria-label]="t('daviTrain.scores')">
          <div class="score"><strong>{{ s.points }}</strong><span>{{ t('daviTrain.points') }}</span></div>
          <div class="score"><strong>{{ s.questions }}</strong><span>{{ t('daviTrain.questions') }}</span></div>
          <div class="score bad"><strong>{{ s.wrong }}</strong><span>{{ t('daviTrain.found') }}</span></div>
          <div class="score"><strong>{{ rate() }}</strong><span>{{ t('daviTrain.rate') }}</span></div>
          <div class="score"><strong>{{ s.streak }}</strong><span>{{ t('daviTrain.streak') }}</span></div>
        </section>
      }

      <div class="kinds" role="radiogroup" [attr.aria-label]="t('daviTrain.kind')">
        @for (k of kinds; track k) {
          <button type="button" role="radio" class="kind" [class.on]="kind() === k" [attr.aria-checked]="kind() === k" (click)="choose(k)">
            <dw-icon aria-hidden="true">{{ icons[k] }}</dw-icon>{{ t('daviTrain.kinds.' + k) }}
            @if (k !== 'all' && stats()?.by_kind?.[k]; as c) { <small>{{ c.covered }}/{{ c.missions }}</small> }
          </button>
        }
      </div>

      @if (mission(); as m) {
        <section class="dw-panel mission">
          <p class="chip"><dw-icon aria-hidden="true">{{ icons[m.kind] }}</dw-icon>{{ t('daviTrain.kinds.' + m.kind) }}
            @if (m.played) { <span class="dw-faint"> · {{ t('daviTrain.played', { count: m.played }) }}</span> }</p>
          <h2 class="goal">{{ m.goal }}</h2>
          <p class="dw-muted">{{ m.ask }}</p>
          <form class="ask" (submit)="$event.preventDefault(); askDavi()">
            <textarea name="question" rows="2" [ngModel]="question()" (ngModelChange)="question.set($event)" [disabled]="busy() || !!reply()"
              [placeholder]="t('daviTrain.placeholder')" maxlength="1000" (keydown.enter)="enter($event)"></textarea>
            <button pButton type="submit" [disabled]="busy() || !!reply() || !question().trim()"><dw-icon>send</dw-icon>{{ t('daviTrain.ask') }}</button>
          </form>

          @if (busy() && !reply()) { <p class="working"><dw-davi-avatar [size]="22" [active]="true" />{{ t('daviTrain.working') }}</p> }

          @if (reply(); as r) {
            <div class="answer">
              <dw-davi-avatar [size]="26" />
              <div class="bubble">
                @for (line of lines(r.answer); track $index) { <p>{{ line }}</p> }
                @if (r.action?.label) { <p class="meta"><dw-icon aria-hidden="true">bolt</dw-icon>{{ t('daviTrain.prepared', { action: r.action?.label }) }}</p> }
                @for (link of r.links; track link.path) { <p class="meta"><dw-icon aria-hidden="true">arrow_forward</dw-icon>{{ link.label }}@if (link.button) { › « {{ link.button }} » }</p> }
                @if (r.sources.length) { <p class="meta"><dw-icon aria-hidden="true">verified</dw-icon>{{ r.sources[0].title }}</p> }
              </div>
            </div>
            <p class="expected"><dw-icon aria-hidden="true">flag</dw-icon><span><small>{{ t('daviTrain.expected') }}</small>{{ expected() || m.shows }}</span>
              @if (judged(); as j) { <em [class.ok]="j === 'right'">{{ t('daviTrain.judged.' + j) }}</em> }</p>
            @if (!saved()) {
              <div class="verdict">
                <button pButton type="button" severity="success" [disabled]="busy()" (click)="verdict('right')"><dw-icon>check</dw-icon>{{ t('daviTrain.right') }}</button>
                <button pButton type="button" severity="danger" [disabled]="busy()" (click)="verdict('wrong')"><dw-icon>close</dw-icon>{{ t('daviTrain.wrong') }}</button>
                <button pButton type="button" text [disabled]="busy()" (click)="verdict('other')">{{ t('daviTrain.other') }}</button>
              </div>
              <textarea class="remark" name="remark" rows="2" [ngModel]="remark()" (ngModelChange)="remark.set($event)" [placeholder]="t('daviTrain.remark')" maxlength="2000"></textarea>
            } @else {
              <p class="saved" role="status"><dw-icon aria-hidden="true">task_alt</dw-icon>{{ t(saved() === 'wrong' ? 'daviTrain.savedWrong' : saved() === 'other' ? 'daviTrain.savedOther' : 'daviTrain.savedRight') }}</p>
              <div class="verdict">
                <button pButton type="button" (click)="again()"><dw-icon>replay</dw-icon>{{ t('daviTrain.again') }}</button>
                <button pButton type="button" outlined (click)="next()"><dw-icon>skip_next</dw-icon>{{ t('daviTrain.next') }}</button>
              </div>
            }
          } @else if (!busy()) {
            <button pButton type="button" text class="skip" (click)="next(true)"><dw-icon>skip_next</dw-icon>{{ t('daviTrain.skip') }}</button>
          }
        </section>
      } @else if (!busy()) {
        <section class="dw-panel mission"><p class="dw-muted">{{ t('daviTrain.none') }}</p></section>
      }
      <p class="fine dw-faint"><dw-icon aria-hidden="true">shield</dw-icon>{{ t('daviTrain.fine') }} <a routerLink="/dashboard">{{ t('daviTrain.back') }}</a></p>
    </ng-container>
  `,
  styles: `
    :host { display: grid; gap: var(--dw-4); }
    .scores { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; }
    .score { display: grid; gap: 2px; padding: 12px 14px; border-radius: 12px; background: var(--dw-surface); box-shadow: inset 0 0 0 1px var(--dw-line-soft); }
    .score strong { font-size: 24px; font-variant-numeric: tabular-nums; } .score span { font-size: 12.5px; color: var(--dw-ink-3); } .score.bad strong { color: var(--dw-danger); }
    .kinds { display: flex; flex-wrap: wrap; gap: 8px; }
    .kind { all: unset; box-sizing: border-box; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 10px; font-size: 13.5px; font-weight: 560;
      box-shadow: inset 0 0 0 1px var(--dw-line); background: var(--dw-surface); }
    .kind dw-icon { font-size: 18px; color: var(--dw-primary); } .kind small { color: var(--dw-ink-3); font-weight: 500; }
    .kind.on { box-shadow: inset 0 0 0 2px var(--dw-primary); background: var(--dw-primary-soft); } .kind:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .mission { display: grid; gap: 12px; padding: 20px; }
    .chip { display: inline-flex; align-items: center; gap: 6px; margin: 0; font-size: 12px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: var(--dw-primary); }
    .chip dw-icon { font-size: 16px; } .goal { margin: 0; font-size: 20px; line-height: 1.35; font-weight: 650; } .mission > .dw-muted { margin: 0; }
    .ask { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    textarea { flex: 1 1 320px; min-height: 52px; padding: 10px 12px; border-radius: 12px; border: 1px solid var(--dw-line); font: inherit; font-size: 15px; background: var(--dw-surface); color: var(--dw-ink); resize: vertical; }
    textarea:focus { outline: 2px solid var(--dw-primary); outline-offset: 0; }
    .working { display: flex; align-items: center; gap: 8px; margin: 0; color: var(--dw-ink-2); font-size: 13.5px; }
    .answer { display: flex; gap: 10px; align-items: flex-start; }
    .bubble { flex: 1; min-width: 0; padding: 12px 14px; border-radius: 4px 16px 16px 16px; background: var(--dw-surface-2); font-size: 14px; line-height: 1.55; }
    .bubble p { margin: 0; white-space: pre-wrap; } .bubble p + p { margin-block-start: 4px; }
    .meta { display: flex; align-items: center; gap: 6px; color: var(--dw-primary); font-weight: 560; font-size: 13px; } .meta dw-icon { font-size: 16px; }
    .expected { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 0; padding: 10px 12px; border-radius: 10px; background: var(--dw-primary-soft); font-size: 14px; }
    .expected dw-icon { color: var(--dw-primary); } .expected span { display: grid; flex: 1; min-width: 200px; }
    .expected small { font-size: 11px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: var(--dw-primary); }
    .expected em { font-style: normal; font-size: 12.5px; font-weight: 600; color: var(--dw-danger); } .expected em.ok { color: var(--dw-success); }
    .verdict { display: flex; flex-wrap: wrap; gap: 8px; } .remark { min-height: 44px; font-size: 14px; }
    .saved { display: flex; align-items: center; gap: 8px; margin: 0; color: var(--dw-success); font-weight: 600; }
    .skip { justify-self: start; }
    .fine { display: flex; align-items: center; gap: 6px; margin: 0; font-size: 12.5px; } .fine dw-icon { font-size: 15px; }
  `,
})
export class DaviTrainPage {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  protected readonly kinds: Kind[] = ['all', 'where', 'action', 'knowledge', 'lookup'];
  protected readonly icons: Record<Kind, string> = { all: 'casino', where: 'ads_click', action: 'bolt', knowledge: 'menu_book', lookup: 'query_stats' };
  protected readonly kind = signal<Kind>('all');
  protected readonly mission = signal<Mission | null>(null);
  protected readonly stats = signal<Stats | null>(null);
  protected readonly question = signal('');
  protected readonly remark = signal('');
  protected readonly reply = signal<DaviReply | null>(null);
  protected readonly judged = signal<'right' | 'wrong' | null>(null);
  protected readonly expected = signal('');
  protected readonly saved = signal<'right' | 'wrong' | 'other' | null>(null);
  protected readonly busy = signal(false);
  protected readonly rate = computed(() => {
    const s = this.stats();
    const n = (s?.right ?? 0) + (s?.wrong ?? 0);
    return n ? `${Math.round(((s?.right ?? 0) / n) * 100)} %` : '–';
  });
  /** Missions skipped in this visit: not offered again before the others. */
  private skipped: string[] = [];

  constructor() {
    void this.load();
  }

  protected choose(kind: Kind): void {
    this.kind.set(kind);
    void this.load();
  }

  private reset(): void {
    this.question.set('');
    this.remark.set('');
    this.reply.set(null);
    this.judged.set(null);
    this.expected.set('');
    this.saved.set(null);
  }

  private async load(same?: string): Promise<void> {
    this.busy.set(true);
    try {
      const data = await firstValueFrom(this.api.post<{ mission: Mission | null; stats: Stats }>('davi/game/mission', { kind: this.kind(), same: same ?? null, skip: this.skipped.slice(-50) }));
      this.reset();
      this.mission.set(data.mission);
      this.stats.set(data.stats);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected enter(event: Event): void {
    const key = event as KeyboardEvent;
    if (!key.shiftKey) {
      key.preventDefault();
      void this.askDavi();
    }
  }

  /** DAVI answers as everywhere, in a conversation of its own (each question alone; nothing is ever confirmed here). */
  protected async askDavi(): Promise<void> {
    const mission = this.mission();
    const question = this.question().trim();
    if (!mission || !question || this.busy()) {
      return;
    }
    this.busy.set(true);
    try {
      const conversation = `game-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
      const reply = await firstValueFrom(this.api.post<DaviReply>('davi/turn', { conversation, message: question, screen: '/dashboard' }));
      this.reply.set(reply);
      const judge = await firstValueFrom(this.api.post<{ judged: 'right' | 'wrong' | null; expected: string }>('davi/game/judge', { key: mission.key, reply: this.brief(reply) }));
      this.judged.set(judge.judged);
      this.expected.set(judge.expected);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  private brief(reply: DaviReply): Record<string, unknown> {
    return { kind: reply.kind, tier: reply.tier, answer: reply.answer, links: reply.links, sources: reply.sources, action: reply.action ?? null };
  }

  protected async verdict(verdict: 'right' | 'wrong' | 'other'): Promise<void> {
    const mission = this.mission();
    const reply = this.reply();
    if (!mission || !reply) {
      return;
    }
    this.busy.set(true);
    try {
      const data = await firstValueFrom(this.api.post<{ stats: Stats }>('davi/game/play', { key: mission.key, question: this.question().trim(), reply: this.brief(reply), verdict,
        remark: this.remark().trim() || null, screen: '/dashboard' }));
      this.stats.set(data.stats);
      this.saved.set(verdict);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  /** The same mission, asked another way: more ways of saying one thing is what DAVI needs most. */
  protected again(): void {
    const mission = this.mission();
    void this.load(mission?.key);
  }

  protected next(skip = false): void {
    const mission = this.mission();
    if (skip && mission) {
      this.skipped.push(mission.key);
    }
    void this.load();
  }

  protected lines(text: string): string[] {
    return text.split('\n').filter((line) => line.trim() !== '');
  }
}
