/** API contracts, as returned by the Laravel resources. Decimals are strings (exact), dates ISO 8601 UTC. */

export type Localized = Record<string, string>;
export type CompanyType = 'industrial' | 'provider' | 'brand';
export type CompanyStatus = 'pending_verification' | 'pending_approval' | 'active' | 'rejected' | 'suspended' | 'closed';

export interface Membership {
  company: string;
  legal_name: string;
  company_type: CompanyType;
  status: CompanyStatus;
  reason: string | null;
}

export interface Me {
  ulid: string;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  locale: string;
  email_verified: boolean;
  two_factor_enabled: boolean;
  platform_roles: string[];
  platform_permissions: string[];
  memberships: Membership[];
}

export interface Company {
  ulid: string;
  company_type: CompanyType;
  status: CompanyStatus;
  legal_name: string;
  trade_name: string | null;
  tax_id: string | null;
  vat_number: string | null;
  country: string;
  currency_code: string;
  timezone: string;
  address_line1: string | null;
  address_line2: string | null;
  city: string | null;
  postal_code: string | null;
  region: string | null;
  phone: string | null;
  email: string | null;
  billing_email: string | null;
  has_logo: boolean;
  activities: string[];
  weight_variance_threshold_pct: string;
  submitted_at: string | null;
  approved_at: string | null;
}

export interface AccessSite {
  ulid: string;
  code: string;
  name: string;
  is_active: boolean;
  permissions: string[];
}

export interface Access {
  company: Company;
  roles: { name: string; label: Localized | null; site: string | null }[];
  company_permissions: string[];
  sites: AccessSite[];
}

/** How full a zone is: counted from the lots it holds, never stored. */
export interface ZoneFill {
  /** null when the zone has no limit at all. */
  pct: number | null;
  weight_pct: number | null;
  count_pct: number | null;
  /** Lots whose packaging the zone says nothing about (or that have none): they are not in the percentage. */
  uncounted: number;
  remaining: { kg: string | null; counts: Record<string, number> };
  quantity_kg: string;
  lots: number;
  /** Lots of a waste type or colour the zone is not meant for. */
  misplaced: number;
  alert_level: 'warning' | 'exceeded' | null;
  /** Packages per packaging ('' = loose lots, which have none and are counted as lots). */
  packages?: Record<string, number>;
  /** Lots that only carry the default of one package nobody confirmed. */
  unconfirmed_lots?: number;
  unweighed_lots?: number;
}

export interface Zone {
  ulid: string;
  code: string;
  name: string;
  zone_type: string | null;
  capacity_kg: string | null;
  capacity_m3: string | null;
  capacity_alert_pct: number;
  is_waste_storage: boolean;
  is_active: boolean;
  /** "This zone holds 40 bales, or 100 bags". */
  capacities: { packaging_type: string; max_count: number }[];
  /** Empty = the zone accepts anything. */
  accepted_waste_types: string[];
  accepted_colors: string[];
  fill: ZoneFill;
}

export interface Site {
  ulid: string;
  code: string;
  name: string;
  site_kind: 'production' | 'warehouse' | 'mixed';
  address_line1: string | null;
  city: string | null;
  postal_code: string | null;
  region: string | null;
  timezone: string | null;
  regulatory_identifier: string | null;
  capacity_kg: string | null;
  is_active: boolean;
  zones_count?: number;
  zones?: Zone[];
}

export interface WasteType {
  ulid: string;
  code: string;
  name: string;
  description: string | null;
  catalog_item: string | null;
  waste_family: string | null;
  regulatory_code: string | null;
  is_hazardous: boolean;
  /** This waste only leaves the site under an authorisation (declaration signed by customs). */
  requires_authorization: boolean;
  unit: string | null;
  /** What a lot of this type is declared and compared in: a weight, a count of pieces or a volume. */
  measure_dimension?: MeasureDimension;
  measurement_method?: 'weighed' | 'counted' | 'measured' | 'estimated';
  packaging_type: string | null;
  color_family: string | null;
  treatment_channel: string | null;
  default_grammage_gsm: string | null;
  default_composition: { material: string; pct: string }[] | null;
  density_kg_m3: string | null;
  unit_weight_kg: string | null;
  is_active: boolean;
}

export type MeasureDimension = 'mass' | 'count' | 'volume';

