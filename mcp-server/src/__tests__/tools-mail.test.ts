// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the OCS client module (needed for list_messages, read_message)
const mockFetchOCS = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
  fetchStatus: vi.fn(),
}));

// Mock the Mail API client module
const mockFetchMailAPI = vi.fn();

vi.mock('../client/mail.js', () => ({
  fetchMailAPI: (...args: unknown[]) => mockFetchMailAPI(...args),
}));

// ---------------------------------------------------------------------------
// Mail tools
// ---------------------------------------------------------------------------

describe('Mail Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_mail_accounts', () => {
    it('should return formatted account list', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve([
            { id: 1, name: 'Personal', emailAddress: 'user@example.com' },
            { id: 2, name: 'Work', emailAddress: 'user@work.com' },
          ]),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'list_mail_accounts')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('Personal');
      expect(result.content[0].text).toContain('user@example.com');
      expect(result.content[0].text).toContain('ID: 1');
      expect(result.content[0].text).toContain('Work');
      expect(result.content[0].text).toContain('user@work.com');
    });

    it('should handle empty account list', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([]),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'list_mail_accounts')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No mail accounts configured');
    });

    it('should handle API errors', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: false,
        status: 500,
        text: () => Promise.resolve('Internal Server Error'),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'list_mail_accounts')!;
      const result = await tool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('500');
    });
  });

  describe('list_mailboxes', () => {
    it('should return formatted mailbox list (legacy array shape)', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve([
            { id: 10, accountId: 1, name: 'INBOX', unread: 5, total: 120, delimiter: '/' },
            { id: 11, accountId: 1, name: 'Sent', unread: 0, total: 45, delimiter: '/' },
            { id: 12, accountId: 1, name: 'Drafts', unread: 2, total: 3, delimiter: '/' },
          ]),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'list_mailboxes')!;
      const result = await tool.handler({ accountId: 1 });

      expect(mockFetchMailAPI).toHaveBeenCalledWith('/mailboxes?accountId=1');
      expect(result.content[0].text).toContain('INBOX');
      expect(result.content[0].text).toContain('5 unread');
      expect(result.content[0].text).toContain('ID: 10');
      expect(result.content[0].text).toContain('Sent');
      expect(result.content[0].text).toContain('Drafts');
    });

    it('should handle Mail 5.x wrapped shape with displayName/databaseId', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            mailboxes: [
              {
                id: 10,
                databaseId: 42,
                accountId: 1,
                name: 'INBOX',
                displayName: 'Inbox',
                unread: 3,
                total: 99,
                delimiter: '/',
              },
            ],
          }),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'list_mailboxes')!;
      const result = await tool.handler({ accountId: 1 });

      expect(result.content[0].text).toContain('Inbox');
      expect(result.content[0].text).toContain('ID: 42');
      expect(result.content[0].text).not.toContain('ID: 10');
    });

    it('should handle errors', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: false,
        status: 404,
        text: () => Promise.resolve('Account not found'),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'list_mailboxes')!;
      const result = await tool.handler({ accountId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('mail_list_messages', () => {
    it('should return formatted message summaries (legacy shape)', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve([
            {
              id: 100,
              uid: 1,
              mailboxId: 10,
              subject: 'Hello World',
              from: [{ label: 'Alice', email: 'alice@example.com' }],
              to: [{ label: 'Bob', email: 'bob@example.com' }],
              cc: [],
              dateInt: 1700000000,
              flags: {
                seen: false,
                flagged: true,
                answered: false,
                deleted: false,
                draft: false,
                important: false,
                junk: false,
              },
              hasAttachments: true,
            },
            {
              id: 101,
              uid: 2,
              mailboxId: 10,
              subject: 'Meeting notes',
              from: [{ label: '', email: 'carol@example.com' }],
              to: [{ label: 'Bob', email: 'bob@example.com' }],
              cc: [],
              dateInt: 1700100000,
              flags: {
                seen: true,
                flagged: false,
                answered: true,
                deleted: false,
                draft: false,
                important: false,
                junk: false,
              },
              hasAttachments: false,
            },
          ]),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_list_messages')!;
      const result = await tool.handler({ mailboxId: 10 });

      expect(mockFetchOCS).not.toHaveBeenCalled();
      expect(mockFetchMailAPI).toHaveBeenCalledWith('/messages?mailboxId=10');
      expect(result.content[0].text).toContain('Hello World');
      expect(result.content[0].text).toContain('UNREAD');
      expect(result.content[0].text).toContain('starred');
      expect(result.content[0].text).toContain('attachment');
      expect(result.content[0].text).toContain('Alice');
      expect(result.content[0].text).toContain('ID: 100');
      expect(result.content[0].text).toContain('Meeting notes');
      expect(result.content[0].text).toContain('replied');
      expect(result.content[0].text).toContain('Messages (2)');
    });

    it('should pick up databaseId and flags.hasAttachments (Mail 5.x shape)', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            messages: [
              {
                id: 1,
                databaseId: 555,
                uid: 1,
                mailboxId: 10,
                subject: 'New shape',
                from: [{ label: '', email: 'x@example.com' }],
                to: [],
                cc: [],
                dateInt: 1700000000,
                flags: {
                  seen: true,
                  flagged: false,
                  answered: false,
                  deleted: false,
                  draft: false,
                  important: false,
                  junk: false,
                  hasAttachments: true,
                },
              },
            ],
          }),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_list_messages')!;
      const result = await tool.handler({ mailboxId: 10, limit: 5, cursor: 99, filter: 'unread' });

      expect(mockFetchMailAPI).toHaveBeenCalledWith(
        '/messages?mailboxId=10&limit=5&cursor=99&filter=unread'
      );
      expect(result.content[0].text).toContain('ID: 555');
      expect(result.content[0].text).toContain('attachment');
    });

    it('should handle empty results', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([]),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_list_messages')!;
      const result = await tool.handler({ mailboxId: 10 });

      expect(result.content[0].text).toContain('No messages found');
    });

    it('should handle errors', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: false,
        status: 404,
        text: () => Promise.resolve('Not found'),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_list_messages')!;
      const result = await tool.handler({ mailboxId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('mail_read_message', () => {
    it('should return full message content from single /body call', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            subject: 'Test Subject',
            from: [{ label: 'Alice', email: 'alice@example.com' }],
            to: [{ label: 'Bob', email: 'bob@example.com' }],
            cc: [{ label: 'Carol', email: 'carol@example.com' }],
            dateInt: 1700000000,
            attachments: [{ id: '2', fileName: 'report.pdf', size: 12345 }],
            body: '<p>Hello <b>World</b></p><br>Nice to meet you.',
          }),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_read_message')!;
      const result = await tool.handler({ messageId: 100 });

      expect(mockFetchOCS).not.toHaveBeenCalled();
      expect(mockFetchMailAPI).toHaveBeenCalledTimes(1);
      expect(mockFetchMailAPI).toHaveBeenCalledWith('/messages/100/body');
      expect(result.content[0].text).toContain('Subject: Test Subject');
      expect(result.content[0].text).toContain('Alice <alice@example.com>');
      expect(result.content[0].text).toContain('Bob <bob@example.com>');
      expect(result.content[0].text).toContain('Cc: Carol <carol@example.com>');
      expect(result.content[0].text).toContain('Hello World');
      expect(result.content[0].text).toContain('Nice to meet you');
      expect(result.content[0].text).toContain('report.pdf');
      expect(result.content[0].text).toContain('12345 bytes');
      expect(result.content[0].text).toContain('[id: 2]');
      expect(result.content[0].text).toContain('Message ID: 100');
    });

    it('should extract <img alt=...> and strip invisible chars', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            subject: 'Newsletter',
            from: [{ label: 'News', email: 'n@example.com' }],
            to: [],
            dateInt: 1700000000,
            // U+200B padding + alt-only image + table cells
            body: '​​<table><tr><td>Left</td><td>Right</td></tr></table><img alt="Big Headline" src="x.png">',
          }),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_read_message')!;
      const result = await tool.handler({ messageId: 7 });

      expect(result.content[0].text).toContain('Big Headline');
      expect(result.content[0].text).toContain('Left Right');
      expect(result.content[0].text).not.toContain('​');
    });

    it('should handle errors', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: false,
        status: 404,
        text: () => Promise.resolve('Message not found'),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_read_message')!;
      const result = await tool.handler({ messageId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('mail_get_attachment', () => {
    it('should return text content for text attachments', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        headers: { get: () => 'text/plain; charset=utf-8' },
        text: () => Promise.resolve('hello attachment'),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_get_attachment')!;
      const result = await tool.handler({ messageId: 100, attachmentId: '2' });

      expect(mockFetchMailAPI).toHaveBeenCalledWith('/messages/100/attachment/2');
      expect(result.content[0].type).toBe('text');
      expect(result.content[0].text).toBe('hello attachment');
    });

    it('should return an image block for image attachments', async () => {
      const bytes = Buffer.from('fakepng');
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        headers: { get: () => 'image/png' },
        arrayBuffer: () => Promise.resolve(bytes.buffer.slice(0, bytes.length)),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_get_attachment')!;
      const result = await tool.handler({ messageId: 100, attachmentId: '3' });

      expect(result.content[0].type).toBe('image');
      expect(result.content[0]).toHaveProperty('mimeType', 'image/png');
      expect(result.content[0]).toHaveProperty('data');
      expect((result.content[0] as { data: string }).data.length).toBeGreaterThan(0);
    });

    it('should fall back gracefully for PDFs when pdftotext yields no text', async () => {
      const bytes = Buffer.from('%PDF-1.4 not-real');
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        headers: { get: () => 'application/pdf' },
        arrayBuffer: () => Promise.resolve(bytes.buffer.slice(0, bytes.length)),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_get_attachment')!;
      const result = await tool.handler({ messageId: 100, attachmentId: '4' });

      // Either extracted text (UNTRUSTED marker) or graceful fallback — never an error.
      expect(result.isError).toBeUndefined();
      expect(result.content[0].type).toBe('text');
      const text = result.content[0].text;
      expect(text.includes('UNTRUSTED EXTERNAL CONTENT') || text.includes('application/pdf')).toBe(
        true
      );
    });

    it('should return type + size for other binary attachments', async () => {
      const bytes = Buffer.alloc(2048);
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        headers: { get: () => 'application/octet-stream' },
        arrayBuffer: () => Promise.resolve(bytes.buffer.slice(0, bytes.length)),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_get_attachment')!;
      const result = await tool.handler({ messageId: 100, attachmentId: '5' });

      expect(result.content[0].text).toContain('application/octet-stream');
      expect(result.content[0].text).toContain('2.0 KB');
      expect(result.content[0].text).toContain('Cannot be read inline');
    });

    it('should handle errors', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: false,
        status: 404,
        text: () => Promise.resolve('Not found'),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_get_attachment')!;
      const result = await tool.handler({ messageId: 999, attachmentId: '1' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('mail_search_messages', () => {
    it('should return IDs and resolve multi-message threads', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            entries: [
              {
                title: 'Re: Project',
                subline: 'alice@example.com',
                resourceUrl: '/apps/mail/box/3/thread/42',
              },
              {
                title: 'Single message',
                subline: 'bob@example.com',
                resourceUrl: '/apps/mail/box/3/thread/77',
              },
            ],
            isPaginated: false,
          },
        },
      });

      mockFetchMailAPI.mockImplementation((url: string) => {
        if (url === '/messages/42/thread') {
          return Promise.resolve({
            ok: true,
            json: () =>
              Promise.resolve([
                { databaseId: 42, dateInt: 1700000200 },
                { databaseId: 41, dateInt: 1700000100 },
              ]),
          });
        }
        if (url === '/messages/77/thread') {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve([{ databaseId: 77, dateInt: 1700000300 }]),
          });
        }
        return Promise.resolve({ ok: false, status: 404, text: () => Promise.resolve('') });
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_search_messages')!;
      const result = await tool.handler({ query: 'project' });

      expect(result.content[0].text).toContain('Re: Project');
      // Multi-message thread: sorted ascending by dateInt → 41, 42
      expect(result.content[0].text).toContain('Thread (2 messages) IDs: 41, 42');
      // Single-message thread
      expect(result.content[0].text).toContain('Single message');
      expect(result.content[0].text).toContain('ID: 77');
    });

    it('should report no results', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { entries: [], isPaginated: false },
        },
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_search_messages')!;
      const result = await tool.handler({ query: 'nothing' });

      expect(result.content[0].text).toContain('No messages found');
    });
  });

  describe('mail_send_message', () => {
    it('should send email successfully', async () => {
      // First call: GET /accounts to find fromEmail
      // Second call: POST /messages/send
      let callCount = 0;
      mockFetchMailAPI.mockImplementation(() => {
        callCount++;
        if (callCount === 1) {
          return Promise.resolve({
            ok: true,
            json: () =>
              Promise.resolve([{ id: 1, name: 'Personal', emailAddress: 'me@example.com' }]),
          });
        }
        return Promise.resolve({ ok: true, status: 200 });
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_send_message')!;
      const result = await tool.handler({
        accountId: 1,
        to: ['alice@example.com'],
        subject: 'Test',
        body: 'Hello!',
      });

      expect(result.content[0].text).toContain('Email sent successfully');
      expect(result.content[0].text).toContain('alice@example.com');

      // Verify send was called with correct params
      expect(mockFetchMailAPI).toHaveBeenCalledTimes(2);
      const sendCall = mockFetchMailAPI.mock.calls[1];
      expect(sendCall[0]).toBe('/messages/send');
      expect(sendCall[1].method).toBe('POST');
      expect(sendCall[1].body.fromEmail).toBe('me@example.com');
      expect(sendCall[1].body.subject).toBe('Test');
    });

    it('should handle account not found', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([]),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_send_message')!;
      const result = await tool.handler({
        accountId: 999,
        to: ['alice@example.com'],
        subject: 'Test',
        body: 'Hello!',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Account with ID 999 not found');
    });
  });

  describe('mail_delete_message', () => {
    it('should delete a message', async () => {
      mockFetchMailAPI.mockResolvedValue({ ok: true, status: 200 });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_delete_message')!;
      const result = await tool.handler({ messageId: 100 });

      expect(result.content[0].text).toContain('Message 100 deleted');
      expect(mockFetchMailAPI).toHaveBeenCalledWith('/messages/100', { method: 'DELETE' });
    });

    it('should handle errors', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: false,
        status: 404,
        text: () => Promise.resolve('Not found'),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_delete_message')!;
      const result = await tool.handler({ messageId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('mail_move_message', () => {
    it('should move a message', async () => {
      mockFetchMailAPI.mockResolvedValue({ ok: true, status: 200 });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_move_message')!;
      const result = await tool.handler({ messageId: 100, destMailboxId: 20 });

      expect(result.content[0].text).toContain('Message 100 moved to mailbox 20');
      expect(mockFetchMailAPI).toHaveBeenCalledWith('/messages/100/move', {
        method: 'POST',
        body: { destFolderId: 20 },
      });
    });

    it('should handle errors', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: false,
        status: 400,
        text: () => Promise.resolve('Invalid destination'),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_move_message')!;
      const result = await tool.handler({ messageId: 100, destMailboxId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('400');
    });
  });

  describe('mail_set_message_flags', () => {
    it('should set flags on a message', async () => {
      mockFetchMailAPI.mockResolvedValue({ ok: true, status: 200 });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_set_message_flags')!;
      const result = await tool.handler({ messageId: 100, flags: { seen: true, flagged: true } });

      expect(result.content[0].text).toContain('Flags updated on message 100');
      expect(result.content[0].text).toContain('seen=true');
      expect(result.content[0].text).toContain('flagged=true');
      expect(mockFetchMailAPI).toHaveBeenCalledWith('/messages/100/flags', {
        method: 'PUT',
        body: { seen: true, flagged: true },
      });
    });

    it('should handle errors', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: false,
        status: 500,
        text: () => Promise.resolve('Server error'),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_set_message_flags')!;
      const result = await tool.handler({ messageId: 100, flags: { seen: true } });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('500');
    });
  });
});
