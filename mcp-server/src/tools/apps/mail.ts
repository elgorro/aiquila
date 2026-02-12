import { z } from 'zod';
import { fetchMailAPI } from '../../client/mail.js';
import { fetchOCS } from '../../client/ocs.js';

// ── Types ────────────────────────────────────────────────────────────

interface MailAccount {
  id: number;
  name: string;
  emailAddress: string;
}

interface Mailbox {
  id: number;
  accountId: number;
  name: string;
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
  };
  hasAttachments: boolean;
}

// ── Helpers ──────────────────────────────────────────────────────────

function stripHtml(html: string): string {
  let text = html;
  // Remove style and script blocks
  text = text.replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '');
  text = text.replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '');
  // Convert line-break tags to newlines
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
  // Collapse multiple blank lines
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
      const response = await fetchMailAPI(`/accounts/${args.accountId}/mailboxes`);
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${await response.text()}`);
      }
      const mailboxes = (await response.json()) as Mailbox[];

      if (mailboxes.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No mailboxes found.' }] };
      }

      const lines = mailboxes.map((m) => {
        const unread = m.unread > 0 ? ` (${m.unread} unread)` : '';
        return `• ${m.name}${unread} — ID: ${m.id}`;
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
  name: 'list_messages',
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
      const queryParams: Record<string, string> = {};
      if (args.limit) queryParams.limit = String(args.limit);
      if (args.cursor) queryParams.cursor = String(args.cursor);
      if (args.filter && args.filter !== 'all') queryParams.filter = args.filter;

      const response = await fetchOCS<MailMessageSummary[]>(
        `/ocs/v2.php/apps/mail/api/v1/mailboxes/${args.mailboxId}/messages`,
        { queryParams }
      );

      const messages = response.ocs.data;
      if (!messages || messages.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No messages found.' }] };
      }

      const lines = messages.map((msg) => {
        const flags: string[] = [];
        if (!msg.flags.seen) flags.push('UNREAD');
        if (msg.flags.flagged) flags.push('starred');
        if (msg.flags.answered) flags.push('replied');
        if (msg.hasAttachments) flags.push('attachment');
        const flagStr = flags.length > 0 ? ` [${flags.join(', ')}]` : '';
        const from = formatRecipients(msg.from);
        return `• ${msg.subject}${flagStr}\n  From: ${from} | ${formatDate(msg.dateInt)} | ID: ${msg.id}`;
      });

      let text = `Messages (${messages.length}):\n${lines.join('\n')}`;
      if (messages.length >= (args.limit || 20)) {
        const lastId = messages[messages.length - 1].id;
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
  name: 'read_message',
  description:
    'Read the full content of an email message by ID. Returns headers, body text, and attachment list.',
  inputSchema: z.object({
    messageId: z.number().describe('The message ID (from list_messages)'),
  }),
  handler: async (args: { messageId: number }) => {
    try {
      // Fetch metadata via OCS
      const metaResponse = await fetchOCS<Record<string, unknown>>(
        `/ocs/v2.php/apps/mail/api/v1/message/${args.messageId}`
      );
      const meta = metaResponse.ocs.data;

      // Fetch body via Mail REST API
      const bodyResponse = await fetchMailAPI(`/messages/${args.messageId}/body`);
      let bodyText = '';
      if (bodyResponse.ok) {
        const bodyData = (await bodyResponse.json()) as Record<string, unknown>;
        const rawBody = (bodyData.body as string) || (bodyData.data as string) || '';
        bodyText = stripHtml(rawBody);
      }

      // Format output
      const from = formatRecipients(meta.from as MailRecipient[]);
      const to = formatRecipients(meta.to as MailRecipient[]);
      const cc = formatRecipients((meta.cc as MailRecipient[]) || []);
      const date = meta.dateInt ? formatDate(meta.dateInt as number) : 'Unknown';
      const subject = (meta.subject as string) || '(No subject)';

      let text = `Subject: ${subject}\nFrom: ${from}\nTo: ${to}`;
      if (cc) text += `\nCc: ${cc}`;
      text += `\nDate: ${date}`;
      text += `\n${'─'.repeat(60)}\n${bodyText}`;

      const attachments = meta.attachments as Array<{ fileName: string; size: number }> | undefined;
      if (attachments && attachments.length > 0) {
        text += `\n${'─'.repeat(60)}\nAttachments:`;
        for (const att of attachments) {
          text += `\n  • ${att.fileName} (${att.size} bytes)`;
        }
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

const sendMessageTool = {
  name: 'send_message',
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
  name: 'delete_message',
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
  name: 'move_message',
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
  name: 'set_message_flags',
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
  sendMessageTool,
  deleteMessageTool,
  moveMessageTool,
  setMessageFlagsTool,
];
