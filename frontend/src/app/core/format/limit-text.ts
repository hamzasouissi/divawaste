import { Injectable, Injector, inject, runInInjectionContext, untracked } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';

import { LimitResult } from '../api/models';
import { CodePipe, KgPipe, NumberPipe, QuantityPipe } from './pipes';

/** What the API sends with the refusal of an authorisation: the document, the waste and every limit with its answer. */
export interface AuthorizationRefusal {
  reference?: string | null;
  waste_type?: string | null;
  reason?: string | null;
  constraints?: LimitResult[];
  /** A refusal that comes from what the receiver asks of a collection, not from an authorisation. */
  provider?: string | null;
  may_override?: boolean;
}

/**
 * Says a limit in words, from the figures the API answered with: its name ("Colis (Sac)"), and what is wrong with it
 * ("limite de 300 × Sac dépassée de 10 × Sac"). Used wherever a refusal is told outside the page that shows every limit.
 */
@Injectable({ providedIn: 'root' })
export class LimitText {
  private readonly transloco = inject(TranslocoService);
  private readonly injector = inject(Injector);
  private pipes: { kg: KgPipe; code: CodePipe; number: NumberPipe; quantity: QuantityPipe } | null = null;

  /** "Poids", "Lots", "Colis (Sac)", "Colis (quantité commune)". */
  name(limit: LimitResult): string {
    if (limit.kind) {
      return this.transloco.translate('requirements.name.' + limit.kind);
    }

    return limit.packaging_type && limit.key !== 'stock'
      ? `${this.transloco.translate('limits.name.packaging')} (${this.packaging(limit.packaging_type)})`
      : this.transloco.translate('limits.name.' + limit.key);
  }

  /** A quantity in the unit of the limit: "500 kg", "0,9 t", "12,5 m³", "500 l", "240 pièces", "300 × Sac", "2 lot(s)". */
  quantity(limit: LimitResult, value: string | number | null | undefined): string {
    if (limit.unit === 'kg') {
      return this.tools().kg.transform(value ?? null, 'auto');
    }
    if (limit.unit === 'm3') {
      return this.tools().number.transform(value ?? null, 2, ' m³');
    }
    if (limit.unit === 't' || limit.unit === 'l' || limit.unit === 'piece') {
      return this.tools().quantity.transform(value ?? null, limit.unit);
    }

    // Packages are whole: "100 × Sac", whatever the number of decimals the figure was stored with.
    return limit.packaging_type ? `${this.tools().number.transform(value ?? null)} × ${this.packaging(limit.packaging_type)}` : this.transloco.translate('dashboard.lots', { count: value });
  }

  /** What is wrong with a limit that is exceeded or cannot be verified, and what to do about it. Empty when nothing is. */
  problem(limit: LimitResult): string {
    const lots = limit.missing_lots.join(', ') + (limit.missing_count > limit.missing_lots.length ? '…' : '');
    if (limit.outcome === 'exceeded') {
      const said = limit.lines
        ? this.transloco.translate('limits.problem.sharedExceeded', {
          lines: limit.lines.map((line) => `${line.max} × ${this.packaging(line.packaging_type)}`).join(` ${this.transloco.translate('common.or')} `),
          pct: Math.round((limit.pct ?? 100) - 100),
        })
        : this.transloco.translate('limits.problem.exceeded', { max: this.quantity(limit, limit.max), by: this.quantity(limit, limit.excess) });

      return limit.missing_count > 0 ? `${said} ${this.transloco.translate('limits.problem.alsoMissing', { lots })}` : said;
    }
    if (limit.outcome !== 'unverifiable') {
      return '';
    }

    return this.transloco.translate(limit.key === 'unlisted' ? 'limits.problem.unlisted' : limit.unit === 'kg' || limit.unit === 't' ? 'limits.problem.weightUnknown' : limit.unit === 'package' ? 'limits.problem.countUnknown' : 'limits.problem.measureUnknown',
      { lots, count: limit.missing_count, packaging: limit.packaging_type ? this.packaging(limit.packaging_type) : '' });
  }

  /**
   * What a provider asks of a collection, and how the collection answers: "au moins 500 kg : 320 kg, il manque 180 kg",
   * "conditionnement demandé Balle : 1 lot dans un autre conditionnement (L-2026-000012)".
   */
  requirement(limit: LimitResult): string {
    const lots = (numbers: string[] | undefined, count: number | undefined): string => (numbers ?? []).join(', ') + ((count ?? 0) > (numbers ?? []).length ? '…' : '');
    if (limit.kind === 'packaging') {
      return this.transloco.translate(limit.outcome === 'respected' ? 'requirements.packagingMet' : 'requirements.packagingNotMet',
        { packaging: this.packaging(limit.packaging_type ?? ''), count: limit.outside_count ?? 0, lots: lots(limit.outside_lots, limit.outside_count) });
    }
    const params = { min: this.quantity(limit, limit.min), have: this.quantity(limit, limit.used), short: this.quantity(limit, limit.shortfall), count: limit.missing_count, lots: lots(limit.missing_lots, limit.missing_count) };
    const said = this.transloco.translate(limit.outcome === 'respected' ? 'requirements.met' : limit.outcome === 'unverifiable' ? 'requirements.unverifiable' : 'requirements.notMet', params);

    return limit.outcome === 'respected' && limit.missing_count > 0 ? `${said} ${this.transloco.translate('requirements.alsoMissing', params)}` : said;
  }

  /** The whole refusal in one sentence, or null when the API sent no limit to explain it with. */
  refusal(context: AuthorizationRefusal | null | undefined): string | null {
    if (context?.provider) {
      const failing = (context.constraints ?? []).filter((limit) => limit.blocking && (limit.outcome === 'exceeded' || limit.outcome === 'unverifiable')).map((limit) => this.requirement(limit));

      return failing.length === 0 ? null : this.transloco.translate(context.may_override ? 'errors.providerRequirementOverridable' : 'errors.providerRequirement', { provider: context.provider, problems: failing.join(' ; ') });
    }
    const problems = (context?.constraints ?? []).filter((limit) => limit.blocking).map((limit) => this.problem(limit)).filter(Boolean);

    return problems.length === 0 ? null
      : this.transloco.translate('errors.authorizationRefused', { problems: problems.join(' ; '), reference: context?.reference ?? '—', wasteType: context?.waste_type ?? '—' });
  }

  private packaging(code: string): string {
    return this.tools().code.transform(code, 'packaging');
  }

  /**
   * The pipes read the reference data of the signed-in company: they are made when first needed, not at start-up. That
   * first need is often a template being rendered, and the pipes fill a signal as soon as they are created: they are
   * made outside of whatever is being computed at that moment.
   */
  private tools(): { kg: KgPipe; code: CodePipe; number: NumberPipe; quantity: QuantityPipe } {
    return (this.pipes ??= untracked(() => runInInjectionContext(this.injector, () => ({ kg: new KgPipe(), code: new CodePipe(), number: new NumberPipe(), quantity: new QuantityPipe() }))));
  }
}
