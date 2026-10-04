import { TestBed } from '@angular/core/testing';
import { ApiClient, ApiError } from '../../../shared/api';
import { SessionStore } from './session.store';

describe('SessionStore', () => {
  it('retries bootstrap after unauthenticated state', async () => {
    const api = jasmine.createSpyObj<ApiClient>('ApiClient', ['get']);
    api.get.and.returnValues(
      Promise.reject(new ApiError('unauthenticated', 401, {}, null)),
      Promise.resolve({ data: { ulid: 'user-1' } }),
      Promise.resolve({ data: { ulid: 'company-1' } }),
    );
    TestBed.configureTestingModule({ providers: [{ provide: ApiClient, useValue: api }] });
    const session = TestBed.inject(SessionStore);

    await session.bootstrap();
    expect(session.status()).toBe('unauthenticated');

    await session.retry();
    expect(session.status()).toBe('authenticated');
    expect(api.get).toHaveBeenCalledTimes(3);
  });
});