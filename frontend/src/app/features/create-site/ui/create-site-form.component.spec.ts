import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { SiteDto } from '../../../entities/site';
import { LocaleService } from '../../../shared/i18n/locale.service';
import { CreateSiteApi, CreateSiteInput } from '../api/create-site.api';
import { CreateSiteFormComponent } from './create-site-form.component';

describe('CreateSiteFormComponent', () => {
  it('sends the endpoint fields and normalizes blank optional values', async () => {
    const api = jasmine.createSpyObj<CreateSiteApi>('CreateSiteApi', ['execute']);
    const createdSite: SiteDto = {
      ulid: 'site-ulid',
      code: 'NORTH_1',
      name: 'North plant',
      site_kind: 'production',
      city: null,
      region: null,
      timezone: null,
      capacity_kg: null,
      is_active: true,
    };
    api.execute.and.resolveTo({ data: createdSite });
    await TestBed.configureTestingModule({
      imports: [CreateSiteFormComponent],
      providers: [{ provide: CreateSiteApi, useValue: api }, provideRouter([])],
    }).compileComponents();
    TestBed.inject(LocaleService).set('en');
    const fixture = TestBed.createComponent(CreateSiteFormComponent);
    const component = fixture.componentInstance;
    component.form.controls.code.setValue(' NORTH_1 ');
    component.form.controls.name.setValue(' North plant ');
    component.form.controls.country.setValue('us');
    component.form.controls.city.setValue('   ');
    component.form.controls.capacity_kg.setValue('1500.250');
    const emitted: SiteDto[] = [];
    component.created.subscribe((site) => emitted.push(site));

    await component.submit();

    const expected: CreateSiteInput = {
      code: 'NORTH_1',
      name: 'North plant',
      country: 'US',
      address_line1: null,
      city: null,
      postal_code: null,
      region: null,
      timezone: null,
      regulatory_identifier: null,
      capacity_kg: '1500.250',
    };
    expect(api.execute).toHaveBeenCalledOnceWith(expected);
    expect(emitted).toEqual([createdSite]);
  });
});