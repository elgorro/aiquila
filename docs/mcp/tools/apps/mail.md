# Nextcloud Mail Tools

Integration with Nextcloud Mail app. Manage email accounts, mailboxes, and messages through Claude.

## Prerequisites

- Nextcloud Mail app must be installed and enabled
- At least one email account must be configured in Nextcloud Mail

## Available Tools

| Tool | Description |
|------|-------------|
| `list_mail_accounts` | List configured email accounts |
| `list_mailboxes` | List mailboxes/folders for an account |
| `list_messages` | List messages in a mailbox |
| `read_message` | Read full message content |
| `mail_get_attachment` | Download an attachment inline (text/image/PDF) |
| `send_message` | Send an email |
| `delete_message` | Delete a message |
| `move_message` | Move a message to another mailbox |
| `set_message_flags` | Set message flags (read, starred, etc.) |

---

### list_mail_accounts

List all configured email accounts in Nextcloud Mail.

**Parameters:**
None

**Returns:**
Account IDs, names, and email addresses.

**Example Usage:**
```
Ask Claude: "List my email accounts"
Ask Claude: "What mail accounts do I have configured?"
```

---

### list_mailboxes

List all mailboxes (folders) for a Nextcloud Mail account.

**Parameters:**
- `accountId` (number, required): The mail account ID

**Returns:**
Mailbox names, IDs, and unread counts.

**Example Usage:**
```
Ask Claude: "Show mailboxes for account 1"
Ask Claude: "What mail folders do I have?"
```

---

### list_messages

List email messages in a Nextcloud Mail mailbox. Supports pagination via cursor.

**Parameters:**
- `mailboxId` (number, required): The mailbox ID
- `limit` (number, optional): Number of messages to return (default 20, max 100)
- `cursor` (number, optional): Cursor for pagination (message ID to start after)
- `filter` (enum, optional): Filter messages — `all`, `unread`, `flagged`

**Returns:**
Subject, sender, date, and message IDs.

**Example Usage:**
```
Ask Claude: "Show my latest emails"
Ask Claude: "List unread messages in mailbox 5"
Ask Claude: "Show the next page of messages after message 1234"
```

---

### read_message

Read the full content of an email message by ID.

**Parameters:**
- `messageId` (number, required): The message ID

**Returns:**
Headers, body text, and attachment list.

**Example Usage:**
```
Ask Claude: "Read message 1234"
Ask Claude: "Show me the full email from John"
```

---

### mail_get_attachment

Download an email attachment by message ID and attachment ID and return its content
inline. Attachment IDs are listed in `read_message` output (shown as `[id: …]`).

**Parameters:**
- `messageId` (number, required): The message ID (from `read_message`)
- `attachmentId` (string, required): The attachment ID shown in `read_message` (e.g. `"2"`)

**Returns (depends on the attachment's content type):**
- **Text / ICS / JSON** — the raw text
- **Images** — a base64 image block, usable directly by vision-capable models
- **PDFs** — plain text extracted with `pdftotext`, wrapped in
  `[UNTRUSTED EXTERNAL CONTENT]` markers
- **Other binary** — a short note with the MIME type and size (cannot be read inline)

**Dependency:** PDF text extraction requires `pdftotext` from `poppler-utils`. It is
included in the official Docker image. If it is unavailable, the tool degrades gracefully
and returns the type + size fallback instead of failing.

**Example Usage:**
```
Ask Claude: "Read message 1234, then open the PDF attachment"
Ask Claude: "Show me the image attached to message 1234"
```

---

### send_message

Send an email message through Nextcloud Mail.

**Parameters:**
- `accountId` (number, required): The mail account ID to send from
- `to` (string[], required): Recipient email addresses
- `subject` (string, required): Email subject line
- `body` (string, required): Email body content (plain text)
- `cc` (string[], optional): CC email addresses
- `bcc` (string[], optional): BCC email addresses
- `isHtml` (boolean, optional): Whether the body is HTML (default false)

**Returns:**
Confirmation message.

**Example Usage:**
```
Ask Claude: "Send an email to alice@example.com with subject 'Meeting tomorrow' and body 'Hi Alice, can we meet at 2pm?'"
Ask Claude: "Email the team at team@example.com about the project update"
```

---

### delete_message

Delete an email message by ID. This typically moves it to trash.

**Parameters:**
- `messageId` (number, required): The message ID to delete

**Returns:**
Confirmation message.

**Example Usage:**
```
Ask Claude: "Delete message 1234"
```

---

### move_message

Move an email message to a different mailbox/folder.

**Parameters:**
- `messageId` (number, required): The message ID to move
- `destMailboxId` (number, required): The destination mailbox ID

**Returns:**
Confirmation message.

**Example Usage:**
```
Ask Claude: "Move message 1234 to mailbox 10"
Ask Claude: "Archive message 1234"
```

---

### set_message_flags

Set flags on an email message (mark as read/unread, star/unstar, mark as important or junk).

**Parameters:**
- `messageId` (number, required): The message ID
- `flags` (object, required): Flags to set
  - `seen` (boolean, optional): Mark as read (true) or unread (false)
  - `flagged` (boolean, optional): Star (true) or unstar (false)
  - `important` (boolean, optional): Mark as important
  - `junk` (boolean, optional): Mark as junk/spam

**Returns:**
Confirmation message with changes applied.

**Example Usage:**
```
Ask Claude: "Mark message 1234 as read"
Ask Claude: "Star message 5678"
Ask Claude: "Mark message 1234 as junk"
```

---

## Workflow Examples

### Email Triage
```
User: "Show my unread emails and summarize each one"
Claude: Lists unread messages -> reads each -> provides summaries
```

### Bulk Management
```
User: "Mark all messages from newsletter@example.com as read"
Claude: Lists messages -> filters by sender -> sets seen flag on each
```

## Development

To extend mail tools:
- See [Adding Tools Guide](../../development/adding-tools.md)
- Source code: [mcp-server/src/tools/apps/mail.ts](../../../../mcp-server/src/tools/apps/mail.ts)

## References

- [Nextcloud Mail App](https://apps.nextcloud.com/apps/mail)
- [Nextcloud Mail Documentation](https://docs.nextcloud.com/server/latest/user_manual/en/groupware/mail.html)
