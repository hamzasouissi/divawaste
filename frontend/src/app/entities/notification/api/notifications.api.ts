import { Injectable, inject } from '@angular/core';
import { ApiClient, ApiEnvelope } from '../../../shared/api';

export interface NotificationDto extends Record<string, unknown> {
	id: string;
	type: string;
	data: Record<string, unknown>;
	read_at: string | null;
	created_at: string;
}

export interface CursorPaginationMeta {
	path: string;
	per_page: number;
	next_cursor: string | null;
	prev_cursor: string | null;
}

export interface CursorPaginatedEnvelope<T> {
	data: T[];
	links: { first: string | null; last: string | null; prev: string | null; next: string | null };
	meta: CursorPaginationMeta;
}

@Injectable({ providedIn: 'root' })
export class NotificationsApi {
	private readonly api = inject(ApiClient);

	list(cursor: string | null = null): Promise<CursorPaginatedEnvelope<NotificationDto>> {
		return this.api.get<CursorPaginatedEnvelope<NotificationDto>>('/me/notifications', {
			cursor: cursor ?? undefined,
		});
	}
}
