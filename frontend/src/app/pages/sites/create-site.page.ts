import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { SiteDto } from '../../entities/site';
import { CreateSiteFormComponent } from '../../features/create-site';

@Component({
  imports: [CreateSiteFormComponent],
  template: `<app-create-site-form (created)="onCreated($event)" />`,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CreateSitePage {
  private readonly router = inject(Router);

  onCreated(_site: SiteDto): void {
    void this.router.navigate(['/sites']);
  }
}