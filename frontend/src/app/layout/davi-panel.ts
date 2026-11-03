import { ChangeDetectionStrategy, Component, DestroyRef, ElementRef, afterRenderEffect, computed, inject, signal, viewChild } from '@angular/core';
import { DatePipe, NgTemplateOutlet } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { NavigationEnd, Router, RouterLink } from '@angular/router';
import { Title } from '@angular/platform-browser';
import { TranslocoDirective } from '@jsverse/transloco';
import { filter } from 'rxjs/operators';

import { Session } from '../core/auth/session';
import { Davi, DaviMessage, DaviReply, DaviSuggestion, looksLikeTable } from '../core/davi/davi';
import { DaviAvatarComponent } from '../ui/davi-avatar';
import { IconComponent } from '../ui/icon';

/** A piece of an answer line: plain text, or a lot / pickup number that opens it. */
interface Piece {
  text: string;
  number?: boolean;
}

interface Line {
  bullet: boolean;
  pieces: Piece[];
}

const BIG_KEY = 'dw.davi.big';
const NUMBER_RE = /(L-\d{4}-\d{4,6}|ENL-\d{4}-\d{3,5}|CTR-\d{4}-\d{3,5}|ANN-\d{4}-\d{3,5})/;
/** An answer longer than this shows its first sentence, the rest on demand. */
const SHORT = 240;

/**
 * DAVI's panel, at the side of every screen. Built like the assistants of business software people already know:
 * - it opens on what matters here (DAVI's note for the page), then what it can do and what to ask, in two groups;
 * - an answer leads with one sentence, the details fold under "Plus de détails", its sources under that;
 * - an action waiting for the yes is a card: what it is, what will change, Confirmer / Modifier / Annuler, and a
 *   warning when it cannot be undone; a done action says so and opens what it changed;
 * - while DAVI works, it says what it is doing and for how long; a failure offers to try again.
 * DAVI's text is shown as it comes (rendered by code from verified records and the user's data); nothing here
 * interprets it.
 */
