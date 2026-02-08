import { z } from "zod";
import { fetchCalDAV } from "../../client/caldav.js";
import { getNextcloudConfig } from "../types.js";

/**
 * Nextcloud Contacts App Tools
 * Provides contact management via CardDAV
 */

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface ParsedAddressBook {
  displayName: string;
  url: string;
  ctag?: string;
}

interface ContactEmail {
  value: string;
  type?: string;
}

interface ContactPhone {
  value: string;
  type?: string;
}

interface ContactAddress {
  street?: string;
  city?: string;
  region?: string;
  postalCode?: string;
  country?: string;
  type?: string;
}

interface ParsedContact {
  uid: string;
  fullName: string;
  firstName?: string;
  lastName?: string;
  prefix?: string;
  suffix?: string;
  emails: ContactEmail[];
  phones: ContactPhone[];
  addresses: ContactAddress[];
  org?: string;
  title?: string;
  note?: string;
  birthday?: string;
  url?: string;
  categories: string[];
  rev?: string;
  etag?: string;
  href?: string;
}

// ---------------------------------------------------------------------------
// vCard helpers
// ---------------------------------------------------------------------------

function unfoldVCardLines(text: string): string {
  return text.replace(/\r?\n[ \t]/g, "");
}

function escapeVCardValue(value: string): string {
  return value
    .replace(/\\/g, "\\\\")
    .replace(/;/g, "\\;")
    .replace(/,/g, "\\,")
    .replace(/\n/g, "\\n");
}

function unescapeVCardValue(value: string): string {
  return value
    .replace(/\\n/gi, "\n")
    .replace(/\\,/g, ",")
    .replace(/\\;/g, ";")
    .replace(/\\\\/g, "\\");
}

/**
 * Extract TYPE parameter from a vCard property line.
 * Handles TYPE=WORK, TYPE="WORK", type=work, etc.
 */
function extractType(params: string): string | undefined {
  const match = params.match(/TYPE="?([^";:]+)"?/i);
  return match ? match[1].toUpperCase() : undefined;
}

/**
 * Replace, add, or remove a simple vCard property.
 * - value === null  -> remove the property
 * - property exists -> replace its value
 * - property absent -> insert before END:VCARD
 */
function setVCardProperty(
  vcardData: string,
  propName: string,
  value: string | null,
): string {
  const regex = new RegExp(`^${propName}(;[^:]*)?:.*$`, "mi");
  if (value === null) {
    return vcardData.replace(regex, "").replace(/(\r?\n){2,}/g, "\r\n");
  }
  if (regex.test(vcardData)) {
    return vcardData.replace(regex, `${propName}:${value}`);
  }
  return vcardData.replace(/END:VCARD/i, `${propName}:${value}\r\nEND:VCARD`);
}

/**
 * Remove all instances of a multi-valued property (e.g. EMAIL, TEL, ADR).
 */
function removeAllVCardProperty(vcardData: string, propName: string): string {
  const regex = new RegExp(`^${propName}(;[^:]*)?:.*\\r?\\n?`, "gmi");
  return vcardData.replace(regex, "");
}

// ---------------------------------------------------------------------------
// Parsing
// ---------------------------------------------------------------------------

/**
 * Parse address book collections from a PROPFIND response.
 */
function parseAddressBooks(responseXml: string): ParsedAddressBook[] {
  const books: ParsedAddressBook[] = [];

  const responseBlocks = responseXml.match(
    /<d:response>[\s\S]*?<\/d:response>/g,
  );
  if (!responseBlocks) return books;

  for (const block of responseBlocks) {
    const resourceTypes = block.match(/<d:resourcetype>([\s\S]*?)<\/d:resourcetype>/);
    if (!resourceTypes || !resourceTypes[1].includes("addressbook")) continue;

    const hrefMatch = block.match(/<d:href>([^<]+)<\/d:href>/);
    const displayNameMatch = block.match(/<d:displayname>([^<]*)<\/d:displayname>/);
    const ctagMatch = block.match(/<cs:getctag>([^<]*)<\/cs:getctag>/);

    const url = hrefMatch?.[1] || "";
    const name = displayNameMatch?.[1] || url.split("/").filter(Boolean).pop() || "";

    books.push({
      displayName: name,
      url,
      ctag: ctagMatch?.[1],
    });
  }

  return books;
}

