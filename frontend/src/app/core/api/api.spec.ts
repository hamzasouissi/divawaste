import { HttpErrorResponse, HttpHeaders } from '@angular/common/http';

import { ApiError, toParams } from './api';

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
