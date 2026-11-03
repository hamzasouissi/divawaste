import { Injectable, inject, signal } from '@angular/core';
import { NavigationEnd, Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { filter } from 'rxjs/operators';

import { Api } from '../api/api';
import { Session } from '../auth/session';
import { Toast } from '../feedback/toast';

/** What DAVI's answer is: a reply, an action waiting for "oui", a question back, an action done or not done. */
export type DaviKind = 'answer' | 'preview' | 'ask' | 'done' | 'cancelled' | 'refused';

export interface DaviSuggestion {
  /** ask: sent as is; draft: put in the input for the user to complete (a weight, a zone, a date); go: opens the page
   *  where the user does it (what DAVI cannot do here). */
  kind: 'ask' | 'draft' | 'go';
  text: string;
  path?: string;
  /** The button of that page DAVI lights up. */
  button?: string;
}

/** What the card's button does: on its page, light up the button; elsewhere, open that page (and light it up there). */
export interface DaviCardAction {
  label: string;
  path: string;
  button: string | null;
  here: boolean;
}

/** One step of the company's start: done, skipped (in the setup), current or to do. */
export interface DaviStep {
  key: string;
  label: string;
  state: 'done' | 'skipped' | 'current' | 'todo';
  path: string;
}

/**
 * DAVI's one card on the page (davi/journey.py, screens.hint): the start of the company while it is not finished (what
 * was just done, the current step and why, its action, the steps), else what waits on this page, else that nothing does.
 */
export interface DaviCard {
  kind: 'start' | 'advice' | 'todo' | 'clear';
  step: string | null;
  done: string | null;
  title: string;
  text: string | null;
  action: DaviCardAction | null;
  alt?: { label: string; draft: string } | null;
  progress: { done: number; total: number; steps: DaviStep[] } | null;
}

export interface DaviLink {
  label: string;
  path: string;
  button?: string;
}

export interface DaviReply {
  answer: string;
  kind: DaviKind;
  lang: 'fr' | 'en';
  /** Verified records the answer is made of. */
  sources: { id: string; title: string }[];
  /** Pages the answer leads to; with a button, the one DAVI lights up on that page ("montrez-moi"). */
  links: DaviLink[];
  /** Figures to draw: one value per label for each series. */
  chart: { x: string; labels: string[]; series: Record<string, number[]> } | null;
  suggestions: DaviSuggestion[];
  /** An action waiting for the user's yes: its name, its number of steps, whether it can be undone. */
  action?: { op: string; label: string; steps: number; irreversible: boolean } | null;
  /** A file DAVI read: which column it took for what, and the lines it set aside. */
  details?: string[];
  problems?: { line: number; message: string }[];
  tier: string;
  ms: number;
}

export interface DaviMessage {
  id: number;
  from: 'user' | 'davi';
  text: string;
  reply?: DaviReply;
  failed?: boolean;
  /** The name of a file the user gave DAVI. */
  file?: string;
  /** The user's thumbs on this answer. */
  rated?: 'up' | 'down';
}

/** The lot or pickup the page shows: what "ce lot", "it", "il pèse combien ?" mean. */
export interface DaviFocus {
  kind: 'lot' | 'pickup';
  number: string;
}

interface ImportField {
  field: string;
  label: string;
  required: boolean;
}

interface ImportPreview {
  headers: string[];
  lines: number;
  fields: ImportField[];
  mapping: Record<string, number | null>;
}

interface ImportResult {
  total: number;
  created: number;
  unchanged: number;
  errors: { line: number; message: string }[];
}

/** A file of lots read and checked, waiting for the user's yes. */
interface ImportJob {
  file: File;
  mapping: Record<string, number | null>;
  headers: string[];
}

const LOT_FILE = /\.(xlsx|xls|csv|txt)$/i;

/** Rows copied from a spreadsheet: several lines whose cells are separated by tabs (or semicolons). */
export function looksLikeTable(text: string): boolean {
  const lines = text.trim().split(/\r?\n/).filter((line) => line.trim() !== '');
  return lines.length >= 2 && (lines.every((line) => line.includes('\t')) || ((lines[0].match(/;/g) ?? []).length >= 2 && lines.every((line) => line.includes(';'))));
}

const CONVERSATION_KEY = 'dw.davi.conversation';
const HISTORY_KEY = 'dw.davi.chats';
const HISTORY_MAX = 12;

/** A past conversation with DAVI, kept on this device for its user: reopened from the panel's history. */
export interface DaviChat {
  id: string;
  title: string;
  at: number;
  messages: DaviMessage[];
}

/** The words of a button as the user reads them: without its icon's ligature, spaces and case folded. */
function labelOf(element: HTMLElement): string {
  const copy = element.cloneNode(true) as HTMLElement;
  copy.querySelectorAll('dw-icon, .material-icons, .material-icons-outlined').forEach((icon) => icon.remove());
  return fold(copy.textContent ?? '') || fold(element.getAttribute('aria-label') ?? '');
}

function fold(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim().toLowerCase();
}

function newConversation(): string {
  const id = `c${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;
  try {
    sessionStorage.setItem(CONVERSATION_KEY, id);
  } catch {
    // Private browsing: the conversation lasts for the page.
  }

  return id;
}

/**
 * DAVI, the assistant, beside every screen. It answers from the verified knowledge and the user's own data, finds the
 * page or button for a task, says what waits, and prepares actions that run only after the user's explicit yes —
 * always with the user's rights (the API gives it a short-lived token of the user). The page the user is on and the
 * lot or pickup it shows go with every question.
 */
@Injectable({ providedIn: 'root' })
export class Davi {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly router = inject(Router);
  private readonly session = inject(Session);
  /** Past conversations of this user on this device, most recent first. */
  readonly history = signal<DaviChat[]>([]);

  readonly available = signal(false);
  readonly open = signal(false);
  readonly busy = signal(false);
  readonly messages = signal<DaviMessage[]>([]);
  readonly suggestions = signal<DaviSuggestion[]>([]);
  /** What DAVI says by itself on this page, before any question (the setup step, what waits here, the next step). */
  readonly note = signal<string | null>(null);
  /** DAVI's card on this page. */
  readonly card = signal<DaviCard | null>(null);
  /** The page's own explanation (help.<topic>), shown inside DAVI's card instead of beside it. */
  readonly helpTopic = signal<string | null>(null);
  /** How many page titles show DAVI's card (dw-page-header): the shell's own card is only for pages without one. */
  readonly headers = signal<symbol[]>([]);
  readonly focus = signal<DaviFocus | null>(null);
  /** Text put in the input (a suggestion to complete, a question from a help panel). */
  readonly draft = signal('');
  /** An action waits for the user's yes or no. */
  readonly pending = signal(false);
  /** When DAVI started on the current question (what the panel shows while it works). */
  readonly since = signal<number | null>(null);
  /** The last question asked: "Réessayer" after a failure, "Modifier" on an action's card. */
  readonly lastQuestion = signal('');
  /** A file of lots handed to the import page ("Choisir les colonnes"), which takes it on opening. */
  readonly handoff = signal<File | null>(null);

  private conversation = ((): string => {
    try {
      return sessionStorage.getItem(CONVERSATION_KEY) ?? newConversation();
    } catch {
      return newConversation();
    }
  })();
  private sequence = 0;
  private checked = false;
  private historyOwner = '';
  private loading?: ReturnType<typeof setTimeout>;
  private importing: ImportJob | null = null;

  constructor() {
    // The page changed: its suggestions change with it (DAVI speaks up on the page even with the panel closed); the
    // lot or pickup of the old page is no longer "this one".
    this.router.events.pipe(filter((event) => event instanceof NavigationEnd)).subscribe(() => {
      this.focus.set(null);
      this.suggestions.set([]);
      this.note.set(null);
      this.card.set(null);
      this.loadSuggestions();
    });
    // Something was saved (a site, a zone, a lot…): the card says at once that it is done and what comes next.
    this.api.changed?.subscribe(() => this.loadSuggestions(700));
  }

  /** The conversation shown: DAVI's thread of it. */
  get conversationId(): string {
    return this.conversation;
  }

  private historyKey(): string {
    return `${HISTORY_KEY}.${this.session.me()?.email ?? 'anyone'}`;
  }

  /** The history of the signed-in user (another user on the same browser has their own). */
  private loadHistory(): void {
    const owner = this.historyKey();
    if (owner === this.historyOwner) {
      return;
    }
    this.historyOwner = owner;
    try {
      this.history.set(JSON.parse(localStorage.getItem(owner) ?? '[]') as DaviChat[]);
    } catch {
      this.history.set([]);
    }
  }

  /** The conversation in progress, kept in the history (titled by its first question). */
  private remember(): void {
    const messages = this.messages();
    const first = messages.find((message) => message.from === 'user');
    if (!first) {
      return;
    }
    this.loadHistory();
    const chat: DaviChat = { id: this.conversation, title: first.text.slice(0, 80), at: Date.now(), messages: messages.slice(-60) };
    const list = [chat, ...this.history().filter((item) => item.id !== chat.id)].slice(0, HISTORY_MAX);
    this.history.set(list);
    try {
      localStorage.setItem(this.historyOwner, JSON.stringify(list));
    } catch {
      // Full or private storage: the history lasts for the visit.
    }
  }

  /** A past conversation, reopened where it was (DAVI remembers it for two hours; after that it starts afresh). */
  openChat(id: string): void {
    const chat = this.history().find((item) => item.id === id);
    if (!chat) {
      return;
    }
    this.conversation = id;
    try {
      sessionStorage.setItem(CONVERSATION_KEY, id);
    } catch {
      // Private browsing: for the page only.
    }
    this.sequence = Math.max(0, ...chat.messages.map((message) => message.id));
    this.importing = null;
    this.messages.set(chat.messages);
    this.pending.set(false);
    this.draft.set('');
  }

  /** A past conversation removed from the history, and DAVI's thread of it with it. */
  forgetChat(id: string): void {
    this.api.post('davi/forget', { conversation: id }).subscribe({ error: () => undefined });
    const list = this.history().filter((item) => item.id !== id);
    this.history.set(list);
    try {
      localStorage.setItem(this.historyKey(), JSON.stringify(list));
    } catch {
      // nothing kept
    }
    if (id === this.conversation) {
      this.reset();
    }
  }

  /** Once per session, after sign-in: whether this server has DAVI. */
  check(): void {
    if (this.checked) {
      return;
    }
    this.checked = true;
    this.api.get<{ available: boolean }>('davi/status').subscribe({
      next: (status) => {
        this.available.set(status.available);
        this.loadSuggestions();
      },
      error: () => this.available.set(false),
    });
  }

  /** Opens the panel; with a question, asks it (or leaves it in the input to complete). */
  show(question?: string, send = true): void {
    this.open.set(true);
    this.loadHistory();
    if (question && send) {
      void this.ask(question);
    } else if (question) {
      this.draft.set(question);
    }
  }

  toggle(): void {
    if (this.open()) {
      this.open.set(false);
    } else {
      this.show();
    }
  }

  /** The page shows this lot or pickup (null when it is left). */
  setFocus(focus: DaviFocus | null): void {
    this.focus.set(focus);
    this.loadSuggestions();
  }

  private screen(): string {
    return this.router.url.split(/[?#]/)[0];
  }

  /** The page's suggestions, once the page has settled (a detail page names its lot or pickup just after opening). */
  loadSuggestions(delay = 250): void {
    clearTimeout(this.loading);
    if (!this.available()) {
      return;
    }
    this.loading = setTimeout(() => {
      this.api.post<{ suggestions: DaviSuggestion[]; note?: string | null; card?: DaviCard | null }>('davi/suggest', { screen: this.screen(), entity: this.focus() }).subscribe({
        next: (data) => {
          this.suggestions.set(data.suggestions);
          this.note.set(data.note ?? null);
          this.card.set(data.card ?? null);
        },
        error: () => {
          this.suggestions.set([]);
          this.note.set(null);
          this.card.set(null);
        },
      });
    }, delay);
  }

  /** The card's button: its page's button lit up here, or its page opened (with the button lit up there). */
  act(action: DaviCardAction): void {
    if (action.here && action.button) {
      this.open.set(false);
      this.spotlight(action.button);
    } else {
      this.goTo(action.path, action.button ?? undefined);
    }
  }

  /** A suggestion chosen, in the panel or on the page: a question is asked, a sentence to complete is put in the
   *  input, a page is opened. */
  use(suggestion: DaviSuggestion): void {
    if (suggestion.kind === 'go' && suggestion.path) {
      // a page already open is not opened again: its button is lit up
      if (suggestion.path.split('?')[0] === this.screen() && suggestion.button) {
        this.spotlight(suggestion.button);
      } else {
        this.goTo(suggestion.path, suggestion.button);
      }
    } else {
      this.show(suggestion.text, suggestion.kind === 'ask');
    }
  }

  /** Opens a page; with a button, lights it up there so the user sees where it is. */
  goTo(path: string, button?: string): void {
    void this.router.navigateByUrl(path).then(() => {
      if (button) {
        this.spotlight(button);
      }
    });
  }

  /** Lights up the button with these words on the page (once it is drawn): scrolled into view, ringed for a while. */
  spotlight(label: string): void {
    const wanted = fold(label);
    let tries = 0;
    const look = (): void => {
      // the page's own buttons: never DAVI's card or panel, whose button carries the same words (it would light itself up)
      const candidates = [...document.querySelectorAll<HTMLElement>('main button, main a, main [role="button"], main [role="tab"]')]
        .filter((element) => element.offsetParent !== null && !element.closest('dw-davi-hint, dw-davi-panel'));
      const found = candidates.find((element) => labelOf(element) === wanted) ?? candidates.find((element) => labelOf(element).includes(wanted));
      if (!found) {
        if (++tries < 30) {
          setTimeout(look, 150);
        } else {
          this.toast.info('davi.hint.notShown', { button: label });
        }
        return;
      }
      // On a narrow screen, or when the panel would hide it, the panel steps aside.
      if (this.open() && (window.innerWidth < 720 || found.getBoundingClientRect().right > window.innerWidth - 440)) {
        this.open.set(false);
      }
      found.scrollIntoView({ block: 'center', behavior: 'smooth' });
      found.classList.add('dw-spot');
      found.focus({ preventScroll: true });
      setTimeout(() => found.classList.remove('dw-spot'), 4500);
    };
    setTimeout(look, 100);
  }

  async ask(text: string): Promise<void> {
    const message = text.trim();
    if (!message || this.busy()) {
      return;
    }
    // A file of lots waits for its yes: "oui" / "non" answer it here; anything else drops it.
    if (this.importing) {
      if (/^(oui|non|yes|no)$/i.test(message)) {
        this.draft.set('');
        return this.finishImport(/^(oui|yes)$/i.test(message));
      }
      this.importing = null;
      this.pending.set(false);
    }
    if (looksLikeTable(message) && this.canImport()) {
      this.draft.set('');
      return this.importRows(message);
    }
    this.draft.set('');
    this.push({ from: 'user', text: message });
    if (!/^(oui|non|yes|no)$/i.test(message)) {
      this.lastQuestion.set(message);
    }
    this.busy.set(true);
    this.since.set(Date.now());
    try {
      const reply = await firstValueFrom(this.api.post<DaviReply>('davi/turn', { conversation: this.conversation, message, screen: this.screen(), entity: this.focus() }));
      this.push({ from: 'davi', text: reply.answer, reply });
      this.pending.set(reply.kind === 'preview');
      // A done action changed the data on screen: the page reloads what it shows.
      if (reply.kind === 'done') {
        // what DAVI changed was written by the API directly: the lists of the forms and DAVI's card follow
        this.api.changed?.next('davi:done');
        this.refreshPage();
      }
      // "Où est … ?": the page DAVI found is opened and its button lit up, without a second click.
      const shown = reply.links.find((link) => link.button);
      if (shown && reply.links.length === 1) {
        this.goTo(shown.path, shown.button);
      }
    } catch (error) {
      this.push({ from: 'davi', text: this.toast.describe(error), failed: true });
      this.pending.set(false);
    } finally {
      this.busy.set(false);
      this.since.set(null);
    }
  }

  /** The last question again, after a failure. */
  retry(): Promise<void> {
    return this.ask(this.lastQuestion());
  }

  /** Back to the input with the question behind an action, to change it (the action waiting is dropped). */
  edit(): void {
    if (this.importing) {
      // The columns of a file are chosen on the import page, which opens with this file.
      this.handoff.set(this.importing.file);
      this.importing = null;
      this.pending.set(false);
      this.say('answer', this.toast.text('davi.import.toPage'));
      this.goTo('/lots/import');
      return;
    }
    const question = this.lastQuestion();
    void this.ask(this.locale() === 'en' ? 'no' : 'non').then(() => this.draft.set(question));
  }

  private locale(): 'fr' | 'en' {
    const last = [...this.messages()].reverse().find((message) => message.reply);
    return last?.reply?.lang ?? 'fr';
  }

  confirm(yes: boolean): Promise<void> {
    return this.importing ? this.finishImport(yes) : this.ask(yes ? 'oui' : 'non');
  }

  /** A new conversation; the one left stays in the history (and DAVI keeps its thread two hours, to reopen it). */
  reset(): void {
    this.conversation = newConversation();
    this.importing = null;
    this.messages.set([]);
    this.pending.set(false);
    this.draft.set('');
  }

  /** Opens what a number of the answer refers to (a lot, a pickup), through the search of the application. */
  async go(number: string): Promise<void> {
    try {
      const found = await firstValueFrom(this.api.get<{ link: string }[]>('search', { q: number }));
      if (found[0]) {
        await this.router.navigateByUrl(found[0].link);
      }
    } catch {
      // The number stays plain text.
    }
  }

  /** A thumbs up or down on one of DAVI's answers, with a remark: kept by DAVI for its next training. */
  async rate(message: DaviMessage, verdict: 'up' | 'down', remark?: string): Promise<void> {
    const list = this.messages();
    const index = list.findIndex((item) => item.id === message.id);
    const question = [...list.slice(0, index)].reverse().find((item) => item.from === 'user')?.text ?? '';
    await firstValueFrom(this.api.post('davi/feedback', { question, answer: message.text, tier: message.reply?.tier ?? null, screen: this.screen(), verdict,
      remark: remark?.trim() || null }));
    this.messages.update((items) => items.map((item) => (item.id === message.id ? { ...item, rated: verdict } : item)));
    this.remember();
  }

  /** Whether the user creates lots somewhere: DAVI then takes their files of lots. */
  canImport(): boolean {
    return this.session.sitesFor('lots.create').length > 0;
  }

  /** Rows pasted from a spreadsheet, read as a file. */
  importRows(text: string): Promise<void> {
    return this.importLots(new File([text], 'lignes-collees.csv', { type: 'text/csv' }), true);
  }

  /**
   * A file of lots given to DAVI (picked, dropped, or rows pasted from a spreadsheet): read and checked line by line
   * without writing anything, then shown as an action to confirm, like any other. The import is the application's own
   * (the same check and the same rules as the import page), with the user's rights; DAVI's server never sees the file.
   */
  async importLots(file: File, pasted = false): Promise<void> {
    if (this.busy()) {
      return;
    }
    this.open.set(true);
    this.importing = null;
    this.pending.set(false);
    this.lastQuestion.set('');
    this.push({ from: 'user', text: pasted ? this.toast.text('davi.import.pasted') : file.name, file: file.name });
    if (!LOT_FILE.test(file.name)) {
      this.say('refused', this.toast.text('davi.import.kind'));
      return;
    }
    this.busy.set(true);
    this.since.set(Date.now());
    try {
      const preview = await firstValueFrom(this.api.post<ImportPreview>('production/imports/preview', this.form(file)));
      const mapping = { ...preview.mapping, ...(await this.savedColumns(preview.headers)) };
      const choose = [{ label: this.toast.text('davi.import.choose'), path: '/lots/import' }];
      const missing = preview.fields.filter((field) => field.required && (mapping[field.field] ?? null) === null);
      if (missing.length > 0) {
        this.handoff.set(file);
        this.say('answer', this.toast.text('davi.import.missing', { fields: missing.map((field) => field.label).join(', ') }), choose);
        return;
      }
      const check = await firstValueFrom(this.api.post<ImportResult>('production/imports/run', this.form(file, mapping, true)));
      const details = preview.fields.filter((field) => (mapping[field.field] ?? null) !== null).map((field) => `${field.label} ← « ${preview.headers[mapping[field.field] as number]} »`);
      const problems = check.errors.slice(0, 6);
      if (check.created === 0) {
        this.handoff.set(file);
        this.say('answer', this.count(check.errors.length === 0 ? 'davi.import.already' : 'davi.import.nothing', check.unchanged), choose, { details, problems });
        return;
      }
      this.importing = { file, mapping, headers: preview.headers };
      const text = [this.count('davi.import.preview', check.created, { total: check.total }),
        ...(check.unchanged > 0 ? [this.count('davi.import.unchanged', check.unchanged)] : []),
        ...(check.errors.length > 0 ? [this.count('davi.import.skipped', check.errors.length)] : [])];
      this.say('preview', text.join('\n'), [], { action: { op: 'importLots', label: this.count('davi.import.label', check.created), steps: check.created, irreversible: false }, details, problems });
      this.pending.set(true);
    } catch (error) {
      this.push({ from: 'davi', text: this.toast.describe(error), failed: true });
    } finally {
      this.busy.set(false);
      this.since.set(null);
    }
  }

  /** The yes or no to a file of lots: imported (only what the check accepted), or nothing. */
  private async finishImport(yes: boolean): Promise<void> {
    const job = this.importing;
    if (!job || this.busy()) {
      return;
    }
    this.importing = null;
    this.pending.set(false);
    this.push({ from: 'user', text: this.toast.text(yes ? 'davi.yes' : 'davi.no') });
    if (!yes) {
      this.say('cancelled', this.toast.text('davi.import.cancelled'));
      return;
    }
    this.busy.set(true);
    this.since.set(Date.now());
    try {
      const done = await firstValueFrom(this.api.post<ImportResult>('production/imports/run', this.form(job.file, job.mapping)));
      const text = [this.count('davi.import.done', done.created), ...(done.errors.length > 0 ? [this.count('davi.import.notDone', done.errors.length)] : [])];
      this.say('done', text.join('\n'), [{ label: this.toast.text('davi.import.seeLots'), path: '/lots' }], { problems: done.errors.slice(0, 6) });
      // The columns are found again next time by their headings, here and on the import page.
      const byHeading = Object.fromEntries(Object.entries(job.mapping).filter(([, column]) => column !== null && job.headers[column]).map(([field, column]) => [field, job.headers[column as number]]));
      this.api.put('company/import-mappings/waste_lots', { mapping: byHeading }).subscribe({ error: () => undefined });
      this.api.changed?.next('davi:done');
      if (['/lots', '/stock', '/dashboard'].includes(this.screen())) {
        this.refreshPage();
      }
    } catch (error) {
      this.push({ from: 'davi', text: this.toast.describe(error), failed: true });
    } finally {
      this.busy.set(false);
      this.since.set(null);
    }
  }

  private form(file: File, mapping?: Record<string, number | null>, dryRun = false): FormData {
    const body = new FormData();
    body.append('file', file);
    body.append('target', 'waste_lots');
    for (const [field, column] of Object.entries(mapping ?? {})) {
      if (column !== null && column !== undefined) {
        body.append(`mapping[${field}]`, String(column));
      }
    }
    if (dryRun) {
      body.append('dry_run', '1');
    }

    return body;
  }

  /** The columns chosen last time for a file of lots, found again by their headings. */
  private async savedColumns(headers: string[]): Promise<Record<string, number>> {
    try {
      const saved = (await firstValueFrom(this.api.get<Record<string, Record<string, string>>>('company/import-mappings')))?.['waste_lots'] ?? {};
      return Object.fromEntries(Object.entries(saved).map(([field, heading]) => [field, headers.indexOf(heading)]).filter(([, index]) => (index as number) >= 0));
    } catch {
      return {};
    }
  }

  /** A sentence with a number: its own words for one ("1 lot", not "1 lots"). */
  private count(key: string, count: number, params: Record<string, unknown> = {}): string {
    return this.toast.text(count === 1 ? `${key}One` : key, { count, ...params });
  }

  /** DAVI's own words, made here: a file is imported by the application, not by DAVI's server. */
  private say(kind: DaviKind, answer: string, links: DaviLink[] = [], extra: Partial<DaviReply> = {}): void {
    const lang = document.documentElement.lang.startsWith('en') ? 'en' : 'fr';
    this.push({ from: 'davi', text: answer, reply: { answer, kind, lang, sources: [], links, chart: null, suggestions: [], tier: 'app', ms: 0, ...extra } });
  }

  private push(message: Omit<DaviMessage, 'id'>): void {
    this.messages.update((messages) => [...messages, { ...message, id: ++this.sequence }]);
    this.remember();
  }

  private refreshPage(): void {
    const url = this.router.url;
    void this.router.navigateByUrl('/', { skipLocationChange: true }).then(() => this.router.navigateByUrl(url));
  }
}
