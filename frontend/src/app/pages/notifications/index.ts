import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { NotificationDto, NotificationsApi } from '../../entities/notification';
import { MarkNotificationRead } from '../../features/mark-notification-read';
import { ApiError } from '../../shared/api';
import { LocaleService } from '../../shared/i18n/locale.service';

@Component({
	template: `
		<section class="page" aria-labelledby="notifications-title">
			<header class="page-header">
				<div>
					<h1 id="notifications-title">{{ locale.t('notifications') }}</h1>
					<p>{{ locale.t('notificationsDescription') }}</p>
				</div>
				<button class="button secondary" type="button" (click)="load(currentCursor())" [disabled]="loading()">
					{{ locale.t('refresh') }}
				</button>
			</header>

			@if (loading()) {
				<p class="state" role="status">{{ locale.t('loading') }}</p>
			} @else if (loadError()) {
				<div class="state error" role="alert">
					<p>{{ loadError() }}</p>
					<button class="button" type="button" (click)="load(currentCursor())">{{ locale.t('retry') }}</button>
				</div>
			} @else if (notifications().length === 0) {
				<p class="state">{{ locale.t('noNotifications') }}</p>
			} @else {
				<ul class="notification-list" aria-label="{{ locale.t('notifications') }}">
					@for (notification of notifications(); track notification.id) {
						<li>
							<article class="notification" [class.unread]="!notification.read_at">
								<div class="notification-content">
									<div class="notification-heading">
										<h2>{{ notificationLabel(notification.type) }}</h2>
										<span class="read-state">{{ locale.t(notification.read_at ? 'read' : 'unread') }}</span>
									</div>
									<time [attr.datetime]="notification.created_at">{{ formatTimestamp(notification.created_at) }}</time>
									@if (actionErrorId() === notification.id) {
										<p class="action-error" role="alert">{{ locale.t('notificationReadError') }}</p>
									}
								</div>
								@if (!notification.read_at) {
									<button
										class="button secondary"
										type="button"
										[disabled]="readingId() !== null"
										(click)="markRead(notification)"
									>
										{{ locale.t(readingId() === notification.id ? 'saving' : 'markRead') }}
									</button>
								}
							</article>
						</li>
					}
				</ul>

				<nav class="pagination" [attr.aria-label]="locale.t('notificationPages')">
					<button
						class="button secondary"
						type="button"
						[disabled]="loading() || !previousCursor()"
						(click)="load(previousCursor())"
					>{{ locale.t('previous') }}</button>
					<button
						class="button secondary"
						type="button"
						[disabled]="loading() || !nextCursor()"
						(click)="load(nextCursor())"
					>{{ locale.t('next') }}</button>
				</nav>
			}
		</section>
	`,
	styles: `
		.notification-list { display: grid; gap: .65rem; margin: 0; padding: 0; list-style: none; }
		.notification { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.1rem; border: 1px solid var(--border); border-radius: .45rem; background: var(--surface); }
		.notification.unread { border-inline-start: 3px solid var(--primary); }
		.notification-content { min-width: 0; }
		.notification-heading { display: flex; align-items: center; flex-wrap: wrap; gap: .65rem; }
		h2 { margin: 0; font-size: 1rem; font-weight: 650; overflow-wrap: anywhere; }
		time { display: block; margin-top: .35rem; color: var(--text-muted); font-size: .875rem; }
		.read-state { color: var(--text-muted); font-size: .8rem; }
		.action-error { margin: .5rem 0 0; color: var(--danger); }
		.pagination { display: flex; justify-content: space-between; gap: .75rem; margin-top: 1rem; }
		@media (max-width: 560px) { .notification { align-items: flex-start; flex-direction: column; } }
	`,
	changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NotificationsPage implements OnInit {
	private readonly api = inject(NotificationsApi);
	private readonly markNotificationRead = inject(MarkNotificationRead);
	readonly locale = inject(LocaleService);
	readonly notifications = signal<NotificationDto[]>([]);
	readonly loading = signal(true);
	readonly loadError = signal<string | null>(null);
	readonly actionErrorId = signal<string | null>(null);
	readonly readingId = signal<string | null>(null);
	readonly currentCursor = signal<string | null>(null);
	readonly previousCursor = signal<string | null>(null);
	readonly nextCursor = signal<string | null>(null);

	ngOnInit(): void {
		void this.load();
	}

	async load(cursor: string | null = null): Promise<void> {
		this.loading.set(true);
		this.loadError.set(null);
		try {
			const response = await this.api.list(cursor);
			this.notifications.set(response.data);
			this.currentCursor.set(cursor);
			this.previousCursor.set(response.meta.prev_cursor);
			this.nextCursor.set(response.meta.next_cursor);
		} catch (error) {
			this.loadError.set(error instanceof ApiError ? this.locale.t('notificationLoadError') : this.locale.t('unexpectedError'));
		} finally {
			this.loading.set(false);
		}
	}

	async markRead(notification: NotificationDto): Promise<void> {
		if (notification.read_at || this.readingId()) return;
		this.readingId.set(notification.id);
		this.actionErrorId.set(null);
		try {
			const response = await this.markNotificationRead.execute(notification.id);
			this.notifications.update((items) => items.map((item) => item.id === notification.id ? response.data : item));
		} catch {
			this.actionErrorId.set(notification.id);
		} finally {
			this.readingId.set(null);
		}
	}

	formatTimestamp(value: string): string {
		const date = new Date(value);
		if (Number.isNaN(date.getTime())) return value;
		return new Intl.DateTimeFormat(this.locale.locale(), { dateStyle: 'medium', timeStyle: 'short' }).format(date);
	}

	notificationLabel(type: string): string {
		const key = `notificationType.${type}`;
		const translated = this.locale.t(key);
		return translated === key ? type : translated;
	}
}
