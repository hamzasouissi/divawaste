import { ChangeDetectionStrategy, Component } from '@angular/core';

/**
 * Icon of the product (Material Icons Outlined ligature): `<dw-icon>add</dw-icon>`. Decorative unless the
 * caller gives it an `aria-label`. Size follows `font-size`, so a context only sets one property.
 */
@Component({
  selector: 'dw-icon',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<ng-content />',
  host: { class: 'dw-icon', translate: 'no' },
})
export class IconComponent {}
