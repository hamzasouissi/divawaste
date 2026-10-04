import { EnvironmentProviders, Provider, inject, provideAppInitializer } from '@angular/core';
import { provideAnimationsAsync } from '@angular/platform-browser/animations/async';
import { TranslocoService } from '@jsverse/transloco';
import { definePreset } from '@primeuix/themes';
import Aura from '@primeuix/themes/aura';
import { ConfirmationService, MessageService } from 'primeng/api';
import { AutoFocus } from 'primeng/autofocus';
import { PrimeNG, providePrimeNG } from 'primeng/config';
import { DialogService } from 'primeng/dynamicdialog';

/**
 * DivaWaste theme for PrimeNG. PrimeNG supplies the behaviour of the components; everything visual comes from
 * here and from `styles.scss`: one teal brand scale, cool neutral surfaces, 32 px controls, 6 px radius,
 * hairline borders instead of shadows. Colours that also exist as `--dw-*` tokens reference those tokens so
 * the product has a single palette.
 */
const DivaWastePreset = definePreset(Aura, {
  primitive: {
    borderRadius: { none: '0', xs: '2px', sm: '4px', md: '6px', lg: '8px', xl: '10px' },
  },
  semantic: {
    transitionDuration: '0.12s',
    focusRing: { width: '2px', style: 'solid', color: 'var(--dw-focus)', offset: '1px', shadow: 'none' },
    disabledOpacity: '0.55',
    iconSize: '14px',
    primary: {
      50: '#eef7f5', 100: '#d7ece8', 200: '#b0d9d1', 300: '#7fbfb4', 400: '#4a9f93', 500: '#2a8379',
      600: '#176b64', 700: '#0f5c56', 800: '#0c4a45', 900: '#0a3c38', 950: '#052321',
    },
    formField: {
      paddingX: '10px',
      paddingY: '6px',
      sm: { fontSize: '12px', paddingX: '8px', paddingY: '4px' },
      lg: { fontSize: '15px', paddingX: '14px', paddingY: '10px' },
      borderRadius: '{border.radius.md}',
      focusRing: { width: '0', style: 'none', color: 'transparent', offset: '0', shadow: '0 0 0 3px var(--dw-focus-soft)' },
    },
    list: {
      padding: '4px',
      gap: '1px',
      header: { padding: '6px 8px 4px' },
      option: { padding: '6px 8px', borderRadius: '{border.radius.sm}' },
      optionGroup: { padding: '6px 8px', fontWeight: '600' },
    },
    navigation: {
      list: { padding: '4px', gap: '1px' },
      item: { padding: '6px 8px', borderRadius: '{border.radius.sm}', gap: '8px' },
      submenuLabel: { padding: '6px 8px', fontWeight: '600' },
    },
    overlay: {
      select: { borderRadius: '{border.radius.lg}', shadow: 'var(--dw-shadow-pop)' },
      popover: { borderRadius: '{border.radius.lg}', padding: '12px', shadow: 'var(--dw-shadow-pop)' },
      modal: { borderRadius: '{border.radius.xl}', padding: '20px', shadow: 'var(--dw-shadow-modal)' },
      navigation: { shadow: 'var(--dw-shadow-pop)' },
    },
    colorScheme: {
      light: {
        surface: {
          0: '#ffffff', 50: '#f8fafb', 100: '#f1f4f6', 200: '#e4e9ed', 300: '#d0d7dd', 400: '#9ba7b2', 500: '#6c7985',
          600: '#4b5864', 700: '#36424d', 800: '#232d37', 900: '#17212b', 950: '#0d141b',
        },
        primary: { color: '{primary.700}', contrastColor: '#ffffff', hoverColor: '{primary.800}', activeColor: '{primary.900}' },
        highlight: { background: '{primary.50}', focusBackground: '{primary.100}', color: '{primary.800}', focusColor: '{primary.900}' },
        mask: { background: 'rgb(13 20 27 / 38%)', color: '{surface.200}' },
        formField: {
          background: '{surface.0}',
          disabledBackground: '{surface.100}',
          borderColor: '{surface.300}',
          hoverBorderColor: '{surface.400}',
          focusBorderColor: '{primary.600}',
          invalidBorderColor: 'var(--dw-danger)',
          color: '{surface.900}',
          disabledColor: '{surface.500}',
          placeholderColor: '{surface.400}',
          invalidPlaceholderColor: '{surface.400}',
          iconColor: '{surface.500}',
          shadow: 'none',
        },
        text: { color: '{surface.900}', hoverColor: '{surface.950}', mutedColor: '{surface.500}', hoverMutedColor: '{surface.600}' },
        content: { background: '{surface.0}', hoverBackground: '{surface.100}', borderColor: '{surface.200}', color: '{text.color}', hoverColor: '{text.hover.color}' },
      },
    },
  },
  components: {
    button: {
      root: {
        gap: '6px',
        paddingX: '12px',
        iconOnlyWidth: '32px',
        sm: { fontSize: '12px', paddingX: '9px', paddingY: '4px', iconOnlyWidth: '28px' },
        lg: { fontSize: '15px', paddingX: '18px', paddingY: '10px', iconOnlyWidth: '44px' },
        label: { fontWeight: '550' },
      },
      colorScheme: {
        light: {
          root: {
            secondary: {
              background: '{surface.0}', hoverBackground: '{surface.50}', activeBackground: '{surface.100}',
              borderColor: '{surface.300}', hoverBorderColor: '{surface.400}', activeBorderColor: '{surface.400}',
              color: '{surface.800}', hoverColor: '{surface.900}', activeColor: '{surface.900}',
            },
            danger: {
              background: 'var(--dw-danger)', hoverBackground: '#9a1f18', activeBackground: '#851a14',
              borderColor: 'var(--dw-danger)', hoverBorderColor: '#9a1f18', activeBorderColor: '#851a14',
            },
          },
          text: {
            secondary: { hoverBackground: '{surface.100}', activeBackground: '{surface.200}', color: '{surface.600}' },
          },
        },
      },
    },
    datatable: {
      headerCell: { padding: '7px 12px', gap: '4px', background: '{surface.50}', color: '{surface.600}' },
      columnTitle: { fontWeight: '600' },
      bodyCell: { padding: '7px 12px', borderColor: '{surface.100}' },
      row: { hoverBackground: '{surface.50}' },
      colorScheme: { light: { root: { borderColor: '{surface.200}' } } },
    },
    paginator: {
      root: { padding: '0', gap: '2px', background: 'transparent' },
      navButton: { width: '28px', height: '28px', borderRadius: '{border.radius.md}' },
    },
    dialog: {
      header: { padding: '14px 20px', gap: '8px' },
      title: { fontSize: '15px', fontWeight: '600' },
      content: { padding: '0 20px 20px' },
      footer: { padding: '12px 20px', gap: '8px' },
    },
    tabs: {
      tab: { padding: '10px 14px', fontWeight: '550' },
      tabpanel: { padding: '0' },
      activeBar: { height: '2px', bottom: '-1px' },
    },
    tag: {
      root: { fontSize: '11.5px', fontWeight: '600', padding: '2px 8px', borderRadius: '999px', gap: '5px' },
    },
    tooltip: {
      root: { padding: '5px 8px', maxWidth: '260px', borderRadius: '{border.radius.sm}' },
      colorScheme: { light: { root: { background: '{surface.900}', color: '{surface.0}' } } },
    },
    checkbox: {
      root: { width: '16px', height: '16px', borderRadius: '4px' },
      icon: { size: '10px' },
    },
    toggleswitch: {
      root: { width: '32px', height: '18px' },
      handle: { size: '14px' },
    },
    select: { dropdown: { width: '30px' } },
    multiselect: { dropdown: { width: '30px' } },
    datepicker: { dropdown: { width: '32px' } },
    toast: { root: { width: '380px' }, summary: { fontSize: '13px', fontWeight: '600' }, detail: { fontSize: '13px', fontWeight: '400' }, content: { padding: '10px 12px', gap: '10px' } },
    message: { content: { padding: '8px 12px', gap: '8px' }, text: { fontSize: '13px', fontWeight: '500' } },
    progressbar: { root: { height: '3px', borderRadius: '0' } },
    drawer: { header: { padding: '14px 20px' }, title: { fontSize: '15px', fontWeight: '600' }, content: { padding: '0 20px 20px' } },
    popover: { content: { padding: '0' } },
    menu: { root: { borderRadius: '{border.radius.lg}' } },
  },
});