/** Outcome of comparing what was measured with what was expected. `no_reference`: nothing reliable to compare with. */
export type ToleranceStatus = 'within' | 'warning' | 'blocking' | 'no_reference' | 'not_measured';

/** The rule that applies to a waste type handled in a given way, and where it comes from. */
export interface ToleranceRuleSnapshot {
  source: 'type_channel' | 'type' | 'channel' | 'company' | 'company_setting' | 'default';
  rule: string | null;
  warning_pct: string | null;
  blocking_pct: string | null;
  direction: 'both' | 'excess' | 'shortage';
  basis: 'operational' | 'legal';
  reference: string | null;
  channel: string | null;
}

/** A rule as applied to one reception: the figures it was applied to are kept with it. */
export interface ToleranceDecision extends ToleranceRuleSnapshot {
  unit: string;
  /** 'estimated': what left was a guess, not a measure — the gap is an indication and the tolerance is not said to be met. */
  reference_basis?: 'measured' | 'estimated';
  reference_quantity: string | null;
  measured_quantity: string | null;
  /** Signed: + = more than what left. */
  difference: string | null;
  pct: string | null;
  pct_abs: string | null;
}

export interface ToleranceRule {
  ulid: string;
  waste_type: { ulid: string; code: string; name: string; unit: string | null } | null;
  treatment_channel: string | null;
  warning_pct: string | null;
  blocking_pct: string | null;
  direction: 'both' | 'excess' | 'shortage';
  basis: 'operational' | 'legal';
  reference: string | null;
  notes: string | null;
  is_active: boolean;
}

export type LotStatus = 'created' | 'stored' | 'awaiting_pickup' | 'collected' | 'treated' | 'closed';

/** Where a waste lot comes from in production; each part may be unknown. */
export interface LotOrigin {
  production_order: { ulid: string; order_number: string } | null;
  article: { reference: string; name: string } | null;
  customer_order: string | null;
  client: string | null;
  step: { code: string; name: Localized } | null;
  raw_material: { ulid: string; reference: string; name: string } | null;
  supplier: string | null;
  batch: { ulid: string; batch_number: string } | null;
}

/** A manufacturing order a lot can be attached to: only what is needed to choose it. */
export interface OpenOrder {
  ulid: string;
  order_number: string;
  article: { reference: string; name: string };
  steps: { step: string; site: string }[];
  materials: { ulid: string; reference: string; name: string }[];
}

export interface Named {
  ulid: string;
  code: string;
  name: string;
}

export interface Lot {
  ulid: string;
  lot_number: string;
  status: LotStatus;
  closed_reason: string | null;
  origin_type: 'created' | 'split' | 'grouping';
  site: Named | null;
  zone: Named | null;
  waste_type: Named | null;
  is_hazardous: boolean;
  regulatory_code: string | null;
  packaging_type: string | null;
  /** How many packages (bags, bales…) the lot is made of; null for loose waste, which has none. */
  package_count?: number | null;
  /** false = the default of one, or a figure nobody checked. */
  package_count_confirmed?: boolean;
  /** null = nobody weighed the lot yet. Never to be read as 0 kg. */
  net_weight_kg: string | null;
  /** Where the weight comes from: a scale, the quantity × the conversion of the type, a guess of the person, or nothing yet. */
  weight_basis?: 'measured' | 'computed' | 'estimated' | 'unknown';
  gross_weight_kg: string | null;
  tare_weight_kg: string | null;
  quantity?: string | null;
  unit?: string | null;
  color_family: string | null;
  color_label: string | null;
  /** null when the lot says nothing about its origin. */
  origin?: LotOrigin | null;
  grammage_gsm: string | null;
  composition_label: string | null;
  notes: string | null;
  qr: string | null;
  label_print_count: number;
  version: number;
  generated_at: string;
  stored_at: string | null;
  awaiting_pickup_at: string | null;
  collected_at: string | null;
  treated_at: string | null;
  closed_at: string | null;
}

export interface LotDetailMeta {
  composition: { material: string; name: Localized; pct: string }[];
  parents: { ulid: string; lot_number: string; status: LotStatus; relation_type: string; quantity_kg: string }[];
  children: { ulid: string; lot_number: string; status: LotStatus; relation_type: string; quantity_kg: string }[];
  pickup: { ulid: string; pickup_number: string; status: PickupStatus; provider: string; line_status: string; received_weight_kg: string | null } | null;
}

