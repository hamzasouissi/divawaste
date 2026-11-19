import { CUSTOM_ELEMENTS_SCHEMA, ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { TranslocoDirective } from '@jsverse/transloco';

import { DivaBi } from '../core/divabi/divabi';

/**
 * A place of a screen where the company can show its own custom views (DivaBI "slot"): the dashboard, a lot's page…
 * Which views appear is decided in DivaBI (Emplacements). The screen passes what it shows — e.g. the lot's ULID — and
 * each view receives it as a parameter. Nothing is drawn when the user may not open custom views or no view is placed.
 */
@Component({
  selector: 'dw-custom-views-slot',
  imports: [TranslocoDirective],
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (divabi.ready()) {
      <section class="dw-panel" [hidden]="empty()" *transloco="let t">
        <div class="dw-panel-head"><h2 class="dw-h2">{{ title() || t('customViews.title') }}</h2></div>
        <div class="dw-panel-body">
          <divabi-slot [attr.name]="name()" [attr.context]="contextJson()" [attr.layout]="layout()" (divabi-slot-loaded)="loaded($event)"></divabi-slot>
        </div>
      </section>
    }
  `,
  styles: `
    divabi-slot { display: block; --divabi-font: inherit; --divabi-primary: var(--dw-primary); --divabi-primary-soft: var(--dw-primary-soft);
      --divabi-text: var(--dw-ink); --divabi-muted: var(--dw-ink-3); --divabi-border: var(--dw-line-soft); --divabi-surface: var(--dw-surface); --divabi-soft: var(--dw-surface-2); }
  `,
})
export class CustomViewsSlotComponent implements OnInit {
  protected readonly divabi = inject(DivaBi);
  /** Slot name, as declared in DivaBI's application package (config/divabi.php `slots`). */
  readonly name = input.required<string>();
  /** Values the slot provides, e.g. { lot_ulid: '01…' }. */
  readonly context = input<Record<string, string | number | null>>({});
  readonly layout = input<'stack' | 'tabs'>('tabs');
  readonly title = input<string | null>(null);
  protected readonly contextJson = computed(() => JSON.stringify(this.context()));
  protected readonly empty = signal(true);

  ngOnInit(): void {
    void this.divabi.load();
  }

  protected loaded(event: Event): void {
    this.empty.set(((event as CustomEvent<{ count: number }>).detail?.count ?? 0) === 0);
  }
}
