// SPDX-License-Identifier: MIT

import { describe, it, expect } from 'vitest';

import {
  getParamValues,
  getProperty,
  parseVCard,
  property,
  replaceAll,
  serializeVCard,
  setProperty,
} from '../tools/vcard.js';

const card = (...lines: string[]) => lines.join('\r\n') + '\r\n';

describe('parseVCard', () => {
  it('parses name, group, params and value', () => {
    const doc = parseVCard(
      card('BEGIN:VCARD', 'VERSION:4.0', 'item1.TEL;TYPE=voice:+41 79 111 22 33', 'END:VCARD')
    );

    const tel = getProperty(doc, 'TEL')!;
    expect(tel.group).toBe('item1');
    expect(tel.name).toBe('TEL');
    expect(tel.params).toEqual([['TYPE', 'voice']]);
    expect(tel.value).toBe('+41 79 111 22 33');
  });

  it('splits at the value colon, not at colons inside quoted params', () => {
    const doc = parseVCard(
      card('BEGIN:VCARD', 'TEL;TYPE="voice,work";VALUE=uri:tel:+41791112233', 'END:VCARD')
    );

    const tel = getProperty(doc, 'TEL')!;
    expect(tel.value).toBe('tel:+41791112233');
    expect(tel.params).toEqual([
      ['TYPE', '"voice,work"'],
      ['VALUE', 'uri'],
    ]);
  });

  it('handles valueless parameters', () => {
    const doc = parseVCard(card('BEGIN:VCARD', 'TEL;WORK:+41 44 000 00 00', 'END:VCARD'));
    expect(getProperty(doc, 'TEL')!.params).toEqual([['WORK', '']]);
  });

  it('unfolds continuation lines for every line-ending flavour', () => {
    for (const eol of ['\r\n', '\n', '\r']) {
      const doc = parseVCard(
        ['BEGIN:VCARD', 'NOTE:first part', ' second part', 'END:VCARD'].join(eol)
      );
      expect(getProperty(doc, 'NOTE')!.value).toBe('first partsecond part');
    }
  });

  it('skips blank and unparseable lines rather than throwing', () => {
    const doc = parseVCard(card('BEGIN:VCARD', '', 'this-line-has-no-colon', 'FN:Ok', 'END:VCARD'));
    expect(doc.properties.map((p) => p.name)).toEqual(['BEGIN', 'FN', 'END']);
  });
});

describe('serializeVCard', () => {
  it('round-trips an untouched card', () => {
    const source = card(
      'BEGIN:VCARD',
      'VERSION:4.0',
      'UID:abc-123',
      'FN:John Doe',
      'item1.TEL;TYPE=voice:+41 79 111 22 33',
      'item1.X-ABLabel:Mobil',
      'END:VCARD'
    );
    expect(serializeVCard(parseVCard(source))).toBe(source);
  });

  it('always emits CRLF and a trailing newline after END:VCARD', () => {
    const out = serializeVCard(parseVCard('BEGIN:VCARD\nFN:Test\nEND:VCARD'));
    expect(out).toBe('BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Test\r\nEND:VCARD\r\n');
  });

  it('forces BEGIN first, VERSION second and END last', () => {
    const doc = parseVCard(card('BEGIN:VCARD', 'FN:Test', 'VERSION:4.0', 'END:VCARD'));
    const lines = serializeVCard(doc).split('\r\n').filter(Boolean);
    expect(lines[0]).toBe('BEGIN:VCARD');
    expect(lines[1]).toBe('VERSION:4.0');
    expect(lines[lines.length - 1]).toBe('END:VCARD');
  });

  it('folds long lines at 75 octets without splitting multi-byte characters', () => {
    const doc = parseVCard(card('BEGIN:VCARD', `NOTE:${'ü'.repeat(80)}`, 'END:VCARD'));
    const out = serializeVCard(doc);

    for (const line of out.split('\r\n').filter(Boolean)) {
      expect(Buffer.byteLength(line, 'utf8')).toBeLessThanOrEqual(75);
    }
    // Folding is lossless: unfolding restores the original value.
    expect(getProperty(parseVCard(out), 'NOTE')!.value).toBe('ü'.repeat(80));
  });

  it('re-folds a value that arrived folded', () => {
    const doc = parseVCard(card('BEGIN:VCARD', `NOTE:${'a'.repeat(200)}`, 'END:VCARD'));
    const out = serializeVCard(doc);
    expect(out).toContain('\r\n ');
    expect(getProperty(parseVCard(out), 'NOTE')!.value).toBe('a'.repeat(200));
  });
});

