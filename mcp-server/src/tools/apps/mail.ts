// SPDX-License-Identifier: MIT

import { spawnSync } from 'node:child_process';
import { writeFileSync, unlinkSync } from 'node:fs';
import { z } from 'zod';
import { fetchMailAPI } from '../../client/mail.js';
import { fetchOCS } from '../../client/ocs.js';

// Invisible padding characters used in newsletter preheaders
// (U+034F, U+00AD, U+200B–U+200D, U+FEFF)
const INVISIBLE_CHARS_RE = /[\u034F\u00AD\u200B-\u200D\uFEFF]/g;

// ── Types ────────────────────────────────────────────────────────────

interface MailAccount {
  id: number;
  name: string;
  emailAddress: string;
}

interface Mailbox {
  id: number;
  databaseId?: number;
  accountId: number;
  name: string;
  displayName?: string;
  specialUse: string[];
  unread: number;
  total: number;
  delimiter: string;
}

interface MailRecipient {
  label: string;
  email: string;
}

interface MailMessageSummary {
  id: number;
  databaseId?: number;
  uid: number;
  mailboxId: number;
  subject: string;
  from: MailRecipient[];
  to: MailRecipient[];
  cc: MailRecipient[];
  dateInt: number;
  flags: {
    seen: boolean;
    flagged: boolean;
    answered: boolean;
    deleted: boolean;
    draft: boolean;
    important: boolean;
    junk: boolean;
    hasAttachments?: boolean;
  };
  hasAttachments?: boolean;
}

// ── Helpers ──────────────────────────────────────────────────────────

function stripHtml(html: string): string {
  let text = html;
  // Remove style and script blocks
  text = text.replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '');
  text = text.replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '');
  // Image-based newsletters carry content in alt attributes
  text = text.replace(/<img[^>]+alt="([^"]{2,})"[^>]*\/?>/gi, (_, alt) => `\n${alt.trim()}\n`);
  text = text.replace(/<img[^>]+alt='([^']{2,})'[^>]*\/?>/gi, (_, alt) => `\n${alt.trim()}\n`);
  text = text.replace(/<img[^>]*\/?>/gi, '');
  // Block-level structure
  text = text.replace(/<\/h[1-6]>/gi, '\n\n');
  text = text.replace(/<\/tr>/gi, '\n');
  text = text.replace(/<td[^>]*>/gi, ' ');
  text = text.replace(/<th[^>]*>/gi, ' ');
  text = text.replace(/<br\s*\/?>/gi, '\n');
  text = text.replace(/<\/p>/gi, '\n\n');
  text = text.replace(/<\/div>/gi, '\n');
  text = text.replace(/<\/li>/gi, '\n');
  text = text.replace(/<li[^>]*>/gi, '- ');
  // Strip remaining tags
  text = text.replace(/<[^>]+>/g, '');
  // Decode HTML entities
  text = text.replace(/&amp;/g, '&');
  text = text.replace(/&lt;/g, '<');
  text = text.replace(/&gt;/g, '>');
  text = text.replace(/&quot;/g, '"');
  text = text.replace(/&#39;/g, "'");
  text = text.replace(/&nbsp;/g, ' ');
  text = text.replace(/&#\d+;/g, '');
  // Strip invisible preheader padding
  text = text.replace(INVISIBLE_CHARS_RE, '');
  // Trim per-line and drop blanks before collapsing
  text = text
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line.length > 0)
    .join('\n');
  text = text.replace(/\n{3,}/g, '\n\n');
  return text.trim();
}

