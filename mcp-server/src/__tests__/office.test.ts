// SPDX-License-Identifier: MIT

import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockFetchOCS = vi.fn();
vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

const mockExists = vi.fn();
const mockStat = vi.fn();
vi.mock('../client/webdav.js', () => ({
  getWebDAVClient: () => ({ exists: mockExists, stat: mockStat }),
}));

vi.mock('../logger.js', () => ({
  logger: { info: vi.fn(), warn: vi.fn(), error: vi.fn(), debug: vi.fn(), trace: vi.fn() },
}));

import { _resetCapabilitiesCache } from '../client/capabilities.js';
import {
  convertDocumentTool,
  createDocumentFromTemplateTool,
  listDocumentTemplatesTool,
} from '../tools/apps/office.js';

const wrap = <T>(data: T) => ({ ocs: { data } });

const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
const CREATORS = [
  { app: 'text', label: 'Text', extension: '.md', templates: [] },
  {
    app: 'richdocuments',
    label: 'Document',
    extension: '.docx',
    templates: [{ templateId: '120', basename: 'Invoice.odt', templateType: 'Provider' }],
  },
];
const CONVERSIONS = [
  { from: DOCX, to: 'application/pdf', extension: 'pdf' },
  { from: DOCX, to: 'text/plain', extension: 'txt' },
];

function serve(conversions: unknown = CONVERSIONS, capabilitiesFail = false) {
  mockFetchOCS.mockImplementation(async (path: string) => {
    if (path.includes('/cloud/capabilities')) {
      if (capabilitiesFail) throw new Error('unreachable');
      return wrap({ capabilities: { files: { file_conversions: conversions } } });
    }
    if (path.endsWith('/templates')) return wrap(CREATORS);
    return wrap({ path: '/a.pdf' });
  });
}

const writes = () => mockFetchOCS.mock.calls.filter(([, options]) => options?.method === 'POST');

describe('Office tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    _resetCapabilitiesCache();
    mockExists.mockResolvedValue(false);
    mockStat.mockResolvedValue({ mime: DOCX, props: { fileid: '42' } });
    serve();
  });

  it('lists only the Office templates', async () => {
    const result = await listDocumentTemplatesTool.handler();

    expect(result.content[0].text).toContain('[120] Invoice.odt');
    expect(result.content[0].text).toContain('default format: docx');
    expect(result.content[0].text).not.toContain('Text');
  });

  describe('create_document_from_template', () => {
    it('uses the fill endpoint, which writes the content of the template', async () => {
      const result = await createDocumentFromTemplateTool.handler({
        path: '/Invoice.DOCX',
        templateId: '120',
      });

      expect(writes()[0]).toEqual([
        '/ocs/v2.php/apps/richdocuments/api/v1/template/fields/fill/120',
        {
          method: 'POST',
          jsonBody: { fields: {}, destination: '/Invoice.DOCX', convert: 'docx' },
        },
      ]);
      expect(result.content[0].text).toContain('/Invoice.DOCX');
    });

    it('refuses to replace a file, which the endpoint would do silently', async () => {
      mockExists.mockResolvedValue(true);

      const result = await createDocumentFromTemplateTool.handler({
        path: '/a.odt',
        templateId: '120',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('already exists');
      expect(writes()).toEqual([]);
    });

    it('refuses an unknown template', async () => {
      const result = await createDocumentFromTemplateTool.handler({
        path: '/a.odt',
        templateId: '1',
      });

      expect(result.isError).toBe(true);
      expect(writes()).toEqual([]);
    });

    it('needs an extension to know the format', async () => {
      const result = await createDocumentFromTemplateTool.handler({
        path: '/a',
        templateId: '120',
      });

      expect(result.isError).toBe(true);
      expect(mockFetchOCS).not.toHaveBeenCalled();
    });
  });

  describe('convert_document', () => {
    it('converts by file ID and target type, and reports the path Nextcloud chose', async () => {
      const result = await convertDocumentTool.handler({ path: '/a.docx', format: '.PDF' });

      expect(writes()[0]).toEqual([
        '/ocs/v2.php/apps/files/api/v1/convert',
        { method: 'POST', jsonBody: { fileId: 42, targetMimeType: 'application/pdf' } },
      ]);
      expect(result.content[0].text).toBe('Converted: /a.pdf');
    });

    it('passes a destination', async () => {
      await convertDocumentTool.handler({ path: '/a.docx', format: 'txt', destination: '/b.txt' });

      expect(writes()[0][1].jsonBody).toMatchObject({
        targetMimeType: 'text/plain',
        destination: '/b.txt',
      });
    });

    it('names the formats a file can become', async () => {
      const result = await convertDocumentTool.handler({ path: '/a.docx', format: 'png' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('It can be converted to: pdf, txt.');
      expect(writes()).toEqual([]);
    });

    it('says when a type has no conversion', async () => {
      mockStat.mockResolvedValue({ mime: 'application/x-bin', props: { fileid: '1' } });

      const result = await convertDocumentTool.handler({ path: '/a.bin', format: 'pdf' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('cannot convert');
    });

    it('refuses when no conversion provider is connected', async () => {
      serve([]);

      const result = await convertDocumentTool.handler({ path: '/a.docx', format: 'pdf' });

      expect(result.isError).toBe(true);
      expect(writes()).toEqual([]);
    });

    it('refuses when capabilities cannot be read', async () => {
      serve(CONVERSIONS, true);

      const result = await convertDocumentTool.handler({ path: '/a.docx', format: 'pdf' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('nothing was sent');
    });
  });
});