@Component({
  selector: 'dw-davi-panel',
  imports: [NgTemplateOutlet, FormsModule, RouterLink, TranslocoDirective, IconComponent, DaviAvatarComponent, DatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[class.open]': 'davi.open()', '[class.big]': 'big()', role: 'complementary', '[attr.aria-label]': '"Assistant virtuel"', '[attr.aria-hidden]': '!davi.open()',
    '(dragover)': 'over($event)', '(dragleave)': 'leave($event)', '(drop)': 'drop($event)' },
  template: `
    <ng-container *transloco="let t">
      <header class="head">
        <dw-davi-avatar [size]="36" [active]="davi.busy()" />
        <div class="who">
          <strong>{{ t('davi.name') }}</strong>
          <small>@if (page()) { {{ t('davi.onPage', { page: page() }) }} } @else { {{ t('davi.tagline') }} }</small>
        </div>
        <button type="button" class="new" (click)="startNew()" [title]="t('davi.newConversation')"><dw-icon aria-hidden="true">add_comment</dw-icon>{{ t('davi.new') }}</button>
        <a class="icon" routerLink="/davi/train" (click)="davi.open.set(false)" [title]="t('davi.train')" [attr.aria-label]="t('davi.train')"><dw-icon>school</dw-icon></a>
        <button type="button" class="icon" [class.on]="showHistory()" (click)="showHistory.set(!showHistory())" [attr.aria-pressed]="showHistory()" [title]="t('davi.history')" [attr.aria-label]="t('davi.history')"><dw-icon>history</dw-icon></button>
        <button type="button" class="icon" (click)="toggleBig()" [attr.aria-pressed]="big()" [title]="t('davi.bigText')" [attr.aria-label]="t('davi.bigText')"><dw-icon>text_increase</dw-icon></button>
        <button type="button" class="icon" (click)="davi.open.set(false)" [title]="t('common.close') + ' (Échap)'" [attr.aria-label]="t('common.close')"><dw-icon>close</dw-icon></button>
      </header>

      <div class="body" #scroller aria-live="polite">
        @if (showHistory()) {
          <!-- The past conversations of this user, to reopen one or start another. -->
          <section class="history">
            <p class="history-title">{{ t('davi.history') }}</p>
            <button type="button" class="chat new-chat" (click)="startNew()"><dw-icon aria-hidden="true">add_comment</dw-icon><span>{{ t('davi.newConversation') }}</span></button>
            @for (chat of davi.history(); track chat.id) {
              <div class="chat-row">
                <button type="button" class="chat" [class.current]="chat.id === currentId()" (click)="reopen(chat.id)">
                  <dw-icon aria-hidden="true">chat_bubble_outline</dw-icon>
                  <span class="chat-text"><strong>{{ chat.title }}</strong><small>{{ chat.at | date: 'd MMM, HH:mm' }} · {{ t('davi.messagesCount', { count: chat.messages.length }) }}</small></span>
                </button>
                <button type="button" class="icon small" (click)="davi.forgetChat(chat.id)" [title]="t('davi.forget')" [attr.aria-label]="t('davi.forget') + ' — ' + chat.title"><dw-icon>delete</dw-icon></button>
              </div>
            } @empty {
              <p class="muted">{{ t('davi.noHistory') }}</p>
            }
          </section>
        } @else {
        @if (davi.messages().length === 0) {
          <section class="welcome">
            <p class="hello">{{ name() ? t('davi.helloName', { name: name() }) : t('davi.hello') }}</p>
            @if (davi.note(); as note) {
              <div class="now"><dw-icon aria-hidden="true">near_me</dw-icon><span><small>{{ t('davi.nowHere') }}</small>{{ note }}</span></div>
            } @else {
              <p class="muted">{{ t('davi.helloHint') }}</p>
            }
            @if (davi.focus(); as f) { <p class="focus"><dw-icon aria-hidden="true">center_focus_strong</dw-icon>{{ t('davi.focus.' + f.kind, { number: f.number }) }}</p> }
            @if (davi.canImport()) { <p class="tip"><dw-icon aria-hidden="true">upload_file</dw-icon>{{ t('davi.import.tip') }}</p> }
          </section>
          @if (davi.suggestions().length > 0) {
            <ng-container *ngTemplateOutlet="chips; context: { $implicit: davi.suggestions(), start: true }" />
          }
        }

        @for (message of davi.messages(); track message.id) {
          @if (message.from === 'user') {
            <div class="msg user" [class.with-file]="message.file">
              @if (message.file) { <p class="file"><dw-icon aria-hidden="true">table_view</dw-icon>{{ message.text }}</p> } @else { <p>{{ message.text }}</p> }
            </div>
          } @else if (message.failed) {
            <div class="msg davi failed" role="alert">
              <p><dw-icon aria-hidden="true">error</dw-icon>{{ message.text }}</p>
              @if (message.id === last()?.id && davi.lastQuestion()) {
                <button type="button" class="btn ghost" (click)="davi.retry()"><dw-icon aria-hidden="true">refresh</dw-icon>{{ t('davi.retry') }}</button>
              }
            </div>
          } @else if (message.reply?.kind === 'preview') {
            <!-- An action waiting for the yes: a card, not a sentence to answer "oui" to. -->
            <div class="msg card-action" [class.danger]="message.reply?.action?.irreversible">
              <div class="card-head">
                <span class="badge"><dw-icon aria-hidden="true">{{ message.reply?.action?.irreversible ? 'warning' : 'bolt' }}</dw-icon></span>
                <div><small>{{ t(message.id === last()?.id && davi.pending() ? 'davi.toConfirm' : 'davi.wasProposed') }}</small>
                  <strong>{{ message.reply?.action?.label || t('davi.preview') }}</strong></div>
              </div>
              <ng-container *ngTemplateOutlet="text; context: { $implicit: message, full: true }" />
              <ng-container *ngTemplateOutlet="file; context: { $implicit: message.reply }" />
              @if (message.reply?.action?.irreversible) { <p class="warn"><dw-icon aria-hidden="true">info</dw-icon>{{ t('davi.irreversible') }}</p> }
              @if (message.id === last()?.id && davi.pending()) {
                <div class="card-acts">
                  <button type="button" class="btn primary" [disabled]="davi.busy()" (click)="davi.confirm(true)"><dw-icon aria-hidden="true">check</dw-icon>{{ t('davi.yes') }}</button>
                  <button type="button" class="btn ghost" [disabled]="davi.busy()" (click)="davi.edit()"><dw-icon aria-hidden="true">edit</dw-icon>{{ t('davi.change') }}</button>
                  <button type="button" class="btn ghost" [disabled]="davi.busy()" (click)="davi.confirm(false)">{{ t('davi.no') }}</button>
                </div>
              }
            </div>
          } @else if (message.reply?.kind === 'done') {
            <div class="msg card-done">
              <div class="card-head"><span class="badge ok"><dw-icon aria-hidden="true">check</dw-icon></span><strong>{{ t('davi.done') }}</strong></div>
              <ng-container *ngTemplateOutlet="text; context: { $implicit: message, full: true }" />
              <ng-container *ngTemplateOutlet="file; context: { $implicit: message.reply }" />
              @if (firstNumber(message); as number) {
                <button type="button" class="btn ghost" (click)="davi.go(number)"><dw-icon aria-hidden="true">open_in_new</dw-icon>{{ t('davi.openThing', { number: number }) }}</button>
              }
              @for (link of message.reply?.links ?? []; track link.path) {
                <a class="btn ghost" [routerLink]="path(link.path)" [queryParams]="query(link.path)" (click)="follow(link.button)"><dw-icon aria-hidden="true">arrow_forward</dw-icon>{{ link.label }}</a>
              }
              @if (message.reply?.suggestions?.length && message.id === last()?.id) {
                <ng-container *ngTemplateOutlet="chips; context: { $implicit: message.reply?.suggestions, start: false }" />
              }
            </div>
          } @else {
            <div class="msg davi" [class.refused]="message.reply?.kind === 'refused'">
              <dw-davi-avatar class="avatar" [size]="26" />
              <div class="bubble">
                <ng-container *ngTemplateOutlet="text; context: { $implicit: message, full: false }" />
                <ng-container *ngTemplateOutlet="file; context: { $implicit: message.reply }" />
                @if (message.reply; as r) {
                  @if (r.chart; as chart) {
                    <div class="chart" role="img" [attr.aria-label]="t('davi.chart')">
                      @for (label of chart.labels; track $index; let i = $index) {
                        <div class="bar-row"><span class="bar-label">{{ label }}</span>
                          <span class="bars">@for (name of seriesNames(r); track name; let s = $index) {
                            <span class="bar" [class]="'s' + s" [style.width.%]="share(r, name, i)" [title]="name + ' : ' + chart.series[name][i]"></span> }</span>
                          <span class="bar-value">{{ total(r, i) }}</span></div>
                      }
                    </div>
                  }
                  @if (r.links.length > 0) {
                    <div class="links">@for (link of r.links; track link.path) {
                      <a [routerLink]="path(link.path)" [queryParams]="query(link.path)" (click)="follow(link.button)"><dw-icon aria-hidden="true">{{ link.button ? 'ads_click' : 'arrow_forward' }}</dw-icon>{{ link.button ? t('davi.showMe', { button: link.button }) : link.label }}</a> }</div>
                  }
                  @if (r.sources.length > 0) {
                    <details class="sources"><summary><dw-icon aria-hidden="true">verified</dw-icon>{{ t('davi.sources', { count: r.sources.length }) }}</summary>
                      <ul>@for (source of r.sources; track source.id) { <li>{{ source.title }}</li> }</ul></details>
                  }
                  @if (r.suggestions.length > 0 && message.id === last()?.id) {
                    <ng-container *ngTemplateOutlet="chips; context: { $implicit: r.suggestions, start: false }" />
                  }
                }
                <ng-container *ngTemplateOutlet="rate; context: { $implicit: message }" />
              </div>
            </div>
          }
        }

        @if (davi.busy()) {
          <!-- What DAVI is doing, and for how long: a wait that explains itself. -->
          <div class="msg thinking" role="status">
            <dw-davi-avatar [size]="24" [active]="true" />
            <span>{{ t('davi.working.' + stage()) }}@if (seconds() >= 3) { <small> · {{ seconds() }} s</small> }</span>
          </div>
        }
        }
      </div>

      <!-- A file DAVI read: the column it took for each piece of information, and the lines it set aside, with why. -->
      <ng-template #file let-reply>
        @if (reply?.details?.length) {
          <details class="cols"><summary><dw-icon aria-hidden="true">view_column</dw-icon>{{ t('davi.import.columns') }}</summary>
            <ul>@for (detail of reply.details; track $index) { <li>{{ detail }}</li> }</ul></details>
        }
        @if (reply?.problems?.length) {
          <div class="problems"><small>{{ t('davi.import.problems') }}</small>
            <ul>@for (problem of reply.problems; track problem.line) { <li><b>{{ t('davi.import.line', { line: problem.line }) }}</b> {{ problem.message }}</li> }</ul></div>
        }
      </ng-template>

      <!-- Was the answer right? A thumbs up, or a thumbs down with what was wrong: DAVI learns from both. -->
      <ng-template #rate let-message>
        <div class="rate">
          @if (message.rated) {
            <small class="thanks"><dw-icon aria-hidden="true">{{ message.rated === 'up' ? 'thumb_up' : 'thumb_down' }}</dw-icon>{{ t('davi.rated') }}</small>
          } @else if (remarking() === message.id) {
            <textarea class="remark" rows="2" [ngModel]="remarkText()" (ngModelChange)="remarkText.set($event)" [placeholder]="t('davi.remarkPlaceholder')" maxlength="2000"></textarea>
            <button type="button" class="btn ghost small" (click)="sendRate(message, 'down')">{{ t('davi.remarkSend') }}</button>
          } @else {
            <button type="button" class="thumb" (click)="sendRate(message, 'up')" [title]="t('davi.good')" [attr.aria-label]="t('davi.good')"><dw-icon>thumb_up</dw-icon></button>
            <button type="button" class="thumb" (click)="remarking.set(message.id); remarkText.set('')" [title]="t('davi.bad')" [attr.aria-label]="t('davi.bad')"><dw-icon>thumb_down</dw-icon></button>
          }
        </div>
      </ng-template>

      <!-- An answer: its first sentence, the details on demand. -->
      <ng-template #text let-message let-full="full">
        @if (full || !long(message) || expanded().has(message.id)) {
          @for (line of lines(message.text); track $index) {
            <p [class.bullet]="line.bullet"><ng-container *ngTemplateOutlet="pieces; context: { $implicit: line }" /></p>
          }
          @if (!full && long(message)) { <button type="button" class="more" (click)="fold(message.id)">{{ t('davi.lessDetail') }}</button> }
        } @else {
          <p class="lead"><ng-container *ngTemplateOutlet="pieces; context: { $implicit: lead(message.text) }" /></p>
          <button type="button" class="more" (click)="unfold(message.id)">{{ t('davi.moreDetail') }}<dw-icon aria-hidden="true">expand_more</dw-icon></button>
        }
      </ng-template>
      <ng-template #pieces let-line>@for (piece of line.pieces; track $index) { @if (piece.number) { <button type="button" class="num" (click)="davi.go(piece.text)">{{ piece.text }}</button> } @else { {{ piece.text }} } }</ng-template>

      <!-- What DAVI proposes: what it can do (or the page that does it), then what the user can ask it. -->
      <ng-template #chips let-list let-start="start">
        @if (actionsOf(list); as acts) {
          @if (acts.length > 0) {
            <div class="group" [class.start]="start">
              <span class="group-title">{{ t('davi.canDo') }}</span>
              <div class="chips">@for (s of acts; track s.text; let first = $first) {
                <button type="button" class="chip act" [class.first]="first && start" (click)="use(s)"><dw-icon aria-hidden="true">{{ s.kind === 'go' ? 'arrow_forward' : 'bolt' }}</dw-icon><span>{{ label(s) }}</span></button> }</div>
            </div>
          }
        }
        @if (questionsOf(list); as asks) {
          @if (asks.length > 0) {
            <div class="group" [class.start]="start">
              <span class="group-title">{{ t('davi.canAsk') }}</span>
              <div class="chips">@for (s of asks; track s.text) { <button type="button" class="chip" (click)="use(s)"><dw-icon aria-hidden="true">chat_bubble_outline</dw-icon><span>{{ s.text.trim() }}</span></button> }</div>
            </div>
          }
        }
      </ng-template>

      @if (dragging()) {
        <div class="dropzone" aria-hidden="true"><dw-icon>upload_file</dw-icon><strong>{{ t('davi.import.drop') }}</strong><small>{{ t('davi.import.dropHint') }}</small></div>
      }
      <form class="ask" (submit)="$event.preventDefault(); send()">
        @if (davi.canImport()) {
          <!-- A file of lots: picked here, dropped on the panel, or its rows pasted in the input. -->
          <label class="attach" [title]="t('davi.import.attach')">
            <input type="file" accept=".xlsx,.xls,.csv" (change)="attach($event)" [attr.aria-label]="t('davi.import.attach')" [disabled]="davi.busy()" />
            <dw-icon aria-hidden="true">attach_file</dw-icon>
          </label>
        }
        <textarea #input name="question" rows="1" [ngModel]="davi.draft()" (ngModelChange)="davi.draft.set($event)" (keydown.enter)="enter($event)" (paste)="paste($event)"
          [placeholder]="t(davi.pending() ? 'davi.placeholderPending' : 'davi.placeholder')" [attr.aria-label]="t('davi.placeholder')" maxlength="1000"></textarea>
        <button type="submit" class="send" [disabled]="davi.busy() || !davi.draft().trim()" [attr.aria-label]="t('davi.send')" [title]="t('davi.send') + ' (Entrée)'"><dw-icon>arrow_upward</dw-icon></button>
      </form>
      <p class="fine"><dw-icon aria-hidden="true">shield</dw-icon>{{ t('davi.fine') }}</p>
    </ng-container>
  `,
  styles: `
    :host { position: fixed; z-index: 30; inset-block: 0; inset-inline-end: 0; width: min(440px, 100vw); display: flex; flex-direction: column;
      background: var(--dw-surface); box-shadow: -16px 0 48px rgb(13 20 27 / 14%), -1px 0 0 var(--dw-line-soft); transform: translateX(105%); visibility: hidden;
      transition: transform 220ms var(--dw-ease), visibility 0s linear 220ms; }
    :host(.open) { transform: none; visibility: visible; transition: transform 220ms var(--dw-ease); }
    button { font: inherit; }
    .head { display: flex; align-items: center; gap: 8px; height: var(--dw-shell-bar); padding: 0 8px 0 16px; border-block-end: 1px solid var(--dw-line-soft); flex: none; }
    .new { all: unset; box-sizing: border-box; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; height: 32px; padding: 0 10px; border-radius: 9px;
      font-size: 13px; font-weight: 600; color: var(--dw-primary); box-shadow: inset 0 0 0 1px var(--dw-line); }
    .new:hover { background: var(--dw-primary-soft); } .new dw-icon { font-size: 18px; }
    .icon.on { background: var(--dw-primary-soft); color: var(--dw-primary); } .icon.small { width: 30px; height: 30px; } .icon.small dw-icon { font-size: 18px; }
    .history { display: grid; gap: 6px; } .history-title { margin: 0 0 4px; font-size: 16px; font-weight: 650; }
    .chat-row { display: flex; align-items: center; gap: 4px; }
    .chat { all: unset; box-sizing: border-box; cursor: pointer; flex: 1; min-width: 0; display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 11px;
      box-shadow: inset 0 0 0 1px var(--dw-line-soft); }
    .chat:hover { background: var(--dw-surface-2); } .chat.current { box-shadow: inset 0 0 0 1.5px var(--dw-primary); }
    .chat dw-icon { flex: none; font-size: 18px; color: var(--dw-primary); }
    .chat-text { display: grid; min-width: 0; } .chat-text strong { font-size: 13.5px; font-weight: 560; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .chat-text small { font-size: 11.5px; color: var(--dw-ink-3); }
    .new-chat { color: var(--dw-primary); font-weight: 600; font-size: 13.5px; box-shadow: inset 0 0 0 1.5px var(--dw-primary); }
    .new:focus-visible, .chat:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .who { flex: 1; min-width: 0; display: grid; line-height: 1.2; } .who strong { font-size: 14.5px; letter-spacing: 0.02em; }
    .who small { font-size: 12px; color: var(--dw-ink-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .icon { all: unset; box-sizing: border-box; cursor: pointer; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 9px; color: var(--dw-ink-2); }
    .icon:hover { background: var(--dw-surface-2); color: var(--dw-ink); } .icon dw-icon { font-size: 20px; }
    .body { flex: 1; overflow-y: auto; padding: 18px 16px; display: flex; flex-direction: column; gap: 14px; }
    .welcome { display: grid; gap: 8px; }
    .hello { margin: 0; font-size: 18px; font-weight: 650; letter-spacing: -0.01em; }
    .muted { margin: 0; font-size: 13.5px; line-height: 20px; color: var(--dw-ink-2); }
    .now { display: flex; gap: 10px; align-items: flex-start; padding: 12px; border-radius: 12px; background: var(--dw-primary-soft); font-size: 14px; line-height: 20px; color: var(--dw-ink); }
    .now dw-icon { color: var(--dw-primary); font-size: 19px; flex: none; } .now span { display: grid; gap: 2px; }
    .now small { font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--dw-primary); }
    .focus { display: flex; align-items: center; gap: 6px; margin: 0; padding: 6px 10px; border-radius: 8px; background: var(--dw-surface-2); color: var(--dw-ink-2); font-size: 12.5px; }
    .focus dw-icon { font-size: 16px; }
    .msg { max-width: 100%; font-size: 14px; line-height: 1.55; animation: dw-rise 160ms var(--dw-ease); }
    .msg p { margin: 0; white-space: pre-wrap; } .msg p + p { margin-block-start: 5px; }
    .user { align-self: flex-end; max-width: 86%; padding: 9px 13px; border-radius: 16px 16px 4px 16px; background: var(--dw-primary); color: #fff; }
    .davi { display: flex; gap: 10px; align-items: flex-start; }
    .avatar { margin-block-start: 2px; }
    .bubble { flex: 1; min-width: 0; padding: 10px 13px; border-radius: 4px 16px 16px 16px; background: var(--dw-surface-2); color: var(--dw-ink); }
    .refused .bubble { background: var(--dw-warning-soft); }
    .lead { font-weight: 520; }
    .bullet { padding-inline-start: 2px; }
    .more { all: unset; cursor: pointer; display: inline-flex; align-items: center; gap: 2px; margin-block-start: 6px; font-size: 13px; font-weight: 560; color: var(--dw-primary); }
    .more dw-icon { font-size: 17px; } .more:hover { text-decoration: underline; }
    .failed { display: grid; gap: 8px; padding: 10px 13px; border-radius: 12px; background: var(--dw-danger-soft); color: var(--dw-danger); }
    .failed p { display: flex; gap: 8px; align-items: flex-start; } .failed dw-icon { font-size: 18px; }
    .card-action, .card-done { display: grid; gap: 10px; padding: 14px; border-radius: 14px; background: var(--dw-surface); box-shadow: inset 0 0 0 1.5px var(--dw-primary), var(--dw-shadow); }
    .card-action.danger { box-shadow: inset 0 0 0 1.5px var(--dw-warning), var(--dw-shadow); }
    .card-done { box-shadow: inset 0 0 0 1.5px var(--dw-success); background: var(--dw-success-soft); }
    .card-head { display: flex; gap: 10px; align-items: center; } .card-head div { display: grid; line-height: 1.25; }
    .card-head small { font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--dw-ink-3); }
    .card-head strong { font-size: 15px; }
    .badge { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 9px; background: var(--dw-primary-soft); color: var(--dw-primary); flex: none; }
    .danger .badge { background: var(--dw-warning-soft); color: var(--dw-warning); } .badge.ok { background: var(--dw-success); color: #fff; }
    .badge dw-icon { font-size: 18px; }
    .warn { display: flex; gap: 6px; align-items: flex-start; padding: 8px 10px; border-radius: 9px; background: var(--dw-warning-soft); font-size: 13px; }
    .warn dw-icon { font-size: 17px; color: var(--dw-warning); }
    .card-acts { display: flex; flex-wrap: wrap; gap: 8px; }
    .btn { all: unset; box-sizing: border-box; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 40px; padding: 8px 14px;
      border-radius: 10px; font-size: 14px; font-weight: 600; justify-self: start; }
    .btn dw-icon { font-size: 18px; }
    .btn.primary { background: var(--dw-primary); color: #fff; } .btn.primary:hover { background: var(--dw-primary-strong, var(--dw-primary)); }
    .btn.ghost { color: var(--dw-primary); box-shadow: inset 0 0 0 1px var(--dw-line); background: var(--dw-surface); } .btn.ghost:hover { background: var(--dw-primary-soft); }
    .btn[disabled] { opacity: 0.5; cursor: default; }
    .num { all: unset; cursor: pointer; font-family: var(--dw-mono, monospace); font-size: 13px; font-weight: 600; color: var(--dw-primary); border-block-end: 1px dashed currentColor; }
    .links { display: grid; gap: 4px; margin-block-start: 8px; }
    .links a { display: inline-flex; align-items: center; gap: 6px; min-height: 32px; font-size: 13.5px; font-weight: 560; color: var(--dw-primary); text-decoration: none; }
    .links a:hover { text-decoration: underline; } .links dw-icon { font-size: 16px; }
    .sources { margin-block-start: 8px; font-size: 12px; color: var(--dw-ink-3); }
    .sources summary { cursor: pointer; display: inline-flex; align-items: center; gap: 4px; list-style: none; } .sources summary dw-icon { font-size: 14px; color: var(--dw-success); }
    .sources ul { margin: 4px 0 0; padding-inline-start: 18px; }
    .group { display: grid; gap: 6px; margin-block-start: 12px; } .group.start { margin-block-start: 4px; }
    .group-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--dw-ink-3); }
    .chips { display: flex; flex-wrap: wrap; gap: 6px; } .start .chips { flex-direction: column; align-items: stretch; }
    .chip { all: unset; box-sizing: border-box; cursor: pointer; display: inline-flex; align-items: center; gap: 9px; min-height: 40px; padding: 8px 12px; border-radius: 11px; font-size: 13.5px;
      line-height: 1.35; background: var(--dw-surface); box-shadow: inset 0 0 0 1px var(--dw-line); color: var(--dw-ink); transition: box-shadow 140ms, background-color 140ms; }
    .chip:hover { box-shadow: inset 0 0 0 1px var(--dw-primary); background: var(--dw-primary-soft); }
    .chip dw-icon { flex: none; font-size: 18px; color: var(--dw-primary); }
    .chip.act { font-weight: 600; color: var(--dw-primary-strong, var(--dw-primary)); box-shadow: inset 0 0 0 1.5px var(--dw-primary); }
    .chip.act.first { background: var(--dw-primary); color: #fff; box-shadow: none; } .chip.act.first dw-icon { color: #fff; }
    .thinking { display: inline-flex; align-items: center; gap: 10px; color: var(--dw-ink-2); font-size: 13.5px; } .thinking small { color: var(--dw-ink-3); }
    .dots { display: inline-flex; gap: 3px; } .dots i { width: 6px; height: 6px; border-radius: 50%; background: var(--dw-primary); animation: davi-dot 1s infinite ease-in-out; }
    .dots i:nth-child(2) { animation-delay: 0.15s; } .dots i:nth-child(3) { animation-delay: 0.3s; }
    @keyframes davi-dot { 0%, 80%, 100% { opacity: 0.25; transform: translateY(0); } 40% { opacity: 1; transform: translateY(-3px); } }
    .chart { display: grid; gap: 4px; margin-block-start: 8px; }
    .bar-row { display: grid; grid-template-columns: 64px 1fr auto; align-items: center; gap: 8px; font-size: 11.5px; }
    .bar-label { color: var(--dw-ink-3); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .bars { display: flex; height: 10px; border-radius: 4px; overflow: hidden; background: var(--dw-surface-3); }
    .bar.s0 { background: var(--dw-primary); } .bar.s1 { background: #7fb8ae; } .bar.s2 { background: var(--dw-warning); } .bar.s3 { background: var(--dw-ink-3); }
    .bar-value { font-variant-numeric: tabular-nums; color: var(--dw-ink-2); }
    .ask { display: flex; align-items: flex-end; gap: 8px; margin: 0 12px; padding: 8px 8px 8px 14px; border-radius: 16px; background: var(--dw-surface-2); box-shadow: inset 0 0 0 1px var(--dw-line); }
    .ask:focus-within { box-shadow: inset 0 0 0 1.5px var(--dw-primary); background: var(--dw-surface); }
    textarea { all: unset; flex: 1; min-height: 22px; max-height: 140px; padding-block: 7px; font-size: 14px; line-height: 1.45; field-sizing: content; }
    .send { all: unset; box-sizing: border-box; cursor: pointer; flex: none; display: grid; place-items: center; width: 38px; height: 38px; border-radius: 12px; background: var(--dw-primary); color: #fff; }
    .send[disabled] { background: var(--dw-surface-3); color: var(--dw-ink-3); cursor: default; } .send dw-icon { font-size: 20px; }
    .tip { display: flex; gap: 8px; align-items: flex-start; margin: 2px 0 0; font-size: 12.5px; line-height: 18px; color: var(--dw-ink-3); } .tip dw-icon { font-size: 16px; color: var(--dw-primary); flex: none; }
    .file { display: inline-flex; align-items: center; gap: 6px; font-weight: 560; } .file dw-icon { font-size: 18px; }
    .cols { font-size: 12.5px; color: var(--dw-ink-2); } .cols summary { cursor: pointer; display: inline-flex; align-items: center; gap: 4px; font-weight: 560; color: var(--dw-primary); }
    .cols summary dw-icon { font-size: 16px; } .cols ul, .problems ul { margin: 4px 0 0; padding-inline-start: 18px; display: grid; gap: 2px; }
    .problems { padding: 8px 10px; border-radius: 9px; background: var(--dw-warning-soft); font-size: 12.5px; line-height: 1.45; }
    .problems small { font-size: 11px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: var(--dw-warning); }
    .attach { position: relative; flex: none; display: grid; place-items: center; width: 34px; height: 38px; border-radius: 10px; color: var(--dw-ink-2); cursor: pointer; }
    .attach:hover { background: var(--dw-surface-3); color: var(--dw-primary); } .attach:focus-within { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .attach input { position: absolute; inset: 0; opacity: 0; cursor: pointer; } .attach dw-icon { font-size: 21px; transform: rotate(35deg); }
    .ask:has(.attach) { padding-inline-start: 6px; }
    .dropzone { position: absolute; inset: var(--dw-shell-bar) 10px 10px; z-index: 2; display: grid; place-content: center; justify-items: center; gap: 6px; border-radius: 16px;
      background: color-mix(in srgb, var(--dw-primary-soft) 92%, transparent); box-shadow: inset 0 0 0 2px var(--dw-primary); color: var(--dw-primary); pointer-events: none; text-align: center; }
    .dropzone dw-icon { font-size: 40px; } .dropzone strong { font-size: 16px; } .dropzone small { font-size: 12.5px; color: var(--dw-ink-2); max-width: 260px; }
    .rate { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; margin-block-start: 6px; }
    .thumb { all: unset; cursor: pointer; display: grid; place-items: center; width: 28px; height: 28px; border-radius: 8px; color: var(--dw-ink-3); }
    .thumb:hover { background: var(--dw-surface-3); color: var(--dw-primary); } .thumb dw-icon { font-size: 16px; } .thumb:focus-visible { outline: 2px solid var(--dw-focus); }
    .thanks { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--dw-ink-3); } .thanks dw-icon { font-size: 14px; }
    .rate .remark { flex: 1 1 100%; min-height: 40px; padding: 7px 9px; border-radius: 9px; border: 1px solid var(--dw-line); background: var(--dw-surface); font: inherit; font-size: 13px; }
    .btn.small { min-height: 32px; padding: 4px 10px; font-size: 13px; }
    a.icon { text-decoration: none; }
    .fine { display: flex; align-items: center; justify-content: center; gap: 5px; margin: 7px 16px 12px; font-size: 11.5px; color: var(--dw-ink-3); text-align: center; }
    .fine dw-icon { font-size: 14px; }
    .icon:focus-visible, .chip:focus-visible, .btn:focus-visible, .more:focus-visible, .num:focus-visible, .send:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    /* Larger text, for who reads better so (remembered on the device) */
    :host(.big) .msg, :host(.big) .chip, :host(.big) textarea, :host(.big) .muted, :host(.big) .now, :host(.big) .links a, :host(.big) .btn { font-size: 16.5px; }
    :host(.big) .hello { font-size: 21px; } :host(.big) .chip, :host(.big) .btn { min-height: 48px; } :host(.big) .group-title, :host(.big) .fine { font-size: 13px; }
    @media (prefers-reduced-motion: reduce) { :host, :host(.open) { transition: none; } .msg { animation: none; } }
  `,
})
export class DaviPanelComponent {
  protected readonly davi = inject(Davi);
  private readonly session = inject(Session);
  private readonly title = inject(Title);
  private readonly scroller = viewChild<ElementRef<HTMLElement>>('scroller');
  private readonly input = viewChild<ElementRef<HTMLTextAreaElement>>('input');
  protected readonly last = computed(() => this.davi.messages().at(-1) ?? null);
  protected readonly name = computed(() => this.session.me()?.first_name ?? '');
  /** The page the user is on, by its title ("Types de déchets — DivaWaste" → "Types de déchets"). */
  protected readonly page = signal('');
  protected readonly expanded = signal<Set<number>>(new Set());
  protected readonly showHistory = signal(false);
  /** The answer whose thumbs down waits for its remark. */
  protected readonly remarking = signal<number | null>(null);
  protected readonly remarkText = signal('');
  /** A file is being dragged over the panel. */
  protected readonly dragging = signal(false);
  private readonly now = signal(Date.now());
  protected readonly seconds = computed(() => {
    const since = this.davi.since();
    return since === null ? 0 : Math.max(0, Math.round((this.now() - since) / 1000));
  });
  /** What DAVI is doing, by how long it has been at it (a laptop needs 10 to 20 seconds for a question the model reads). */
  protected readonly stage = computed(() => (this.seconds() < 2 ? 'reading' : this.seconds() < 6 ? 'searching' : this.seconds() < 14 ? 'thinking' : 'almost'));
  protected readonly big = signal(((): boolean => {
    try {
      return localStorage.getItem(BIG_KEY) === '1';
    } catch {
      return false;
    }
  })());

