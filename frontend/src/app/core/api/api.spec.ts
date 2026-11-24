import { HttpErrorResponse, HttpHeaders, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideZonelessChangeDetection } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { Api, ApiError, toParams } from './api';

describe('toParams', () => {
  it('flattens filters the way the API reads them', () => {
    const params = toParams({ filter: { status: ['stored', 'created'], site: 'S1', hazardous: true, search: '' }, sort: '-generated_at', page: 2, per_page: 25 });

    expect(params.getAll('filter[status][]')).toEqual(['stored', 'created']);
    expect(params.get('filter[site]')).toBe('S1');
    expect(params.get('filter[hazardous]')).toBe('1');
    expect(params.has('filter[search]')).toBe(false);
    expect(params.get('sort')).toBe('-generated_at');
    expect(params.get('page')).toBe('2');
  });

  it('drops null and undefined values', () => {
    const params = toParams({ site: null, from: undefined, to: '2026-10-04' });

    expect(params.keys()).toEqual(['to']);
  });

  it('sends false explicitly: "not hazardous" is a filter, not an absence', () => {
    expect(toParams({ hazardous: false }).get('hazardous')).toBe('0');
  });
});

describe('ApiError', () => {
  it('reads the problem+json body', () => {
    const error = ApiError.from(new HttpErrorResponse({
      status: 409,
      error: { code: 'PROVIDER_NOT_ELIGIBLE', detail: 'No valid accreditation.', request_id: '01ABC' },
    }));

    expect(error.code).toBe('PROVIDER_NOT_ELIGIBLE');
    expect(error.detail).toBe('No valid accreditation.');
    expect(error.requestId).toBe('01ABC');
    expect(error.isValidation).toBe(false);
  });

  it('keeps the field messages of a validation failure', () => {
    const error = ApiError.from(new HttpErrorResponse({ status: 422, error: { code: 'VALIDATION_FAILED', errors: { email: ['Adresse invalide.'] } } }));

    expect(error.isValidation).toBe(true);
    expect(error.errors['email']).toEqual(['Adresse invalide.']);
  });

  it('names a network failure and falls back to the request id header', () => {
    expect(ApiError.from(new HttpErrorResponse({ status: 0 })).code).toBe('NETWORK');

    const error = ApiError.from(new HttpErrorResponse({ status: 500, headers: new HttpHeaders({ 'X-Request-Id': 'REQ-9' }) }));
    expect(error.code).toBe('SERVER_ERROR');
    expect(error.requestId).toBe('REQ-9');
  });
});

describe('Api.all', () => {
  it('reads every page of a list, 200 rows at a time', async () => {
    TestBed.configureTestingModule({ providers: [provideZonelessChangeDetection(), provideHttpClient(), provideHttpClientTesting()] });
    const http = TestBed.inject(HttpTestingController);
    const rows = firstValueFrom(TestBed.inject(Api).all<number>('production/materials', { with_inactive: true }));
    const page = (number: number, data: number[]) => http.expectOne((request) => request.url === '/api/v1/production/materials' && request.params.get('page') === String(number)
      && request.params.get('per_page') === '200' && request.params.get('with_inactive') === '1').flush({ data, meta: { current_page: number, last_page: 3, per_page: 200, total: 5 } });

    page(1, [1, 2]);
    page(2, [3, 4]);
    page(3, [5]);

    expect(await rows).toEqual([1, 2, 3, 4, 5]);
    http.verify();
  });
});