describe('setProperty', () => {
  it('preserves parameters and group when replacing a value', () => {
    const doc = parseVCard(card('BEGIN:VCARD', 'NOTE;CHARSET=UTF-8:old', 'END:VCARD'));
    setProperty(doc, 'NOTE', 'new');
    expect(serializeVCard(doc)).toContain('NOTE;CHARSET=UTF-8:new');
  });

  it('inserts a missing property before END:VCARD', () => {
    const doc = parseVCard(card('BEGIN:VCARD', 'FN:Test', 'END:VCARD'));
    setProperty(doc, 'ORG', 'ACME');
    const lines = serializeVCard(doc).split('\r\n').filter(Boolean);
    expect(lines[lines.length - 2]).toBe('ORG:ACME');
  });

  it('removes the property when the value is null', () => {
    const doc = parseVCard(card('BEGIN:VCARD', 'FN:Test', 'ORG:ACME', 'END:VCARD'));
    setProperty(doc, 'ORG', null);
    expect(serializeVCard(doc)).not.toContain('ORG');
  });
});

describe('replaceAll', () => {
  it('removes grouped instances the old regex could not see', () => {
    const doc = parseVCard(
      card(
        'BEGIN:VCARD',
        'item1.TEL;TYPE=voice:+41 79 111 22 33',
        'TEL;TYPE=WORK:+41 44 000 00 00',
        'END:VCARD'
      )
    );
    replaceAll(doc, 'TEL', [property('TEL', '+41 79 999 88 77', [['TYPE', 'CELL']])]);

    const out = serializeVCard(doc);
    expect(out).toContain('TEL;TYPE=CELL:+41 79 999 88 77');
    expect(out).not.toContain('+41 79 111 22 33');
    expect(out).not.toContain('+41 44 000 00 00');
  });

  it('drops the X-ABLabel left dangling by a removed group', () => {
    const doc = parseVCard(
      card('BEGIN:VCARD', 'item1.TEL:+41 79 111 22 33', 'item1.X-ABLabel:Mobil', 'END:VCARD')
    );
    replaceAll(doc, 'TEL', []);
    expect(serializeVCard(doc)).not.toContain('X-ABLabel');
  });

  it('keeps an X-ABLabel whose group still has a property', () => {
    const doc = parseVCard(
      card(
        'BEGIN:VCARD',
        'item1.EMAIL:a@example.com',
        'item1.X-ABLabel:Privat',
        'TEL:+41 44 000 00 00',
        'END:VCARD'
      )
    );
    replaceAll(doc, 'TEL', []);
    expect(serializeVCard(doc)).toContain('item1.X-ABLabel:Privat');
  });

  it('removes every instance when given no replacements', () => {
    const doc = parseVCard(
      card('BEGIN:VCARD', 'EMAIL:a@example.com', 'EMAIL:b@example.com', 'END:VCARD')
    );
    replaceAll(doc, 'EMAIL', []);
    expect(serializeVCard(doc)).not.toContain('EMAIL');
  });
});

describe('getParamValues', () => {
  it('collects repeated and quoted-list TYPE parameters', () => {
    const repeated = getProperty(
      parseVCard(card('BEGIN:VCARD', 'TEL;TYPE=voice;TYPE=cell:+41', 'END:VCARD')),
      'TEL'
    )!;
    expect(getParamValues(repeated, 'TYPE')).toEqual(['voice', 'cell']);

    const quoted = getProperty(
      parseVCard(card('BEGIN:VCARD', 'TEL;TYPE="voice,cell":+41', 'END:VCARD')),
      'TEL'
    )!;
    expect(getParamValues(quoted, 'TYPE')).toEqual(['voice', 'cell']);
  });
});
