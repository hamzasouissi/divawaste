import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

import { IconComponent } from './icon';

export interface Step {
  key: string;
  label: string;
}

/**
 * Where an object stands and what happens next (G-07): its steps, the current one, who must act now, and — projected —
 * the one action that moves it on. A stopped object (refused, cancelled) shows where it stopped and why.
 *
 *   <dw-steps [steps]="steps" current="confirmed" [next]="'Recyclage du Sahel doit confirmer la date'"> <button …/> </dw-steps>
 */
@Component({
  selector: 'dw-steps',
  imports: [IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ol class="track" [attr.aria-label]="label() || null">
      @for (step of steps(); track step.key; let i = $index) {
        <li [class.done]="i < index()" [class.current]="i === index()" [class.stopped]="i === index() && !!stopped()" [attr.aria-current]="i === index() ? 'step' : null">
          <span class="dot">@if (i < index() || (i === index() && finished())) { <dw-icon>check</dw-icon> } @else if (i === index() && stopped()) { <dw-icon>close</dw-icon> }</span>
          <span class="name">{{ i === index() && stopped() ? stopped() : step.label }}</span>
        </li>
      }
    </ol>
    @if (next() || note()) {
      <div class="next" [class.danger]="tone() === 'danger'" [class.done]="finished()">
        <span class="ico"><dw-icon>{{ finished() ? 'task_alt' : tone() === 'danger' ? 'block' : 'arrow_forward' }}</dw-icon></span>
        <span class="text">
          @if (next()) { <strong>{{ next() }}</strong> }
          @if (note()) { <span>{{ note() }}</span> }
        </span>
        <span class="act"><ng-content /></span>
      </div>
    }
  `,
  styles: `
    :host { display: block; background: var(--dw-surface); border-radius: var(--dw-radius-lg); box-shadow: var(--dw-shadow); padding: 18px 20px; }
    .track { list-style: none; margin: 0; padding: 0; display: flex; gap: 0; overflow-x: auto; }
    li { position: relative; flex: 1 1 0; min-width: 96px; display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; }
    li::before { content: ''; position: absolute; inset-block-start: 11px; inset-inline-end: 50%; width: 100%; height: 2px; background: var(--dw-line); z-index: 0; }
    li:first-child::before { display: none; }
    li.done::before, li.current::before { background: var(--dw-primary); }
    .dot { position: relative; z-index: 1; display: grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; background: var(--dw-surface); box-shadow: inset 0 0 0 2px var(--dw-line-strong); color: #fff; }
    .dot dw-icon { font-size: 15px; }
    li.done .dot { background: var(--dw-primary); box-shadow: none; }
    li.current .dot { background: var(--dw-surface); box-shadow: inset 0 0 0 2px var(--dw-primary), 0 0 0 5px var(--dw-focus-soft); }
    li.current .dot::after { content: ''; width: 8px; height: 8px; border-radius: 50%; background: var(--dw-primary); }
    li.current:has(dw-icon) .dot::after { display: none; }
    li.current:has(dw-icon) .dot { background: var(--dw-primary); color: #fff; }
    li.stopped::before { background: var(--dw-danger); }
    li.stopped .dot { background: var(--dw-danger) !important; box-shadow: 0 0 0 5px var(--dw-danger-soft) !important; }
    .name { font-size: 12.5px; font-weight: 540; color: var(--dw-ink-3); line-height: 16px; padding-inline: 4px; }
    li.done .name { color: var(--dw-ink-2); }
    li.current .name { color: var(--dw-ink); font-weight: 650; }
    li.stopped .name { color: var(--dw-danger); }
    .next { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-block-start: 18px; padding: 12px 12px 12px 14px; border-radius: 10px; background: var(--dw-primary-soft); }
    .next.danger { background: var(--dw-danger-soft); }
    .next.done { background: var(--dw-success-soft); }
    .ico { display: grid; place-items: center; flex: none; width: 30px; height: 30px; border-radius: 8px; background: var(--dw-surface); color: var(--dw-primary); }
    .next.danger .ico { color: var(--dw-danger); } .next.done .ico { color: var(--dw-success); }
    .ico dw-icon { font-size: 17px; }
    .text { display: flex; flex-direction: column; gap: 1px; flex: 1 1 260px; min-width: 0; font-size: 13px; color: var(--dw-ink-2); }
    .text strong { font-size: 14px; font-weight: 620; color: var(--dw-ink); }
    .act { display: flex; gap: 8px; flex-wrap: wrap; }
    .act:empty { display: none; }
  `,
})
export class StepsComponent {
  readonly steps = input.required<Step[]>();
  readonly current = input.required<string>();
  /** Replaces the label of the current step when the object stopped there (refused, cancelled). */
  readonly stopped = input<string | null>(null);
  /** The object reached its last step. */
  readonly finished = input(false);
  /** Who must act now, in a sentence. */
  readonly next = input('');
  readonly note = input('');
  readonly tone = input<'normal' | 'danger'>('normal');
  readonly label = input('');

  protected readonly index = computed(() => Math.max(0, this.steps().findIndex((step) => step.key === this.current())));
}
