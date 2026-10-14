import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { Observable, firstValueFrom, forkJoin, of } from 'rxjs';
import { ButtonDirective } from 'primeng/button';

import { Api, ApiError, Page, openBlob } from '../../core/api/api';
import { DocumentRecord, Manifest, Pickup, PickupLine } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { CodePipe, DatePipe, KnownWeightPipe, MoneyPipe, NumberPipe, QuantityPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { RequirementsComponent } from '../../ui/requirements';
import { StateComponent } from '../../ui/state';
import { CertificateDialog, LinesDialogData, LinesMode, PickupLinesDialog } from './pickup-dialogs';
import { ToleranceDecisionCard, measure, nextStep } from './tolerance';
import { DateComponent } from '../../ui/date';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';

@Component({
  imports: [FormsModule, TranslocoDirective, ButtonDirective, ...FIELD, DateComponent, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('pickups.actions.confirm') }}</dw-dialog-title>
      <div class="dw-dialog-body">
        <dw-field [label]="t('pickups.confirmedDate')">
          <dw-date [ngModel]="date()" (ngModelChange)="date.set($event)" [min]="min" required />
        </dw-field>
      </div>
      <div class="dw-dialog-foot">
        <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
        <button pButton type="button" [disabled]="date() < min" (click)="ref.close(date())">{{ t('common.confirm') }}</button>
      </div>
    </ng-container>
  `,
  styles: '.dw-dialog-body { min-width: min(340px, 80vw); }',
})
export class ConfirmDateDialog {
  protected readonly ref = inject(DialogRef);
  private readonly requested = dialogData<string>();
  protected readonly min = new Date().toISOString().slice(0, 10);
  protected readonly date = signal(this.requested >= this.min ? this.requested : this.min);
}

/**
 * One pickup, for both parties (M4-03 to M4-05, M5-02, M5-04). The header offers exactly the actions that the
 * current status and the user's permissions allow; everything else about the pickup is on the page.
 */
@Component({
  imports: [RouterLink, TranslocoDirective, PageHeaderComponent, StatusComponent, StateComponent, CodePipe, DatePipe, KnownWeightPipe, MoneyPipe, NumberPipe, QuantityPipe, ToleranceDecisionCard, IconComponent, ButtonDirective, RequirementsComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (pickup(); as p) {
          <dw-page-header [title]="p.pickup_number" [subtitle]="provider ? (p.company?.legal_name ?? '') : (p.provider?.legal_name ?? '')" [back]="listPath" [backLabel]="t(provider ? 'provider.pickups.title' : 'pickups.title')">
            <dw-status dwTitleBadge kind="pickup" [value]="p.status" />
            @for (action of actions(); track action.key) {
              @if (action.primary) {
                <button pButton type="button" [disabled]="busy()" (click)="run(action.key)"><dw-icon>{{ action.icon }}</dw-icon>{{ t('pickups.actions.' + action.key) }}</button>
              } @else {
                <button pButton [severity]="action.danger ? 'danger' : 'secondary'" outlined type="button" [disabled]="busy()" (click)="run(action.key)"><dw-icon>{{ action.icon }}</dw-icon>{{ t('pickups.actions.' + action.key) }}</button>
              }
            }
          </dw-page-header>

          <div class="dw-stack">
            @if (p.status === 'refused') { <p class="banner danger" role="status">{{ t('pickups.refused', { reason: p.refusal_reason }) }}</p> }
            @if (p.status === 'cancelled') { <p class="banner danger" role="status">{{ t('pickups.cancelled', { reason: p.cancellation_reason }) }}</p> }
            @if (onBehalf()) { <p class="banner info" role="note"><dw-icon aria-hidden="true">info</dw-icon><span>{{ t('pickups.onBehalf', { name: p.provider?.legal_name }) }}</span></p> }
            @if (p.variance_flagged) {
              <p class="banner" [class.danger]="!p.variance_acknowledged" role="status">
                <dw-icon aria-hidden="true">scale</dw-icon>
                <span>
                  {{ t('tolerance.banner.' + (p.tolerance_status ?? 'warning'), { count: flagged().length }) }}
                  @if (p.variance_acknowledged) {
                    {{ t(p.variance_decision === 'authorized' ? 'tolerance.authorized' : 'pickups.varianceAck', { comment: p.variance_comment }) }}
                  } @else if (!provider) {
                    <strong>{{ t('tolerance.next.' + next()) }}</strong>
                  }
                </span>
              </p>
            }

            @if (p.provider_requirements; as asked) {
              <section class="dw-panel dw-panel-body asked">
                <h2 class="dw-h2">{{ t('requirements.titleDecided', { provider: p.provider?.legal_name }) }}</h2>
                <p class="dw-muted">{{ t('requirements.decidedHint', { date: (asked.decided_at | dwDate: 'datetime') }) }}</p>
                <dw-requirements [requirements]="asked.constraints" />
                @if (asked.override; as override) {
                  <p class="banner" role="note"><dw-icon aria-hidden="true">gavel</dw-icon><span>{{ t('requirements.overridden', { by: override.by, date: (override.at | dwDate: 'datetime'), reason: override.reason }) }}</span></p>
                }
              </section>
            }

            <section class="dw-panel dw-panel-body">
              <dl class="dw-facts">
                <div><dt>{{ t('fields.site') }}</dt><dd>{{ p.site?.name }}<br /><span class="dw-muted">{{ p.site?.address_line1 }} {{ p.site?.city }}</span></dd></div>
                @if (provider) { <div><dt>{{ t('pickups.client') }}</dt><dd>{{ p.company?.legal_name }}</dd></div> }
                <div><dt>{{ t('pickups.receiver') }}</dt><dd>{{ p.provider?.legal_name }} @if (p.provider?.external) { <dw-status kind="member" value="suspended" tone="neutral" [label]="t('partners.noAccount')" /> }</dd></div>
                <div><dt>{{ t('pickups.transporter') }}</dt><dd>{{ p.transporter?.legal_name ?? t('pickups.receiverTransports') }} @if (p.transporter?.external) { <dw-status kind="member" value="suspended" tone="neutral" [label]="t('partners.noAccount')" /> }</dd></div>
                <div><dt>{{ t('pickups.requestedDate') }}</dt><dd>{{ p.requested_date | dwDate }}</dd></div>
                <div><dt>{{ t('pickups.confirmedDate') }}</dt><dd>{{ p.confirmed_date | dwDate }}</dd></div>
                <div><dt>{{ t('pickups.departure') }}</dt><dd>{{ p.departure_weight_kg | dwKgKnown: (p.departure_unweighed_lots ?? (p.received_at ? 0 : p.unweighed_lots)) }} @if (p.collected_at) { <span class="dw-faint">· {{ p.collected_at | dwDate: 'datetime' }}</span> }</dd></div>
                <div><dt>{{ t('pickups.received') }}</dt><dd>{{ p.received_weight_kg | dwKgKnown: (p.received_unweighed_lots ?? (p.received_at ? p.unweighed_lots : 0)) }} @if (p.received_at) { <span class="dw-faint">· {{ p.received_at | dwDate: 'datetime' }}</span> }</dd></div>
                @if (!provider && p.net_amount !== null && p.net_amount !== undefined) {
                  <div><dt>{{ t('pickups.netAmount') }}</dt><dd [class.neg]="+p.net_amount < 0"><strong>{{ p.net_amount | dwMoney: p.currency_code }}</strong></dd></div>
                }
              </dl>
            </section>

            <section class="dw-panel">
              <div class="dw-panel-head"><h2 class="dw-h2">{{ t('pickups.lotsCount', { count: p.lots?.length ?? 0 }) }}</h2></div>
              <div class="scroll">
                <table class="dw-table">
                  <thead>
                    <tr>
                      <th>{{ t('lots.number') }}</th><th>{{ t('fields.wasteType') }}</th><th>{{ t('lots.regulatoryCode') }}</th><th>{{ t('lots.packages') }}</th><th class="dw-num">{{ t('pickups.quantity.declared') }}</th>
                      <th class="dw-num">{{ t('pickups.quantity.departure') }}</th><th class="dw-num">{{ t('pickups.quantity.received') }}</th><th class="dw-num">{{ t('pickups.variance') }}</th>
                      <th>{{ t('pickups.channel') }}</th>@if (!provider) { <th class="dw-num">{{ t('pickups.amount') }}</th> }<th>{{ t('fields.status') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    @for (line of p.lots ?? []; track line.lot_number_snapshot) {
                      <tr>
                        <td class="dw-mono">
                          @if (line.lot) { <a [routerLink]="['/lots', line.lot]">{{ line.lot_number_snapshot }}</a> } @else { {{ line.lot_number_snapshot }} }
                          @if (line.is_hazardous) { <dw-icon class="hazard" [title]="t('common.hazardous')" [attr.aria-label]="t('common.hazardous')">warning</dw-icon> }
                        </td>
                        <td>{{ line.waste_type_label_snapshot }}</td><td class="dw-mono">{{ line.regulatory_code_snapshot ?? '—' }}</td>
                        <td>
                          @if (line.packaging_type) { {{ line.package_count ?? 1 }} × {{ line.packaging_type | dwCode: 'packaging' }} } @else { <span class="dw-faint">{{ t('lots.loose') }}</span> }
                          @if (line.corrected_package_count !== null && line.corrected_package_count !== undefined && line.corrected_package_count !== line.package_count) { <div class="dw-faint">{{ t('lots.packagesCorrected', { count: line.corrected_package_count }) }}</div> }
                        </td>
                        @for (stage of stages; track stage) {
                          <td class="dw-num">
                            @if (measure(line, stage).value === null && unweighed(line, stage)) { <span class="dw-faint">{{ t('lots.toWeigh') }}</span> }
                            @else { {{ measure(line, stage).value | dwQty: measure(line, stage).unit }}@if (stage !== 'received' && line.weight_basis === 'estimated' && measure(line, stage).unit === 'kg') { <span class="dw-faint"> {{ t('lots.estimatedTag') }}</span> } }
                          </td>
                        }
                        <td class="dw-num" [class.neg]="line.variance_flagged">
                          {{ (line.tolerance?.pct ?? line.weight_variance_pct) | dwNumber: 1 : ' %' }}
                          @if (line.tolerance_status && line.tolerance_status !== 'within') { <dw-status kind="tolerance" [value]="line.tolerance_status" /> }
                        </td>
                        <td>{{ line.treatment_channel ? t('channels.' + line.treatment_channel) : '—' }}</td>
                        @if (!provider) { <td class="dw-num" [class.neg]="+(line.net_amount ?? 0) < 0">{{ line.net_amount | dwMoney: p.currency_code }}</td> }
                        <td><dw-status kind="line" [value]="line.line_status" /></td>
                      </tr>
                    }
                  </tbody>
                </table>
              </div>
            </section>

            @if (judged().length > 0) {
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('tolerance.title') }}</h2><span class="dw-muted">{{ t('tolerance.summary', { flagged: flagged().length, total: judged().length }) }}</span></div>
                <div class="dw-panel-body decisions">
                  <p class="dw-muted">{{ t('tolerance.hint') }}</p>
                  @for (line of shown(); track line.lot_number_snapshot) { <dw-tolerance-decision [line]="line" /> }
                  @if (judged().length > flagged().length) {
                    <button pButton type="button" text (click)="everyLine.set(!everyLine())">{{ t(everyLine() ? 'tolerance.onlyFlagged' : 'tolerance.showAll', { count: judged().length - flagged().length }) }}</button>
                  }
                </div>
              </section>
            }

            @if (manifests().length > 0 || documents().length > 0) {
              <section class="dw-panel">
                <div class="dw-panel-head"><h2 class="dw-h2">{{ t('pickups.documents') }}</h2></div>
                <ul class="docs">
                  @for (manifest of manifests(); track manifest.ulid) {
                    <li>
                      <dw-icon aria-hidden="true">assignment_late</dw-icon>
                      <a [routerLink]="[provider ? '/provider/manifests' : '/compliance/manifests', manifest.ulid]">{{ t('compliance.documents.types.hazardous_manifest') }} <span class="dw-mono">{{ manifest.manifest_number }}</span></a>
                      <dw-status kind="manifest" [value]="manifest.status" />
                    </li>
                  }
                  @for (document of documents(); track document.ulid) {
                    @if (document.document_type !== 'hazardous_manifest') {
                      <li>
                        <dw-icon aria-hidden="true">description</dw-icon>
                        <button type="button" class="linkish" (click)="download(document)">{{ t('compliance.documents.types.' + document.document_type) }}</button>
                        <span class="dw-faint">{{ document.uploaded_by?.legal_name }} · {{ document.issued_at | dwDate }}</span>
                      </li>
                    }
                  }
                </ul>
              </section>
            }
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .banner { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: var(--dw-radius); background: var(--dw-success-soft); color: var(--dw-success); font-size: 13.5px; }
    .banner.danger { background: var(--dw-danger-soft); color: var(--dw-danger); }
    .banner.info { background: var(--dw-surface-2); color: var(--dw-ink-2); }
    .scroll { overflow-x: auto; } .decisions { display: grid; gap: 10px; } .decisions button { justify-self: start; }
    .neg { color: var(--dw-danger); font-weight: 600; }
    .hazard { font-size: 15px; width: 15px; height: 15px; color: var(--dw-warning); vertical-align: -2px; }
    .docs { list-style: none; margin: 0; padding: 0; } .docs li { display: flex; align-items: center; gap: 10px; padding: 10px var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }
    .docs li:last-child { border-block-end: 0; } .docs dw-icon { font-size: 18px; color: var(--dw-ink-3); }
    .linkish { all: unset; cursor: pointer; color: var(--dw-primary); } .linkish:hover { text-decoration: underline; } .linkish:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: 2px; }
  `,
})
export class PickupDetailPage implements OnInit {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly dialog = inject(Dialogs);
  private readonly confirm = inject(Confirm);
  private readonly toast = inject(Toast);

