import { Injectable, effect, inject, untracked } from '@angular/core';
import { Observable, map, shareReplay } from 'rxjs';

import { Api } from '../api/api';
import { Session } from '../auth/session';
import { Reference, Site, WasteType, Zone } from '../api/models';

/**
 * Reference data shared by the forms: vocabularies, active waste types, zones per site. Loaded on first use and kept
 * while the company is the same: another company (sign-in, switch) starts from nothing — its types and zones are not
 * the last one's — and a type, a site or a zone saved anywhere (a page, DAVI) is read again.
 */
@Injectable({ providedIn: 'root' })
export class ReferenceData {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private company: string | null | undefined;

  constructor() {
    effect(() => {
      const company = this.session.company()?.ulid ?? null;
      untracked(() => {
        if (company !== this.company) {
          this.company = company;
          this.vocabulary$ = null;
          this.types$ = null;
          this.zones$.clear();
        }
      });
    });
    this.api.changed.subscribe((path) => {
      if (path === 'davi:done') {
        this.vocabulary$ = null;
        this.types$ = null;
        this.zones$.clear();
      }
      if (path.startsWith('waste-types') || path.startsWith('catalog')) {
        this.types$ = null;
      }
      if (path.startsWith('sites') || path.startsWith('zones')) {
        this.zones$.clear();
      }
      if (path.startsWith('colors') || path.startsWith('color')) {
        this.vocabulary$ = null;
      }
    });
  }

  private vocabulary$: Observable<Reference> | null = null;
  private types$: Observable<WasteType[]> | null = null;
  private readonly zones$ = new Map<string, Observable<Zone[]>>();

  vocabulary(): Observable<Reference> {
    this.vocabulary$ ??= this.api.get<Reference>('reference').pipe(shareReplay({ bufferSize: 1, refCount: false }));

    return this.vocabulary$;
  }

  wasteTypes(): Observable<WasteType[]> {
    this.types$ ??= this.api.list<WasteType>('waste-types', { per_page: 100 }).pipe(
      map((page) => page.data),
      shareReplay({ bufferSize: 1, refCount: false }),
    );

    return this.types$;
  }

  /** Active zones of a site, storage areas first (where waste usually goes). */
  zones(site: string): Observable<Zone[]> {
    if (!this.zones$.has(site)) {
      this.zones$.set(
        site,
        this.api.get<Site>(`sites/${site}`).pipe(
          map((detail) => (detail.zones ?? []).filter((zone) => zone.is_active).sort((a, b) => Number(b.is_waste_storage) - Number(a.is_waste_storage) || a.name.localeCompare(b.name))),
          shareReplay({ bufferSize: 1, refCount: false }),
        ),
      );
    }

    return this.zones$.get(site) as Observable<Zone[]>;
  }

  /** After the company adds, renames or withdraws one of its colours. */
  refreshVocabulary(): void {
    this.vocabulary$ = null;
  }

  refreshWasteTypes(): void {
    this.types$ = null;
  }

  refreshZones(site: string): void {
    this.zones$.delete(site);
  }
}