  constructor() {
    afterRenderEffect(() => {
      this.davi.messages();
      this.davi.busy();
      const element = this.scroller()?.nativeElement;
      element?.scrollTo({ top: element.scrollHeight, behavior: 'smooth' });
    });
    afterRenderEffect(() => {
      if (this.davi.open() || this.davi.draft()) {
        this.input()?.nativeElement.focus();
      }
    });
    const tick = setInterval(() => this.davi.busy() && this.now.set(Date.now()), 1000);
    const router = inject(Router);
    const sub = router.events.pipe(filter((event) => event instanceof NavigationEnd)).subscribe(() => setTimeout(() => this.page.set(this.pageName()), 50));
    setTimeout(() => this.page.set(this.pageName()), 50);
    inject(DestroyRef).onDestroy(() => {
      clearInterval(tick);
      sub.unsubscribe();
    });
  }

  /** The conversation shown (its id is DAVI's thread). */
  protected currentId(): string {
    return this.davi.conversationId;
  }

  protected startNew(): void {
    this.davi.reset();
    this.showHistory.set(false);
  }

  protected reopen(id: string): void {
    this.davi.openChat(id);
    this.showHistory.set(false);
  }

  private pageName(): string {
    const title = this.title.getTitle().split(/\s[—–-]\s/)[0].trim();
    return title === 'DivaWaste Textile' || title === 'DivaWaste' ? '' : title;
  }

