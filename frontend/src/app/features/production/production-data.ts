import { Injectable, inject } from '@angular/core';
import { Validators } from '@angular/forms';
import { Observable, map } from 'rxjs';

import { Api } from '../../core/api/api';
import { Localized } from '../../core/api/models';

/** A client the company produces for, or a supplier of raw materials: the same card. */
export interface Party {
  ulid: string;
  code: string;
  name: string;
  country_code: string | null;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  notes: string | null;
  is_active: boolean;
}

export type PartyRef = Pick<Party, 'ulid' | 'code' | 'name'>;

export interface ProductionStep {
  code: string;
  name: Localized;
  is_custom: boolean;
  is_active: boolean;
}

export interface MaterialBatch {
  ulid: string;
  batch_number: string;
  received_on: string | null;
  quantity: string | null;
  notes: string | null;
}

/** A raw material; its supplier, composition and colour are copied onto the waste that comes from it. */
export interface RawMaterial {
  ulid: string;
  reference: string;
  name: string;
  supplier: PartyRef | null;
  unit: string;
  color_family: string | null;
  color_label: string | null;
  grammage_gsm: string | null;
  /** Usable width of a fabric: with its weight per m², metres consumed become kilograms. */
  width_cm: string | null;
  composition: { material: string; pct: string }[];
  notes: string | null;
  is_active: boolean;
  batches: MaterialBatch[];
}

export interface Article {
  ulid: string;
  reference: string;
  name: string;
  family: string | null;
  client: PartyRef | null;
  notes: string | null;
  is_active: boolean;
  /** What one piece is expected to consume. */
  materials: { material: string; reference: string; name: string; unit: string; quantity_per_piece: string }[];
}

export type OrderStatus = 'open' | 'in_progress' | 'completed' | 'cancelled';

export interface CustomerOrder {
  ulid: string;
  order_number: string;
  client: PartyRef | null;
  ordered_on: string | null;
  due_on: string | null;
  status: OrderStatus;
  notes: string | null;
  lines: { article: string; reference: string; name: string; quantity: number }[];
  quantity: number;
  manufacturing_orders: number;
}

export type OfStatus = 'planned' | 'in_progress' | 'completed' | 'cancelled';

/** Ordre de fabrication: an article to make, the stages it goes through (each on its site) and what it consumes. */
export interface ManufacturingOrder {
  ulid: string;
  order_number: string;
  article: { ulid: string; reference: string; name: string };
  customer_order: { ulid: string; order_number: string } | null;
  client: PartyRef | null;
  quantity_planned: number;
  quantity_produced: number | null;
  started_on: string | null;
  finished_on: string | null;
  status: OfStatus;
  notes: string | null;
  steps: { step: string; name: Localized; site: string; site_name: string; pieces_done: number | null; started_on: string | null; finished_on: string | null }[];
  consumptions?: { material: string; reference: string; name: string; unit: string; batch: string | null; batch_number: string | null; quantity: string }[];
  planned_consumption?: { material: string; reference: string; name: string; unit: string; planned: string; consumed: string }[];
}

export const DECIMAL = Validators.pattern(/^\d+([.,]\d{1,4})?$/);
export const decimal = (value: string | number | null): string | null => (value === null || String(value).trim() === '' ? null : String(value).replace(',', '.'));
/** An empty field is "not given", not an empty text. */
export const blankToNull = <T extends Record<string, unknown>>(value: T): T => Object.fromEntries(Object.entries(value).map(([key, item]) => [key, item === '' ? null : item])) as T;

/** The lists the production forms choose from, read when a form opens so they are never stale. */
@Injectable({ providedIn: 'root' })
export class ProductionLists {
  private readonly api = inject(Api);

  private all<T>(path: string): Observable<T[]> {
    return this.api.list<T>(`production/${path}`, { per_page: 200 }).pipe(map((page) => page.data));
  }

  clients = (): Observable<Party[]> => this.all<Party>('clients');
  suppliers = (): Observable<Party[]> => this.all<Party>('suppliers');
  materials = (): Observable<RawMaterial[]> => this.all<RawMaterial>('materials');
  articles = (): Observable<Article[]> => this.all<Article>('articles');
  orders = (): Observable<CustomerOrder[]> => this.all<CustomerOrder>('orders');
  steps = (): Observable<ProductionStep[]> => this.api.get<ProductionStep[]>('production/steps').pipe(map((steps) => steps.filter((step) => step.is_active)));
}