export interface LotEvent {
  event_type: string;
  from_status: LotStatus | null;
  to_status: LotStatus | null;
  weight_kg: string | null;
  payload: Record<string, unknown> | null;
  source: string;
  occurred_at: string;
  actor: string | null;
  from_zone: string | null;
  to_zone: string | null;
  pickup_number: string | null;
}

export interface StockRow {
  site: string;
  site_name: string;
  zone: string;
  zone_name: string;
  waste_type: string;
  waste_type_name: string;
  is_hazardous: boolean;
  unit: string | null;
  quantity_in_unit: string | null;
  quantity_kg: string;
  awaiting_pickup_kg: string;
  lots: number;
  /** Lots of the line nobody weighed: in `lots`, not in `quantity_kg`. */
  unweighed_lots?: number;
  oldest_lot_at: string;
  zone_quantity_kg: string;
  zone_capacity_kg: string | null;
  zone_alert_level: 'warning' | 'exceeded' | null;
  zone_fill: ZoneFill;
}

export interface StockResponse {
  data: StockRow[];
  thresholds: { ulid: string; quantity_kg: string; unweighed_lots?: number; max_quantity_kg: string; alert_level: 'warning' | 'exceeded' | null }[];
  meta: { total_kg: string; unweighed_lots?: number; generated_at: string };
}

export interface StockThreshold {
  ulid: string;
  site: string;
  site_name: string;
  zone: string | null;
  zone_name: string | null;
  waste_type: string | null;
  waste_type_name: string | null;
  max_quantity_kg: string;
  warning_pct: number;
  alert_level: 'warning' | 'exceeded' | null;
  alerted_at: string | null;
  is_active: boolean;
}

export type PickupStatus = 'draft' | 'requested' | 'confirmed' | 'refused' | 'collected' | 'received' | 'completed' | 'cancelled';

export interface PickupLine {
  /** Lot identifier; only present for the industrial that owns the pickup. */
  lot: string | null;
  lot_number_snapshot: string;
  qr_tag_value_snapshot: string | null;
  waste_type_label_snapshot: string;
  regulatory_code_snapshot: string | null;
  is_hazardous: boolean;
  line_status: 'planned' | 'loaded' | 'not_loaded' | 'received' | 'rejected' | 'treated';
  declared_weight_kg: string | null;
  /** 'estimated': the weight the lot left with is a guess; a price per kilogram cannot be based on it. */
  weight_basis?: 'measured' | 'computed' | 'estimated' | 'unknown' | null;
  departure_weight_kg: string | null;
  received_weight_kg: string | null;
  weight_variance_pct: string | null;
  variance_flagged: boolean;
  /** What the line is compared in, its unit, and its quantities in that unit (null for weighed waste). */
  measure_dimension?: MeasureDimension;
  unit?: string;
  declared_quantity?: string | null;
  departure_quantity?: string | null;
  received_quantity?: string | null;
  /** How the lot is packed and with how many packages it was requested and loaded; a count corrected after departure stands beside it. */
  packaging_type?: string | null;
  package_count?: number | null;
  corrected_package_count?: number | null;
  planned_channel?: string | null;
  tolerance_status?: ToleranceStatus | null;
  tolerance?: ToleranceDecision | null;
  treatment_channel: string | null;
  pricing_mode: 'per_kg' | 'per_tonne' | 'flat' | null;
  unit_price: string | null;
  net_amount: string | null;
}

export interface Pickup {
  ulid: string;
  pickup_number: string;
  status: PickupStatus;
  company: { ulid: string; legal_name: string } | null;
  /** The receiver. `external`: a partner without an account — the industrial records each stage itself. */
  provider: { ulid: string; legal_name: string; external?: boolean } | null;
  /** The transporter when it is not the receiver. */
  transporter: { ulid: string; legal_name: string; external?: boolean } | null;
  site: { ulid: string; code: string; name: string; address_line1: string | null; city: string | null } | null;
  requested_date: string;
  confirmed_date: string | null;
  collected_at: string | null;
  received_at: string | null;
  refusal_reason: string | null;
  cancellation_reason: string | null;
  departure_weight_kg: string | null;
  received_weight_kg: string | null;
  weight_variance_kg: string | null;
  weight_variance_pct: string | null;
  variance_flagged: boolean;
  /** The worst outcome among the lots. */
  tolerance_status?: ToleranceStatus | null;
  variance_acknowledged: boolean;
  /** 'authorized': a gap above the blocking limit, accepted by someone entitled to. */
  variance_decision?: 'acknowledged' | 'authorized' | null;
  variance_comment: string | null;
  currency_code: string;
  lots_count?: number;
  /** Lots of the pickup whose weight is not known: its weights are the sum of the others. */
  unweighed_lots?: number;
  declared_weight_kg?: string;
  net_amount?: string | null;
  lots?: PickupLine[];
}

