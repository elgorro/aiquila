import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock fetch for CalDAV
global.fetch = vi.fn();

describe('Tag Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('get_file_tags', () => {
    it('should return tags for a file', async () => {
      const propfindResponse = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/files/testuser/Photos/test.png</d:href>
    <d:propstat>
      <d:prop>
        <oc:tags>
          <oc:tag>Wallpapers</oc:tag>
          <oc:tag>Nature</oc:tag>
        </oc:tags>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve(propfindResponse),
      });

      const { getFileTagsTool } = await import('../tools/system/tags.js');
      const result = await getFileTagsTool.handler({ path: '/Photos/test.png' });

      expect(result.content[0].text).toContain('Wallpapers');
      expect(result.content[0].text).toContain('Nature');
      expect(result.isError).toBeUndefined();
    });

    it('should handle file with no tags', async () => {
      const propfindResponse = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/files/testuser/Photos/test.png</d:href>
    <d:propstat>
      <d:prop>
        <oc:tags />
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve(propfindResponse),
      });

      const { getFileTagsTool } = await import('../tools/system/tags.js');
      const result = await getFileTagsTool.handler({ path: '/Photos/test.png' });

      expect(result.content[0].text).toContain('No tags found');
    });

    it('should strip leading slash from path', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve('<oc:tags />'),
      });

      const { getFileTagsTool } = await import('../tools/system/tags.js');
      await getFileTagsTool.handler({ path: '/Photos/test.png' });

      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining('/remote.php/dav/files/testuser/Photos/test.png'),
        expect.any(Object)
      );
    });

    it('should return error on HTTP failure', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: false,
        status: 404,
        statusText: 'Not Found',
        text: () => Promise.resolve('Not Found'),
      });

      const { getFileTagsTool } = await import('../tools/system/tags.js');
      const result = await getFileTagsTool.handler({ path: '/nonexistent.txt' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error getting tags');
      expect(result.content[0].text).toContain('404');
    });

    it('should handle network errors', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Connection refused'));

      const { getFileTagsTool } = await import('../tools/system/tags.js');
      const result = await getFileTagsTool.handler({ path: '/Photos/test.png' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error getting file tags');
      expect(result.content[0].text).toContain('Connection refused');
    });
  });

  describe('set_file_tags', () => {
    it('should set tags on a file', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve(''),
      });

      const { setFileTagsTool } = await import('../tools/system/tags.js');
      const result = await setFileTagsTool.handler({
        path: '/Photos/test.png',
        tags: ['Wallpapers', 'Nature'],
      });

      expect(result.content[0].text).toContain('Tags set on /Photos/test.png');
      expect(result.content[0].text).toContain('Wallpapers');
      expect(result.content[0].text).toContain('Nature');
      expect(result.isError).toBeUndefined();
    });

    it('should send PROPPATCH with tag elements in body', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve(''),
      });

      const { setFileTagsTool } = await import('../tools/system/tags.js');
      await setFileTagsTool.handler({
        path: '/Photos/test.png',
        tags: ['TagA', 'TagB'],
      });

      const callArgs = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      const body = callArgs[1].body as string;
      expect(body).toContain('<oc:tag>TagA</oc:tag>');
      expect(body).toContain('<oc:tag>TagB</oc:tag>');
      expect(callArgs[1].method).toBe('PROPPATCH');
    });

    it('should clear all tags when given empty array', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve(''),
      });

      const { setFileTagsTool } = await import('../tools/system/tags.js');
      const result = await setFileTagsTool.handler({
        path: '/Photos/test.png',
        tags: [],
      });

      expect(result.content[0].text).toContain('All tags cleared');
    });

    it('should return error on HTTP failure', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: false,
        status: 500,
        statusText: 'Internal Server Error',
        text: () => Promise.resolve('Server error'),
      });

      const { setFileTagsTool } = await import('../tools/system/tags.js');
      const result = await setFileTagsTool.handler({
        path: '/Photos/test.png',
        tags: ['Tag1'],
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error setting tags');
      expect(result.content[0].text).toContain('500');
    });

    it('should handle network errors', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Timeout'));

      const { setFileTagsTool } = await import('../tools/system/tags.js');
      const result = await setFileTagsTool.handler({
        path: '/Photos/test.png',
        tags: ['Tag1'],
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error setting file tags');
      expect(result.content[0].text).toContain('Timeout');
    });
  });

  describe('assign_system_tag', () => {
    it('should assign a system tag to a file', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 200,
        text: () => Promise.resolve(''),
      });

      const { assignSystemTagTool } = await import('../tools/system/tags.js');
      const result = await assignSystemTagTool.handler({ fileId: 42, tagId: 7 });

      expect(result.content[0].text).toBe('System tag 7 assigned to file 42');
      expect(result.isError).toBeUndefined();
    });

    it('should use PUT method with correct URL', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 200,
        text: () => Promise.resolve(''),
      });

      const { assignSystemTagTool } = await import('../tools/system/tags.js');
      await assignSystemTagTool.handler({ fileId: 42, tagId: 7 });

      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining('/remote.php/dav/systemtags-relations/files/42/7'),
        expect.objectContaining({ method: 'PUT' })
      );
    });

    it('should treat 409 conflict as success (tag already assigned)', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: false,
        status: 409,
        statusText: 'Conflict',
        text: () => Promise.resolve('Already assigned'),
      });

      const { assignSystemTagTool } = await import('../tools/system/tags.js');
      const result = await assignSystemTagTool.handler({ fileId: 42, tagId: 7 });

      expect(result.isError).toBeUndefined();
      expect(result.content[0].text).toBe('System tag 7 assigned to file 42');
    });

    it('should return error on other HTTP failures', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: false,
        status: 404,
        statusText: 'Not Found',
        text: () => Promise.resolve('File not found'),
      });

      const { assignSystemTagTool } = await import('../tools/system/tags.js');
      const result = await assignSystemTagTool.handler({ fileId: 999, tagId: 7 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error assigning system tag');
      expect(result.content[0].text).toContain('404');
    });

    it('should handle network errors', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('ECONNREFUSED'));

      const { assignSystemTagTool } = await import('../tools/system/tags.js');
      const result = await assignSystemTagTool.handler({ fileId: 42, tagId: 7 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error assigning system tag');
      expect(result.content[0].text).toContain('ECONNREFUSED');
    });
  });

  describe('remove_system_tag', () => {
    it('should remove a system tag from a file', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 204,
        text: () => Promise.resolve(''),
      });

      const { removeSystemTagTool } = await import('../tools/system/tags.js');
      const result = await removeSystemTagTool.handler({ fileId: 42, tagId: 7 });

      expect(result.content[0].text).toBe('System tag 7 removed from file 42');
      expect(result.isError).toBeUndefined();
    });

    it('should use DELETE method with correct URL', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 204,
        text: () => Promise.resolve(''),
      });

      const { removeSystemTagTool } = await import('../tools/system/tags.js');
      await removeSystemTagTool.handler({ fileId: 42, tagId: 7 });

      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining('/remote.php/dav/systemtags-relations/files/42/7'),
        expect.objectContaining({ method: 'DELETE' })
      );
    });

    it('should return error on HTTP failure', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: false,
        status: 404,
        statusText: 'Not Found',
        text: () => Promise.resolve('Tag not found'),
      });

      const { removeSystemTagTool } = await import('../tools/system/tags.js');
      const result = await removeSystemTagTool.handler({ fileId: 42, tagId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error removing system tag');
      expect(result.content[0].text).toContain('404');
    });

    it('should handle network errors', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Network error'));

      const { removeSystemTagTool } = await import('../tools/system/tags.js');
      const result = await removeSystemTagTool.handler({ fileId: 42, tagId: 7 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error removing system tag');
      expect(result.content[0].text).toContain('Network error');
    });
  });

  describe('list_system_tags', () => {
    it('should return formatted list of system tags', async () => {
      const propfindResponse = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/systemtags/</d:href>
    <d:propstat><d:prop></d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/systemtags/1</d:href>
    <d:propstat><d:prop>
      <oc:id>1</oc:id>
      <oc:display-name>Important</oc:display-name>
      <oc:user-visible>true</oc:user-visible>
      <oc:user-assignable>true</oc:user-assignable>
      <oc:can-assign>true</oc:can-assign>
    </d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/systemtags/2</d:href>
    <d:propstat><d:prop>
      <oc:id>2</oc:id>
      <oc:display-name>Archive</oc:display-name>
      <oc:user-visible>true</oc:user-visible>
      <oc:user-assignable>false</oc:user-assignable>
      <oc:can-assign>false</oc:can-assign>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve(propfindResponse),
      });

      const { listSystemTagsTool } = await import('../tools/system/tags.js');
      const result = await listSystemTagsTool.handler();

      expect(result.content[0].text).toContain('System tags (2)');
      expect(result.content[0].text).toContain('ID: 1');
      expect(result.content[0].text).toContain('"Important"');
      expect(result.content[0].text).toContain('ID: 2');
      expect(result.content[0].text).toContain('"Archive"');
      expect(result.content[0].text).toContain('Assignable: false');
      expect(result.isError).toBeUndefined();
    });

    it('should handle no system tags', async () => {
      const emptyResponse = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/systemtags/</d:href>
    <d:propstat><d:prop></d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve(emptyResponse),
      });

      const { listSystemTagsTool } = await import('../tools/system/tags.js');
      const result = await listSystemTagsTool.handler();

      expect(result.content[0].text).toBe('No system tags found.');
    });

    it('should use PROPFIND with Depth 1', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve('<d:multistatus xmlns:d="DAV:"></d:multistatus>'),
      });

      const { listSystemTagsTool } = await import('../tools/system/tags.js');
      await listSystemTagsTool.handler();

      const callArgs = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      expect(callArgs[1].method).toBe('PROPFIND');
      expect(callArgs[1].headers).toEqual(expect.objectContaining({ Depth: '1' }));
    });

    it('should return error on HTTP failure', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: false,
        status: 500,
        statusText: 'Internal Server Error',
        text: () => Promise.resolve('Server error'),
      });

      const { listSystemTagsTool } = await import('../tools/system/tags.js');
      const result = await listSystemTagsTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error listing system tags');
      expect(result.content[0].text).toContain('500');
    });

    it('should handle network errors', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('DNS failure'));

      const { listSystemTagsTool } = await import('../tools/system/tags.js');
      const result = await listSystemTagsTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error listing system tags');
      expect(result.content[0].text).toContain('DNS failure');
    });

    it('should default missing properties to true', async () => {
      const propfindResponse = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/systemtags/5</d:href>
    <d:propstat><d:prop>
      <oc:id>5</oc:id>
      <oc:display-name>Minimal</oc:display-name>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve(propfindResponse),
      });

      const { listSystemTagsTool } = await import('../tools/system/tags.js');
      const result = await listSystemTagsTool.handler();

      expect(result.content[0].text).toContain('Visible: true');
      expect(result.content[0].text).toContain('Assignable: true');
      expect(result.content[0].text).toContain('CanAssign: true');
    });
  });

  describe('create_system_tag', () => {
    it('should create a system tag and return its ID', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 201,
        headers: new Map([['Content-Location', '/remote.php/dav/systemtags/42']]),
        text: () => Promise.resolve(''),
      });

      const { createSystemTagTool } = await import('../tools/system/tags.js');
      const result = await createSystemTagTool.handler({
        name: 'NewTag',
        userVisible: true,
        userAssignable: true,
      });

      expect(result.content[0].text).toContain('"NewTag"');
      expect(result.content[0].text).toContain('ID: 42');
      expect(result.isError).toBeUndefined();
    });

    it('should send POST with JSON body', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 201,
        headers: new Map([['Content-Location', '/remote.php/dav/systemtags/1']]),
        text: () => Promise.resolve(''),
      });

      const { createSystemTagTool } = await import('../tools/system/tags.js');
      await createSystemTagTool.handler({
        name: 'TestTag',
        userVisible: false,
        userAssignable: false,
      });

      const callArgs = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      expect(callArgs[1].method).toBe('POST');
      const body = JSON.parse(callArgs[1].body as string);
      expect(body.name).toBe('TestTag');
      expect(body.userVisible).toBe(false);
      expect(body.userAssignable).toBe(false);
      expect(body.canAssign).toBe(true);
    });

    it('should handle missing Content-Location header', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 201,
        headers: new Map(),
        text: () => Promise.resolve(''),
      });

      const { createSystemTagTool } = await import('../tools/system/tags.js');
      const result = await createSystemTagTool.handler({
        name: 'NoHeader',
        userVisible: true,
        userAssignable: true,
      });

      expect(result.content[0].text).toContain('"NoHeader"');
      expect(result.content[0].text).toContain('ID: unknown');
    });

    it('should return error on HTTP failure', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: false,
        status: 409,
        statusText: 'Conflict',
        text: () => Promise.resolve('Tag already exists'),
      });

      const { createSystemTagTool } = await import('../tools/system/tags.js');
      const result = await createSystemTagTool.handler({
        name: 'Duplicate',
        userVisible: true,
        userAssignable: true,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error creating system tag');
      expect(result.content[0].text).toContain('409');
    });

    it('should handle network errors', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Socket hang up'));

      const { createSystemTagTool } = await import('../tools/system/tags.js');
      const result = await createSystemTagTool.handler({
        name: 'FailTag',
        userVisible: true,
        userAssignable: true,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error creating system tag');
      expect(result.content[0].text).toContain('Socket hang up');
    });
  });
});
