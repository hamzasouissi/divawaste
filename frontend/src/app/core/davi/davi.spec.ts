import { provideZonelessChangeDetection } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { NavigationEnd, Router } from '@angular/router';
import { Subject, of, throwError } from 'rxjs';

import { Api } from '../api/api';
import { Session } from '../auth/session';
import { Toast } from '../feedback/toast';
import { Davi, DaviReply } from './davi';

const reply = (kind: DaviReply['kind'], answer: string): DaviReply =>
  ({ answer, kind, lang: 'fr', sources: [], links: [], chart: null, suggestions: [], tier: 'model', ms: 10 });

describe('Davi', () => {
  let posted: { path: string; body: Record<string, unknown> }[];
  let next: () => unknown;
  let events: Subject<unknown>;
  let davi: Davi;
  let suggested: { suggestions: unknown[]; note?: string | null };

  beforeEach(() => {
    posted = [];
    next = () => of(reply('answer', 'Bonjour'));
    events = new Subject();
    suggested = { suggestions: [] };
    const api = {
      get: () => of({ available: true }),
      post: (path: string, body: Record<string, unknown>) => {
        posted.push({ path, body });
        if (path === 'production/imports/preview') {
          return of({ headers: ['Site', 'Déchet', 'Poids'], lines: 3, mapping: { site: 0, waste_type: 1, net_weight_kg: 2 },
            fields: [{ field: 'site', label: 'Site', required: true }, { field: 'waste_type', label: 'Type de déchet', required: true }, { field: 'net_weight_kg', label: 'Poids (kg)', required: false }] });
        }
        if (path === 'production/imports/run') {
          return of({ total: 3, created: 2, unchanged: 0, errors: [{ line: 4, message: 'Site inconnu : « X ».' }] });
        }
        return path === 'davi/turn' ? (next() as ReturnType<typeof of>) : of(suggested);
      },
      put: () => of(undefined),
      changed: new Subject<string>(),
    };
    const router = { url: '/lots/01ABC?tab=x', events, navigateByUrl: () => Promise.resolve(true) };
    TestBed.configureTestingModule({
      providers: [provideZonelessChangeDetection(), { provide: Api, useValue: api }, { provide: Router, useValue: router },
        { provide: Toast, useValue: { describe: () => 'DAVI ne répond pas.', text: (key: string) => key } }, { provide: Session, useValue: { me: () => null, sitesFor: () => [{}] } }],
    });
    davi = TestBed.inject(Davi);
  });

  it('sends the page and the lot it shows with the question', async () => {
    davi.setFocus({ kind: 'lot', number: 'L-2026-000088' });
    await davi.ask('Où en est ce lot ?');

    const turn = posted.find((call) => call.path === 'davi/turn');
    expect(turn?.body['screen']).toBe('/lots/01ABC');
    expect(turn?.body['entity']).toEqual({ kind: 'lot', number: 'L-2026-000088' });
    expect(davi.messages().map((message) => message.from)).toEqual(['user', 'davi']);
  });

  it('runs a previewed action only on the explicit confirmation', async () => {
    next = () => of(reply('preview', 'Je vais peser le lot… Confirmez-vous ?'));
    await davi.ask('Pèse ce lot à 42 kg');
    expect(davi.pending()).toBe(true);

    next = () => of(reply('done', "C'est fait."));
    await davi.confirm(true);

    expect(posted.filter((call) => call.path === 'davi/turn').map((call) => call.body['message'])).toEqual(['Pèse ce lot à 42 kg', 'oui']);
    expect(davi.pending()).toBe(false);
  });

  it('forgets the lot of a page that was left, and says when it fails', async () => {
    davi.setFocus({ kind: 'lot', number: 'L-2026-000088' });
    events.next(new NavigationEnd(1, '/stock', '/stock'));
    expect(davi.focus()).toBeNull();

    next = () => throwError(() => new Error('down'));
    await davi.ask('bonjour');
    expect(davi.messages().at(-1)).toEqual(expect.objectContaining({ from: 'davi', failed: true, text: 'DAVI ne répond pas.' }));
  });

  it('checks rows pasted from a spreadsheet and imports them as lots only on the yes', async () => {
    await davi.ask(['Site\tDéchet\tPoids', 'SFX\tChutes\t120', 'SFX\tChutes\t80'].join('\n'));

    const runs = () => posted.filter((call) => call.path === 'production/imports/run').map((call) => (call.body as unknown as FormData).get('dry_run'));
    expect(posted.some((call) => call.path === 'davi/turn')).toBe(false);
    expect(runs()).toEqual(['1']);
    expect(davi.pending()).toBe(true);
    const card = davi.messages().at(-1)?.reply;
    expect(card?.kind).toBe('preview');
    expect(card?.action?.label).toBe('davi.import.label');
    expect(card?.problems).toEqual([{ line: 4, message: 'Site inconnu : « X ».' }]);

    await davi.confirm(true);
    expect(runs()).toEqual(['1', null]);
    expect(davi.pending()).toBe(false);
    expect(davi.messages().at(-1)?.reply?.kind).toBe('done');
    expect(posted.some((call) => call.path === 'davi/turn')).toBe(false);
  });

  it('puts a suggestion to complete in the input instead of sending it', () => {
    davi.use({ kind: 'draft', text: 'Pèse le lot L-2026-000088 à ' });

    expect(davi.draft()).toBe('Pèse le lot L-2026-000088 à ');
    expect(posted.some((call) => call.path === 'davi/turn')).toBe(false);
  });

  it('says what it proposes on a new page with the panel closed, once the page has settled', () => {
    vi.useFakeTimers();
    suggested = { note: '3 lots ne sont pas pesés.', suggestions: [{ kind: 'draft', text: 'Pèse le lot L-2026-000088 à ' }] };
    davi.check();
    events.next(new NavigationEnd(1, '/lots', '/lots'));
    davi.setFocus({ kind: 'lot', number: 'L-2026-000088' });
    vi.advanceTimersByTime(300);
    vi.useRealTimers();

    // one request for the settled page (with its lot), not one per step of the navigation
    const asked = posted.filter((call) => call.path === 'davi/suggest');
    expect(asked.length).toBe(1);
    expect(asked[0].body['entity']).toEqual({ kind: 'lot', number: 'L-2026-000088' });
    expect(davi.open()).toBe(false);
    expect(davi.note()).toBe('3 lots ne sont pas pesés.');
  });

  it('opens the panel with a question asked, or a sentence to complete left in the input', async () => {
    davi.use({ kind: 'ask', text: 'Que puis-je faire sur cette page ?' });
    await Promise.resolve();
    expect(davi.open()).toBe(true);
    expect(posted.filter((call) => call.path === 'davi/turn').map((call) => call.body['message'])).toEqual(['Que puis-je faire sur cette page ?']);
  });
});
