import { provideHttpClient, withInterceptors, withXsrfConfiguration } from '@angular/common/http';
import { ApplicationConfig, isDevMode, provideBrowserGlobalErrorListeners, provideZonelessChangeDetection } from '@angular/core';
import { provideRouter, withComponentInputBinding, withInMemoryScrolling } from '@angular/router';
import { provideServiceWorker } from '@angular/service-worker';
import { provideTransloco } from '@jsverse/transloco';

import { routes } from './app.routes';
import { apiErrorInterceptor, apiRequestInterceptor } from './core/auth/interceptors';
import { AVAILABLE_LANGUAGES, TranslationLoader } from './core/i18n/i18n';
import { provideDivaWasteUi } from './ui/theme';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideZonelessChangeDetection(),
    provideRouter(routes, withComponentInputBinding(), withInMemoryScrolling({ scrollPositionRestoration: 'enabled' })),
    provideHttpClient(
      withInterceptors([apiRequestInterceptor, apiErrorInterceptor]),
      // Laravel sets XSRF-TOKEN; Angular echoes it on every state-changing same-origin request.
      withXsrfConfiguration({ cookieName: 'XSRF-TOKEN', headerName: 'X-XSRF-TOKEN' }),
    ),
    provideTransloco({
      config: { availableLangs: AVAILABLE_LANGUAGES, defaultLang: 'fr', fallbackLang: 'fr', reRenderOnLangChange: true, prodMode: !isDevMode(), missingHandler: { logMissingKey: isDevMode() } },
      loader: TranslationLoader,
    }),
    // PrimeNG with the DivaWaste theme, plus the services behind toasts, confirmations and dialogs.
    ...provideDivaWasteUi(),
    // The service worker makes the workshop application installable and available offline (production builds).
    provideServiceWorker('ngsw-worker.js', { enabled: !isDevMode(), registrationStrategy: 'registerWhenStable:30000' }),
  ],
};