/** Texts of the PrimeNG components (calendar, table, selectors) come from the translation files like every other label. */
function syncComponentTexts(): void {
  const transloco = inject(TranslocoService);
  const primeng = inject(PrimeNG);
  transloco.selectTranslateObject<Record<string, unknown>>('primeng').subscribe((texts) => {
    if (texts && typeof texts === 'object') {
      primeng.setTranslation(texts);
    }
  });
}

/**
 * PrimeNG 20.4 writes the `autofocus` attribute on every selector whose `autofocus` input is left unset
 * (it only removes it for an explicit `false`). The browser then focuses the first selector of a page, and a
 * dialog focuses a selector instead of the field marked `autofocus`. An unset input means "no".
 */
function fixAutoFocus(): void {
  const proto = AutoFocus.prototype as unknown as { autofocus: boolean | undefined; onAfterContentChecked(): void; dwPatched?: boolean };
  if (proto.dwPatched) {
    return;
  }
  const original = proto.onAfterContentChecked;
  proto.onAfterContentChecked = function (this: typeof proto): void {
    this.autofocus ||= false;
    original.call(this);
  };
  proto.dwPatched = true;
}

export function provideDivaWasteUi(): (Provider | EnvironmentProviders)[] {
  fixAutoFocus();

  return [
    provideAnimationsAsync(),
    providePrimeNG({
      theme: {
        preset: DivaWastePreset,
        // The cascade layer lets the product stylesheet refine any component without specificity fights.
        options: { darkModeSelector: '.dw-dark', cssLayer: { name: 'primeng', order: 'primeng' } },
      },
      ripple: false,
      inputVariant: 'outlined',
      overlayAppendTo: 'body',
    }),
    MessageService,
    ConfirmationService,
    DialogService,
    provideAppInitializer(syncComponentTexts),
  ];
}
