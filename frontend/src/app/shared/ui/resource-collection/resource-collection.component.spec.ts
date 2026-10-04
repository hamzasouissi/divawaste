import { ComponentFixture, TestBed } from '@angular/core/testing';
import { PaginatedEnvelope } from '../../api';
import { LocaleService } from '../../i18n/locale.service';
import { ResourceCollectionComponent } from './resource-collection.component';

interface Row extends Record<string, unknown> {
  ulid: string;
  name: string;
}

describe('ResourceCollectionComponent', () => {
  let fixture: ComponentFixture<ResourceCollectionComponent<Row>>;
  let load: jasmine.Spy<(page: number) => Promise<PaginatedEnvelope<Row>>>;

  const response = (currentPage: number, lastPage: number): PaginatedEnvelope<Row> => ({
    data: [{ ulid: `site-${currentPage}`, name: `Site ${currentPage}` }],
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: currentPage, last_page: lastPage, per_page: 25, total: lastPage },
  });

  beforeEach(async () => {
    load = jasmine.createSpy('load');
    await TestBed.configureTestingModule({ imports: [ResourceCollectionComponent] }).compileComponents();
    fixture = TestBed.createComponent(ResourceCollectionComponent<Row>);
    fixture.componentRef.setInput('title', 'Sites');
    fixture.componentRef.setInput('description', 'Visible sites');
    fixture.componentRef.setInput('columns', [{ key: 'name', label: 'Name' }]);
    fixture.componentRef.setInput('load', load);
    TestBed.inject(LocaleService).set('en');
  });

  it('requests the next page and disables controls at server-reported boundaries', async () => {
    load.and.returnValues(Promise.resolve(response(1, 2)), Promise.resolve(response(2, 2)));
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    let buttons = Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('button'));
    expect(buttons.find((button) => button.textContent?.includes('Previous'))?.disabled).toBeTrue();
    buttons.find((button) => button.textContent?.includes('Next'))?.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(load).toHaveBeenCalledWith(2);
    buttons = Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('button'));
    expect(buttons.find((button) => button.textContent?.includes('Next'))?.disabled).toBeTrue();
  });
});