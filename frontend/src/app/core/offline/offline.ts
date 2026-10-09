import { Injectable, computed, inject, signal } from '@angular/core';
import Dexie, { Table } from 'dexie';
import { firstValueFrom } from 'rxjs';
import { ulid } from 'ulid';

import { Api } from '../api/api';
import { Lot, OpenOrder, Site, WasteType } from '../api/models';

export type OperationStatus = 'pending' | 'synced' | 'failed' | 'conflict';

/** One action recorded on the device, replayed to the server as soon as it can be reached (M2-08). */
export interface Operation {
  /** Idempotency key: the server answers a replay with the first outcome instead of acting twice. */
  id: string;
  sequence: number;
  type: 'lot.create' | 'lot.store';
  occurred_at: string;
  payload: Record<string, unknown>;
  status: OperationStatus;
  /** What the operator sees in the queue: "Chutes de coupe — 42 kg". */
  summary: string;
  error?: string;
  lot_number?: string;
  entity?: string;
  /** Who recorded it ("company:user"). It is sent with that person's device token, whoever uses the tablet afterwards. */
  owner?: string;
}

export interface Bootstrap {
  server_time: string;
  sites: Site[];
  waste_types: WasteType[];
  /** Open manufacturing orders of these sites (absent on data saved before they existed). */
  manufacturing_orders?: OpenOrder[];
}

interface Meta {
  key: string;
  value: unknown;
}

interface Device {
  ulid: string;
  token: string;
  company: string;
  user: string;
}

class WorkshopDatabase extends Dexie {
  operations!: Table<Operation, string>;
  meta!: Table<Meta, string>;

  constructor() {
    super('divawaste-workshop');
    this.version(1).stores({ operations: 'id, sequence, status', meta: 'key' });
  }
}

interface PushResult {
  id: string;
  status: 'applied' | 'rejected' | 'conflict' | 'retry';
  entity?: string;
  error_code?: string;
  conflict_type?: string;
  result?: { lot_number?: string; errors?: Record<string, string[]>; message?: string };
}

/**
 * Offline storage and synchronisation of the workshop application.
 *
 * Everything the operator records goes through the same path, online or not: it is written to IndexedDB first,
 * then pushed. Nothing depends on the network being there at the moment of the action, so there is nothing to
 * lose when it drops. Duplicates are prevented by the operation id and the lot ULID, both generated here.
 */
@Injectable({ providedIn: 'root' })
export class Offline {
  private readonly api = inject(Api);
  private readonly db = new WorkshopDatabase();
  private flushing: Promise<void> | null = null;
  /** "company:user" of the operator using the application; null once signed out. */
  private owner: string | null = null;
  /** Operators whose device token the server refused during this visit: their operations wait for their next sign-in. */
  private readonly refused = new Set<string>();

  readonly online = signal(navigator.onLine);
  readonly operations = signal<Operation[]>([]);
  readonly bootstrap = signal<Bootstrap | null>(null);
  readonly syncing = signal(false);
  readonly lastSync = signal<string | null>(null);
  /** The device token expired or was revoked: the operator must sign in again, the queue is kept. */
  readonly needsSignIn = signal(false);

  /** Operations recorded by someone else on this device and not sent yet. They are never shown, only counted. */
  readonly othersPending = signal(0);

  readonly pending = computed(() => this.operations().filter((operation) => operation.status === 'pending').length);
  readonly problems = computed(() => this.operations().filter((operation) => operation.status === 'failed' || operation.status === 'conflict').length);

  constructor() {
    addEventListener('online', () => {
      this.online.set(true);
      void this.flush();
    });
    addEventListener('offline', () => this.online.set(false));
    void this.restore();
  }

