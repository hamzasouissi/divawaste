/** The ways a colour is written on screen. The server is the reference; these keep a form coherent while typing. */

export interface Rgb {
  r: number;
  g: number;
  b: number;
}

/** "#0a6c74", "0A6C74", "#abc" → "#0A6C74" / "#AABBCC"; anything else is not a colour. */
export function normalizeHex(value: string | null | undefined): string | null {
  const match = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec((value ?? '').trim());
  if (!match) {
    return null;
  }
  const digits = match[1].toUpperCase();

  return '#' + (digits.length === 3 ? [...digits].map((digit) => digit + digit).join('') : digits);
}

export function hexToRgb(hex: string): Rgb {
  return { r: parseInt(hex.slice(1, 3), 16), g: parseInt(hex.slice(3, 5), 16), b: parseInt(hex.slice(5, 7), 16) };
}

/** Null when a channel is missing or outside 0–255. */
export function rgbToHex(rgb: { r: unknown; g: unknown; b: unknown }): string | null {
  const channels = [rgb.r, rgb.g, rgb.b].map((value) => (value === '' || value === null || value === undefined ? NaN : Number(value)));
  if (channels.some((channel) => !Number.isInteger(channel) || channel < 0 || channel > 255)) {
    return null;
  }

  return '#' + channels.map((channel) => channel.toString(16).padStart(2, '0')).join('').toUpperCase();
}

/**
 * How a colour is named in a list: its name, followed by its Pantone reference unless the name already says it
 * ("Bleu canard — Pantone 19-4052 TCX", but "Pantone 300-c" alone).
 */
export function colorLabel(name: string, pantoneReference: string | null | undefined): string {
  if (!pantoneReference || name.toLowerCase().includes(pantoneReference.toLowerCase())) {
    return name;
  }

  return `${name} — Pantone ${pantoneReference}`;
}
