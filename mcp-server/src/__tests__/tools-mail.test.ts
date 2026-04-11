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
    it('should return formatted mailbox list', async () => {
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

      expect(result.content[0].text).toContain('INBOX');
      expect(result.content[0].text).toContain('5 unread');
      expect(result.content[0].text).toContain('ID: 10');
      expect(result.content[0].text).toContain('Sent');
      expect(result.content[0].text).toContain('Drafts');
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
    it('should return formatted message summaries', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [
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
          ],
        },
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_list_messages')!;
      const result = await tool.handler({ mailboxId: 10 });

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

    it('should handle empty results', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [],
        },
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_list_messages')!;
      const result = await tool.handler({ mailboxId: 10 });

      expect(result.content[0].text).toContain('No messages found');
    });

    it('should handle errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS API error: 404 Not Found'));

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_list_messages')!;
      const result = await tool.handler({ mailboxId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('mail_read_message', () => {
    it('should return full message content', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            id: 100,
            subject: 'Test Subject',
            from: [{ label: 'Alice', email: 'alice@example.com' }],
            to: [{ label: 'Bob', email: 'bob@example.com' }],
            cc: [{ label: 'Carol', email: 'carol@example.com' }],
            dateInt: 1700000000,
            attachments: [{ fileName: 'report.pdf', size: 12345 }],
          },
        },
      });

      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ body: '<p>Hello <b>World</b></p><br>Nice to meet you.' }),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_read_message')!;
      const result = await tool.handler({ messageId: 100 });

      expect(result.content[0].text).toContain('Subject: Test Subject');
      expect(result.content[0].text).toContain('Alice <alice@example.com>');
      expect(result.content[0].text).toContain('Bob <bob@example.com>');
      expect(result.content[0].text).toContain('Cc: Carol <carol@example.com>');
      expect(result.content[0].text).toContain('Hello World');
      expect(result.content[0].text).toContain('Nice to meet you');
      expect(result.content[0].text).toContain('report.pdf');
      expect(result.content[0].text).toContain('12345 bytes');
      expect(result.content[0].text).toContain('Message ID: 100');
    });

    it('should handle errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('Message not found'));

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find((t) => t.name === 'mail_read_message')!;
      const result = await tool.handler({ messageId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Message not found');
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