function formatDate(dateInt: number): string {
  const d = new Date(dateInt * 1000);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function formatRecipients(recipients: MailRecipient[]): string {
  if (!recipients || recipients.length === 0) return '';
  return recipients
    .map((r) => (r.label && r.label !== r.email ? `${r.label} <${r.email}>` : r.email))
    .join(', ');
}

// ── Tools ────────────────────────────────────────────────────────────

const listMailAccountsTool = {
  name: 'list_mail_accounts',
  description:
    'List all configured email accounts in Nextcloud Mail. Returns account IDs, names, and email addresses.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const response = await fetchMailAPI('/accounts');
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${await response.text()}`);
      }
      const accounts = (await response.json()) as MailAccount[];

      if (accounts.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No mail accounts configured.' }] };
      }

      const lines = accounts.map((a) => `• ${a.name} <${a.emailAddress}> (ID: ${a.id})`);
      return {
        content: [{ type: 'text' as const, text: `Mail accounts:\n${lines.join('\n')}` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing mail accounts: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

const listMailboxesTool = {
  name: 'list_mailboxes',
  description:
    'List all mailboxes (folders) for a Nextcloud Mail account. Returns mailbox names, IDs, and unread counts.',
  inputSchema: z.object({
    accountId: z.number().describe('The mail account ID (from list_mail_accounts)'),
  }),
  handler: async (args: { accountId: number }) => {
    try {
      const response = await fetchMailAPI(`/mailboxes?accountId=${args.accountId}`);
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${await response.text()}`);
      }
      const data = (await response.json()) as Mailbox[] | { mailboxes?: Mailbox[] };
      const mailboxes = Array.isArray(data) ? data : (data.mailboxes ?? []);

      if (mailboxes.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No mailboxes found.' }] };
      }

      const lines = mailboxes.map((m) => {
        const id = m.databaseId ?? m.id;
        const name = m.displayName ?? m.name;
        const unread = m.unread > 0 ? ` (${m.unread} unread)` : '';
        return `• ${name}${unread} — ID: ${id}`;
      });
      return {
        content: [{ type: 'text' as const, text: `Mailboxes:\n${lines.join('\n')}` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing mailboxes: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

const listMessagesTool = {
  name: 'mail_list_messages',
  description:
    'List email messages in a Nextcloud Mail mailbox. Supports pagination via cursor. Returns subject, sender, date, and message IDs.',
  inputSchema: z.object({
    mailboxId: z.number().describe('The mailbox ID (from list_mailboxes)'),
    limit: z
      .number()
      .min(1)
      .max(100)
      .optional()
      .describe('Number of messages to return (default: 20)'),
    cursor: z.number().optional().describe('Cursor for pagination (message ID to start after)'),
    filter: z
      .enum(['all', 'unread', 'flagged'])
      .optional()
      .describe('Filter messages (default: all)'),
  }),
  handler: async (args: {
    mailboxId: number;
    limit?: number;
    cursor?: number;
    filter?: string;
  }) => {
    try {
      const queryParts = [`mailboxId=${args.mailboxId}`];
      if (args.limit) queryParts.push(`limit=${args.limit}`);
      if (args.cursor) queryParts.push(`cursor=${args.cursor}`);
      if (args.filter && args.filter !== 'all') queryParts.push(`filter=${args.filter}`);

      const response = await fetchMailAPI(`/messages?${queryParts.join('&')}`);
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${await response.text()}`);
      }
      const data = (await response.json()) as
        | MailMessageSummary[]
        | { messages?: MailMessageSummary[]; data?: MailMessageSummary[] };
      const messages = Array.isArray(data) ? data : (data.messages ?? data.data ?? []);

      if (!messages || messages.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No messages found.' }] };
      }

      const lines = messages.map((msg) => {
        const id = msg.databaseId ?? msg.id;
        const flags: string[] = [];
        if (!msg.flags.seen) flags.push('UNREAD');
        if (msg.flags.flagged) flags.push('starred');
        if (msg.flags.answered) flags.push('replied');
        if (msg.flags?.hasAttachments ?? msg.hasAttachments) flags.push('attachment');
        const flagStr = flags.length > 0 ? ` [${flags.join(', ')}]` : '';
        const from = formatRecipients(msg.from);
        return `• ${msg.subject}${flagStr}\n  From: ${from} | ${formatDate(msg.dateInt)} | ID: ${id}`;
      });

      let text = `Messages (${messages.length}):\n${lines.join('\n')}`;
      if (messages.length >= (args.limit || 20)) {
        const last = messages[messages.length - 1];
        const lastId = last.databaseId ?? last.id;
        text += `\n\nMore messages available. Use cursor: ${lastId} to load next page.`;
      }

      return { content: [{ type: 'text' as const, text }] };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing messages: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

const readMessageTool = {
  name: 'mail_read_message',
  description:
    'Read the full content of an email message by ID. Returns headers, body text, and attachment list.',
  inputSchema: z.object({
    messageId: z.number().describe('The message ID (from list_messages)'),
  }),
  handler: async (args: { messageId: number }) => {
    try {
      // Mail 5.x: /messages/{id}/body returns both metadata and body in one call.
      const bodyResponse = await fetchMailAPI(`/messages/${args.messageId}/body`);
      if (!bodyResponse.ok) {
        throw new Error(`HTTP ${bodyResponse.status}: ${await bodyResponse.text()}`);
      }
      const data = (await bodyResponse.json()) as Record<string, unknown>;
      const rawBody = (data.body as string) || '';
      const bodyText = rawBody ? stripHtml(rawBody) : '(no body)';

      const from = formatRecipients(data.from as MailRecipient[]);
      const to = formatRecipients(data.to as MailRecipient[]);
      const cc = formatRecipients((data.cc as MailRecipient[]) || []);
      const date = data.dateInt ? formatDate(data.dateInt as number) : 'Unknown';
      const subject = (data.subject as string) || '(No subject)';

      let text = `Subject: ${subject}\nFrom: ${from}\nTo: ${to}`;
      if (cc) text += `\nCc: ${cc}`;
      text += `\nDate: ${date}`;
      text += `\n${'─'.repeat(60)}\n${bodyText}`;

      const attachments = data.attachments as
        | Array<{ id?: string | number; fileName: string; size: number }>
        | undefined;
      if (attachments && attachments.length > 0) {
        text += `\n${'─'.repeat(60)}\nAttachments:`;
        for (const att of attachments) {
          const idPart = att.id !== undefined ? `[id: ${att.id}] ` : '';
          text += `\n  • ${idPart}${att.fileName} (${att.size} bytes)`;
        }
        text += `\n(Use mail_get_attachment with the message ID and an attachment id to read one.)`;
      }

      text += `\n\nMessage ID: ${args.messageId}`;

      return { content: [{ type: 'text' as const, text }] };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error reading message: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

const getAttachmentTool = {
  name: 'mail_get_attachment',
  description:
    'Download an email attachment by message ID and attachment ID. ' +
    'Returns text for text files and calendar invites (ICS), image data for images, ' +
    'extracted text for PDFs (requires pdftotext). ' +
    'Attachment IDs are shown in mail_read_message output.',
  inputSchema: z.object({
    messageId: z.number().describe('Message ID (from mail_read_message)'),
    attachmentId: z.string().describe('Attachment ID shown in mail_read_message (e.g. "2")'),
  }),
  handler: async (args: { messageId: number; attachmentId: string }) => {
    try {
      const response = await fetchMailAPI(
        `/messages/${args.messageId}/attachment/${args.attachmentId}`
      );
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${await response.text()}`);
      }

      const contentType = response.headers.get('content-type') ?? 'application/octet-stream';
      const mimeType = contentType.split(';')[0].trim();

      if (
        mimeType.startsWith('text/') ||
        mimeType === 'application/json' ||
        mimeType === 'application/ics'
      ) {
        const text = await response.text();
        return { content: [{ type: 'text' as const, text }] };
      }

      if (mimeType.startsWith('image/')) {
        const buffer = await response.arrayBuffer();
        const base64 = Buffer.from(buffer).toString('base64');
        return { content: [{ type: 'image' as const, data: base64, mimeType }] };
      }

      if (mimeType === 'application/pdf') {
        const buffer = await response.arrayBuffer();
        const safeId =
          String(args.messageId).replace(/[^a-zA-Z0-9_-]/g, '') +
          '_' +
          String(args.attachmentId).replace(/[^a-zA-Z0-9_-]/g, '');
        const tmpFile = `/tmp/mcp_att_${safeId}.pdf`;
        try {
          writeFileSync(tmpFile, Buffer.from(buffer));
          const result = spawnSync('pdftotext', [tmpFile, '-'], { encoding: 'utf8' });
          unlinkSync(tmpFile);
          if (result.status === 0 && result.stdout.trim().length > 0) {
            return {
              content: [
                {
                  type: 'text' as const,
                  text: `[UNTRUSTED EXTERNAL CONTENT - PDF ATTACHMENT]\n${result.stdout}\n[END EXTERNAL CONTENT]`,
                },
              ],
            };
          }
        } catch {
          try {
            unlinkSync(tmpFile);
          } catch {
            /* ignore */
          }
        }
        return {
          content: [
            {
              type: 'text' as const,
              text: `Attachment: ${mimeType}, ${(buffer.byteLength / 1024).toFixed(1)} KB. Could not extract text (pdftotext unavailable or empty).`,
            },
          ],
        };
      }

      const buffer = await response.arrayBuffer();
      return {
        content: [
          {
            type: 'text' as const,
            text: `Attachment: ${mimeType}, ${(buffer.byteLength / 1024).toFixed(1)} KB. Cannot be read inline.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error downloading attachment: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

const searchMessagesTool = {
  name: 'mail_search_messages',
  description:
    'Search email messages across all mailboxes by subject or sender. ' +
    'Returns matches with message IDs usable in mail_read_message. ' +
    'For threaded conversations all message IDs in the thread are returned.',
  inputSchema: z.object({
    query: z.string().describe('Search query (matches subject and sender name)'),
    limit: z.number().min(1).max(20).optional().describe('Max results (default: 10)'),
  }),
  handler: async (args: { query: string; limit?: number }) => {
    try {
      const response = await fetchOCS<{
        entries: Array<{ title: string; subline: string; resourceUrl?: string }>;
        isPaginated: boolean;
        cursor?: string;
      }>('/ocs/v2.php/search/providers/mail/search', {
        queryParams: {
          term: args.query,
          limit: String(args.limit ?? 10),
          format: 'json',
        },
      });
      const results = response.ocs.data;
      if (!results.entries?.length) {
        return {
          content: [{ type: 'text' as const, text: `No messages found for "${args.query}".` }],
        };
      }

      const lines = await Promise.all(
        results.entries.map(async (entry) => {
          const match = entry.resourceUrl?.match(/\/box\/(\d+)\/thread\/(\d+)/);
          if (!match) return `• ${entry.title}\n  ${entry.subline}`;
          const msgId = parseInt(match[2], 10);
          try {
            const threadResp = await fetchMailAPI(`/messages/${msgId}/thread`);
            if (!threadResp.ok) throw new Error('thread fetch failed');
            const threadData = (await threadResp.json()) as unknown;
            const msgs = (
              Array.isArray(threadData)
                ? threadData
                : ((threadData as Record<string, unknown>).messages ?? [])
            ) as Array<Record<string, unknown>>;
            const sorted = msgs.sort(
              (a, b) => ((a.dateInt as number) ?? 0) - ((b.dateInt as number) ?? 0)
            );
            if (sorted.length <= 1) {
              return `• ${entry.title}\n  ${entry.subline} | ID: ${msgId}`;
            }
            const idList = sorted.map((m) => (m.databaseId ?? m.id) as number).join(', ');
            return `• ${entry.title}\n  ${entry.subline} | Thread (${sorted.length} messages) IDs: ${idList}`;
          } catch {
            return `• ${entry.title}\n  ${entry.subline} | ID: ${msgId}`;
          }
        })
      );

      let text = `Search results for "${args.query}" (${results.entries.length}):\n${lines.join('\n')}`;
      if (results.isPaginated && results.cursor) {
        text += `\n\nMore results available.`;
      }
      return { content: [{ type: 'text' as const, text }] };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error searching messages: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

const sendMessageTool = {
  name: 'mail_send_message',
  description:
    'Send an email message through Nextcloud Mail. Requires an account ID and recipient addresses.',
  inputSchema: z.object({
    accountId: z.number().describe('The mail account ID to send from (from list_mail_accounts)'),
    to: z.array(z.string()).describe('Recipient email addresses'),
    subject: z.string().describe('Email subject line'),
    body: z.string().describe('Email body content (plain text)'),
    cc: z.array(z.string()).optional().describe('CC email addresses'),
    bcc: z.array(z.string()).optional().describe('BCC email addresses'),
    isHtml: z.boolean().optional().describe('Whether the body is HTML (default: false)'),
  }),
  handler: async (args: {
    accountId: number;
    to: string[];
    subject: string;
    body: string;
    cc?: string[];
    bcc?: string[];
    isHtml?: boolean;
  }) => {
    try {
      // Get account info to find the from email
      const accountResponse = await fetchMailAPI('/accounts');
      if (!accountResponse.ok) {
        throw new Error(`Failed to fetch accounts: ${accountResponse.status}`);
      }
      const accounts = (await accountResponse.json()) as MailAccount[];
      const account = accounts.find((a) => a.id === args.accountId);
      if (!account) {
        throw new Error(`Account with ID ${args.accountId} not found`);
      }

      const toRecipients = args.to.map((email) => ({ label: email, email }));
      const ccRecipients = args.cc?.map((email) => ({ label: email, email })) || [];
      const bccRecipients = args.bcc?.map((email) => ({ label: email, email })) || [];

      const response = await fetchMailAPI('/messages/send', {
        method: 'POST',
        body: {
          accountId: args.accountId,
          fromEmail: account.emailAddress,
          subject: args.subject,
          body: args.body,
          isHtml: args.isHtml || false,
          to: toRecipients,
          cc: ccRecipients,
          bcc: bccRecipients,
        },
      });

      if (!response.ok) {
        const errorText = await response.text();
        throw new Error(`Failed to send: HTTP ${response.status} - ${errorText}`);
      }

      return {
        content: [
          { type: 'text' as const, text: `Email sent successfully to ${args.to.join(', ')}` },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error sending message: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

const deleteMessageTool = {
  name: 'mail_delete_message',
  description: 'Delete an email message by ID. This typically moves it to trash.',
  inputSchema: z.object({
    messageId: z.number().describe('The message ID to delete'),
  }),
  handler: async (args: { messageId: number }) => {
    try {
      const response = await fetchMailAPI(`/messages/${args.messageId}`, {
        method: 'DELETE',
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${await response.text()}`);
      }

      return {
        content: [{ type: 'text' as const, text: `Message ${args.messageId} deleted.` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error deleting message: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

const moveMessageTool = {
  name: 'mail_move_message',
  description: 'Move an email message to a different mailbox/folder.',
  inputSchema: z.object({
    messageId: z.number().describe('The message ID to move'),
    destMailboxId: z.number().describe('The destination mailbox ID (from list_mailboxes)'),
  }),
  handler: async (args: { messageId: number; destMailboxId: number }) => {
    try {
      const response = await fetchMailAPI(`/messages/${args.messageId}/move`, {
        method: 'POST',
        body: { destFolderId: args.destMailboxId },
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${await response.text()}`);
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Message ${args.messageId} moved to mailbox ${args.destMailboxId}.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error moving message: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

const setMessageFlagsTool = {
  name: 'mail_set_message_flags',
  description:
    'Set flags on an email message (mark as read/unread, star/unstar, mark as important or junk).',
  inputSchema: z.object({
    messageId: z.number().describe('The message ID'),
    flags: z
      .object({
        seen: z.boolean().optional().describe('Mark as read (true) or unread (false)'),
        flagged: z.boolean().optional().describe('Star (true) or unstar (false)'),
        important: z.boolean().optional().describe('Mark as important (true) or not (false)'),
        junk: z.boolean().optional().describe('Mark as junk/spam (true) or not (false)'),
      })
      .describe('Flags to set on the message'),
  }),
  handler: async (args: { messageId: number; flags: Record<string, boolean | undefined> }) => {
    try {
      const response = await fetchMailAPI(`/messages/${args.messageId}/flags`, {
        method: 'PUT',
        body: args.flags,
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${await response.text()}`);
      }

      const changes = Object.entries(args.flags)
        .filter(([, v]) => v !== undefined)
        .map(([k, v]) => `${k}=${v}`)
        .join(', ');

      return {
        content: [
          { type: 'text' as const, text: `Flags updated on message ${args.messageId}: ${changes}` },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error setting flags: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ── Export ────────────────────────────────────────────────────────────

export const mailTools = [
  listMailAccountsTool,
  listMailboxesTool,
  listMessagesTool,
  readMessageTool,
  getAttachmentTool,
  searchMessagesTool,
  sendMessageTool,
  deleteMessageTool,
  moveMessageTool,
  setMessageFlagsTool,
];