  readonly ulid = input.required<string>();
  protected readonly provider = this.session.audience() === 'provider';
  protected readonly listPath = this.provider ? '/provider/pickups' : '/pickups';
  protected readonly pickup = signal<Pickup | null>(null);
  protected readonly manifests = signal<Manifest[]>([]);
  protected readonly documents = signal<DocumentRecord[]>([]);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal<unknown>(null);
  protected readonly stages = ['declared', 'departure', 'received'] as const;
  protected readonly measure = measure;

  /** A weighed lot with no weight at a stage it has reached: said in words, an empty cell would look like a forgotten figure. */
  protected unweighed(line: PickupLine, stage: 'declared' | 'departure' | 'received'): boolean {
    return (line.measure_dimension ?? 'mass') === 'mass' && (stage === 'declared' || (stage === 'departure' && line.line_status !== 'planned' && line.line_status !== 'not_loaded'));
  }

  /** The lots judged against a tolerance at reception, and among them the ones someone has to look at. */
  protected readonly judged = computed(() => (this.pickup()?.lots ?? []).filter((line) => line.tolerance && line.tolerance_status));
  protected readonly flagged = computed(() => this.judged().filter((line) => line.variance_flagged));
  protected readonly everyLine = signal(false);
  protected readonly shown = computed(() => (this.everyLine() ? this.judged() : this.flagged()));
  protected readonly next = computed(() => {
    const pickup = this.pickup();

    return nextStep(pickup?.tolerance_status ?? (pickup?.variance_flagged ? 'warning' : null), pickup?.variance_acknowledged ?? false, this.session.can('pickups.authorize_variance', pickup?.site?.ulid));
  });

