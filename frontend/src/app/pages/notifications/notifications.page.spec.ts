import { ComponentFixture, TestBed } from '@angular/core/testing';
import { NotificationDto, NotificationsApi, CursorPaginatedEnvelope } from '../../entities/notification';
import { MarkNotificationRead } from '../../features/mark-notification-read';
import { LocaleService } from '../../shared/i18n/locale.service';
import { NotificationsPage } from './index';

describe('NotificationsPage', () => {
  let fixture: ComponentFixture<NotificationsPage>;
  let api: jasmine.SpyObj<NotificationsApi>;
  let markRead: jasmine.SpyObj<MarkNotificationRead>;

  const notification: NotificationDto = {
    id: 'notification-1',
    type: 'PickupUpdated',
    data: {},
    read_at: null,
    created_at: '2026-10-02T10:00:00Z',
  };

  const page = (items: NotificationDto[], previous: string | null, next: string | null): CursorPaginatedEnvelope<NotificationDto> => ({
    data: items,
    links: { first: null, last: null, prev: null, next: null },
    meta: { path: '/api/v1/me/notifications', per_page: 20, prev_cursor: previous, next_cursor: next },
  });

  beforeEach(async () => {
    api = jasmine.createSpyObj<NotificationsApi>('NotificationsApi', ['list']);
    markRead = jasmine.createSpyObj<MarkNotificationRead>('MarkNotificationRead', ['execute']);
    await TestBed.configureTestingModule({
      imports: [NotificationsPage],
      providers: [
        { provide: NotificationsApi, useValue: api },
        { provide: MarkNotificationRead, useValue: markRead },
      ],
    }).compileComponents();
    TestBed.inject(LocaleService).set('en');
  });

  it('follows next and previous cursors returned by the backend', async () => {
    api.list.and.returnValues(
      Promise.resolve(page([notification], null, 'next-cursor')),
      Promise.resolve(page([notification], 'previous-cursor', null)),
      Promise.resolve(page([notification], null, 'next-cursor')),
    );
    fixture = TestBed.createComponent(NotificationsPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    let element = fixture.nativeElement as HTMLElement;
    let next = Array.from(element.querySelectorAll('button')).find((button) => button.textContent?.includes('Next'));
    expect(next).toBeDefined();
    next?.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(api.list).toHaveBeenCalledWith('next-cursor');
    expect(fixture.componentInstance.previousCursor()).toBe('previous-cursor');

    element = fixture.nativeElement as HTMLElement;
    const previous = Array.from(element.querySelectorAll('button')).find((button) => button.textContent?.includes('Previous'));
    expect(previous).toBeDefined();
    previous?.click();
    await fixture.whenStable();

    expect(api.list).toHaveBeenCalledWith('previous-cursor');
  });

  it('replaces the unread item with the backend response after marking it read', async () => {
    api.list.and.resolveTo(page([notification], null, null));
    markRead.execute.and.resolveTo({ data: { ...notification, read_at: '2026-10-02T10:05:00Z' } });
    fixture = TestBed.createComponent(NotificationsPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    const action = Array.from(element.querySelectorAll('button')).find((button) => button.textContent?.includes('Mark as read'));
    expect(action).toBeDefined();
    action?.click();
    await fixture.whenStable();

    expect(markRead.execute).toHaveBeenCalledWith(notification.id);
    expect(fixture.componentInstance.notifications()[0].read_at).toBe('2026-10-02T10:05:00Z');
  });
});