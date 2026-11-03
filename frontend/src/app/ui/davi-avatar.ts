import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/**
 * The virtual assistant's face: a small robot on the platform's teal tile — a mint head with a dark visor, two glowing
 * eyes that blink now and then, an antenna light. `active` makes the eyes look from side to side and the antenna pulse
 * (the assistant at work); `size` in pixels. Purely decorative: whoever uses it names the assistant in text.
 */
@Component({
  selector: 'dw-davi-avatar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[class.active]': 'active()', '[style.--size.px]': 'size()', 'aria-hidden': 'true' },
  template: `
    <svg viewBox="0 0 40 40" role="presentation">
      <defs>
        <linearGradient [attr.id]="'dg' + uid" x1="4" y1="2" x2="36" y2="38" gradientUnits="userSpaceOnUse">
          <stop offset="0" stop-color="#1c8a7c" />
          <stop offset="0.55" stop-color="#0f5c56" />
          <stop offset="1" stop-color="#0b3f3b" />
        </linearGradient>
        <radialGradient [attr.id]="'dh' + uid" cx="12" cy="8" r="20" gradientUnits="userSpaceOnUse">
          <stop offset="0" stop-color="#ffffff" stop-opacity="0.35" />
          <stop offset="1" stop-color="#ffffff" stop-opacity="0" />
        </radialGradient>
        <linearGradient [attr.id]="'ds' + uid" x1="10" y1="11" x2="30" y2="31" gradientUnits="userSpaceOnUse">
          <stop offset="0" stop-color="#ffffff" />
          <stop offset="1" stop-color="#bfeee1" />
        </linearGradient>
        <filter [attr.id]="'dl' + uid" x="-50%" y="-50%" width="200%" height="200%">
          <feGaussianBlur stdDeviation="0.8" result="blur" />
          <feMerge><feMergeNode in="blur" /><feMergeNode in="SourceGraphic" /></feMerge>
        </filter>
      </defs>
      <rect x="2" y="2" width="36" height="36" rx="12" [attr.fill]="'url(#dg' + uid + ')'" />
      <rect x="2" y="2" width="36" height="36" rx="12" [attr.fill]="'url(#dh' + uid + ')'" />
      <!-- shoulders, cut by the tile -->
      <rect x="11.5" y="31" width="17" height="10" rx="4.5" fill="#bfeee1" opacity="0.85" />
      <g class="bot">
        <line x1="20" y1="7.8" x2="20" y2="11.5" stroke="#bfeee1" stroke-width="1.6" stroke-linecap="round" />
        <circle class="bulb" cx="20" cy="6.6" r="2.1" fill="#7ff5d9" [attr.filter]="'url(#dl' + uid + ')'" />
        <rect x="7" y="17.5" width="3.4" height="7.5" rx="1.7" fill="#bfeee1" />
        <rect x="29.6" y="17.5" width="3.4" height="7.5" rx="1.7" fill="#bfeee1" />
        <rect x="9.5" y="11" width="21" height="19.5" rx="6.5" [attr.fill]="'url(#ds' + uid + ')'" />
        <rect x="12.3" y="14.6" width="15.4" height="10" rx="4.6" fill="#0b3f3b" />
        <g class="look">
          <g class="eyes" [attr.filter]="'url(#dl' + uid + ')'">
            <rect x="15" y="17.4" width="3.3" height="4.4" rx="1.65" fill="#7ff5d9" />
            <rect x="21.7" y="17.4" width="3.3" height="4.4" rx="1.65" fill="#7ff5d9" />
          </g>
        </g>
        <path d="M17.6 27 Q20 28.5 22.4 27" fill="none" stroke="#0f5c56" stroke-width="1.3" stroke-linecap="round" />
      </g>
      <rect class="edge" x="2.5" y="2.5" width="35" height="35" rx="11.5" fill="none" stroke="#ffffff" stroke-opacity="0.18" />
    </svg>
  `,
  styles: `
    :host { --size: 32px; position: relative; display: inline-block; flex: none; inline-size: var(--size); block-size: var(--size); border-radius: calc(var(--size) * 0.32);
      filter: drop-shadow(0 2px 6px rgb(15 92 86 / 30%)); overflow: hidden; }
    svg { display: block; inline-size: 100%; block-size: 100%; }
    .bot { transform-origin: 20px 24px; transition: transform 300ms ease; }
    .eyes { transform-origin: 20px 19.6px; animation: davi-blink 5s ease-in-out infinite; }
    .look { transition: transform 300ms ease; }
    :host(:hover) .bot { transform: rotate(-7deg); }
    :host(:hover) .look { transform: translateX(-1px); }
    :host(.active) { animation: davi-glow 1.6s ease-in-out infinite; }
    :host(.active) .look { animation: davi-scan 2.2s ease-in-out infinite; }
    :host(.active) .bulb { animation: davi-pulse 1s ease-in-out infinite; }
    @keyframes davi-blink { 0%, 90%, 100% { transform: scaleY(1); } 94% { transform: scaleY(0.12); } }
    @keyframes davi-scan { 0%, 100% { transform: translateX(-1.6px); } 50% { transform: translateX(1.6px); } }
    @keyframes davi-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }
    @keyframes davi-glow { 0%, 100% { filter: drop-shadow(0 2px 6px rgb(15 92 86 / 30%)); } 50% { filter: drop-shadow(0 0 10px rgb(58 157 143 / 70%)); } }
    @media (prefers-reduced-motion: reduce) { .eyes, :host(.active), :host(.active) .look, :host(.active) .bulb { animation: none; } }
  `,
})
export class DaviAvatarComponent {
  readonly size = input(32);
  readonly active = input(false);
  /** Gradient ids unique to this avatar (several are on the page at once). */
  protected readonly uid = Math.random().toString(36).slice(2, 8);
}
