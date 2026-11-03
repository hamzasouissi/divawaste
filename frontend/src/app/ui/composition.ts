import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { FormArray, FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { startWith, switchMap } from 'rxjs';

import { ReferenceData } from '../core/data/reference';
import { LocalizedPipe } from '../core/format/pipes';
import { FIELD } from './field';
import { IconComponent } from './icon';
import { SELECT } from './select';

export interface CompositionLine {
  material: string;
  pct: string;
}

const PCT = [Validators.required, Validators.pattern(/^\d+([.,]\d{1,2})?$/)];

/** A form array of `{ material, pct }` lines, filled with what is already known. */
export function compositionArray(fb: FormBuilder, lines: readonly CompositionLine[] = []): FormArray<FormGroup> {
  return fb.array(lines.map((line) => compositionLine(fb, line.material, String(Number(line.pct)))));
}

export function compositionLine(fb: FormBuilder, material = '', pct = ''): FormGroup {
  return fb.group({ material: [material, Validators.required], pct: [pct, PCT] });
}

/** What the API takes: decimals with a dot, an empty list when nothing is known. */
export function compositionValue(array: FormArray<FormGroup>): CompositionLine[] {
  return array.getRawValue().map((line) => ({ material: String(line['material']), pct: String(line['pct']).replace(',', '.') }));
}

/** The total of the percentages, rounded to the hundredth; 100 when the composition is complete. */
export function compositionTotal(array: FormArray<FormGroup>): number {
  return Math.round(array.getRawValue().reduce((sum, line) => sum + (Number(String(line['pct']).replace(',', '.')) || 0), 0) * 100) / 100;
}

/**
 * What a waste is made of, fibre by fibre: a material and its share, the shares adding up to 100 %. Anyone may fill it
 * in — the label of the garment, the supplier's data sheet, or what the operator knows — without following raw
 * materials or production. Left empty, the composition is simply not known.
 */
@Component({
  selector: 'dw-composition-editor',
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, LocalizedPipe, IconComponent, ...FIELD, ...SELECT],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <div class="lines">
        @for (group of lines().controls; track group; let index = $index) {
          <div class="line" [formGroup]="group">
            <dw-field [label]="index === 0 ? t('wasteTypes.material') : ''">
              <dw-select formControlName="material" required [ariaLabel]="t('wasteTypes.material')">
                @for (material of materials(); track material.code) { <dw-option [value]="material.code" [label]="material.name | dwLocalized" /> }
              </dw-select>
            </dw-field>
            <dw-field class="pct" [label]="index === 0 ? t('wasteTypes.pct') : ''">
              <input pInputText formControlName="pct" inputmode="decimal" required [attr.aria-label]="t('wasteTypes.pct')" />
            </dw-field>
            <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="lines().removeAt(index)" [attr.aria-label]="t('common.remove')" [title]="t('common.remove')"><dw-icon>close</dw-icon></button>
          </div>
        }
      </div>
      <div class="dw-row foot">
        <button pButton severity="secondary" outlined type="button" (click)="add()"><dw-icon>add</dw-icon>{{ t('wasteTypes.addMaterial') }}</button>
        @if (lines().length > 0) {
          <span [class.bad]="total() !== 100" role="status">{{ t('wasteTypes.compositionTotal', { total: total() }) }}</span>
          @if (total() < 100) { <button type="button" class="linkish" (click)="complete()">{{ t('lots.compositionComplete') }}</button> }
        } @else {
          <span class="dw-faint">{{ t('lots.compositionUnknown') }}</span>
        }
      </div>
    </ng-container>
  `,
  styles: `
    :host { display: grid; gap: var(--dw-2); }
    .lines { display: grid; gap: var(--dw-1); }
    .line { display: flex; gap: var(--dw-2); align-items: flex-end; } .line dw-field:first-child { flex: 1; min-width: 0; } .pct { max-width: 110px; }
    .foot { gap: var(--dw-3); align-items: center; flex-wrap: wrap; font-size: 13px; }
    .bad { color: var(--dw-danger); font-weight: 600; }
    .linkish { all: unset; cursor: pointer; color: var(--dw-primary); font-size: 13px; } .linkish:hover { text-decoration: underline; } .linkish:focus-visible { outline: 2px solid var(--dw-primary); outline-offset: 2px; }
  `,
})
export class CompositionEditorComponent {
  private readonly fb = inject(FormBuilder);
  private readonly vocabulary = toSignal(inject(ReferenceData).vocabulary());

  /** The lines, owned by the form of the caller. */
  readonly lines = input.required<FormArray<FormGroup>>();

  protected readonly materials = computed(() => this.vocabulary()?.materials ?? []);
  private readonly value = toSignal(toObservable(this.lines).pipe(switchMap((array) => array.valueChanges.pipe(startWith(array.value)))));
  protected readonly total = computed(() => {
    this.value();

    return compositionTotal(this.lines());
  });

  protected add(): void {
    this.lines().push(compositionLine(this.fb));
  }

  /** Gives what is missing to reach 100 % to the last line: the usual "and the rest is polyester". */
  protected complete(): void {
    const last = this.lines().at(this.lines().length - 1);
    const others = compositionTotal(this.lines()) - (Number(String(last.value['pct']).replace(',', '.')) || 0);
    last.patchValue({ pct: String(Math.round((100 - others) * 100) / 100) });
  }
}
