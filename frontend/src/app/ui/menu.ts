import { ChangeDetectionStrategy, Component, computed, input, viewChild } from '@angular/core';
import { MenuItem } from 'primeng/api';
import { Menu } from 'primeng/menu';

import { IconComponent } from './icon';

export interface MenuAction {
  label?: string;
  icon?: string;
  danger?: boolean;
  disabled?: boolean;
  separator?: boolean;
  run?: () => void;
}

/**
 * Popup menu (PrimeNG Menu) for the secondary actions of a row or of the account. One instance per screen is
 * enough: give it the actions of the row that was clicked, then `toggle($event)`.
 */
@Component({
  selector: 'dw-menu',
  imports: [Menu, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <p-menu #menu [popup]="true" [model]="model()" appendTo="body" styleClass="dw-menu">
      <ng-template #item let-item>
        <a class="p-menu-item-link" [class.danger]="item.state?.danger" tabindex="-1">
          @if (item.state?.icon) { <dw-icon>{{ item.state.icon }}</dw-icon> }
          <span class="p-menu-item-label">{{ item.label }}</span>
        </a>
      </ng-template>
    </p-menu>
  `,
})
export class MenuComponent {
  readonly actions = input<MenuAction[]>([]);

  private readonly menu = viewChild.required<Menu>('menu');

  protected readonly model = computed<MenuItem[]>(() =>
    this.actions().map((action) => (action.separator ? { separator: true } : { label: action.label, disabled: action.disabled, state: { icon: action.icon, danger: action.danger }, command: () => action.run?.() })),
  );

  toggle(event: Event): void {
    this.menu().toggle(event);
  }

  hide(): void {
    this.menu().hide();
  }
}