export interface ProviderAccreditation {
  ulid: string;
  type: string;
  name: Localized;
  covers_hazardous: boolean;
  reference_number: string;
  valid_from: string;
  expires_on: string;
  days_left?: number;
  state?: 'valid' | 'expiring' | 'expired';
  issuing_authority?: string | null;
  review_status?: 'pending' | 'approved' | 'rejected' | 'revoked';
  rejection_reason?: string | null;
  expired?: boolean;
}

export interface DirectoryProvider {
  ulid: string;
  legal_name: string;
  city: string | null;
  roles: string[];
  description: string | null;
  service_area: string | null;
  contact: { name: string | null; email: string | null; phone: string | null };
  channels: string[];
  accepted_wastes: { channel: string; family: string | null; catalog_item: string | null; min_quantity_kg: string | null; notes: string | null }[];
  accreditations: ProviderAccreditation[];
  accepts_hazardous: boolean;
  partnership: { ulid: string; status: string } | null;
  selectable: boolean;
  blocked_reason: 'no_valid_accreditation' | 'not_a_partner' | null;
}

/** A transporter or a receiver without an account on the platform, recorded by the company for its own use. */
export interface ExternalPartner {
  ulid: string;
  legal_name: string;
  tax_id: string | null;
  address_line1: string | null;
  postal_code: string | null;
  city: string | null;
  phone: string | null;
  email: string | null;
  contact_name: string | null;
  roles: ('transporter' | 'recycler' | 'eliminator')[];
  accreditation_reference: string | null;
  accreditation_expires_on: string | null;
  covers_hazardous: boolean;
  notes: string | null;
  is_active: boolean;
  /** The account the partner opened later; new pickups then go through the platform. */
  linked_company: { ulid: string; legal_name: string } | null;
  /** A provider of the platform with the same tax ID: probably this partner. */
  suggested_company: { ulid: string; legal_name: string } | null;
}

/** How much of an authorisation the pickups have used; counted from them, never stored. */
export interface AuthorizationUsage {
  pct: number;
  uncovered: boolean;
  level: 'warning' | 'exceeded' | null;
  used: { kg: string; lots: number; counts: Record<string, number> };
  remaining: { kg: string | null; lots: number | null; counts: Record<string, number> };
}

/** Authorisation to have a waste type leave the site: a period and an allowed quantity. */
export interface WasteAuthorization {
  ulid: string;
  reference: string;
  waste_type: { ulid: string; name: string };
  issued_on: string | null;
  valid_from: string;
  valid_until: string;
  max_kg: string | null;
  max_lots: number | null;
  limits: { packaging_type: string; max_count: number }[];
  alert_pct: number;
  notes: string | null;
  is_active: boolean;
  has_file: boolean;
  state: 'upcoming' | 'valid' | 'ended' | 'withdrawn';
  /** Pickups draw on it: its waste type, period and quantities can no longer change. */
  in_use: boolean;
  usage: AuthorizationUsage;
}

/** Whether a selection of lots would be covered on a date. `reason` null = covered. */
export interface AuthorizationCheck {
  waste_type: string;
  reference: string | null;
  valid_until: string | null;
  /** 'unverifiable': the authorisation is limited in weight and the lots listed in `unweighed` have no measured weight. */
  reason: 'missing' | 'exceeded' | 'packaging' | 'unverifiable' | null;
  unweighed?: string[];
  /** For 'packaging': the lots a document stated in packages cannot count (loose, or a packaging it does not name). */
  uncounted?: string[];
  before_pct: number | null;
  after_pct: number | null;
}