/**
 * Parse a single vCard text block into a ParsedContact.
 */
function parseVCardBlock(vcardText: string): ParsedContact | null {
  const unfolded = unfoldVCardLines(vcardText);
  const lines = unfolded.split(/\r?\n/);

  const contact: ParsedContact = {
    uid: "",
    fullName: "",
    emails: [],
    phones: [],
    addresses: [],
    categories: [],
  };

  for (const line of lines) {
    const propMatch = line.match(/^([A-Za-z0-9-]+)(;[^:]*)?:(.*)/);
    if (!propMatch) continue;

    const [, rawName, params, rawValue] = propMatch;
    const name = rawName.toUpperCase();
    const value = rawValue || "";
    const paramStr = params || "";

    switch (name) {
      case "UID":
        contact.uid = value;
        break;
      case "FN":
        contact.fullName = unescapeVCardValue(value);
        break;
      case "N": {
        // N:family;given;additional;prefix;suffix
        const parts = value.split(";");
        contact.lastName = unescapeVCardValue(parts[0] || "");
        contact.firstName = unescapeVCardValue(parts[1] || "");
        if (parts[3]) contact.prefix = unescapeVCardValue(parts[3]);
        if (parts[4]) contact.suffix = unescapeVCardValue(parts[4]);
        break;
      }
      case "EMAIL":
        contact.emails.push({
          value: unescapeVCardValue(value),
          type: extractType(paramStr),
        });
        break;
      case "TEL":
        contact.phones.push({
          value: unescapeVCardValue(value),
          type: extractType(paramStr),
        });
        break;
      case "ADR": {
        // ADR:PO;ext;street;city;region;postal;country
        const adrParts = value.split(";");
        contact.addresses.push({
          street: unescapeVCardValue(adrParts[2] || ""),
          city: unescapeVCardValue(adrParts[3] || ""),
          region: unescapeVCardValue(adrParts[4] || ""),
          postalCode: unescapeVCardValue(adrParts[5] || ""),
          country: unescapeVCardValue(adrParts[6] || ""),
          type: extractType(paramStr),
        });
        break;
      }
      case "ORG":
        contact.org = unescapeVCardValue(value.split(";")[0]);
        break;
      case "TITLE":
        contact.title = unescapeVCardValue(value);
        break;
      case "NOTE":
        contact.note = unescapeVCardValue(value);
        break;
      case "BDAY":
        contact.birthday = value;
        break;
      case "URL":
        contact.url = value;
        break;
      case "CATEGORIES":
        contact.categories.push(
          ...value
            .split(",")
            .map((c) => unescapeVCardValue(c.trim()))
            .filter(Boolean),
        );
        break;
      case "REV":
        contact.rev = value;
        break;
    }
  }

  if (!contact.uid) return null;
  return contact;
}

/**
 * Parse vCards from a CardDAV REPORT response.
 */
function parseVCards(responseXml: string): ParsedContact[] {
  const contacts: ParsedContact[] = [];

  const responseBlocks = responseXml.match(
    /<d:response>[\s\S]*?<\/d:response>/g,
  );
  if (!responseBlocks) return contacts;

  for (const block of responseBlocks) {
    const hrefMatch = block.match(/<d:href>([^<]+)<\/d:href>/);
    const etagMatch = block.match(/<d:getetag>"?([^"<]+)"?<\/d:getetag>/);

    // Match address-data with any namespace prefix or none
    const cardDataMatch = block.match(
      /<(?:[a-z0-9]+:)?address-data[^>]*>([\s\S]*?)<\/(?:[a-z0-9]+:)?address-data>/,
    );
    if (!cardDataMatch) continue;

    const vcardText = cardDataMatch[1];
    const vcardBlocks = vcardText.match(/BEGIN:VCARD[\s\S]*?END:VCARD/gi);
    if (!vcardBlocks) continue;

    for (const vcardBlock of vcardBlocks) {
      const contact = parseVCardBlock(vcardBlock);
      if (contact) {
        contact.etag = etagMatch?.[1];
        contact.href = hrefMatch?.[1];
        contacts.push(contact);
      }
    }
  }

  return contacts;
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