  protected lines(text: string): Line[] {
    return text.split('\n').filter((line) => line.trim() !== '').map((line) => ({
      bullet: /^\s*[•\-*]\s/.test(line),
      pieces: line.split(NUMBER_RE).filter((part) => part !== '').map((part) => ({ text: part, number: NUMBER_RE.test(part) && /^[A-Z]+-\d/.test(part) })),
    }));
  }

  protected long(message: DaviMessage): boolean {
    return message.text.length > SHORT && this.lead(message.text).pieces.map((piece) => piece.text).join('').length < message.text.length - 20;
  }

  /** The first sentence of an answer (or its first line). */
  protected lead(text: string): Line {
    const first = text.split('\n').find((line) => line.trim() !== '') ?? text;
    const sentence = first.match(/^.{20,}?[.!?](\s|$)/)?.[0]?.trim() ?? first;
    return this.lines(sentence)[0] ?? { bullet: false, pieces: [{ text }] };
  }

  protected unfold(id: number): void {
    this.expanded.update((set) => new Set([...set, id]));
  }

  protected fold(id: number): void {
    this.expanded.update((set) => new Set([...set].filter((value) => value !== id)));
  }

  protected firstNumber(message: DaviMessage): string | null {
    return message.text.match(NUMBER_RE)?.[0] ?? null;
  }

