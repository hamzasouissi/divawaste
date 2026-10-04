import { TestBed } from '@angular/core/testing';
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { AuthTokenStore } from '../auth';
import { authInterceptor } from './interceptors';

describe('authInterceptor', () => {
  it('attaches the in-memory bearer token to API requests', () => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(withInterceptors([authInterceptor])), provideHttpClientTesting()],
    });
    TestBed.inject(AuthTokenStore).set('opaque-test-token');
    const http = TestBed.inject(HttpClient);
    const httpTesting = TestBed.inject(HttpTestingController);

    http.get('/api/v1/me').subscribe();

    const request = httpTesting.expectOne('/api/v1/me');
    expect(request.request.headers.get('Authorization')).toBe('Bearer opaque-test-token');
    request.flush({ data: {} });
    httpTesting.verify();
  });
});