export interface Dashboard {
  period: { from: string; to: string };
  totals: {
    generated_kg: string;
    generated_lots: number;
    /** Lots nobody weighed: counted as lots, in none of the weights. */
    unweighed_lots?: number;
    hazardous_kg: string;
    evacuated_kg: string;
    recovered_kg: string;
    landfill_kg: string;
    undeclared_channel_kg: string;
    recovery_rate_pct: string | null;
    landfill_share_pct: string | null;
    pieces_produced: number;
    kg_per_piece: string | null;
    revenue: string;
    cost: string;
    net_balance: string;
  };
  by_month: { month: string; generated_kg: string; evacuated_kg: string; net_balance: string }[];
  by_waste_type: { waste_type: string; name: string; is_hazardous: boolean; kg: string; lots: number }[];
  by_site: { site: string; name: string; kg: string; lots: number }[];
  by_channel: { channel: string | null; is_valorization: boolean; is_landfill: boolean; kg: string }[];
}

export interface RegisterRow {
  lot: string;
  generated_at: string;
  lot_number: string;
  site: string;
  zone: string;
  waste_type: string;
  regulatory_code: string | null;
  is_hazardous: boolean;
  net_weight_kg: string;
  origin_type: string;
  status: LotStatus;
  pickup_number: string | null;
  collected_at: string | null;
  provider: string | null;
  received_weight_kg: string | null;
  channel: string | null;
}

export interface DocumentRecord {
  ulid: string;
  document_type: 'recycling_certificate' | 'destruction_certificate' | 'hazardous_manifest' | 'annual_declaration';
  title: string;
  reference_number: string | null;
  status: string;
  site: { ulid: string; code: string; name: string } | null;
  pickup: { ulid: string; pickup_number: string } | null;
  uploaded_by: { ulid: string; legal_name: string } | null;
  has_file: boolean;
  issued_at: string | null;
  retention_until: string | null;
  created_at: string;
}

export interface Manifest {
  ulid: string;
  manifest_number: string;
  /** `pending_signed_copy`: everyone has signed, at least one by hand; the scan of the signed slip is still to attach. */
  status: 'draft' | 'pending_producer_signature' | 'pending_transporter_signature' | 'pending_receiver_signature' | 'pending_signed_copy' | 'completed' | 'cancelled';
  pickup: { ulid: string; pickup_number: string } | null;
  regulatory_code: string | null;
  is_hazardous: boolean;
  waste_type: string | null;
  package_count: number | null;
  declared_weight_kg: string | null;
  received_weight_kg: string | null;
  producer: Record<string, string | null>;
  transporter: Record<string, string | null>;
  receiver: Record<string, string | null>;
  signatures: {
    party: 'producer' | 'transporter' | 'receiver';
    sequence: number;
    status: string;
    company: { ulid: string | null; legal_name: string | null };
    /** A partner without an account: it can only sign by hand. */
    external: boolean;
    method: 'electronic' | 'paper' | null;
    signed_by: string | null;
    /** For a handwritten signature: the user who typed it in. */
    recorded_by: string | null;
    signed_at: string | null;
  }[];
  /** The company expected to act next: the signer, or the owner of the slip for a partner without an account. */
  awaiting_company: string | null;
  awaiting_party: 'producer' | 'transporter' | 'receiver' | null;
  awaiting_external: boolean;
  created_at: string;
}

export interface Declaration {
  ulid: string;
  site: { ulid: string; code: string; name: string } | null;
  format_code: string;
  reporting_year: number;
  status: 'draft' | 'in_review' | 'validated' | 'submitted';
  figures: {
    produced: { regulatory_code: string | null; waste_type: string; is_hazardous: boolean; kg: string; lots: number }[];
    evacuated: { regulatory_code: string | null; waste_type: string; is_hazardous: boolean; kg: string; provider: string; channel: string | null }[];
    totals: { produced_kg: string; hazardous_produced_kg: string; evacuated_kg: string; stock_at_year_end_kg: string };
  };
  computed_at: string | null;
  validated_at: string | null;
  submission_reference: string | null;
  document: string | null;
}

export interface GeneratedReport {
  ulid: string;
  report_type: 'waste_register' | 'rse_summary' | 'economic_balance';
  format: 'pdf' | 'xlsx';
  status: 'queued' | 'processing' | 'completed' | 'failed' | 'expired';
  parameters: { from?: string; to?: string; site?: string | null; lang?: string };
  downloadable: boolean;
  error_message: string | null;
  created_at: string;
  expires_at: string | null;
}

export interface AppNotification {
  id: string;
  type: string;
  params: Record<string, string | number | null>;
  link: string | null;
  read_at: string | null;
  created_at: string;
}

