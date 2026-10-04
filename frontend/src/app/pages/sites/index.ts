import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { SitesApi, SiteDto } from '../../entities/site';
import { LocaleService } from '../../shared/i18n/locale.service';
import { ResourceCollectionComponent, TableColumn } from '../../shared/ui/resource-collection';

@Component({
	imports: [ResourceCollectionComponent, RouterLink],
	template: `
		<app-resource-collection title="Sites" description="Sites visible in the active tenant." [columns]="columns" [load]="load">
			<a collection-actions class="button" routerLink="/sites/new">{{ locale.t('createSite') }}</a>
		</app-resource-collection>
	`,
	changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SitesPage {
	private readonly api = inject(SitesApi);
	readonly locale = inject(LocaleService);
	readonly load = (page: number) => this.api.list(page);
	readonly columns: TableColumn<SiteDto>[] = [
		{ key: 'code', label: 'Code' },
		{ key: 'name', label: 'Name' },
		{ key: 'site_kind', label: 'Type' },
		{ key: 'city', label: 'City' },
		{ key: 'is_active', label: 'Status', format: (value) => value ? 'Active' : 'Inactive' },
	];
}
