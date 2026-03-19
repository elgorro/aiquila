# Nextcloud Contacts Tools

Integration with Nextcloud Contacts app via CardDAV protocol. Manage address books and contacts with full support for structured fields (email, phone, address, organization).

## Prerequisites

- Nextcloud Contacts app must be installed and enabled
- At least one address book must exist (default: `contacts`)
- CardDAV access must be enabled (default in Nextcloud)

## Available Tools

| Tool | Description |
|------|-------------|
| `list_address_books` | List all address books |
| `list_contacts` | List/search contacts |
| `get_contact` | Get full contact details by UID |
| `create_contact` | Create a new contact |
| `update_contact` | Update an existing contact |
| `delete_contact` | Delete a contact |

---

### list_address_books

List all address books available to the current user.

**Parameters:**
None

**Returns:**
List of address books with display names and URLs.

**Example Usage:**
```
Ask Claude: "List my address books"
```

---

### list_contacts

List contacts from a Nextcloud address book. Optionally search by name.

**Parameters:**
- `addressBookName` (string, optional): The address book name. Default: `"contacts"`
- `search` (string, optional): Search term to filter contacts by name
- `limit` (number, optional): Maximum number of contacts to return (default 50, max 200)

**Returns:**
List of contacts with name, email, phone, organization, and UID.

**Example Usage:**
```
Ask Claude: "List my contacts"
Ask Claude: "Search for contacts named 'Alice'"
Ask Claude: "Show contacts in my work address book"
```

---

### get_contact

Get detailed information about a single contact by its UID, including all properties.

**Parameters:**
- `uid` (string, required): The UID of the contact
- `addressBookName` (string, optional): The address book name. Default: `"contacts"`

**Returns:**
Complete contact details: name, email, phone, address, organization, birthday, notes, and groups.

**Example Usage:**
```
Ask Claude: "Get details for contact uid-abc123"
Ask Claude: "Show me Alice's full contact info"
```

---

### create_contact

Create a new contact in a Nextcloud address book.

**Parameters:**
- `fullName` (string, required): The contact's full display name
- `addressBookName` (string, optional): The address book name. Default: `"contacts"`
- `firstName` (string, optional): First/given name
- `lastName` (string, optional): Last/family name
- `prefix` (string, optional): Name prefix (e.g. Dr., Mr.)
- `suffix` (string, optional): Name suffix (e.g. Jr., III)
- `emails` (object[], optional): Email addresses with `value` and `type` (HOME, WORK, OTHER)
- `phones` (object[], optional): Phone numbers with `value` and `type` (HOME, WORK, CELL, FAX, PAGER, OTHER)
- `addresses` (object[], optional): Physical addresses with type and fields (street, city, state, postalCode, country)
- `org` (string, optional): Organization name
- `title` (string, optional): Job title
- `note` (string, optional): Notes about the contact
- `birthday` (string, optional): Birthday in `YYYY-MM-DD` or `YYYYMMDD` format
- `url` (string, optional): Website URL
- `categories` (string[], optional): Groups/categories

**Returns:**
Confirmation with contact UID.

**Example Usage:**
```
Ask Claude: "Create a contact for Alice Smith with email alice@example.com and phone 555-1234"
Ask Claude: "Add a work contact: Bob Jones, CTO at Acme Corp, bob@acme.com"
```

**Example with full details:**
```json
{
  "fullName": "Alice Smith",
  "firstName": "Alice",
  "lastName": "Smith",
  "emails": [
    { "value": "alice@example.com", "type": "WORK" }
  ],
  "phones": [
    { "value": "+1-555-1234", "type": "CELL" }
  ],
  "org": "Acme Corp",
  "title": "Software Engineer",
  "categories": ["Work", "Engineering"]
}
```

---

### update_contact

Update an existing contact's fields by UID. Uses CardDAV ETag-based optimistic concurrency. Only provided fields are changed.

**Parameters:**
- `uid` (string, required): The UID of the contact to update
- `addressBookName` (string, optional): The address book name. Default: `"contacts"`
- `fullName` (string, optional): New full display name
- `firstName` (string|null, optional): New first name, or `null` to clear
- `lastName` (string|null, optional): New last name, or `null` to clear
- `emails` (object[], optional): Replace all emails
- `phones` (object[], optional): Replace all phone numbers
- `addresses` (object[], optional): Replace all addresses
- `org` (string|null, optional): New organization, or `null` to remove
- `title` (string|null, optional): New job title, or `null` to remove
- `note` (string|null, optional): New notes, or `null` to remove
- `birthday` (string|null, optional): New birthday, or `null` to remove
- `url` (string|null, optional): New website URL, or `null` to remove
- `categories` (string[], optional): Replace all groups/categories

**Returns:**
Confirmation message.

**Example Usage:**
```
Ask Claude: "Update Alice's phone number to 555-5678"
Ask Claude: "Add a work email to contact uid-123"
Ask Claude: "Change Bob's job title to 'VP of Engineering'"
```

---

### delete_contact

Delete a contact by UID. This action is irreversible.

**Parameters:**
- `uid` (string, required): The UID of the contact to delete
- `addressBookName` (string, optional): The address book name. Default: `"contacts"`

**Returns:**
Confirmation message.

**Example Usage:**
```
Ask Claude: "Delete contact uid-123"
```

---

## Workflow Examples

### Contact Lookup
```
User: "Find Alice's email and phone number"
Claude: Searches contacts -> gets full details -> reports back
```

### Batch Contact Creation
```
User: "Add these three contacts from the meeting: Alice (alice@example.com), Bob (bob@example.com), Carol (carol@example.com)"
Claude: Creates three contacts with the provided emails
```

## Development

To extend contacts tools:
- See [Adding Tools Guide](../../development/adding-tools.md)
- Source code: [mcp-server/src/tools/apps/contacts.ts](../../../../mcp-server/src/tools/apps/contacts.ts)

## References

- [Nextcloud Contacts App](https://apps.nextcloud.com/apps/contacts)
- [CardDAV RFC 6352](https://tools.ietf.org/html/rfc6352)
- [vCard RFC 6350](https://tools.ietf.org/html/rfc6350)
- [Nextcloud CardDAV Docs](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/CardDAV/)