export interface Member {
  ulid: string;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  job_title: string | null;
  status: 'active' | 'suspended';
  two_factor_enabled: boolean;
  joined_at: string;
  last_login_at: string | null;
  assignments: { role: string; label: Localized | null; site: string | null; site_name: string | null }[];
}

export interface Invitation {
  ulid: string;
  email: string;
  first_name: string | null;
  last_name: string | null;
  role: string;
  sites: string[];
  status: 'pending' | 'accepted' | 'expired' | 'revoked';
  expires_at: string;
  last_sent_at: string;
  send_count: number;
}

export interface RoleDefinition {
  name: string;
  label: Localized | null;
  is_system?: boolean;
  audience?: string;
  permissions: string[];
}

export interface AuditEntry {
  event: string;
  entity: string;
  object: string | null;
  actor_type: string;
  actor: { ulid: string; name: string } | null;
  source: string;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  ip_address: string | null;
  request_id: string | null;
  occurred_at: string;
}

export interface Invoice {
  ulid: string;
  invoice_number: string;
  status: 'issued' | 'partially_paid' | 'paid' | 'overdue' | 'cancelled';
  issue_date: string;
  due_date: string | null;
  period_start: string | null;
  period_end: string | null;
  currency_code: string;
  subtotal_amount: string;
  tax_amount: string;
  stamp_duty_amount: string;
  total_amount: string;
  amount_paid: string;
  pending_payment: boolean;
}

export interface Subscription {
  current: {
    plan: string | null;
    status: 'trialing' | 'active' | 'past_due' | 'suspended' | 'cancelled' | 'expired';
    billing_interval: string;
    sites_quantity: number;
    unit_amount: string | null;
    currency_code: string;
    trial_ends_at: string | null;
    current_period_start: string | null;
    current_period_end: string | null;
    cancel_at_period_end: boolean;
  } | null;
  active_sites: number;
  requested_plan: string | null;
  requested_sites: number | null;
  plans: { code: string; name: Localized; billing_model: string; is_trial: boolean }[];
}

/** A colour offered on lots and waste types: a default of the platform, or one the company added. */
export interface Color {
  code: string;
  name: Localized;
  /** "custom", or the colour library the colour refers to ("pantone"). */
  color_system: string;
  /** Inside a library: coated, uncoated, metallic… */
  collection: string | null;
  /** The reference as the library writes it, or the company's own code for a custom colour. */
  reference_code: string | null;
  hex_color: string | null;
  rgb: { r: number; g: number; b: number } | null;
  /** Computed from the screen colour, not measured on a sample. */
  lab: { l: number; a: number; b: number } | null;
  /** Typed by the company, e.g. "19-4052 TCX", or the reference of the catalogue colour it was taken from. */
  pantone_reference: string | null;
  source: 'platform' | 'import' | 'user';
  verification_status: string;
  /** Belongs to the company (added by hand or taken from the catalogue). */
  is_custom: boolean;
  /** A row of the reference catalogue: read, never modified. */
  is_catalogue: boolean;
  is_active: boolean;
}

/** A catalogue colour, with the code of the company colour taken from it, if any. */
export interface CatalogueColor extends Color {
  taken_as: string | null;
}

/** A catalogue colour near a given one; `delta_e` is the CIEDE2000 distance (0 = identical). */
export interface ColorMatch extends Color {
  delta_e: number;
}

export interface Reference {
  zone_types: { code: string; name: Localized; is_waste_storage: boolean }[];
  packaging_types: { code: string; name: Localized }[];
  units: { code: string; name: Localized; symbol: string }[];
  /** How a raw material is counted: metres, kilograms or pieces. */
  material_units: { code: string; name: Localized; symbol: string }[];
  waste_families: { code: string; name: Localized }[];
  materials: { code: string; name: Localized }[];
  color_families: { code: string; name: Localized; hex_color: string | null; pantone_reference: string | null; color_system: string; collection: string | null }[];
  treatment_channels: { code: string; name: Localized; is_valorization: boolean; is_landfill: boolean }[];
  regulatory_waste_codes: { code: string; label: Localized; is_hazardous: boolean }[];
}

export interface OnboardingState {
  completed: boolean;
  dismissed: boolean;
  steps: { key: 'sites' | 'waste_types' | 'users' | 'providers'; done: boolean; skipped: boolean; count: number }[];
}
