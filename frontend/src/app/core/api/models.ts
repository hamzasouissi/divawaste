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
  unit: string | null;
  packaging_type: string | null;
  color_family: string | null;
  treatment_channel: string | null;
  default_grammage_gsm: string | null;
  default_composition: { material: string; pct: string }[] | null;
  density_kg_m3: string | null;
  unit_weight_kg: string | null;
  is_active: boolean;
}

export type LotStatus = 'created' | 'stored' | 'awaiting_pickup' | 'collected' | 'treated' | 'closed';

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
  net_weight_kg: string;
  gross_weight_kg: string | null;
  tare_weight_kg: string | null;
  color_family: string | null;
  color_label: string | null;
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
  oldest_lot_at: string;
  zone_quantity_kg: string;
  zone_capacity_kg: string | null;
  zone_alert_level: 'warning' | 'exceeded' | null;
}

export interface StockResponse {
  data: StockRow[];
  thresholds: { ulid: string; quantity_kg: string; max_quantity_kg: string; alert_level: 'warning' | 'exceeded' | null }[];
  meta: { total_kg: string; generated_at: string };
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
  declared_weight_kg: string;
  departure_weight_kg: string | null;
  received_weight_kg: string | null;
  weight_variance_pct: string | null;
  variance_flagged: boolean;
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
  provider: { ulid: string; legal_name: string } | null;
  transporter: { ulid: string; legal_name: string } | null;
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
  variance_acknowledged: boolean;
  variance_comment: string | null;
  currency_code: string;
  lots_count?: number;
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

export interface Dashboard {
  period: { from: string; to: string };
  totals: {
    generated_kg: string;
    generated_lots: number;
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
  status: 'draft' | 'pending_producer_signature' | 'pending_transporter_signature' | 'pending_receiver_signature' | 'completed' | 'cancelled';
  pickup: { ulid: string; pickup_number: string } | null;
  regulatory_code: string | null;
  waste_type: string | null;
  package_count: number | null;
  declared_weight_kg: string | null;
  received_weight_kg: string | null;
  producer: Record<string, string | null>;
  transporter: Record<string, string | null>;
  receiver: Record<string, string | null>;
  signatures: { party: 'producer' | 'transporter' | 'receiver'; sequence: number; status: string; company: { ulid: string; legal_name: string }; signed_by: string | null; signed_at: string | null }[];
  awaiting_company: string | null;
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
  parameters: { from?: string; to?: string; site?: string | null };
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
  hex_color: string | null;
  is_custom: boolean;
  is_active: boolean;
}

export interface Reference {
  zone_types: { code: string; name: Localized; is_waste_storage: boolean }[];
  packaging_types: { code: string; name: Localized }[];
  units: { code: string; name: Localized; symbol: string }[];
  waste_families: { code: string; name: Localized }[];
  materials: { code: string; name: Localized }[];
  color_families: { code: string; name: Localized; hex_color: string | null }[];
  treatment_channels: { code: string; name: Localized; is_valorization: boolean; is_landfill: boolean }[];
  regulatory_waste_codes: { code: string; label: Localized; is_hazardous: boolean }[];
}

export interface OnboardingState {
  completed: boolean;
  dismissed: boolean;
  steps: { key: 'sites' | 'waste_types' | 'users' | 'providers'; done: boolean; skipped: boolean; count: number }[];
}
