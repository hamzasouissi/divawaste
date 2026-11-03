import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { firstValueFrom } from 'rxjs';

import { Api } from '../../core/api/api';
import { Toast } from '../../core/feedback/toast';
import { DatePipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { PageHeaderComponent } from '../../ui/page';

interface ApiKey {
  id: number;
  name: string;
  owner: string;
  created_at: string;
  last_used_at: string | null;
  expires_at: string | null;
}

/**
 * API keys of the company: an ERP or a script sends its data to DivaWaste directly (lots, production, orders…), with
 * the rights of the person who made the key, for this company only. A key is shown once, lasts a year, and is
 * revoked here at any time.
 */
@Component({
  imports: [FormsModule, TranslocoDirective, ButtonDirective, InputText, ...FIELD, IconComponent, PageHeaderComponent, DatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('apiKeys.title')" [subtitle]="t('apiKeys.subtitle')" back="/settings" [backLabel]="t('nav.settings')" />

      <section class="dw-panel how">
        <h2 class="dw-h3">{{ t('apiKeys.howTitle') }}</h2>
        <ol>
          <li>{{ t('apiKeys.how1') }}</li>
          <li>{{ t('apiKeys.how2') }} <code>Authorization: Bearer &lt;clé&gt;</code></li>
          <li>{{ t('apiKeys.how3') }} <code>{{ base }}</code></li>
        </ol>
        <p class="dw-faint">{{ t('apiKeys.rights') }}</p>
      </section>

      @if (created(); as key) {
        <!-- shown once: the key cannot be read again -->
        <section class="dw-panel fresh" role="status">
          <p><dw-icon aria-hidden="true">key</dw-icon><strong>{{ t('apiKeys.createdTitle', { name: key.name }) }}</strong></p>
          <div class="token"><code>{{ key.token }}</code>
            <button pButton type="button" size="small" (click)="copy(key.token)"><dw-icon>content_copy</dw-icon>{{ t('apiKeys.copy') }}</button></div>
          <p class="dw-faint">{{ t('apiKeys.onlyOnce') }}</p>
        </section>
      }

      <section class="dw-panel">
        <form class="new" (submit)="$event.preventDefault(); create()">
          <dw-field [label]="t('apiKeys.name')"><input pInputText name="name" [(ngModel)]="name" maxlength="80" [placeholder]="t('apiKeys.namePlaceholder')" /></dw-field>
          <button pButton type="submit" [disabled]="busy() || !name.trim()"><dw-icon>add</dw-icon>{{ t('apiKeys.create') }}</button>
        </form>
        <table class="plain">
          <thead><tr><th>{{ t('apiKeys.name') }}</th><th>{{ t('apiKeys.owner') }}</th><th>{{ t('apiKeys.lastUsed') }}</th><th>{{ t('apiKeys.expires') }}</th><th></th></tr></thead>
          <tbody>
            @for (key of keys(); track key.id) {
              <tr><td><strong>{{ key.name }}</strong></td><td>{{ key.owner }}</td><td>{{ key.last_used_at ? (key.last_used_at | dwDate: 'datetime') : t('apiKeys.never') }}</td>
                <td>{{ key.expires_at | dwDate }}</td>
                <td class="dw-num"><button pButton text severity="danger" type="button" (click)="revoke(key)">{{ t('apiKeys.revoke') }}</button></td></tr>
            } @empty {
              <tr><td colspan="5" class="dw-faint">{{ t('apiKeys.none') }}</td></tr>
            }
          </tbody>
        </table>
      </section>
    </ng-container>
  `,
  styles: `
    :host { display: grid; gap: var(--dw-4); }
    .how ol { margin: 8px 0; padding-inline-start: 20px; display: grid; gap: 6px; font-size: 14px; } code { font-family: var(--dw-mono, monospace); font-size: 12.5px; padding: 1px 6px; border-radius: 6px; background: var(--dw-surface-2); }
    .fresh { box-shadow: inset 0 0 0 1.5px var(--dw-success); } .fresh p { display: flex; align-items: center; gap: 8px; margin: 0 0 8px; } .fresh dw-icon { color: var(--dw-success); }
    .token { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; } .token code { flex: 1; min-width: 0; overflow-wrap: anywhere; padding: 8px 10px; }
    .new { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; margin-block-end: var(--dw-3); } .new dw-field { flex: 1 1 260px; }
    table { width: 100%; } th { text-align: start; font-size: 12px; color: var(--dw-ink-3); font-weight: 600; } td, th { padding: 8px 6px; border-block-end: 1px solid var(--dw-line-soft); }
  `,
})
export class ApiKeysPage {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly confirm = inject(Confirm);
  protected readonly keys = signal<ApiKey[]>([]);
  protected readonly created = signal<{ name: string; token: string } | null>(null);
  protected readonly busy = signal(false);
  protected readonly base = `${location.origin}/api/v1`;
  protected name = '';

  constructor() {
    this.load();
  }

  private load(): void {
    this.api.get<ApiKey[]>('company/api-keys').subscribe({ next: (keys) => this.keys.set(keys), error: (error: unknown) => this.toast.error(error) });
  }

  protected async create(): Promise<void> {
    this.busy.set(true);
    try {
      const key = await firstValueFrom(this.api.post<{ name: string; token: string }>('company/api-keys', { name: this.name.trim() }));
      this.created.set(key);
      this.name = '';
      this.load();
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected async copy(token: string): Promise<void> {
    try {
      await navigator.clipboard.writeText(token);
      this.toast.success('apiKeys.copied');
    } catch {
      this.toast.info('apiKeys.copyByHand');
    }
  }

  protected async revoke(key: ApiKey): Promise<void> {
    if (!(await this.confirm.ask({ title: this.toast.text('apiKeys.revokeConfirm', { name: key.name }), message: this.toast.text('apiKeys.revokeHint'), danger: true,
      confirmLabel: this.toast.text('apiKeys.revoke') }))) {
      return;
    }
    this.api.delete(`company/api-keys/${key.id}`).subscribe({ next: () => this.load(), error: (error: unknown) => this.toast.error(error) });
  }
}