  /** Called when the workshop application opens with a signed-in user: registers the device and refreshes the reference data. */
  async start(company: string, user: string): Promise<void> {
    this.owner = `${company}:${user}`;
    const devices = await this.devices();
    await this.adopt(devices);
    await this.restore();
    if (!this.online()) {
      return;
    }
    try {
      // One token per operator: someone else signing in on a shared tablet gets their own, and what the previous
      // operator recorded keeps being sent under that operator's name.
      if (!devices[this.owner] || this.refused.has(this.owner)) {
        const id = Object.values(devices).find((device) => device.company === company)?.ulid ?? ulid();
        const registered = await firstValueFrom(this.api.post<{ device: string; token: string }>('mobile/devices', {
          device: id, name: this.deviceName(), platform: /android/i.test(navigator.userAgent) ? 'android' : /iphone|ipad/i.test(navigator.userAgent) ? 'ios' : 'other',
        }));
        devices[this.owner] = { ulid: registered.device, token: registered.token, company, user };
        await this.set('devices', devices);
        this.refused.delete(this.owner);
      }
      this.needsSignIn.set(false);

      const data = await firstValueFrom(this.api.get<Bootstrap>('sync/bootstrap'));
      await this.set('bootstrap', data);
      this.bootstrap.set(data);
      await this.flush();
    } catch {
      // Offline or briefly unreachable: the cached reference data and the queue keep the application usable.
    }
  }

  /**
   * The operator signs out. Whatever can be sent is sent first; what cannot (no network) stays on the device with
   * its owner's token and leaves as soon as the network is back, even if someone else is using the tablet by then.
   * Returns how many of this operator's records are still waiting.
   */
  async leave(): Promise<number> {
    await this.flush().catch(() => undefined);
    const waiting = this.pending();
    this.owner = null;
    this.needsSignIn.set(false);
    await this.refresh();

    return waiting;
  }

  /** Records a lot created at the workshop. Returns at once; the lot number arrives with the synchronisation. */
  async createLot(input: { site: string; zone: string; waste_type: string; net_weight_kg?: string; quantity?: string; unit?: string | null; production_order?: string | null }, summary: string): Promise<Operation> {
    const operation = await this.enqueue('lot.create', { ...input, ulid: ulid() }, summary);
    operation.entity = operation.payload['ulid'] as string;
    await this.db.operations.put(operation);
    await this.refresh();
    void this.flush();

    return operation;
  }

  async storeLot(lot: Lot, zone: string | null): Promise<Operation> {
    const operation = await this.enqueue('lot.store', { lot: lot.ulid, zone }, lot.lot_number);
    void this.flush();

    return operation;
  }

  /** Sends the pending operations in the order they were recorded. Safe to call at any time and from anywhere. */
  flush(): Promise<void> {
    this.flushing ??= this.push().finally(() => (this.flushing = null));

    return this.flushing;
  }

  async retry(id: string): Promise<void> {
    // A refused operation is re-sent under a new id (the server keeps the refusal of the old one), with the same lot ULID.
    const operation = await this.db.operations.get(id);
    if (operation) {
      await this.db.operations.delete(id);
      await this.db.operations.put({ ...operation, id: ulid(), status: 'pending', error: undefined, owner: operation.owner ?? this.owner ?? undefined });
      await this.refresh();
      await this.flush();
    }
  }

  async discard(id: string): Promise<void> {
    await this.db.operations.delete(id);
    await this.refresh();
  }

  operation(id: string): Operation | undefined {
    return this.operations().find((operation) => operation.id === id);
  }

  private async push(): Promise<void> {
    if (!this.online()) {
      return;
    }
    const devices = await this.devices();
    for (const [owner, device] of Object.entries(devices)) {
      if (!this.refused.has(owner)) {
        await this.pushFor(owner, device);
      }
    }
  }

