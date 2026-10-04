import { Injectable, inject } from '@angular/core';
import { Observable, map, shareReplay } from 'rxjs';

import { Api } from '../api/api';
import { Reference, Site, WasteType, Zone } from '../api/models';

/**
 * Reference data shared by the forms: vocabularies, active waste types, zones per site. Loaded on first use
 * and kept for the session; `refresh*` is called after the user changes one of them.
 */
@Injectable({ providedIn: 'root' })
export class ReferenceData {
  private readonly api = inject(Api);

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

  refreshWasteTypes(): void {
    this.types$ = null;
  }

  refreshZones(site: string): void {
    this.zones$.delete(site);
  }
}
