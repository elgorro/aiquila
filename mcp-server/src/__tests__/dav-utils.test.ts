// SPDX-License-Identifier: MIT

import { describe, it, expect } from 'vitest';

import {
  escapeVCardValue,
  unescapeVCardValue,
  escapeICalValue,
  unescapeICalValue,
} from '../tools/dav-utils.js';

describe('escapeDavValue', () => {
  it('escapes the structural characters', () => {
    expect(escapeVCardValue('a;b,c\\d')).toBe('a\\;b\\,c\\\\d');
  });

  it('escapes carriage returns, which would otherwise split the content line', () => {
    expect(escapeVCardValue('line1\r\nline2')).toBe('line1\\nline2');
    expect(escapeVCardValue('line1\rline2')).toBe('line1\\nline2');
    expect(escapeVCardValue('line1\nline2')).toBe('line1\\nline2');
  });

  it('never emits a raw CR or LF', () => {
    expect(escapeVCardValue('a\r\nb\rc\nd')).not.toMatch(/[\r\n]/);
  });
});

describe('unescapeDavValue', () => {
  it('reverses escaping', () => {
    expect(unescapeVCardValue('a\\;b\\,c\\\\d')).toBe('a;b,c\\d');
    expect(unescapeVCardValue('line1\\nline2')).toBe('line1\nline2');
  });

  it('does not treat an escaped backslash followed by n as a newline', () => {
    // `\\n` is a literal backslash then the letter n — the chained-replace
    // implementation used to turn this into a newline.
    expect(unescapeVCardValue('C:\\\\new')).toBe('C:\\new');
  });

  it('leaves unknown escape sequences alone', () => {
    expect(unescapeVCardValue('a\\qb')).toBe('a\\qb');
  });

  it('treats \\N as a newline for vCard but not for iCalendar', () => {
    expect(unescapeVCardValue('a\\Nb')).toBe('a\nb');
    expect(unescapeICalValue('a\\Nb')).toBe('a\\Nb');
    expect(unescapeICalValue('a\\nb')).toBe('a\nb');
  });

  it('round-trips arbitrary values', () => {
    for (const value of ['plain', 'a;b', 'a,b', 'a\\b', 'a\nb', 'semi; comma, back\\slash']) {
      expect(unescapeICalValue(escapeICalValue(value))).toBe(value);
    }
  });
});