  protected seriesNames(reply: DaviReply): string[] {
    return Object.keys(reply.chart?.series ?? {});
  }

  protected total(reply: DaviReply, index: number): string {
    const sum = this.seriesNames(reply).reduce((acc, name) => acc + (reply.chart?.series[name][index] ?? 0), 0);
    return new Intl.NumberFormat(reply.lang === 'en' ? 'en-GB' : 'fr-FR', { maximumFractionDigits: 0 }).format(sum);
  }

  protected share(reply: DaviReply, name: string, index: number): number {
    const chart = reply.chart;
    if (!chart) {
      return 0;
    }
    const max = Math.max(1, ...chart.labels.map((_, i) => this.seriesNames(reply).reduce((acc, n) => acc + (chart.series[n][i] ?? 0), 0)));
    return ((chart.series[name][index] ?? 0) / max) * 100;
  }

  protected path(link: string): string {
    return link.split('?')[0];
  }

  protected query(link: string): Record<string, string> | null {
    const query = link.split('?')[1];
    return query ? Object.fromEntries(new URLSearchParams(query)) : null;
  }

  /** On a phone the panel covers the page: it closes when DAVI leads elsewhere. With a button, DAVI lights it up. */
  protected follow(button?: string): void {
    if (window.innerWidth < 720) {
      this.davi.open.set(false);
    }
    if (button) {
      this.davi.spotlight(button);
    }
  }

