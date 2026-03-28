import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the CalDAV client module
const mockFetchCalDAV = vi.fn();
vi.mock('../client/caldav.js', () => ({
  fetchCalDAV: (...args: unknown[]) => mockFetchCalDAV(...args),
  nsTagContent: (localName: string) =>
    new RegExp(
      `<(?:[a-zA-Z][a-zA-Z0-9]*:)?${localName}[^>]*>([\\s\\S]*?)</(?:[a-zA-Z][a-zA-Z0-9]*:)?${localName}>`
    ),
  decodeXmlEntities: (text: string) =>
    text
      .replace(/&quot;/g, '"')
      .replace(/&apos;/g, "'")
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&amp;/g, '&'),
}));

function mockResponse(status: number, body: string, statusText = 'OK'): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    statusText,
    text: async () => body,
    headers: new Headers(),
  } as unknown as Response;
}

describe('Photos Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  // -------------------------------------------------------------------------
  // photos_list_albums
  // -------------------------------------------------------------------------

  describe('photos_list_albums', () => {
    it('should return formatted list of albums', async () => {
      const xml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/photos/admin/albums/</d:href>
    <d:propstat><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/photos/admin/albums/Vacation/</d:href>
    <d:propstat><d:prop>
      <d:displayname>Vacation</d:displayname>
      <nc:nbItems>42</nc:nbItems>
      <nc:location>Paris</nc:location>
      <nc:dateRange>{"start":1700000000,"end":1700500000}</nc:dateRange>
    </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/photos/admin/albums/Wildlife/</d:href>
    <d:propstat><d:prop>
      <d:displayname>Wildlife</d:displayname>
      <nc:nbItems>15</nc:nbItems>
      <nc:location></nc:location>
      <nc:dateRange></nc:dateRange>
    </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
</d:multistatus>`;

      mockFetchCalDAV.mockResolvedValue(mockResponse(207, xml));

      const { photosListAlbumsTool } = await import('../tools/apps/photos.js');
      const result = await photosListAlbumsTool.handler({});

      expect(result.content[0].text).toContain('Albums (2)');
      expect(result.content[0].text).toContain('Vacation');
      expect(result.content[0].text).toContain('42 items');
      expect(result.content[0].text).toContain('Paris');
      expect(result.content[0].text).toContain('Wildlife');
      expect(result.content[0].text).toContain('15 items');
    });

    it('should handle empty album list', async () => {
      const xml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/photos/admin/albums/</d:href>
    <d:propstat><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
</d:multistatus>`;

      mockFetchCalDAV.mockResolvedValue(mockResponse(207, xml));

      const { photosListAlbumsTool } = await import('../tools/apps/photos.js');
      const result = await photosListAlbumsTool.handler({});

      expect(result.content[0].text).toBe('No albums found.');
    });

    it('should handle API error', async () => {
      mockFetchCalDAV.mockRejectedValue(new Error('Network error'));

      const { photosListAlbumsTool } = await import('../tools/apps/photos.js');
      const result = await photosListAlbumsTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Network error');
    });

    it('should call correct URL with PROPFIND', async () => {
      mockFetchCalDAV.mockResolvedValue(
        mockResponse(207, '<d:multistatus xmlns:d="DAV:"></d:multistatus>')
      );

      const { photosListAlbumsTool } = await import('../tools/apps/photos.js');
      await photosListAlbumsTool.handler({});

      expect(mockFetchCalDAV).toHaveBeenCalledWith(
        'https://cloud.example.com/remote.php/dav/photos/admin/albums/',
        expect.objectContaining({
          method: 'PROPFIND',
          headers: { Depth: '1' },
        })
      );
    });
  });

  // -------------------------------------------------------------------------
  // photos_get_album
  // -------------------------------------------------------------------------

  describe('photos_get_album', () => {
    it('should return album details with files', async () => {
      const xml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/photos/admin/albums/Vacation/</d:href>
    <d:propstat><d:prop>
      <d:displayname>Vacation</d:displayname>
    </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/photos/admin/albums/Vacation/12345-sunset.jpg</d:href>
    <d:propstat><d:prop>
      <oc:fileid>12345</oc:fileid>
      <d:displayname>sunset.jpg</d:displayname>
      <d:getcontenttype>image/jpeg</d:getcontenttype>
      <d:getcontentlength>2097152</d:getcontentlength>
      <nc:favorite>0</nc:favorite>
    </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/photos/admin/albums/Vacation/67890-video.mp4</d:href>
    <d:propstat><d:prop>
      <oc:fileid>67890</oc:fileid>
      <d:displayname>video.mp4</d:displayname>
      <d:getcontenttype>video/mp4</d:getcontenttype>
      <d:getcontentlength>52428800</d:getcontentlength>
      <nc:favorite>1</nc:favorite>
    </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
</d:multistatus>`;

      mockFetchCalDAV.mockResolvedValue(mockResponse(207, xml));

      const { photosGetAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosGetAlbumTool.handler({ name: 'Vacation' });

      expect(result.content[0].text).toContain('# Vacation');
      expect(result.content[0].text).toContain('Files: 2');
      expect(result.content[0].text).toContain('[12345] sunset.jpg');
      expect(result.content[0].text).toContain('image/jpeg');
      expect(result.content[0].text).toContain('[67890] video.mp4');
      expect(result.content[0].text).toContain('*'); // favorite marker
    });

    it('should handle empty album', async () => {
      const xml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/photos/admin/albums/Empty/</d:href>
    <d:propstat><d:prop>
      <d:displayname>Empty</d:displayname>
    </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
</d:multistatus>`;

      mockFetchCalDAV.mockResolvedValue(mockResponse(207, xml));

      const { photosGetAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosGetAlbumTool.handler({ name: 'Empty' });

      expect(result.content[0].text).toContain('# Empty');
      expect(result.content[0].text).toContain('Files: 0');
      expect(result.content[0].text).toContain('(empty album)');
    });

    it('should handle API error', async () => {
      mockFetchCalDAV.mockRejectedValue(new Error('Connection refused'));

      const { photosGetAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosGetAlbumTool.handler({ name: 'Test' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Connection refused');
    });
  });

  // -------------------------------------------------------------------------
  // photos_create_album
  // -------------------------------------------------------------------------

  describe('photos_create_album', () => {
    it('should create album successfully', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(201, ''));

      const { photosCreateAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosCreateAlbumTool.handler({ name: 'New Album' });

      expect(result.content[0].text).toContain('Album "New Album" created');
      expect(mockFetchCalDAV).toHaveBeenCalledWith(
        'https://cloud.example.com/remote.php/dav/photos/admin/albums/New%20Album',
        expect.objectContaining({ method: 'MKCOL' })
      );
    });

    it('should handle album already exists', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(405, '', 'Method Not Allowed'));

      const { photosCreateAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosCreateAlbumTool.handler({ name: 'Existing' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('already exists');
    });

    it('should handle API error', async () => {
      mockFetchCalDAV.mockRejectedValue(new Error('Server error'));

      const { photosCreateAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosCreateAlbumTool.handler({ name: 'Test' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Server error');
    });
  });

  // -------------------------------------------------------------------------
  // photos_delete_album
  // -------------------------------------------------------------------------

  describe('photos_delete_album', () => {
    it('should delete album successfully', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(204, ''));

      const { photosDeleteAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosDeleteAlbumTool.handler({ name: 'Old Album' });

      expect(result.content[0].text).toContain('Album "Old Album" deleted');
    });

    it('should handle album not found', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(404, '', 'Not Found'));

      const { photosDeleteAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosDeleteAlbumTool.handler({ name: 'Missing' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  // -------------------------------------------------------------------------
  // photos_rename_album
  // -------------------------------------------------------------------------

  describe('photos_rename_album', () => {
    it('should rename album successfully', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(201, ''));

      const { photosRenameAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosRenameAlbumTool.handler({ name: 'Old', newName: 'New' });

      expect(result.content[0].text).toContain('renamed from "Old" to "New"');
      expect(mockFetchCalDAV).toHaveBeenCalledWith(
        'https://cloud.example.com/remote.php/dav/photos/admin/albums/Old',
        expect.objectContaining({
          method: 'MOVE',
          headers: expect.objectContaining({
            Destination: 'https://cloud.example.com/remote.php/dav/photos/admin/albums/New',
            Overwrite: 'F',
          }),
        })
      );
    });

    it('should handle album not found', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(404, '', 'Not Found'));

      const { photosRenameAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosRenameAlbumTool.handler({
        name: 'Missing',
        newName: 'New',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });

    it('should handle destination exists (412)', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(412, '', 'Precondition Failed'));

      const { photosRenameAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosRenameAlbumTool.handler({
        name: 'Old',
        newName: 'Existing',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('already exists');
    });
  });

  // -------------------------------------------------------------------------
  // photos_add_to_album
  // -------------------------------------------------------------------------

  describe('photos_add_to_album', () => {
    it('should add files to album', async () => {
      // First call: PROPFIND to get file ID
      const propfindXml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:response>
    <d:propstat><d:prop><oc:fileid>12345</oc:fileid></d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      mockFetchCalDAV
        .mockResolvedValueOnce(mockResponse(207, propfindXml)) // PROPFIND for file ID
        .mockResolvedValueOnce(mockResponse(201, '')); // COPY to album

      const { photosAddToAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosAddToAlbumTool.handler({
        albumName: 'Vacation',
        filePaths: ['/Photos/sunset.jpg'],
      });

      expect(result.content[0].text).toContain('Added 1/1');
      expect(result.content[0].text).toContain('OK');

      // Verify COPY call has correct destination
      const copyCall = mockFetchCalDAV.mock.calls[1];
      expect(copyCall[1].method).toBe('COPY');
      expect(copyCall[1].headers.Destination).toContain('/albums/Vacation/12345-sunset.jpg');
    });

    it('should handle file not found', async () => {
      mockFetchCalDAV.mockResolvedValueOnce(mockResponse(404, '', 'Not Found'));

      const { photosAddToAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosAddToAlbumTool.handler({
        albumName: 'Vacation',
        filePaths: ['/Photos/missing.jpg'],
      });

      expect(result.content[0].text).toContain('Added 0/1');
      expect(result.content[0].text).toContain('FAIL');
    });

    it('should handle file already in album (409)', async () => {
      const propfindXml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:response>
    <d:propstat><d:prop><oc:fileid>12345</oc:fileid></d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      mockFetchCalDAV
        .mockResolvedValueOnce(mockResponse(207, propfindXml))
        .mockResolvedValueOnce(mockResponse(409, '', 'Conflict'));

      const { photosAddToAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosAddToAlbumTool.handler({
        albumName: 'Vacation',
        filePaths: ['/Photos/sunset.jpg'],
      });

      expect(result.content[0].text).toContain('SKIP');
      expect(result.content[0].text).toContain('already in album');
    });

    it('should handle multiple files with mixed results', async () => {
      const propfindOk = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:response>
    <d:propstat><d:prop><oc:fileid>111</oc:fileid></d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      mockFetchCalDAV
        .mockResolvedValueOnce(mockResponse(207, propfindOk)) // PROPFIND file 1
        .mockResolvedValueOnce(mockResponse(201, '')) // COPY file 1 OK
        .mockResolvedValueOnce(mockResponse(404, '', 'Not Found')); // PROPFIND file 2 fails

      const { photosAddToAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosAddToAlbumTool.handler({
        albumName: 'Album',
        filePaths: ['/Photos/a.jpg', '/Photos/missing.jpg'],
      });

      expect(result.content[0].text).toContain('Added 1/2');
      expect(result.content[0].text).toContain('OK   /Photos/a.jpg');
      expect(result.content[0].text).toContain('FAIL /Photos/missing.jpg');
    });
  });

  // -------------------------------------------------------------------------
  // photos_remove_from_album
  // -------------------------------------------------------------------------

  describe('photos_remove_from_album', () => {
    it('should remove files from album', async () => {
      const albumXml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/photos/admin/albums/Vacation/</d:href>
    <d:propstat><d:prop><d:displayname>Vacation</d:displayname></d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/photos/admin/albums/Vacation/12345-sunset.jpg</d:href>
    <d:propstat><d:prop><oc:fileid>12345</oc:fileid></d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      mockFetchCalDAV
        .mockResolvedValueOnce(mockResponse(207, albumXml)) // PROPFIND album
        .mockResolvedValueOnce(mockResponse(204, '')); // DELETE file

      const { photosRemoveFromAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosRemoveFromAlbumTool.handler({
        albumName: 'Vacation',
        fileIds: ['12345'],
      });

      expect(result.content[0].text).toContain('Removed 1/1');
      expect(result.content[0].text).toContain('OK   12345');
    });

    it('should handle file not in album', async () => {
      const albumXml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/photos/admin/albums/Vacation/</d:href>
    <d:propstat><d:prop><d:displayname>Vacation</d:displayname></d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      mockFetchCalDAV.mockResolvedValueOnce(mockResponse(207, albumXml));

      const { photosRemoveFromAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosRemoveFromAlbumTool.handler({
        albumName: 'Vacation',
        fileIds: ['99999'],
      });

      expect(result.content[0].text).toContain('Removed 0/1');
      expect(result.content[0].text).toContain('SKIP 99999');
    });

    it('should handle album read error', async () => {
      mockFetchCalDAV.mockResolvedValueOnce(mockResponse(404, '', 'Not Found'));

      const { photosRemoveFromAlbumTool } = await import('../tools/apps/photos.js');
      const result = await photosRemoveFromAlbumTool.handler({
        albumName: 'Missing',
        fileIds: ['12345'],
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error reading album');
    });
  });

  // -------------------------------------------------------------------------
  // photos_get_metadata
  // -------------------------------------------------------------------------

  describe('photos_get_metadata', () => {
    it('should return full metadata', async () => {
      const xml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:response>
    <d:propstat><d:prop>
      <oc:fileid>12345</oc:fileid>
      <d:displayname>sunset.jpg</d:displayname>
      <d:getcontenttype>image/jpeg</d:getcontenttype>
      <d:getcontentlength>2097152</d:getcontentlength>
      <d:getlastmodified>Thu, 14 Nov 2024 10:00:00 GMT</d:getlastmodified>
      <nc:favorite>1</nc:favorite>
      <nc:metadata-photos-size>{"width":4000,"height":3000}</nc:metadata-photos-size>
      <nc:metadata-photos-original_date_time>1700000000</nc:metadata-photos-original_date_time>
      <nc:metadata-photos-ifd0>{"Make":"Canon","Model":"EOS R5"}</nc:metadata-photos-ifd0>
      <nc:metadata-photos-exif>{"FocalLength":85,"FNumber":1.4,"ExposureTime":0.005,"ISOSpeedRatings":400,"LensModel":"RF 85mm F1.2L USM","Flash":0}</nc:metadata-photos-exif>
      <nc:metadata-photos-gps>{"latitude":48.8566,"longitude":2.3522}</nc:metadata-photos-gps>
    </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
</d:multistatus>`;

      mockFetchCalDAV.mockResolvedValue(mockResponse(207, xml));

      const { photosGetMetadataTool } = await import('../tools/apps/photos.js');
      const result = await photosGetMetadataTool.handler({ path: '/Photos/sunset.jpg' });

      const text = result.content[0].text;
      expect(text).toContain('# sunset.jpg');
      expect(text).toContain('File ID: 12345');
      expect(text).toContain('image/jpeg');
      expect(text).toContain('2.0 MB');
      expect(text).toContain('Favorite: yes');
      expect(text).toContain('4000 x 3000');
      expect(text).toContain('Canon EOS R5');
      expect(text).toContain('85mm');
      expect(text).toContain('f/1.4');
      expect(text).toContain('1/200s');
      expect(text).toContain('ISO: 400');
      expect(text).toContain('RF 85mm F1.2L USM');
      expect(text).toContain('no flash');
      expect(text).toContain('48.8566');
      expect(text).toContain('2.3522');
    });

    it('should handle file with minimal metadata', async () => {
      const xml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:response>
    <d:propstat><d:prop>
      <oc:fileid>99999</oc:fileid>
      <d:displayname>doc.pdf</d:displayname>
      <d:getcontenttype>application/pdf</d:getcontenttype>
      <d:getcontentlength>1024</d:getcontentlength>
      <d:getlastmodified>Thu, 14 Nov 2024 10:00:00 GMT</d:getlastmodified>
      <nc:favorite>0</nc:favorite>
    </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
</d:multistatus>`;

      mockFetchCalDAV.mockResolvedValue(mockResponse(207, xml));

      const { photosGetMetadataTool } = await import('../tools/apps/photos.js');
      const result = await photosGetMetadataTool.handler({ path: '/Documents/doc.pdf' });

      const text = result.content[0].text;
      expect(text).toContain('# doc.pdf');
      expect(text).toContain('application/pdf');
      expect(text).not.toContain('Camera');
      expect(text).not.toContain('GPS');
      expect(text).not.toContain('Dimensions');
    });

    it('should handle file not found', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(404, '', 'Not Found'));

      const { photosGetMetadataTool } = await import('../tools/apps/photos.js');
      const result = await photosGetMetadataTool.handler({ path: '/missing.jpg' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('File not found');
    });

    it('should handle API error', async () => {
      mockFetchCalDAV.mockRejectedValue(new Error('Timeout'));

      const { photosGetMetadataTool } = await import('../tools/apps/photos.js');
      const result = await photosGetMetadataTool.handler({ path: '/Photos/test.jpg' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Timeout');
    });

    it('should call correct URL with Depth 0', async () => {
      mockFetchCalDAV.mockResolvedValue(
        mockResponse(
          207,
          '<d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:prop></d:prop></d:propstat></d:response></d:multistatus>'
        )
      );

      const { photosGetMetadataTool } = await import('../tools/apps/photos.js');
      await photosGetMetadataTool.handler({ path: '/Photos/test.jpg' });

      expect(mockFetchCalDAV).toHaveBeenCalledWith(
        'https://cloud.example.com/remote.php/dav/files/admin/Photos/test.jpg',
        expect.objectContaining({
          method: 'PROPFIND',
          headers: { Depth: '0' },
        })
      );
    });
  });

  // -------------------------------------------------------------------------
  // photos_set_favorite
  // -------------------------------------------------------------------------

  describe('photos_set_favorite', () => {
    it('should mark file as favorite', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(207, ''));

      const { photosSetFavoriteTool } = await import('../tools/apps/photos.js');
      const result = await photosSetFavoriteTool.handler({
        path: '/Photos/sunset.jpg',
        favorite: true,
      });

      expect(result.content[0].text).toContain('marked as favorite');
      expect(mockFetchCalDAV).toHaveBeenCalledWith(
        'https://cloud.example.com/remote.php/dav/files/admin/Photos/sunset.jpg',
        expect.objectContaining({ method: 'PROPPATCH' })
      );
      const callBody = mockFetchCalDAV.mock.calls[0][1].body;
      expect(callBody).toContain('<oc:favorite>1</oc:favorite>');
    });

    it('should unmark file as favorite', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(207, ''));

      const { photosSetFavoriteTool } = await import('../tools/apps/photos.js');
      const result = await photosSetFavoriteTool.handler({
        path: '/Photos/sunset.jpg',
        favorite: false,
      });

      expect(result.content[0].text).toContain('removed from favorites');
      const callBody = mockFetchCalDAV.mock.calls[0][1].body;
      expect(callBody).toContain('<oc:favorite>0</oc:favorite>');
    });

    it('should handle file not found', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(404, '', 'Not Found'));

      const { photosSetFavoriteTool } = await import('../tools/apps/photos.js');
      const result = await photosSetFavoriteTool.handler({
        path: '/Photos/missing.jpg',
        favorite: true,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('File not found');
    });

    it('should handle API error', async () => {
      mockFetchCalDAV.mockRejectedValue(new Error('Network error'));

      const { photosSetFavoriteTool } = await import('../tools/apps/photos.js');
      const result = await photosSetFavoriteTool.handler({
        path: '/Photos/test.jpg',
        favorite: true,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Network error');
    });
  });

  // -------------------------------------------------------------------------
  // photos_set_album_location
  // -------------------------------------------------------------------------

  describe('photos_set_album_location', () => {
    it('should set album location', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(207, ''));

      const { photosSetAlbumLocationTool } = await import('../tools/apps/photos.js');
      const result = await photosSetAlbumLocationTool.handler({
        name: 'Vacation',
        location: 'Paris, France',
      });

      expect(result.content[0].text).toContain('location set to "Paris, France"');
      expect(mockFetchCalDAV).toHaveBeenCalledWith(
        'https://cloud.example.com/remote.php/dav/photos/admin/albums/Vacation',
        expect.objectContaining({ method: 'PROPPATCH' })
      );
      const callBody = mockFetchCalDAV.mock.calls[0][1].body;
      expect(callBody).toContain('<nc:location>Paris, France</nc:location>');
    });

    it('should escape special characters in location', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(207, ''));

      const { photosSetAlbumLocationTool } = await import('../tools/apps/photos.js');
      await photosSetAlbumLocationTool.handler({
        name: 'Trip',
        location: 'Rock & Roll <Hall>',
      });

      const callBody = mockFetchCalDAV.mock.calls[0][1].body;
      expect(callBody).toContain('Rock &amp; Roll &lt;Hall&gt;');
    });

    it('should handle album not found', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(404, '', 'Not Found'));

      const { photosSetAlbumLocationTool } = await import('../tools/apps/photos.js');
      const result = await photosSetAlbumLocationTool.handler({
        name: 'Missing',
        location: 'Nowhere',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  // -------------------------------------------------------------------------
  // photos_add_collaborators
  // -------------------------------------------------------------------------

  describe('photos_add_collaborators', () => {
    it('should add user collaborator', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(207, ''));

      const { photosAddCollaboratorsTool } = await import('../tools/apps/photos.js');
      const result = await photosAddCollaboratorsTool.handler({
        albumName: 'Vacation',
        collaborators: [{ id: 'alice', type: 0 }],
      });

      expect(result.content[0].text).toContain('Added collaborators');
      expect(result.content[0].text).toContain('alice');
      expect(mockFetchCalDAV).toHaveBeenCalledWith(
        'https://cloud.example.com/remote.php/dav/photos/admin/albums/Vacation',
        expect.objectContaining({ method: 'PROPPATCH' })
      );
      const callBody = mockFetchCalDAV.mock.calls[0][1].body;
      expect(callBody).toContain('<id>alice</id>');
      expect(callBody).toContain('<type>0</type>');
    });

    it('should add multiple collaborators including groups', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(207, ''));

      const { photosAddCollaboratorsTool } = await import('../tools/apps/photos.js');
      const result = await photosAddCollaboratorsTool.handler({
        albumName: 'Team',
        collaborators: [
          { id: 'alice', type: 0 },
          { id: 'photographers', type: 1 },
        ],
      });

      expect(result.content[0].text).toContain('alice, photographers');
      const callBody = mockFetchCalDAV.mock.calls[0][1].body;
      expect(callBody).toContain('<type>0</type>');
      expect(callBody).toContain('<type>1</type>');
    });

    it('should handle album not found', async () => {
      mockFetchCalDAV.mockResolvedValue(mockResponse(404, '', 'Not Found'));

      const { photosAddCollaboratorsTool } = await import('../tools/apps/photos.js');
      const result = await photosAddCollaboratorsTool.handler({
        albumName: 'Missing',
        collaborators: [{ id: 'alice', type: 0 }],
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });

    it('should handle API error', async () => {
      mockFetchCalDAV.mockRejectedValue(new Error('Forbidden'));

      const { photosAddCollaboratorsTool } = await import('../tools/apps/photos.js');
      const result = await photosAddCollaboratorsTool.handler({
        albumName: 'Album',
        collaborators: [{ id: 'bob', type: 0 }],
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Forbidden');
    });
  });
});