  /** Sends one operator's pending operations with that operator's token, in the order they were recorded. */
  private async pushFor(owner: string, device: Device): Promise<void> {
    const batch = (await this.db.operations.where('status').equals('pending').sortBy('sequence')).filter((operation) => operation.owner === owner).slice(0, 100);
    if (!this.online() || batch.length === 0) {
      return;
    }

    this.syncing.set(true);
    try {
      // Sent without cookies on purpose: the device token authenticates the request even when the web session has expired.
      const response = await fetch('/api/v1/sync/push', {
        method: 'POST', credentials: 'omit',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${device.token}` },
        body: JSON.stringify({ sent_at: new Date().toISOString(), operations: batch.map(({ id, sequence, type, occurred_at, payload }) => ({ id, sequence, type, occurred_at, payload })) }),
      });

      if (response.status === 401 || response.status === 403) {
        // The token expired or was revoked: the operations wait, untouched, for their owner's next sign-in.
        this.refused.add(owner);
        if (owner === this.owner) {
          this.needsSignIn.set(true);
        }

        return;
      }
      if (!response.ok) {
        return;
      }

      const results = ((await response.json()) as { data: PushResult[] }).data;
      for (const result of results) {
        const operation = batch.find((item) => item.id === result.id);
        // "retry" means the server failed unexpectedly: the operation stays pending and goes out again next time.
        if (!operation || result.status === 'retry') {
          continue;
        }
        await this.db.operations.put({
          ...operation,
          status: result.status === 'applied' ? 'synced' : result.status === 'conflict' ? 'conflict' : 'failed',
          lot_number: result.result?.lot_number ?? operation.lot_number,
          entity: result.entity ?? operation.entity,
          error: result.status === 'applied' ? undefined : (Object.values(result.result?.errors ?? {})[0]?.[0] ?? result.result?.message ?? result.conflict_type ?? result.error_code),
        });
      }
      const now = new Date().toISOString();
      await this.set('lastSync', now);
      this.lastSync.set(now);
      await this.prune();
    } catch {
      // Network dropped mid-request: nothing was marked, everything is re-sent later and the server deduplicates.
      this.online.set(navigator.onLine);
    } finally {
      this.syncing.set(false);
      await this.refresh();
    }

    if ((await this.db.operations.where('status').equals('pending').filter((operation) => operation.owner === owner).count()) > 0 && this.online() && !this.refused.has(owner)) {
      await this.pushFor(owner, device);
    }
  }

  private async devices(): Promise<Record<string, Device>> {
    const devices = (await this.get<Record<string, Device>>('devices')) ?? {};
    // Before each operator had a token of their own, the device kept a single one.
    const single = await this.get<Device>('device');
    if (single && !devices[`${single.company}:${single.user}`]) {
      devices[`${single.company}:${single.user}`] = single;
    }

    return devices;
  }

  /** Operations recorded before owners were tracked belong to whoever held the device then, else to the current operator. */
  private async adopt(devices: Record<string, Device>): Promise<void> {
    const single = await this.get<Device>('device');
    const owner = single ? `${single.company}:${single.user}` : this.owner;
    const orphans = (await this.db.operations.toArray()).filter((operation) => !operation.owner);
    if (owner && orphans.length > 0) {
      await this.db.operations.bulkPut(orphans.map((operation) => ({ ...operation, owner })));
    }
    if (single) {
      await this.set('devices', devices);
      await this.db.meta.delete('device');
    }
  }

  private async enqueue(type: Operation['type'], payload: Record<string, unknown>, summary: string): Promise<Operation> {
    const sequence = ((await this.get<number>('sequence')) ?? 0) + 1;
    await this.set('sequence', sequence);
    const operation: Operation = { id: ulid(), sequence, type, occurred_at: new Date().toISOString(), payload, status: 'pending', summary, owner: this.owner ?? undefined };
    await this.db.operations.put(operation);
    await this.refresh();

    return operation;
  }

  /** Keeps what still matters to the operator: everything unresolved, and the last 50 synchronised lots. */
  private async prune(): Promise<void> {
    const synced = await this.db.operations.where('status').equals('synced').sortBy('sequence');
    await this.db.operations.bulkDelete(synced.slice(0, Math.max(0, synced.length - 50)).map((operation) => operation.id));
  }

  private async restore(): Promise<void> {
    this.bootstrap.set((await this.get<Bootstrap>('bootstrap')) ?? null);
    this.lastSync.set((await this.get<string>('lastSync')) ?? null);
    await this.refresh();
  }

  private async refresh(): Promise<void> {
    const all = await this.db.operations.orderBy('sequence').reverse().toArray();
    // Each operator sees their own records only; the others' keep being sent in the background.
    this.operations.set(all.filter((operation) => this.owner !== null && (operation.owner ?? this.owner) === this.owner));
    this.othersPending.set(all.filter((operation) => operation.status === 'pending' && operation.owner !== undefined && operation.owner !== this.owner).length);
  }

  private async get<T>(key: string): Promise<T | undefined> {
    return (await this.db.meta.get(key))?.value as T | undefined;
  }

  private async set(key: string, value: unknown): Promise<void> {
    await this.db.meta.put({ key, value });
  }

  private deviceName(): string {
    const agent = navigator.userAgent;
    const model = /\(([^)]+)\)/.exec(agent)?.[1]?.split(';').pop()?.trim() ?? 'Appareil';

    return model.slice(0, 80);
  }
}
