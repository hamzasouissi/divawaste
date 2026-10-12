import { Injectable, Injector, inject, runInInjectionContext, untracked } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';

import { LimitResult } from '../api/models';
import { CodePipe, KgPipe, NumberPipe } from './pipes';

/** What the API sends with the refusal of an authorisation: the document, the waste and every limit with its answer. */
export interface AuthorizationRefusal {
  reference?: string | null;
  waste_type?: string | null;
  reason?: string | null;
  constraints?: LimitResult[];
}

/**
 * Says a limit in words, from the figures the API answered with: its name ("Colis (Sac)"), and what is wrong with it
 * ("limite de 300 × Sac dépassée de 10 × Sac"). Used wherever a refusal is told outside the page that shows every limit.
 */
@Injectable({ providedIn: 'root' })
export class LimitText {
  private readonly transloco = inject(TranslocoService);
  private readonly injector = inject(Injector);
  private pipes: { kg: KgPipe; code: CodePipe; number: NumberPipe } | null = null;

  /** "Poids", "Lots", "Colis (Sac)", "Colis (quantité commune)". */
  name(limit: LimitResult): string {
    return limit.packaging_type
      ? `${this.transloco.translate('limits.name.packaging')} (${this.packaging(limit.packaging_type)})`
      : this.transloco.translate('limits.name.' + limit.key);
  }

  /** A quantity in the unit of the limit: "500 kg", "12,5 m³", "300 × Sac", "2 lot(s)". */
  quantity(limit: LimitResult, value: string | number | null | undefined): string {
    if (limit.unit === 'kg') {
      return this.tools().kg.transform(value ?? null, 'auto');
    }
    if (limit.unit === 'm3') {
      return this.tools().number.transform(value ?? null, 2, ' m³');
    }

    return limit.packaging_type ? `${value} × ${this.packaging(limit.packaging_type)}` : this.transloco.translate('dashboard.lots', { count: value });
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

    return this.transloco.translate(limit.key === 'unlisted' ? 'limits.problem.unlisted' : limit.unit === 'kg' ? 'limits.problem.weightUnknown' : 'limits.problem.countUnknown',
      { lots, count: limit.missing_count, packaging: limit.packaging_type ? this.packaging(limit.packaging_type) : '' });
  }

  /** The whole refusal in one sentence, or null when the API sent no limit to explain it with. */
  refusal(context: AuthorizationRefusal | null | undefined): string | null {
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
  private tools(): { kg: KgPipe; code: CodePipe; number: NumberPipe } {
    return (this.pipes ??= untracked(() => runInInjectionContext(this.injector, () => ({ kg: new KgPipe(), code: new CodePipe(), number: new NumberPipe() }))));
  }
}