function formatContact(contact: ParsedContact): string {
  let line = contact.fullName || "(no name)";

  if (contact.org) line += ` — ${contact.org}`;
  if (contact.title) line += `, ${contact.title}`;

  if (contact.emails.length > 0) {
    const emailStr = contact.emails
      .map((e) => (e.type ? `${e.value} (${e.type})` : e.value))
      .join(", ");
    line += `\n    Email: ${emailStr}`;
  }

  if (contact.phones.length > 0) {
    const phoneStr = contact.phones
      .map((p) => (p.type ? `${p.value} (${p.type})` : p.value))
      .join(", ");
    line += `\n    Phone: ${phoneStr}`;
  }

  line += `\n    UID: ${contact.uid}`;

  return line;
}

function formatContactDetail(contact: ParsedContact): string {
  let line = `Name: ${contact.fullName || "(no name)"}`;

  if (contact.firstName || contact.lastName) {
    const nameParts: string[] = [];
    if (contact.prefix) nameParts.push(contact.prefix);
    if (contact.firstName) nameParts.push(contact.firstName);
    if (contact.lastName) nameParts.push(contact.lastName);
    if (contact.suffix) nameParts.push(contact.suffix);
    line += `\n  Structured name: ${nameParts.join(" ")}`;
  }

  if (contact.org) line += `\n  Organization: ${contact.org}`;
  if (contact.title) line += `\n  Title: ${contact.title}`;

  if (contact.emails.length > 0) {
    for (const e of contact.emails) {
      line += `\n  Email${e.type ? ` (${e.type})` : ""}: ${e.value}`;
    }
  }

  if (contact.phones.length > 0) {
    for (const p of contact.phones) {
      line += `\n  Phone${p.type ? ` (${p.type})` : ""}: ${p.value}`;
    }
  }

  if (contact.addresses.length > 0) {
    for (const a of contact.addresses) {
      const parts = [a.street, a.city, a.region, a.postalCode, a.country]
        .filter(Boolean);
      if (parts.length > 0) {
        line += `\n  Address${a.type ? ` (${a.type})` : ""}: ${parts.join(", ")}`;
      }
    }
  }

  if (contact.birthday) line += `\n  Birthday: ${contact.birthday}`;
  if (contact.url) line += `\n  URL: ${contact.url}`;

  if (contact.categories.length > 0) {
    line += `\n  Groups: ${contact.categories.join(", ")}`;
  }

  if (contact.note) {
    line += `\n  Note: ${contact.note}`;
  }

  line += `\n  UID: ${contact.uid}`;

  return line;
}

// ---------------------------------------------------------------------------
// CardDAV helpers
// ---------------------------------------------------------------------------

/**
 * Resolve a contact's CardDAV href, ETag, and full vCard data by UID.
 */
async function resolveContactByUid(
  addressBookName: string,
  uid: string,
): Promise<{ href: string; etag: string; vcardData: string }> {
  const config = getNextcloudConfig();
  const cardDavUrl = `${config.url}/remote.php/dav/addressbooks/users/${config.user}/${addressBookName}/`;

  const reportBody = `<?xml version="1.0" encoding="UTF-8"?>
<cr:addressbook-query xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:prop>
    <d:getetag />
    <cr:address-data />
  </d:prop>
  <cr:filter>
    <cr:prop-filter name="UID">
      <cr:text-match collation="i;octet">${uid}</cr:text-match>
    </cr:prop-filter>
  </cr:filter>
</cr:addressbook-query>`;

  const response = await fetchCalDAV(cardDavUrl, {
    method: "REPORT",
    body: reportBody,
    headers: { Depth: "1" },
  });

  const responseText = await response.text();

  const hrefMatch = responseText.match(/<d:href>([^<]+)<\/d:href>/);
  const etagMatch = responseText.match(
    /<d:getetag>"?([^"<]+)"?<\/d:getetag>/,
  );
  const cardDataMatch = responseText.match(
    /<(?:[a-z0-9]+:)?address-data[^>]*>([\s\S]*?)<\/(?:[a-z0-9]+:)?address-data>/,
  );

  if (!hrefMatch || !etagMatch || !cardDataMatch) {
    throw new Error(
      `Contact with UID "${uid}" not found in address book "${addressBookName}"`,
    );
  }

  return {
    href: hrefMatch[1],
    etag: etagMatch[1],
    vcardData: cardDataMatch[1],
  };
}

