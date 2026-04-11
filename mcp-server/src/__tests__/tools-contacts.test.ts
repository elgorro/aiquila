// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock declarations
vi.mock('webdav', () => ({ createClient: vi.fn(() => ({})) }));
global.fetch = vi.fn();

describe('Contacts Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_address_books', () => {
    it('should return formatted address book list', async () => {
      const propfindResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/</d:href>
    <d:propstat>
      <d:prop>
        <d:resourcetype><d:collection/><cr:addressbook/></d:resourcetype>
        <d:displayname>Contacts</d:displayname>
        <cs:getctag>ctag-123</cs:getctag>
      </d:prop>
    </d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/work/</d:href>
    <d:propstat>
      <d:prop>
        <d:resourcetype><d:collection/><cr:addressbook/></d:resourcetype>
        <d:displayname>Work</d:displayname>
        <cs:getctag>ctag-456</cs:getctag>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(propfindResponse),
      });

      const { listAddressBooksTool } = await import('../tools/apps/contacts.js');
      const result = await listAddressBooksTool.handler({});

      expect(result.content[0].text).toContain('Contacts');
      expect(result.content[0].text).toContain('Work');
      expect(result.content[0].text).toContain('2 found');
    });

    it('should handle no address books', async () => {
      const propfindResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/</d:href>
    <d:propstat>
      <d:prop>
        <d:resourcetype><d:collection/></d:resourcetype>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(propfindResponse),
      });

      const { listAddressBooksTool } = await import('../tools/apps/contacts.js');
      const result = await listAddressBooksTool.handler({});

      expect(result.content[0].text).toContain('No address books found');
    });
  });

  describe('list_contacts', () => {
    it('should return formatted contact list', async () => {
      const reportResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/contact-1.vcf</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>&quot;etag-1&quot;</d:getetag>
        <cr:address-data>BEGIN:VCARD
VERSION:3.0
UID:contact-1
FN:John Doe
N:Doe;John;;;
EMAIL;TYPE=WORK:john@example.com
TEL;TYPE=CELL:+1234567890
ORG:ACME Corp
TITLE:Engineer
END:VCARD</cr:address-data>
      </d:prop>
    </d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/contact-2.vcf</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>&quot;etag-2&quot;</d:getetag>
        <cr:address-data>BEGIN:VCARD
VERSION:3.0
UID:contact-2
FN:Jane Smith
N:Smith;Jane;;;
EMAIL;TYPE=HOME:jane@example.com
END:VCARD</cr:address-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(reportResponse),
      });

      const { listContactsTool } = await import('../tools/apps/contacts.js');
      const result = await listContactsTool.handler({ addressBookName: 'contacts' });

      expect(result.content[0].text).toContain('Jane Smith');
      expect(result.content[0].text).toContain('John Doe');
      expect(result.content[0].text).toContain('2 found');
      expect(result.content[0].text).toContain('john@example.com');
      expect(result.content[0].text).toContain('+1234567890');
      expect(result.content[0].text).toContain('ACME Corp');
      expect(result.content[0].text).toContain('UID: contact-1');
    });

    it('should handle empty address book', async () => {
      const reportResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(reportResponse),
      });

      const { listContactsTool } = await import('../tools/apps/contacts.js');
      const result = await listContactsTool.handler({ addressBookName: 'contacts' });

      expect(result.content[0].text).toContain('No contacts found');
    });
  });

  describe('get_contact', () => {
    it('should return full contact details', async () => {
      const reportResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/contact-1.vcf</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>&quot;etag-1&quot;</d:getetag>
        <cr:address-data>BEGIN:VCARD
VERSION:3.0
UID:contact-1
FN:John Doe
N:Doe;John;;Dr.;Jr.
EMAIL;TYPE=WORK:john@example.com
EMAIL;TYPE=HOME:john.doe@home.com
TEL;TYPE=CELL:+1234567890
TEL;TYPE=WORK:+0987654321
ADR;TYPE=WORK:;;123 Main St;Springfield;IL;62701;USA
ORG:ACME Corp
TITLE:Senior Engineer
NOTE:Important client contact
BDAY:1990-06-15
URL:https://johndoe.example.com
CATEGORIES:Friends,Work
END:VCARD</cr:address-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(reportResponse),
      });

      const { getContactTool } = await import('../tools/apps/contacts.js');
      const result = await getContactTool.handler({
        uid: 'contact-1',
        addressBookName: 'contacts',
      });

      expect(result.content[0].text).toContain('Name: John Doe');
      expect(result.content[0].text).toContain('Dr. John Doe Jr.');
      expect(result.content[0].text).toContain('ACME Corp');
      expect(result.content[0].text).toContain('Senior Engineer');
      expect(result.content[0].text).toContain('john@example.com');
      expect(result.content[0].text).toContain('john.doe@home.com');
      expect(result.content[0].text).toContain('+1234567890');
      expect(result.content[0].text).toContain('+0987654321');
      expect(result.content[0].text).toContain('123 Main St');
      expect(result.content[0].text).toContain('Springfield');
      expect(result.content[0].text).toContain('Important client contact');
      expect(result.content[0].text).toContain('1990-06-15');
      expect(result.content[0].text).toContain('https://johndoe.example.com');
      expect(result.content[0].text).toContain('Friends, Work');
    });

    it('should handle contact not found', async () => {
      const reportResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(reportResponse),
      });

      const { getContactTool } = await import('../tools/apps/contacts.js');
      const result = await getContactTool.handler({
        uid: 'nonexistent',
        addressBookName: 'contacts',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('create_contact', () => {
    it('should create a vCard with all fields', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 201,
        text: () => Promise.resolve(''),
      });

      const { createContactTool } = await import('../tools/apps/contacts.js');
      const result = await createContactTool.handler({
        fullName: 'John Doe',
        addressBookName: 'contacts',
        firstName: 'John',
        lastName: 'Doe',
        emails: [{ value: 'john@example.com', type: 'WORK' }],
        phones: [{ value: '+1234567890', type: 'CELL' }],
        org: 'ACME Corp',
        title: 'Engineer',
        note: 'Test contact',
        categories: ['Friends'],
      });

      expect(result.content[0].text).toContain('Contact created successfully');
      expect(result.content[0].text).toContain('John Doe');

      // Verify the PUT request body
      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      const body = putCall[1].body;
      expect(body).toContain('BEGIN:VCARD');
      expect(body).toContain('VERSION:3.0');
      expect(body).toContain('FN:John Doe');
      expect(body).toContain('N:Doe;John;;');
      expect(body).toContain('EMAIL;TYPE=WORK:john@example.com');
      expect(body).toContain('TEL;TYPE=CELL:+1234567890');
      expect(body).toContain('ORG:ACME Corp');
      expect(body).toContain('TITLE:Engineer');
      expect(body).toContain('NOTE:Test contact');
      expect(body).toContain('CATEGORIES:Friends');
      expect(body).toContain('END:VCARD');
    });

    it('should create a minimal vCard with only full name', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 201,
        text: () => Promise.resolve(''),
      });

      const { createContactTool } = await import('../tools/apps/contacts.js');
      const result = await createContactTool.handler({
        fullName: 'Simple Contact',
        addressBookName: 'contacts',
      });

      expect(result.content[0].text).toContain('Contact created successfully');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      const body = putCall[1].body;
      expect(body).toContain('FN:Simple Contact');
      expect(body).toContain('N:;;;');
      expect(body).not.toContain('EMAIL');
      expect(body).not.toContain('TEL');
    });
  });

  describe('update_contact', () => {
    it('should update contact fields with ETag concurrency', async () => {
      const resolveResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/contact-1.vcf</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>&quot;etag-old&quot;</d:getetag>
        <cr:address-data>BEGIN:VCARD
VERSION:3.0
UID:contact-1
FN:John Doe
N:Doe;John;;;
EMAIL;TYPE=WORK:john@example.com
ORG:Old Corp
END:VCARD</cr:address-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      let callCount = 0;
      (global.fetch as ReturnType<typeof vi.fn>).mockImplementation(() => {
        callCount++;
        if (callCount === 1) {
          return Promise.resolve({
            ok: true,
            text: () => Promise.resolve(resolveResponse),
          });
        }
        return Promise.resolve({
          ok: true,
          status: 204,
          text: () => Promise.resolve(''),
        });
      });

      const { updateContactTool } = await import('../tools/apps/contacts.js');
      const result = await updateContactTool.handler({
        uid: 'contact-1',
        addressBookName: 'contacts',
        org: 'New Corp',
        title: 'CTO',
      });

      expect(result.content[0].text).toContain('Contact updated successfully');

      // Verify the PUT request
      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].headers['If-Match']).toBe('"etag-old"');
      const body = putCall[1].body;
      expect(body).toContain('ORG:New Corp');
      expect(body).toContain('TITLE:CTO');
      expect(body).toContain('FN:John Doe');
    });

    it('should normalize &quot;-encoded ETags from XML', async () => {
      // NC/libxml2 encodes " as &quot; in XML text content.
      // The ETag in <d:getetag>&quot;abc&quot;</d:getetag> must become "abc" (single quotes).
      const resolveResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/contact-1.vcf</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>&quot;entity-encoded-etag&quot;</d:getetag>
        <cr:address-data>BEGIN:VCARD
VERSION:3.0
UID:contact-1
FN:Test
END:VCARD</cr:address-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      let callCount = 0;
      (global.fetch as ReturnType<typeof vi.fn>).mockImplementation(() => {
        callCount++;
        if (callCount === 1) {
          return Promise.resolve({
            ok: true,
            text: () => Promise.resolve(resolveResponse),
          });
        }
        return Promise.resolve({
          ok: true,
          status: 204,
          text: () => Promise.resolve(''),
        });
      });

      const { updateContactTool } = await import('../tools/apps/contacts.js');
      const result = await updateContactTool.handler({
        uid: 'contact-1',
        addressBookName: 'contacts',
        fullName: 'Updated',
      });

      expect(result.content[0].text).toContain('Contact updated successfully');

      // Verify single-layer quoting in If-Match (not double-quoted)
      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].headers['If-Match']).toBe('"entity-encoded-etag"');
    });

    it('should handle ETag conflict', async () => {
      const resolveResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/contact-1.vcf</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>&quot;etag-old&quot;</d:getetag>
        <cr:address-data>BEGIN:VCARD
VERSION:3.0
UID:contact-1
FN:John Doe
END:VCARD</cr:address-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      let callCount = 0;
      (global.fetch as ReturnType<typeof vi.fn>).mockImplementation(() => {
        callCount++;
        if (callCount === 1) {
          return Promise.resolve({
            ok: true,
            text: () => Promise.resolve(resolveResponse),
          });
        }
        return Promise.resolve({
          ok: false,
          status: 412,
          text: () => Promise.resolve('Precondition Failed'),
        });
      });

      const { updateContactTool } = await import('../tools/apps/contacts.js');
      const result = await updateContactTool.handler({
        uid: 'contact-1',
        addressBookName: 'contacts',
        fullName: 'Updated Name',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('ETag mismatch');
    });
  });

  describe('delete_contact', () => {
    it('should delete a contact', async () => {
      const resolveResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/contact-1.vcf</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>&quot;etag-1&quot;</d:getetag>
        <cr:address-data>BEGIN:VCARD
VERSION:3.0
UID:contact-1
FN:John Doe
END:VCARD</cr:address-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      let callCount = 0;
      (global.fetch as ReturnType<typeof vi.fn>).mockImplementation(() => {
        callCount++;
        if (callCount === 1) {
          return Promise.resolve({
            ok: true,
            text: () => Promise.resolve(resolveResponse),
          });
        }
        return Promise.resolve({
          ok: true,
          status: 204,
          text: () => Promise.resolve(''),
        });
      });

      const { deleteContactTool } = await import('../tools/apps/contacts.js');
      const result = await deleteContactTool.handler({
        uid: 'contact-1',
        addressBookName: 'contacts',
      });

      expect(result.content[0].text).toContain('Contact deleted successfully');

      // Verify DELETE was called with correct ETag
      const deleteCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(deleteCall[1].method).toBe('DELETE');
      expect(deleteCall[1].headers['If-Match']).toBe('"etag-1"');
    });
  });
});
