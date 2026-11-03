import { Injectable, computed, inject, signal } from '@angular/core';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Contract } from './contracts';

/**
 * The contract the company has with each partner (a provider of the platform or a partner without an account), so a
 * partner already under contract shows "Voir le contrat" instead of "Faire un contrat". A contract counts while it is
 * being written, upcoming or active; a terminated or expired one leaves room for a new one. The one in force wins.
 */
@Injectable({ providedIn: 'root' })
export class PartyContracts {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly list = signal<Contract[]>([]);

  readonly byParty = computed(() => {
    const current = new Map<string, Contract>();
    for (const contract of this.list()) {
      if (!contract.party || contract.status === 'terminated' || contract.state === 'expired') {
        continue;
      }
      const known = current.get(contract.party.ulid);
      if (!known || (known.status !== 'active' && contract.status === 'active')) {
        current.set(contract.party.ulid, contract);
      }
    }

    return current;
  });

  load(): void {
    if (!this.session.uses('contracts') || !this.session.can('contracts.view')) {
      this.list.set([]);
      return;
    }
    this.api.get<Contract[]>('contracts').subscribe({ next: (contracts) => this.list.set(contracts), error: () => this.list.set([]) });
  }
}