// ---------------------------------------------------------------------------
// Tools
// ---------------------------------------------------------------------------

/**
 * List all address books available to the user.
 */
export const listAddressBooksTool = {
  name: "list_address_books",
  description:
    "List all address books available to the current user, including their display names and metadata.",
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const config = getNextcloudConfig();
      const cardDavUrl = `${config.url}/remote.php/dav/addressbooks/users/${config.user}/`;

      const propfindBody = `<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:prop>
    <d:resourcetype />
    <d:displayname />
    <cs:getctag />
  </d:prop>
</d:propfind>`;

      const response = await fetchCalDAV(cardDavUrl, {
        method: "PROPFIND",
        body: propfindBody,
        headers: { Depth: "1" },
      });

      const responseText = await response.text();
      const books = parseAddressBooks(responseText);

      if (books.length === 0) {
        return {
          content: [
            {
              type: "text" as const,
              text: "No address books found.",
            },
          ],
        };
      }

      const formatted = books
        .map((b) => `${b.displayName}\n    URL: ${b.url}`)
        .join("\n\n");
      return {
        content: [
          {
            type: "text" as const,
            text: `Address books (${books.length} found):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error listing address books: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * List contacts from an address book.
 */
export const listContactsTool = {
  name: "list_contacts",
  description:
    "List contacts from a Nextcloud address book. Optionally search by name. Returns name, email, phone, organization, and UID for each contact.",
  inputSchema: z.object({
    addressBookName: z
      .string()
      .default("contacts")
      .describe("The address book name (default: 'contacts')"),
    search: z
      .string()
      .optional()
      .describe("Search term to filter contacts by name (case-insensitive contains match)"),
    limit: z
      .number()
      .min(1)
      .max(200)
      .optional()
      .describe("Maximum number of contacts to return (default: 50)"),
  }),
  handler: async (args: {
    addressBookName: string;
    search?: string;
    limit?: number;
  }) => {
    try {
      const config = getNextcloudConfig();
      const cardDavUrl = `${config.url}/remote.php/dav/addressbooks/users/${config.user}/${args.addressBookName}/`;
      const limit = args.limit || 50;

      let filterXml = "";
      if (args.search) {
        filterXml = `
  <cr:filter>
    <cr:prop-filter name="FN">
      <cr:text-match collation="i;unicode-casemap" match-type="contains">${args.search}</cr:text-match>
    </cr:prop-filter>
  </cr:filter>`;
      }

      const reportBody = `<?xml version="1.0" encoding="UTF-8"?>
<cr:addressbook-query xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:prop>
    <d:getetag />
    <cr:address-data />
  </d:prop>${filterXml}
</cr:addressbook-query>`;

      const response = await fetchCalDAV(cardDavUrl, {
        method: "REPORT",
        body: reportBody,
        headers: { Depth: "1" },
      });

      const responseText = await response.text();
      let contacts = parseVCards(responseText);

      // Sort by full name
      contacts.sort((a, b) => a.fullName.localeCompare(b.fullName));

      // Apply limit
      if (contacts.length > limit) {
        contacts = contacts.slice(0, limit);
      }

      if (contacts.length === 0) {
        const msg = args.search
          ? `No contacts found matching "${args.search}" in "${args.addressBookName}".`
          : `No contacts found in "${args.addressBookName}".`;
        return {
          content: [{ type: "text" as const, text: msg }],
        };
      }

      const formatted = contacts.map(formatContact).join("\n\n");
      return {
        content: [
          {
            type: "text" as const,
            text: `Contacts in "${args.addressBookName}" (${contacts.length} found):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error listing contacts: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Get a single contact by UID.
 */
export const getContactTool = {
  name: "get_contact",
  description:
    "Get detailed information about a single contact by its UID, including all properties like name, email, phone, address, organization, birthday, notes, and groups.",
  inputSchema: z.object({
    uid: z.string().describe("The UID of the contact"),
    addressBookName: z
      .string()
      .default("contacts")
      .describe("The address book name (default: 'contacts')"),
  }),
  handler: async (args: { uid: string; addressBookName: string }) => {
    try {
      const { vcardData } = await resolveContactByUid(
        args.addressBookName,
        args.uid,
      );

      const contact = parseVCardBlock(unfoldVCardLines(vcardData));
      if (!contact) {
        throw new Error(`Contact with UID "${args.uid}" could not be parsed`);
      }

      return {
        content: [
          {
            type: "text" as const,
            text: formatContactDetail(contact),
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error getting contact: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Create a new contact.
 */
export const createContactTool = {
  name: "create_contact",
  description:
    "Create a new contact in a Nextcloud address book with name, email, phone, address, organization, and other properties.",
  inputSchema: z.object({
    fullName: z.string().describe("The contact's full display name (required)"),
    addressBookName: z
      .string()
      .default("contacts")
      .describe("The address book name (default: 'contacts')"),
    firstName: z.string().optional().describe("First/given name"),
    lastName: z.string().optional().describe("Last/family name"),
    prefix: z.string().optional().describe("Name prefix (e.g. Dr., Mr.)"),
    suffix: z.string().optional().describe("Name suffix (e.g. Jr., III)"),
    emails: z
      .array(
        z.object({
          value: z.string().describe("Email address"),
          type: z
            .enum(["HOME", "WORK", "OTHER"])
            .optional()
            .describe("Email type"),
        }),
      )
      .optional()
      .describe("Email addresses"),
    phones: z
      .array(
        z.object({
          value: z.string().describe("Phone number"),
          type: z
            .enum(["HOME", "WORK", "CELL", "FAX", "PAGER", "OTHER"])
            .optional()
            .describe("Phone type"),
        }),
      )
      .optional()
      .describe("Phone numbers"),
    addresses: z
      .array(
        z.object({
          street: z.string().optional().describe("Street address"),
          city: z.string().optional().describe("City"),
          region: z.string().optional().describe("State or province"),
          postalCode: z.string().optional().describe("Postal/ZIP code"),
          country: z.string().optional().describe("Country"),
          type: z
            .enum(["HOME", "WORK", "OTHER"])
            .optional()
            .describe("Address type"),
        }),
      )
      .optional()
      .describe("Physical addresses"),
    org: z.string().optional().describe("Organization name"),
    title: z.string().optional().describe("Job title"),
    note: z.string().optional().describe("Notes about the contact"),
    birthday: z
      .string()
      .optional()
      .describe("Birthday in YYYY-MM-DD or YYYYMMDD format"),
    url: z.string().optional().describe("Website URL"),
    categories: z
      .array(z.string())
      .optional()
      .describe("Groups/categories for the contact"),
  }),
  handler: async (args: {
    fullName: string;
    addressBookName: string;
    firstName?: string;
    lastName?: string;
    prefix?: string;
    suffix?: string;
    emails?: Array<{ value: string; type?: string }>;
    phones?: Array<{ value: string; type?: string }>;
    addresses?: Array<{
      street?: string;
      city?: string;
      region?: string;
      postalCode?: string;
      country?: string;
      type?: string;
    }>;
    org?: string;
    title?: string;
    note?: string;
    birthday?: string;
    url?: string;
    categories?: string[];
  }) => {
    try {
      const config = getNextcloudConfig();
      const contactUid = `${Date.now()}-${Math.random().toString(36).substring(7)}`;
      const cardDavUrl = `${config.url}/remote.php/dav/addressbooks/users/${config.user}/${args.addressBookName}/${contactUid}.vcf`;
      const now = new Date().toISOString().replace(/[-:]/g, "").split(".")[0] + "Z";

      // Build N property: family;given;additional;prefix;suffix
      const family = args.lastName ? escapeVCardValue(args.lastName) : "";
      const given = args.firstName ? escapeVCardValue(args.firstName) : "";
      const prefix = args.prefix ? escapeVCardValue(args.prefix) : "";
      const suffix = args.suffix ? escapeVCardValue(args.suffix) : "";

      let vcard = `BEGIN:VCARD\r\nVERSION:3.0\r\nPRODID:-//AIquila//MCP Server//EN\r\nUID:${contactUid}\r\nREV:${now}\r\nFN:${escapeVCardValue(args.fullName)}\r\nN:${family};${given};;${prefix};${suffix}`;

      if (args.emails) {
        for (const email of args.emails) {
          const typeParam = email.type ? `;TYPE=${email.type}` : "";
          vcard += `\r\nEMAIL${typeParam}:${email.value}`;
        }
      }

      if (args.phones) {
        for (const phone of args.phones) {
          const typeParam = phone.type ? `;TYPE=${phone.type}` : "";
          vcard += `\r\nTEL${typeParam}:${phone.value}`;
        }
      }

      if (args.addresses) {
        for (const addr of args.addresses) {
          const typeParam = addr.type ? `;TYPE=${addr.type}` : "";
          const street = addr.street ? escapeVCardValue(addr.street) : "";
          const city = addr.city ? escapeVCardValue(addr.city) : "";
          const region = addr.region ? escapeVCardValue(addr.region) : "";
          const postalCode = addr.postalCode ? escapeVCardValue(addr.postalCode) : "";
          const country = addr.country ? escapeVCardValue(addr.country) : "";
          vcard += `\r\nADR${typeParam}:;;${street};${city};${region};${postalCode};${country}`;
        }
      }

      if (args.org) {
        vcard += `\r\nORG:${escapeVCardValue(args.org)}`;
      }
      if (args.title) {
        vcard += `\r\nTITLE:${escapeVCardValue(args.title)}`;
      }
      if (args.note) {
        vcard += `\r\nNOTE:${escapeVCardValue(args.note)}`;
      }
      if (args.birthday) {
        vcard += `\r\nBDAY:${args.birthday}`;
      }
      if (args.url) {
        vcard += `\r\nURL:${args.url}`;
      }
      if (args.categories && args.categories.length > 0) {
        vcard += `\r\nCATEGORIES:${args.categories.map(escapeVCardValue).join(",")}`;
      }

      vcard += `\r\nEND:VCARD`;

      const response = await fetchCalDAV(cardDavUrl, {
        method: "PUT",
        body: vcard,
        headers: {
          "Content-Type": "text/vcard; charset=utf-8",
        },
      });

      if (response.ok) {
        return {
          content: [
            {
              type: "text" as const,
              text: `Contact created successfully: ${args.fullName}\n  UID: ${contactUid}`,
            },
          ],
        };
      } else {
        const errorText = await response.text();
        throw new Error(
          `Failed to create contact: ${response.status} - ${errorText}`,
        );
      }
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error creating contact: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Update an existing contact by UID.
 */
export const updateContactTool = {
  name: "update_contact",
  description:
    "Update an existing contact's fields by UID. Uses CardDAV ETag-based optimistic concurrency. Only provided fields are changed.",
  inputSchema: z.object({
    uid: z.string().describe("The UID of the contact to update"),
    addressBookName: z
      .string()
      .default("contacts")
      .describe("The address book name (default: 'contacts')"),
    fullName: z.string().optional().describe("New full display name"),
    firstName: z
      .string()
      .nullable()
      .optional()
      .describe("New first name, or null to clear"),
    lastName: z
      .string()
      .nullable()
      .optional()
      .describe("New last name, or null to clear"),
    emails: z
      .array(
        z.object({
          value: z.string().describe("Email address"),
          type: z
            .enum(["HOME", "WORK", "OTHER"])
            .optional()
            .describe("Email type"),
        }),
      )
      .optional()
      .describe("Replace all emails with these"),
    phones: z
      .array(
        z.object({
          value: z.string().describe("Phone number"),
          type: z
            .enum(["HOME", "WORK", "CELL", "FAX", "PAGER", "OTHER"])
            .optional()
            .describe("Phone type"),
        }),
      )
      .optional()
      .describe("Replace all phone numbers with these"),
    addresses: z
      .array(
        z.object({
          street: z.string().optional().describe("Street address"),
          city: z.string().optional().describe("City"),
          region: z.string().optional().describe("State or province"),
          postalCode: z.string().optional().describe("Postal/ZIP code"),
          country: z.string().optional().describe("Country"),
          type: z
            .enum(["HOME", "WORK", "OTHER"])
            .optional()
            .describe("Address type"),
        }),
      )
      .optional()
      .describe("Replace all addresses with these"),
    org: z
      .string()
      .nullable()
      .optional()
      .describe("New organization, or null to remove"),
    title: z
      .string()
      .nullable()
      .optional()
      .describe("New job title, or null to remove"),
    note: z
      .string()
      .nullable()
      .optional()
      .describe("New notes, or null to remove"),
    birthday: z
      .string()
      .nullable()
      .optional()
      .describe("New birthday (YYYY-MM-DD or YYYYMMDD), or null to remove"),
    url: z
      .string()
      .nullable()
      .optional()
      .describe("New website URL, or null to remove"),
    categories: z
      .array(z.string())
      .optional()
      .describe("Replace all groups/categories with these"),
  }),
  handler: async (args: {
    uid: string;
    addressBookName: string;
    fullName?: string;
    firstName?: string | null;
    lastName?: string | null;
    emails?: Array<{ value: string; type?: string }>;
    phones?: Array<{ value: string; type?: string }>;
    addresses?: Array<{
      street?: string;
      city?: string;
      region?: string;
      postalCode?: string;
      country?: string;
      type?: string;
    }>;
    org?: string | null;
    title?: string | null;
    note?: string | null;
    birthday?: string | null;
    url?: string | null;
    categories?: string[];
  }) => {
    try {
      const config = getNextcloudConfig();
      const { href, etag, vcardData } = await resolveContactByUid(
        args.addressBookName,
        args.uid,
      );

      let modified = unfoldVCardLines(vcardData);

      // Update FN
      if (args.fullName !== undefined) {
        modified = setVCardProperty(
          modified,
          "FN",
          escapeVCardValue(args.fullName),
        );
      }

      // Update N (structured name)
      if (args.firstName !== undefined || args.lastName !== undefined) {
        // Parse existing N property
        const nMatch = modified.match(/^N(;[^:]*)?:(.*)/mi);
        const existingParts = nMatch ? nMatch[2].split(";") : ["", "", "", "", ""];

        if (args.lastName !== undefined) {
          existingParts[0] = args.lastName ? escapeVCardValue(args.lastName) : "";
        }
        if (args.firstName !== undefined) {
          existingParts[1] = args.firstName ? escapeVCardValue(args.firstName) : "";
        }

        modified = setVCardProperty(
          modified,
          "N",
          existingParts.slice(0, 5).join(";"),
        );
      }

      // Update simple properties
      if (args.org !== undefined) {
        modified = setVCardProperty(
          modified,
          "ORG",
          args.org ? escapeVCardValue(args.org) : null,
        );
      }
      if (args.title !== undefined) {
        modified = setVCardProperty(
          modified,
          "TITLE",
          args.title ? escapeVCardValue(args.title) : null,
        );
      }
      if (args.note !== undefined) {
        modified = setVCardProperty(
          modified,
          "NOTE",
          args.note ? escapeVCardValue(args.note) : null,
        );
      }
      if (args.birthday !== undefined) {
        modified = setVCardProperty(modified, "BDAY", args.birthday);
      }
      if (args.url !== undefined) {
        modified = setVCardProperty(modified, "URL", args.url);
      }

      // Update multi-valued properties (replace all)
      if (args.emails !== undefined) {
        modified = removeAllVCardProperty(modified, "EMAIL");
        for (const email of args.emails) {
          const typeParam = email.type ? `;TYPE=${email.type}` : "";
          const line = `EMAIL${typeParam}:${email.value}`;
          modified = modified.replace(/END:VCARD/i, `${line}\r\nEND:VCARD`);
        }
      }

      if (args.phones !== undefined) {
        modified = removeAllVCardProperty(modified, "TEL");
        for (const phone of args.phones) {
          const typeParam = phone.type ? `;TYPE=${phone.type}` : "";
          const line = `TEL${typeParam}:${phone.value}`;
          modified = modified.replace(/END:VCARD/i, `${line}\r\nEND:VCARD`);
        }
      }

      if (args.addresses !== undefined) {
        modified = removeAllVCardProperty(modified, "ADR");
        for (const addr of args.addresses) {
          const typeParam = addr.type ? `;TYPE=${addr.type}` : "";
          const street = addr.street ? escapeVCardValue(addr.street) : "";
          const city = addr.city ? escapeVCardValue(addr.city) : "";
          const region = addr.region ? escapeVCardValue(addr.region) : "";
          const postalCode = addr.postalCode ? escapeVCardValue(addr.postalCode) : "";
          const country = addr.country ? escapeVCardValue(addr.country) : "";
          const line = `ADR${typeParam}:;;${street};${city};${region};${postalCode};${country}`;
          modified = modified.replace(/END:VCARD/i, `${line}\r\nEND:VCARD`);
        }
      }

      if (args.categories !== undefined) {
        modified = removeAllVCardProperty(modified, "CATEGORIES");
        if (args.categories.length > 0) {
          const line = `CATEGORIES:${args.categories.map(escapeVCardValue).join(",")}`;
          modified = modified.replace(/END:VCARD/i, `${line}\r\nEND:VCARD`);
        }
      }

      // Update REV timestamp
      const now = new Date().toISOString().replace(/[-:]/g, "").split(".")[0] + "Z";
      modified = setVCardProperty(modified, "REV", now);

      const putUrl = `${config.url}${href}`;
      const putResponse = await fetchCalDAV(putUrl, {
        method: "PUT",
        body: modified,
        headers: {
          "Content-Type": "text/vcard; charset=utf-8",
          "If-Match": `"${etag}"`,
        },
      });

      if (putResponse.ok) {
        return {
          content: [
            {
              type: "text" as const,
              text: `Contact updated successfully (UID: ${args.uid})`,
            },
          ],
        };
      } else if (putResponse.status === 412) {
        throw new Error(
          "Contact was modified by another client (ETag mismatch). Please retry.",
        );
      } else {
        const errorText = await putResponse.text();
        throw new Error(
          `Failed to update contact: ${putResponse.status} - ${errorText}`,
        );
      }
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error updating contact: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Delete a contact by UID.
 */
export const deleteContactTool = {
  name: "delete_contact",
  description:
    "Delete a contact from a Nextcloud address book by UID. This action is irreversible.",
  inputSchema: z.object({
    uid: z.string().describe("The UID of the contact to delete"),
    addressBookName: z
      .string()
      .default("contacts")
      .describe("The address book name (default: 'contacts')"),
  }),
  handler: async (args: { uid: string; addressBookName: string }) => {
    try {
      const config = getNextcloudConfig();
      const { href, etag } = await resolveContactByUid(
        args.addressBookName,
        args.uid,
      );

      const deleteUrl = `${config.url}${href}`;
      const response = await fetchCalDAV(deleteUrl, {
        method: "DELETE",
        headers: {
          "If-Match": `"${etag}"`,
        },
      });

      if (response.ok || response.status === 204) {
        return {
          content: [
            {
              type: "text" as const,
              text: `Contact deleted successfully (UID: ${args.uid})`,
            },
          ],
        };
      } else if (response.status === 412) {
        throw new Error(
          "Contact was modified by another client (ETag mismatch). Please retry.",
        );
      } else {
        const errorText = await response.text();
        throw new Error(
          `Failed to delete contact: ${response.status} - ${errorText}`,
        );
      }
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error deleting contact: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Export all Contacts app tools
 */
export const contactsTools = [
  listAddressBooksTool,
  listContactsTool,
  getContactTool,
  createContactTool,
  updateContactTool,
  deleteContactTool,
];
