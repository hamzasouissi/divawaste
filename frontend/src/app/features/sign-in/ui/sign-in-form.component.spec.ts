import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { signal } from '@angular/core';
import { SessionStore, SessionStatus } from '../../../entities/session';
import { ApiError } from '../../../shared/api';
import { AuthTokenStore } from '../../../shared/auth';
import { LocaleService } from '../../../shared/i18n/locale.service';
import { LoginApi, LoginData } from '../api/login.api';
import { SignInFormComponent } from './sign-in-form.component';

describe('SignInFormComponent', () => {
  let fixture: ComponentFixture<SignInFormComponent>;
  let loginApi: jasmine.SpyObj<LoginApi>;
  let session: { status: ReturnType<typeof signal<SessionStatus>>; retry: jasmine.Spy };

  beforeEach(async () => {
    loginApi = jasmine.createSpyObj<LoginApi>('LoginApi', ['execute']);
    session = { status: signal<SessionStatus>('authenticated'), retry: jasmine.createSpy('retry').and.resolveTo() };
    await TestBed.configureTestingModule({
      imports: [SignInFormComponent],
      providers: [
        { provide: LoginApi, useValue: loginApi },
        { provide: SessionStore, useValue: session },
        provideRouter([]),
      ],
    }).compileComponents();
    TestBed.inject(LocaleService).set('en');
    fixture = TestBed.createComponent(SignInFormComponent);
    fixture.componentRef.setInput('returnUrl', '/sites?view=all');
    fixture.detectChanges();
  });

  function enterCredentials(): void {
    fixture.componentInstance.form.controls.email.setValue('user@example.test');
    fixture.componentInstance.form.controls.password.setValue('test-password');
  }

  it('repeats login with a selected company before accepting the bearer token', async () => {
    const selectedCompany = { ulid: '01JCOMPANY', legal_name: 'Textile Works' };
    loginApi.execute.and.returnValues(
      Promise.resolve({ data: { requires_company_selection: true, companies: [selectedCompany] } }),
      Promise.resolve({ data: {
        requires_company_selection: false,
        access_token: 'opaque-test-token',
        token_type: 'Bearer',
        company: selectedCompany,
        user: { ulid: '01JUSER', email: 'user@example.test', first_name: 'A', last_name: 'User' },
      } }),
    );
    enterCredentials();

    await fixture.componentInstance.submit();
    expect(fixture.componentInstance.companies()).toEqual([selectedCompany]);
    expect(loginApi.execute).toHaveBeenCalledOnceWith({ email: 'user@example.test', password: 'test-password' });

    fixture.componentInstance.selectedCompany.set(selectedCompany.ulid);
    const router = TestBed.inject(Router);
    spyOn(router, 'navigateByUrl').and.resolveTo(true);
    await fixture.componentInstance.submit();

    expect(loginApi.execute).toHaveBeenCalledWith({ email: 'user@example.test', password: 'test-password', company: selectedCompany.ulid });
    expect(TestBed.inject(AuthTokenStore).token()).toBe('opaque-test-token');
    expect(session.retry).toHaveBeenCalledTimes(1);
    expect(router.navigateByUrl).toHaveBeenCalledWith('/sites?view=all');
  });

  it('does not accept a token or navigate unless session bootstrap succeeds', async () => {
    const login: LoginData = {
      requires_company_selection: false,
      access_token: 'opaque-test-token',
      token_type: 'Bearer',
      company: null,
      user: { ulid: '01JUSER', email: 'user@example.test', first_name: 'A', last_name: 'User' },
    };
    loginApi.execute.and.resolveTo({ data: login });
    session.status.set('unauthenticated');
    enterCredentials();
    const router = TestBed.inject(Router);
    spyOn(router, 'navigateByUrl').and.resolveTo(true);

    await fixture.componentInstance.submit();

    expect(TestBed.inject(AuthTokenStore).token()).toBeNull();
    expect(fixture.componentInstance.errorKey()).toBe('loginSessionFailed');
    expect(router.navigateByUrl).not.toHaveBeenCalled();
  });

  it('shows a safe credential error for a backend 401', async () => {
    loginApi.execute.and.rejectWith(new ApiError('unauthenticated', 401, {}, null));
    enterCredentials();

    await fixture.componentInstance.submit();

    expect(fixture.componentInstance.errorKey()).toBe('invalidCredentials');
    expect(TestBed.inject(AuthTokenStore).token()).toBeNull();
    expect(session.retry).not.toHaveBeenCalled();
  });
});