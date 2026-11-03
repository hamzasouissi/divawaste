import { CompositionSource } from '../../core/api/models';

/** How a price is given: per kilogram, per tonne, or one amount for the whole listing. */
export type PricingMode = 'per_kg' | 'per_tonne' | 'flat';
/** revenue = the buyer pays the seller; cost = the seller pays the buyer to take the waste. */
export type Direction = 'revenue' | 'cost';
export type ListingStatus = 'draft' | 'published' | 'sold' | 'expired' | 'cancelled';
export type NegotiationStatus = 'awaiting_seller' | 'awaiting_buyer' | 'accepted' | 'refused' | 'withdrawn' | 'closed';

export interface Terms {
  pricing_mode: PricingMode;
  unit_price: string;
  direction: Direction;
}

export interface Proposal extends Terms {
  ulid: string;
  sequence: number;
  party: 'seller' | 'buyer';
  currency_code: string;
  /** What it comes to on the weight known today, signed for the seller; null for a price by weight without any weight. */
  amount: string | null;
  /** Some lots are not weighed: the amount leaves them out. */
  amount_partial: boolean;
  pickup_date: string | null;
  message: string | null;
  expires_at: string | null;
  expired: boolean;
  /** Made on lots that changed since: it can be answered, not accepted. */
  outdated: boolean;
  created_at: string | null;
}

export interface ListingLot {
  lot_number: string;
  status: string;
  is_hazardous: boolean;
  regulatory_code: string | null;
  net_weight_kg: string | null;
  weight_basis: 'measured' | 'computed' | 'estimated' | 'unknown';
  quantity: string | null;
  unit: string | null;
  packaging_type: string | null;
  package_count: number | null;
  color_family: string | null;
  color_label: string | null;
  grammage_gsm: string | null;
  composition: { material: string; pct: string }[];
  composition_source: CompositionSource | null;
}

export interface ListingTotals {
  /** Sum of the known weights; null when no lot is weighed. */
  weight_kg: string | null;
  unweighed_lots: number;
  /** Waste counted in pieces or litres, per unit. */
  quantities: Record<string, string>;
  lots_count: number;
}

interface ListingCommon {
  ulid: string;
  listing_number: string;
  title: string;
  description: string | null;
  status: ListingStatus;
  waste_type: { name: string | null; family: string | null; catalog_item: string | null };
  is_hazardous: boolean;
  show_seller: boolean;
  asking_price: (Terms & { amount: string | null }) | null;
  currency_code: string;
  totals: ListingTotals;
  /** Average of the lots weighted by weight, when every lot is weighed and composed. */
  composition: { material: string; pct: string }[] | null;
  available_from: string | null;
  closes_at: string | null;
  published_at: string | null;
  closed_at: string | null;
  lots: ListingLot[] | null;
}

export interface PickupRef {
  ulid: string;
  pickup_number: string;
  status: string;
}

/** A listing as its seller reads it, every negotiation side by side. */
export interface SellerListing extends ListingCommon {
  site: { ulid: string; code: string; name: string };
  pickup: PickupRef | null;
  cancellation_reason: string | null;
  negotiations_count: number;
  awaiting_seller: number;
  negotiations: {
    ulid: string;
    status: NegotiationStatus;
    buyer: { ulid: string; legal_name: string; city: string | null } | null;
    last_proposal: Proposal | null;
    updated_at: string | null;
  }[];
}

/** A listing as a buyer reads it: no seller until a deal, nothing of the other buyers. */
export interface BuyerListing extends ListingCommon {
  seller: { ulid: string; legal_name: string; city: string | null } | null;
  location: { region: string | null; city: string | null; country: string | null };
  my_negotiation: { ulid: string; status: NegotiationStatus; your_turn: boolean; last_proposal: Proposal | null } | null;
  can_offer: boolean;
  pickup: PickupRef | null;
}

export interface Negotiation {
  ulid: string;
  status: NegotiationStatus;
  party: 'seller' | 'buyer';
  your_turn: boolean;
  refused_by: 'seller' | 'buyer' | null;
  closed_reason: 'sold_to_another' | 'listing_cancelled' | 'listing_expired' | null;
  end_message: string | null;
  listing: SellerListing | BuyerListing;
  buyer: { ulid: string; legal_name: string; city: string | null } | null;
  proposals: Proposal[];
  accepted_proposal: string | null;
  commission_pct: string | null;
  accepted_at: string | null;
  ended_at: string | null;
  pickup: PickupRef | null;
}

export const OPEN: NegotiationStatus[] = ['awaiting_seller', 'awaiting_buyer'];
