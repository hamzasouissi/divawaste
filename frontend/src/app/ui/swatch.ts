import { ChangeDetectionStrategy, Component, Pipe, PipeTransform, computed, inject, input } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';

import { colorLabel } from '../core/format/color';
import { ReferenceData } from '../core/data/reference';
import { Locale } from '../core/i18n/i18n';

/** The tint of a colour. A colour without a tint (multicolour, mixed) shows a hatched square, not an empty one. */
@Component({
  selector: 'dw-swatch',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '',
  styles: `
    :host { display: inline-block; flex: none; width: 16px; height: 16px; border-radius: 4px; border: 1px solid var(--dw-line); vertical-align: -3px; }
    :host(.none) { background: repeating-linear-gradient(45deg, var(--dw-surface-2) 0 4px, var(--dw-surface) 4px 8px); }
    :host(.large) { display: block; width: 100%; height: 56px; border-radius: var(--dw-radius) var(--dw-radius) 0 0; border-width: 0 0 1px; }
  `,
  host: { 'aria-hidden': 'true', '[style.background]': 'hex() || null', '[class.none]': '!hex()', '[class.large]': 'size() === "large"' },
})
export class SwatchComponent {
  readonly hex = input<string | null | undefined>(null);
  readonly size = input<'small' | 'large'>('small');
}

/** Label of a colour in a list of choices: `(color.name | dwLocalized) | dwColorName: color.pantone_reference`. */
@Pipe({ name: 'dwColorName' })
export class ColorNamePipe implements PipeTransform {
  transform(name: string, pantoneReference: string | null | undefined): string {
    return colorLabel(name, pantoneReference);
  }
}

/**
 * A colour named by its code, the same everywhere: its tint, its name and its Pantone reference.
 *
 *   <dw-color [code]="lot.color_family" />
 */
@Component({
  selector: 'dw-color',
  imports: [SwatchComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `@if (color(); as c) { <dw-swatch [hex]="c.hex" /> {{ c.label }} } @else { — }`,
  styles: ':host { white-space: nowrap; }',
})
export class ColorComponent {
  readonly code = input<string | null | undefined>(null);

  private readonly locale = inject(Locale);
  private readonly vocabulary = toSignal(inject(ReferenceData).vocabulary());

  protected readonly color = computed(() => {
    const code = this.code();
    if (!code) {
      return null;
    }
    const entry = this.vocabulary()?.color_families.find((item) => item.code === code);

    // A withdrawn colour is no longer in the vocabulary: what carries it still shows something readable.
    return entry ? { hex: entry.hex_color, label: colorLabel(this.locale.pick(entry.name, code), entry.pantone_reference) } : { hex: null, label: code.replace(/_/g, ' ') };
  });
}

export const COLOR = [SwatchComponent, ColorComponent, ColorNamePipe] as const;
