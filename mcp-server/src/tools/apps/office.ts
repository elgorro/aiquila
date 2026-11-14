// SPDX-License-Identifier: MIT

import { posix } from 'node:path';
import { z } from 'zod';
import { getCapabilities } from '../../client/capabilities.js';
import { fetchOCS } from '../../client/ocs.js';
import { getWebDAVClient } from '../../client/webdav.js';

/**
 * Nextcloud Office Tools
 *
 * Templates and conversion are core APIs that Nextcloud Office (richdocuments)
 * provides for, so a Collabora server has to be connected for either to work.
 */

const FILES_API = '/ocs/v2.php/apps/files/api/v1';
const FILL_API = '/ocs/v2.php/apps/richdocuments/api/v1/template/fields/fill';

interface TemplateFileCreator {
  app: string;
  label: string;
  extension: string;
  templates: {
    templateId: string;
    basename: string;
    templateType: string;
  }[];
}

interface FileConversion {
  from: string;
  to: string;
  extension: string;
}

function text(t: string) {
  return { content: [{ type: 'text' as const, text: t }] };
}

function error(msg: string) {
  return { content: [{ type: 'text' as const, text: msg }], isError: true };
}

function wrapError(action: string, err: unknown) {
  return error(`Error ${action}: ${err instanceof Error ? err.message : String(err)}`);
}

function extensionOf(path: string): string {
  return posix.extname(path).slice(1).toLowerCase();
}

async function officeCreators(): Promise<TemplateFileCreator[]> {
  const data = await fetchOCS<TemplateFileCreator[]>(`${FILES_API}/templates`);
  return (data.ocs.data ?? []).filter((c) => c.app === 'richdocuments');
}

/** The file's ID and MIME type, which the conversion API works from. */
async function fileDetails(path: string): Promise<{ id: number; mime: string }> {
  const client = getWebDAVClient();
  const stat = await client.stat(path, { props: ['oc:fileid'] });
  return { id: Number(stat.props.fileid), mime: stat.mime ?? '' };
}

export const listDocumentTemplatesTool = {
  name: 'list_document_templates',
  title: 'List Document Templates',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List the Nextcloud Office templates for documents, spreadsheets, presentations and diagrams.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const creators = await officeCreators();
      const lines = creators.flatMap((creator) => [
        `${creator.label} (default format: ${creator.extension.slice(1)}):`,
        ...creator.templates.map((t) => `- [${t.templateId}] ${t.basename}`),
      ]);
      return text(lines.join('\n') || 'No templates found.');
    } catch (err) {
      return wrapError('listing templates', err);
    }
  },
};

export const createDocumentFromTemplateTool = {
  name: 'create_document_from_template',
  title: 'Create Document From Template',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Create an Office document from a template. The extension of path sets the format, e.g. .odt or .docx. An existing file is not replaced.',
  inputSchema: z.object({
    path: z.string().describe('Path of the new document, including its extension'),
    templateId: z.string().describe('Template ID (from list_document_templates)'),
  }),
  handler: async (args: { path: string; templateId: string }) => {
    const format = extensionOf(args.path);
    if (!format) return error('path needs a file extension, which sets the document format.');

    try {
      const known = (await officeCreators()).flatMap((c) => c.templates);
      if (!known.some((t) => t.templateId === args.templateId)) {
        return error(`No template ${args.templateId}. See list_document_templates.`);
      }
      // The fill endpoint writes over an existing file without saying so.
      if (await getWebDAVClient().exists(args.path)) {
        return error(`${args.path} already exists, so nothing was changed.`);
      }

      // Not the core templates/create: it leaves an empty file with the
      // template attached, which only gets its content when opened in Office.
      await fetchOCS(`${FILL_API}/${encodeURIComponent(args.templateId)}`, {
        method: 'POST',
        jsonBody: { fields: {}, destination: args.path, convert: format },
      });
      return text(`Document created: ${args.path}`);
    } catch (err) {
      return wrapError('creating document', err);
    }
  },
};

export const convertDocumentTool = {
  name: 'convert_document',
  title: 'Convert Document',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Convert a file to another format with Nextcloud Office, e.g. a .docx to .pdf. The converted file is written next to the original, or to destination, and an existing file is not replaced.',
  inputSchema: z.object({
    path: z.string().describe('File to convert'),
    format: z.string().describe("Target format as a file extension, e.g. 'pdf', 'docx', 'odt'"),
    destination: z.string().optional().describe('Path for the converted file'),
  }),
  handler: async (args: { path: string; format: string; destination?: string }) => {
    const format = args.format.replace(/^\./, '').toLowerCase();
    try {
      const caps = await getCapabilities();
      if (!caps) return error("Could not read this server's capabilities, so nothing was sent.");
      const files = caps.capabilities.files as { file_conversions?: FileConversion[] } | undefined;

      const { id, mime } = await fileDetails(args.path);
      const options = (files?.file_conversions ?? []).filter((c) => c.from === mime);
      const conversion = options.find((c) => c.extension === format);
      if (!conversion) {
        const available = options.map((c) => c.extension).join(', ');
        return error(
          available
            ? `${args.path} (${mime}) cannot be converted to ${format}. It can be converted to: ${available}.`
            : `This server cannot convert ${args.path} (${mime}).`
        );
      }

      const data = await fetchOCS<{ path: string }>(`${FILES_API}/convert`, {
        method: 'POST',
        jsonBody: {
          fileId: id,
          targetMimeType: conversion.to,
          ...(args.destination !== undefined && { destination: args.destination }),
        },
      });
      // Nextcloud picks another name rather than replacing a file.
      return text(`Converted: ${data.ocs.data.path}`);
    } catch (err) {
      return wrapError('converting document', err);
    }
  },
};

export const officeTools = [
  listDocumentTemplatesTool,
  createDocumentFromTemplateTool,
  convertDocumentTool,
];
