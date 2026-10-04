import { Injectable, inject } from '@angular/core';
import { ApiClient, ApiEnvelope } from '../../shared/api';
import { NotificationDto } from '../../entities/notification';

@Injectable({ providedIn: 'root' })
export class MarkNotificationRead {
  private readonly api = inject(ApiClient);

  execute(id: string): Promise<ApiEnvelope<NotificationDto>> {
    return this.api.post<ApiEnvelope<NotificationDto>>(`/me/notifications/${id}/read`);
  }
}