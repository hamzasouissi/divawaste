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
  /** What it is; counted in pieces, what one piece weighs (kg). */
  kind: MaterialKind | null;
  unit_weight_kg: string | null;
  /** The waste it leaves when used, each type with its calculation; for a fabric that says nothing, the cutting scraps (inherited). */
  waste_types: WasteLine[];
  waste_types_inherited: boolean;
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
  /** What one piece is expected to consume; for a fabric, the waste its cutting is expected to leave (kg per piece). */
  materials: ArticleLine[];
  /** Null figures: no fabric of the article has its width and weight. */
  cutting_waste: { default_efficiency_pct: string; fabric_kg_per_piece: string | null; other_kg_per_piece: string | null; waste_kg_per_piece: string | null; by_type: WasteByType[] };
}

/** A line of a composition; for a fabric, what its cutting is expected to leave and the type of waste it is. */
export interface ArticleLine {
  material: string; reference: string; name: string; unit: string; quantity_per_piece: string;
  width_cm: string | null; grammage_gsm: string | null; composition: { material: string; pct: string | number }[];
  fabric_kg_per_piece: string | null; waste_kg_per_piece: string | null;
  kind: MaterialKind | null;
  /** What one piece consumes of it in kg, when it can be said. */
  consumed_kg_per_piece: string | null;
  /** The waste the line gives per piece, line by line with its calculation (null: cannot be computed). */
  waste_types: (WasteLine & { waste_kg_per_piece: string | null })[]; waste_type_inherited: boolean;
}

export interface WasteShare { waste_type: { ulid: string; name: string } | null; share_pct: string }
export type MaterialKind = 'fabric' | 'thread' | 'trim' | 'packaging' | 'chemical' | 'other';
/** The icon of each kind of raw material. */
export const KIND_ICON: Record<MaterialKind, string> = { fabric: 'texture', thread: 'gesture', trim: 'radio_button_checked', packaging: 'inventory_2', chemical: 'science', other: 'category' };
/** cutting: a share of the cutting waste; loss_rate: a % of what is consumed; per_unit: kg per unit consumed; whole: all of it. */
export type WasteMethod = 'cutting' | 'loss_rate' | 'per_unit' | 'whole';
export interface WasteLine { waste_type: { ulid: string; name: string } | null; method: WasteMethod; share_pct: string | null; rate_pct: string | null; kg_per_unit: string | null }

/** A waste line's calculation in a few words: "85 % de la chute de coupe", "3 % du consommé", "0,025 kg par pièce". */
export const describeWaste = (line: WasteLine, unit: string, t: (key: string, params?: Record<string, unknown>) => string): string => {
  const n = (value: string | null) => new Intl.NumberFormat(document.documentElement.lang || 'fr', { maximumFractionDigits: 6 }).format(Number(value ?? 0));
  switch (line.method) {
    case 'cutting':
      return t('production.materials.short.cutting', { pct: n(line.share_pct) });
    case 'loss_rate':
      return line.rate_pct === null ? t('production.materials.short.rateMissing') : t('production.materials.short.lossRate', { rate: n(line.rate_pct) });
    case 'per_unit':
      return t('production.materials.short.perUnit', { kg: n(line.kg_per_unit), unit: t('production.materials.unitShort.' + unit) });
    default:
      return t('production.materials.short.whole');
  }
};
export interface WasteByType { waste_type: { ulid: string; name: string } | null; lines: number; waste_kg_per_piece: string }

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
  kind: MaterialKind | null;
  /** A material of the article (with what one piece consumes), or one consumed beside it. */
  in_article: boolean;
  per_piece: string | null;
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
  /** Lots of the OF still waiting for their weight: counted, never as 0 kg. */
  unweighed_lots: number;
  rows: { ulid: string; lot_number: string; status: string; net_weight_kg: string; generated_at: string; origin_type: string; waste_type: string; site_name: string; step_name: Localized | null;
    material: string | null; batch_number: string | null }[];
  can_view_lots: boolean;
  consumed_kg: string | null;
  waste_rate_pct: number | null;
  /** The cutting waste the placements of the article are expected to leave; null without a fabric to compute it for. */
  estimated: {
    /** cutting: from the spreads of the OF (layers planned, then cut); article: from the article's placements. */
    source: 'cutting' | 'article';
    per_piece_kg: string | null; planned_kg: string; produced_kg: string | null; planned_pieces: number | null; produced_pieces: number | null;
    /** By type of waste: weighed_kg counts only the lots of the OF of that type; lots still to weigh are counted apart. */
    by_type: { waste_type: { ulid: string; name: string } | null; per_piece_kg: string; planned_kg: string; produced_kg: string | null; weighed_kg: string | null; lots: number; unweighed_lots: number }[];
  } | null;
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
  /** The sizes it makes, from one of the company's grids, and the pieces of each. */
  size_grid: { ulid: string; name: string; sizes: string[] } | null;
  sizes: { size: string; quantity: number }[];
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
  /** Its fabrics and their spreads (matelas). */
  cutting?: Cutting;
  expenses?: OfExpense[];
  /** Null for who may not see money. */
  finance?: OfFinance | null;
}

/** One of the company's sizes, and how many grids and OFs name it. */
export interface Size { ulid: string; code: string; grids: number; orders: number }

/** A company's list of sizes, in order, chosen by its OFs. */
export interface SizeGrid { ulid: string; name: string; sizes: string[]; is_active: boolean; orders: number }

/** What layers of a spread lay and leave: metres, kilos (with laize and grammage), cutting waste. */
export interface SpreadFigures { metres: string; fabric_kg: string | null; waste_kg: string | null }
export interface CuttingTotal extends SpreadFigures { waste_pct: string | null }

/** One spread (matelas): its length, its layers planned then cut, its efficiency; the laize and grammage it uses. */
export interface CuttingSpread {
  ulid: string;
  length_m: string; efficiency_pct: string;
  /** The laize and grammage used: the spread's own when own_* is true, else the raw material's. */
  width_cm: string | null; own_width: boolean; grammage_gsm: string | null; own_grammage: boolean;
  layers_planned: number; layers_done: number | null; cut_on: string | null; notes: string | null;
  planned: SpreadFigures; done: SpreadFigures | null;
}

/** A fabric of the OF — those of its article, listed before any spread, and any other a spread is laid of. */
export interface CuttingFabric {
  material: { ulid: string; reference: string; name: string; width_cm: string | null; grammage_gsm: string | null };
  in_article: boolean;
  /** What the article says the OF needs of it, in metres. */
  article_m: string | null;
  /** The efficiency a new spread starts from: the company's usual one. */
  efficiency_pct: string;
  waste_types: WasteShare[];
  spreads: CuttingSpread[];
  planned: CuttingTotal | null; done: CuttingTotal | null;
  consumed_m: string | null;
}

export interface Cutting {
  fabrics: CuttingFabric[];
  totals: { spreads: number; spreads_to_cut: number; planned: CuttingTotal | null; done: CuttingTotal | null };
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
  sizeGrids = (): Observable<SizeGrid[]> => this.api.get<SizeGrid[]>('production/size-grids');
  sizes = (): Observable<Size[]> => this.api.get<Size[]>('production/sizes');
  steps = (): Observable<ProductionStep[]> => this.api.get<ProductionStep[]>('production/steps').pipe(map((steps) => steps.filter((step) => step.is_active)));
}
