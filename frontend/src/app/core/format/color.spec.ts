import { colorLabel, hexToRgb, normalizeHex, rgbToHex } from './color';

describe('colour formats', () => {
  it('normalises HEX values and refuses anything else', () => {
    expect(normalizeHex('#0a6c74')).toBe('#0A6C74');
    expect(normalizeHex(' 0A6C74 ')).toBe('#0A6C74');
    expect(normalizeHex('#abc')).toBe('#AABBCC');
    for (const bad of ['green', '#12', '#12345', '#GGGGGG', '', null, undefined]) {
      expect(normalizeHex(bad)).toBeNull();
    }
  });

  it('converts HEX and RGB both ways', () => {
    expect(hexToRgb('#0F4C81')).toEqual({ r: 15, g: 76, b: 129 });
    expect(rgbToHex({ r: 15, g: 76, b: 129 })).toBe('#0F4C81');
    expect(rgbToHex({ r: '255', g: '0', b: 0 })).toBe('#FF0000');
  });

  it('gives no HEX for an incomplete or impossible RGB', () => {
    expect(rgbToHex({ r: 256, g: 0, b: 0 })).toBeNull();
    expect(rgbToHex({ r: '', g: 0, b: 0 })).toBeNull();
    expect(rgbToHex({ r: 1.5, g: 0, b: 0 })).toBeNull();
    expect(rgbToHex({ r: null, g: null, b: null })).toBeNull();
  });

  it('names a colour with its Pantone reference, once', () => {
    expect(colorLabel('Bleu canard', '19-4052 TCX')).toBe('Bleu canard — Pantone 19-4052 TCX');
    expect(colorLabel('Pantone 300-c', '300-c')).toBe('Pantone 300-c');
    expect(colorLabel('Bleu', null)).toBe('Bleu');
  });
});
