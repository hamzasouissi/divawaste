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

/**
 * Lot reçu: one delivery of a raw material by a supplier. Kept for traceability — it is not a stock. Amounts are
 * null for who may not see money.
 */
export interface MaterialBatch {
  ulid: string;
  batch_number: string;
  /** Who delivered this lot; not necessarily the supplier the material proposes today. */
  supplier: PartyRef | null;
  supplier_batch_reference: string | null;
  received_on: string | null;
  quantity: string | null;
  /** What every order together took from the lot. */
  consumed: string;
  over_consumed: boolean;
  delivery_note: string | null;
  invoice_reference: string | null;
  external_reference: string | null;
  import_source: string | null;
  notes: string | null;
  unit_price: string | null;
  total_amount: string | null;
  delivery_cost: string | null;
  currency_code: string | null;
  tax_rate_pct: string | null;
  /** Per unit, delivery included. */
  unit_cost: string | null;
  /** The whole delivery; `consumed_value` is what orders took of it. */
  purchase_value: string | null;
  consumed_value: string | null;
}

export interface ReceivedLot extends MaterialBatch {
  material: { ulid: string; reference: string; name: string; unit: string };
}

/** A raw material; its supplier, composition and colour are copied onto the waste that comes from it. */
export interface RawMaterial {
  ulid: string;
  reference: string;
  name: string;
  category: string | null;
  /** The supplier proposed for a new received lot; each lot keeps its own. */
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
  average_unit_cost: string | null;
  summary?: {
    received_lots: number; received: string | null; consumed: string; unallocated: string; orders: number; waste_lots: number; waste_kg: string;
    purchase_value: string | null; unpriced_lots: number | null;
  };
  consumptions?: { of: string; order_number: string; status: OfStatus; article: string; batch: string | null; batch_number: string | null; quantity: string; date: string | null; cost: string | null }[];
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
  lines: { article: string; reference: string; name: string; quantity: number; unit_price: string | null; amount: string | null }[];
  quantity: number;
  manufacturing_orders: number;
  currency_code: string;
  discount_amount: string | null;
  /** What the client agreed to pay; `invoiced` is what was really invoiced so far. */
  order_value: string | null;
  invoiced: string | null;
  uninvoiced: string | null;
  invoices?: OrderInvoice[];
  orders?: { ulid: string; order_number: string; status: OfStatus; article: string; quantity_planned: number; quantity_produced: number | null; total_cost: string | null;
    cost_complete: boolean | null; produced_value: string | null; gain: string | null }[];
}

export interface OrderInvoice {
  ulid: string;
  kind: 'invoice' | 'credit_note';
  number: string;
  issued_on: string | null;
  amount: string;
  currency_code: string;
  notes: string | null;
  manufacturing_order: { ulid: string; order_number: string } | null;
}

/** One consumption of an OF: how much, from which received lot (null = "lot non précisé"), and what it cost. */
export interface ConsumptionLine {
  batch: string | null;
  batch_number: string | null;
  supplier: { ulid: string; name: string } | null;
  quantity: string;
  step: string | null;
  step_name: Localized | null;
  site: string | null;
  site_name: string | null;
  consumed_on: string | null;
  notes: string | null;
  unit_cost: string | null;
  cost: string | null;
  /** lot = price of the received lot; average = estimated at the average of the material; unknown = no price at all. */
  basis: 'lot' | 'average' | 'unknown' | null;
  reason: string | null;
}

export interface ConsumedMaterial {
  material: string;
  reference: string;
  name: string;
  unit: string;
  planned: string | null;
  consumed: string;
  variance: string | null;
  variance_pct: number | null;
  allocated: string;
  unallocated: string;
  average_unit_cost: string | null;
  planned_cost: string | null;
  cost_known: string | null;
  cost_estimated: string | null;
  unknown_quantity: string;
  cost: string | null;
  lines: ConsumptionLine[];
}

export type ProducedLotStatus = 'produced' | 'delivered' | 'cancelled';

/** Lot fabriqué: finished goods made by an OF for its client. It has no supplier. */
export interface ProducedLot {
  ulid: string;
  lot_number: string;
  quantity: number;
  unit: string;
  produced_on: string | null;
  produced_until: string | null;
  status: ProducedLotStatus;
  delivered_on: string | null;
  step: string | null;
  step_name: Localized | null;
  site: string | null;
  site_name: string | null;
  notes: string | null;
  external_reference: string | null;
  manufacturing_order: { ulid: string; order_number: string };
  article: { reference: string; name: string };
  customer_order: { ulid: string; order_number: string } | null;
  client: { ulid: string; name: string } | null;
}

export const EXPENSE_CATEGORIES = ['subcontracting', 'external_service', 'purchase', 'transport', 'labour', 'other'] as const;

export interface OfExpense {
  ulid: string;
  category: (typeof EXPENSE_CATEGORIES)[number];
  description: string;
  amount: string;
  currency_code: string;
  incurred_on: string | null;
  reference: string | null;
  notes: string | null;
  supplier: { ulid: string; name: string } | null;
}

/** What an OF cost and what it is worth, as far as it was recorded. Null figures are unknown, never zero. */
export interface OfFinance {
  currency: string;
  material_cost: { known: string; estimated: string; total: string; complete: boolean; unknown_lines: number };
  planned_material_cost: string | null;
  expenses: { total: string; by_category: Record<string, string>; excluded: number };
  total_cost: string;
  cost_complete: boolean;
  produced: { quantity: number | null; delivered: number; source: 'lots' | 'order' | null };
  unit_cost: string | null;
  revenue: {
    unit_price: string | null; net_unit_price: string | null; planned_value: string | null; produced_value: string | null; delivered_value: string | null;
    order: { ulid: string; order_number: string } | null; order_value: string | null; invoiced: string | null; invoiced_scope: 'of' | 'order' | null; uninvoiced: string | null;
  };
  gain: { revenue: string | null; cost: string | null; amount: string | null; margin_pct: number | null; estimated: boolean; missing: string[] };
  warnings: string[];
}

export interface OfWaste {
  lots: number;
  kg: string;
  rows: { ulid: string; lot_number: string; status: string; net_weight_kg: string; generated_at: string; origin_type: string; waste_type: string; site_name: string; step_name: Localized | null;
    material: string | null; batch_number: string | null }[];
  can_view_lots: boolean;
  consumed_kg: string | null;
  waste_rate_pct: number | null;
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
  consumptions?: { material: string; reference: string; name: string; unit: string; batch: string | null; batch_number: string | null; quantity: string; step: string | null; site: string | null;
    consumed_on: string | null; notes: string | null }[];
  planned_consumption?: { material: string; reference: string; name: string; unit: string; planned: string; consumed: string }[];
  /** Planned against consumed, material by material, each consumption with its received lot and supplier. */
  materials?: ConsumedMaterial[];
  produced_lots?: ProducedLot[];
  produced?: OfFinance['produced'];
  waste?: OfWaste;
  expenses?: OfExpense[];
  /** Null for who may not see money. */
  finance?: OfFinance | null;
}

/** Colour of a status tag: done is green, running is amber, the rest is neutral. */
export const statusTone = (status: string): string => (status === 'completed' || status === 'delivered' ? 'approved' : status === 'in_progress' || status === 'produced' ? 'pending' : status === 'cancelled' ? 'rejected' : 'draft');

export const DECIMAL = Validators.pattern(/^\d+([.,]\d{1,4})?$/);
export const AMOUNT = Validators.pattern(/^\d+([.,]\d{1,3})?$/);
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