  /** The receiver has no account: the industrial records its answer, the reception and the treatment itself. */
  protected readonly onBehalf = computed(() => !this.provider && this.pickup()?.provider?.external === true && !['completed', 'cancelled', 'refused'].includes(this.pickup()?.status ?? ''));

  /** The actions available now: status of the pickup × side of the user × permissions. The API re-checks each one. */
  protected readonly actions = computed(() => {
    const pickup = this.pickup();
    if (!pickup) {
      return [];
    }
    const site = pickup.site?.ulid;
    const lines = pickup.lots ?? [];
    const has = (status: string): boolean => lines.some((line) => line.line_status === status);
    const can = (permission: string): boolean => this.session.can(permission, this.provider ? undefined : site);
    const external = pickup.provider?.external === true;
    const all: { key: string; icon: string; show: boolean; primary?: boolean; danger?: boolean }[] = this.provider
      ? [
          { key: 'confirm', icon: 'event_available', primary: true, show: pickup.status === 'requested' && can('provider.pickups.respond') },
          { key: 'refuse', icon: 'block', danger: true, show: pickup.status === 'requested' && can('provider.pickups.respond') },
          { key: 'receive', icon: 'scale', primary: true, show: pickup.status === 'collected' && can('provider.pickups.receive') },
          { key: 'treat', icon: 'recycling', primary: true, show: pickup.status === 'received' && has('received') && can('provider.pickups.receive') },
          { key: 'certificate', icon: 'upload_file', show: ['collected', 'received'].includes(pickup.status) && can('provider.certificates.upload') },
        ]
      : [
          { key: 'submit', icon: 'send', primary: true, show: pickup.status === 'draft' && can('pickups.request') },
          // Stages of a receiver without an account, recorded by the industrial.
          { key: 'confirm', icon: 'event_available', primary: true, show: external && pickup.status === 'requested' && can('pickups.request') },
          { key: 'refuse', icon: 'block', danger: true, show: external && pickup.status === 'requested' && can('pickups.request') },
          { key: 'receive', icon: 'scale', primary: true, show: external && pickup.status === 'collected' && can('pickups.complete') },
          { key: 'treat', icon: 'recycling', primary: true, show: external && pickup.status === 'received' && has('received') && can('pickups.complete') },
          { key: 'collect', icon: 'local_shipping', primary: true, show: pickup.status === 'confirmed' && can('pickups.load') },
          // A gap above the blocking limit is accepted only by who is entitled to; a smaller one is explained by who closes pickups.
          { key: 'acknowledge', icon: 'fact_check', primary: true, show: pickup.status === 'received' && this.next() === 'explain' && can('pickups.complete') },
          { key: 'authorize', icon: 'verified_user', primary: true, show: pickup.status === 'received' && this.next() === 'authorize' && can('pickups.complete') },
          { key: 'complete', icon: 'task_alt', primary: true, show: pickup.status === 'received' && !has('received') && (!pickup.variance_flagged || pickup.variance_acknowledged) && can('pickups.complete') },
          { key: 'price', icon: 'payments', show: ['collected', 'received', 'completed'].includes(pickup.status) && can('pickups.price') },
          { key: 'manifest', icon: 'assignment_late', show: ['confirmed', 'collected', 'received'].includes(pickup.status) && this.manifests().length === 0 && can('documents.generate') },
          { key: 'certificate', icon: 'upload_file', show: ['collected', 'received'].includes(pickup.status) && can('documents.upload') },
          { key: 'cancel', icon: 'cancel', danger: true, show: ['draft', 'requested', 'confirmed'].includes(pickup.status) && can('pickups.cancel') },
        ];

    return all.filter((action) => action.show);
  });

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    const canDocuments = this.provider ? this.session.can('provider.pickups.view') : this.session.can('documents.view');
    forkJoin({
      pickup: this.api.get<Pickup>(`pickups/${this.ulid()}`),
      manifests: canDocuments ? this.api.list<Manifest>('manifests', { filter: { pickup: this.ulid() } }) : of(null),
      documents: canDocuments ? this.api.list<DocumentRecord>('documents', { filter: { pickup: this.ulid() } }) : of(null),
    }).subscribe({
      next: ({ pickup, manifests, documents }) => {
        this.pickup.set(pickup);
        this.manifests.set((manifests as Page<Manifest> | null)?.data ?? []);
        this.documents.set((documents as Page<DocumentRecord> | null)?.data ?? []);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected async run(action: string): Promise<void> {
    const pickup = this.pickup();
    if (!pickup) {
      return;
    }
    const base = `pickups/${pickup.ulid}`;
    const text = (key: string, params?: Record<string, unknown>): string => this.toast.text(key, params);

    switch (action) {
      case 'submit':
        return this.submit(base);
      case 'complete':
        // Closing ends the trail of every lot of the pickup: it is asked once more.
        return (await this.confirm.ask({ title: text('pickups.completeConfirm'), message: text('pickups.completeHint'), confirmLabel: text('pickups.actions.complete') }))
          ? this.call(this.api.post(`${base}/complete`), 'pickups.done.completed') : undefined;
      case 'manifest':
        return this.call(this.api.post(`${base}/manifests`), 'pickups.done.manifest');
      case 'cancel': {
        const reason = await this.confirm.ask({ title: text('pickups.actions.cancel'), reasonLabel: text('pickups.cancelReason'), danger: true });

        return reason ? this.call(this.api.post(`${base}/cancel`, { reason }), 'pickups.done.cancelled') : undefined;
      }
      case 'refuse': {
        const reason = await this.confirm.ask({ title: text('pickups.actions.refuse'), reasonLabel: text('pickups.refuseReason'), danger: true });

        return reason ? this.call(this.api.post(`${base}/refuse`, { reason }), 'pickups.done.refused') : undefined;
      }
      case 'acknowledge':
      case 'authorize': {
        const comment = await this.confirm.ask({ title: text(`pickups.actions.${action}`), message: action === 'authorize' ? text('tolerance.authorizeHint') : undefined, reasonLabel: text('pickups.ackComment') });

        return comment ? this.call(this.api.post(`${base}/acknowledge-variance`, { comment }), 'pickups.done.acknowledged') : undefined;
      }
      case 'confirm': {
        const date = await firstValueFrom(this.dialog.open<ConfirmDateDialog, string, string>(ConfirmDateDialog, { data: pickup.requested_date }).afterClosed());

        return date ? this.call(this.api.post(`${base}/confirm`, { confirmed_date: date }), 'pickups.done.confirmed') : undefined;
      }
      case 'certificate':
        this.dialog.open<CertificateDialog, Pickup, boolean>(CertificateDialog, { data: pickup }).afterClosed().subscribe((done) => done && this.load());

        return;
      default:
        this.dialog.open<PickupLinesDialog, LinesDialogData, Pickup>(PickupLinesDialog, { data: { pickup, mode: action as LinesMode } }).afterClosed().subscribe((done) => done && this.load());
    }
  }

  protected download(document: DocumentRecord): void {
    this.api.blob(`documents/${document.ulid}/download`).subscribe({ next: (blob) => openBlob(blob), error: (error: unknown) => this.toast.error(error) });
  }

  /**
   * Sending a draft. When a blocking requirement of the provider stops it and this person may decide otherwise, the
   * reason is asked and the request is sent with it; the provider reads it and still accepts or refuses.
   */
  private async submit(base: string): Promise<void> {
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.post(`${base}/submit`));
      this.toast.success('pickups.done.submitted');
    } catch (error) {
      const overridable = error instanceof ApiError && ['PROVIDER_REQUIREMENT_NOT_MET', 'PROVIDER_REQUIREMENT_UNVERIFIABLE'].includes(error.code) && error.context?.['may_override'] === true;
      if (!overridable) {
        this.toast.error(error);
      } else {
        this.busy.set(false);
        const reason = await this.confirm.ask({ title: this.toast.text('requirements.overrideTitle'), message: this.toast.describe(error), reasonLabel: this.toast.text('requirements.overrideReason'), danger: true });
        if (reason) {
          return this.call(this.api.post(`${base}/submit`, { requirement_override_reason: reason }), 'pickups.done.submitted');
        }
      }
    } finally {
      this.busy.set(false);
      this.load();
    }
  }

  private async call(request: Observable<unknown>, success: string): Promise<void> {
    this.busy.set(true);
    try {
      await firstValueFrom(request);
      this.toast.success(success);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
      this.load();
    }
  }
}