  protected toggleBig(): void {
    this.big.set(!this.big());
    try {
      localStorage.setItem(BIG_KEY, this.big() ? '1' : '0');
    } catch {
      // Private browsing: for the page only.
    }
  }

  protected actionsOf(list: DaviSuggestion[]): DaviSuggestion[] {
    return list.filter((s) => s.kind !== 'ask');
  }

  protected questionsOf(list: DaviSuggestion[]): DaviSuggestion[] {
    return list.filter((s) => s.kind === 'ask');
  }

  /** A sentence to complete reads as one: "Pèse le lot L-2026-000001 à …". */
  protected label(suggestion: DaviSuggestion): string {
    const text = suggestion.text.trim().replace(/[:\s]+$/, '');
    return suggestion.kind === 'draft' && /[\s:]$/.test(suggestion.text) ? `${text}…` : text;
  }

  protected use(suggestion: DaviSuggestion): void {
    this.davi.use(suggestion);
  }

  protected enter(event: Event): void {
    const key = event as KeyboardEvent;
    if (!key.shiftKey) {
      key.preventDefault();
      this.send();
    }
  }

  protected send(): void {
    void this.davi.ask(this.davi.draft());
  }

  protected async sendRate(message: DaviMessage, verdict: 'up' | 'down'): Promise<void> {
    try {
      await this.davi.rate(message, verdict, verdict === 'down' ? this.remarkText() : undefined);
    } catch {
      // DAVI away: the thumbs are not kept, the conversation goes on
    }
    this.remarking.set(null);
  }

  protected attach(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (file) {
      void this.davi.importLots(file);
    }
  }

  /** Rows copied from Excel and pasted in the input are a file of lots, not a question (and may be longer than one). */
  protected paste(event: ClipboardEvent): void {
    const text = event.clipboardData?.getData('text/plain') ?? '';
    if (this.davi.canImport() && looksLikeTable(text)) {
      event.preventDefault();
      void this.davi.importRows(text);
    }
  }

  protected over(event: DragEvent): void {
    if (this.davi.canImport() && event.dataTransfer?.types.includes('Files')) {
      event.preventDefault();
      this.dragging.set(true);
    }
  }

  protected leave(event: DragEvent): void {
    // leaving for a child of the panel is not leaving the panel
    if (!(event.relatedTarget instanceof Node) || !(event.currentTarget as HTMLElement).contains(event.relatedTarget)) {
      this.dragging.set(false);
    }
  }

  protected drop(event: DragEvent): void {
    this.dragging.set(false);
    const file = event.dataTransfer?.files?.[0];
    if (file && this.davi.canImport()) {
      event.preventDefault();
      void this.davi.importLots(file);
    }
  }
}
