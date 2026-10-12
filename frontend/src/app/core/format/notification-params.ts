import { Pipe, PipeTransform, inject } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';

type Params = Record<string, string | number | boolean | null | undefined>;

/**
 * The parameters of a notification as a reader of this language gets them. Figures that are written differently from
 * one language to the next ("17 m³ sur 20 m³" / "17 m³ of 20 m³") come once per language: `detail` holds the French
 * text and `detail_en` the English one, and the one of the reader replaces the default.
 *
 * Stock-limit notifications sent before limits had a unit only carry kilograms: they are still read as such.
 */
export function notificationParams(params: Params | null | undefined, language: string): Params {
  const result: Params = { ...(params ?? {}) };
  const suffix = `_${language}`;
  for (const [key, value] of Object.entries(result)) {
    if (key.endsWith(suffix) && key.slice(0, -suffix.length) in result) {
      result[key.slice(0, -suffix.length)] = value;
    }
  }
  for (const [recent, former] of [['quantity', 'quantity_kg'], ['max_quantity', 'max_quantity_kg']] as const) {
    if (result[recent] === undefined && result[former] !== undefined && result[former] !== null) {
      result[recent] = `${Number(result[former])} kg`;
    }
  }

  return result;
}

@Pipe({ name: 'dwNotificationParams', pure: false })
export class NotificationParamsPipe implements PipeTransform {
  private readonly transloco = inject(TranslocoService);

  transform(params: Params | null | undefined): Params {
    return notificationParams(params, this.transloco.getActiveLang());
  }
}
