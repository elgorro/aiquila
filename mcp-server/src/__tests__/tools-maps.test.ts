import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the Maps API client module
const mockFetchMapsExternalAPI = vi.fn();
const mockFetchMapsAPI = vi.fn();

vi.mock('../client/maps.js', () => ({
  fetchMapsExternalAPI: (...args: unknown[]) => mockFetchMapsExternalAPI(...args),
  fetchMapsAPI: (...args: unknown[]) => mockFetchMapsAPI(...args),
}));

describe('Maps Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  // ── Favorites ──────────────────────────────────────────────────────────

  describe('list_map_favorites', () => {
    it('should return formatted favorites list', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([
        {
          id: 1,
          name: 'Home',
          lat: 52.52,
          lng: 13.405,
          category: 'Personal',
          comment: 'My home',
          extensions: '',
          date_created: 1700000000,
          date_modified: 1700000000,
        },
        {
          id: 2,
          name: 'Office',
          lat: 48.8566,
          lng: 2.3522,
          category: 'Work',
          comment: '',
          extensions: '',
          date_created: 1700100000,
          date_modified: 1700100000,
        },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_favorites')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('Home');
      expect(result.content[0].text).toContain('52.52, 13.405');
      expect(result.content[0].text).toContain('Office');
      expect(result.content[0].text).toContain('2');
    });

    it('should pass pruneBefore param', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_favorites')!;
      await tool.handler({ pruneBefore: 1700000000 });

      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/favorites', {
        queryParams: { pruneBefore: '1700000000' },
      });
    });

    it('should handle empty results', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_favorites')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No map favorites found');
    });

    it('should handle API errors', async () => {
      mockFetchMapsExternalAPI.mockRejectedValue(new Error('Maps API 500: Internal Server Error'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_favorites')!;
      const result = await tool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('500');
    });
  });

  describe('create_map_favorite', () => {
    it('should create a favorite successfully', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue({
        id: 10,
        name: 'Cafe',
        lat: 52.52,
        lng: 13.405,
        category: 'Food',
        comment: 'Great coffee',
        extensions: '',
        date_created: 1700200000,
        date_modified: 1700200000,
      });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'create_map_favorite')!;
      const result = await tool.handler({
        name: 'Cafe',
        lat: 52.52,
        lng: 13.405,
        category: 'Food',
        comment: 'Great coffee',
      });

      expect(result.content[0].text).toContain('Favorite created');
      expect(result.content[0].text).toContain('ID: 10');
      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/favorites', {
        method: 'POST',
        body: { name: 'Cafe', lat: 52.52, lng: 13.405, category: 'Food', comment: 'Great coffee' },
      });
    });

    it('should handle creation errors', async () => {
      mockFetchMapsExternalAPI.mockRejectedValue(new Error('Maps API 400: Bad Request'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'create_map_favorite')!;
      const result = await tool.handler({ lat: NaN, lng: NaN });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error creating map favorite');
    });
  });

  describe('update_map_favorite', () => {
    it('should update a favorite successfully', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue({
        id: 1,
        name: 'Updated Name',
        lat: 52.52,
        lng: 13.405,
        category: 'Personal',
        comment: '',
        extensions: '',
        date_created: 1700000000,
        date_modified: 1700300000,
      });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'update_map_favorite')!;
      const result = await tool.handler({ id: 1, name: 'Updated Name' });

      expect(result.content[0].text).toContain('Favorite 1 updated');
      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/favorites/1', {
        method: 'PUT',
        body: { name: 'Updated Name' },
      });
    });
  });

  describe('delete_map_favorite', () => {
    it('should delete a favorite successfully', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue('DELETED');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'delete_map_favorite')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Favorite 1 deleted');
      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/favorites/1', { method: 'DELETE' });
    });

    it('should handle deletion errors', async () => {
      mockFetchMapsExternalAPI.mockRejectedValue(new Error('Maps API 400: Not found'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'delete_map_favorite')!;
      const result = await tool.handler({ id: 9999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting map favorite');
    });
  });

  // ── Devices ────────────────────────────────────────────────────────────

  describe('list_map_devices', () => {
    it('should return formatted device list', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([
        { id: 1, user_agent: 'PhoneTrack/1.0', color: '#ff0000' },
        { id: 2, user_agent: 'OwnTracks/2.0', color: '#00ff00' },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_devices')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('PhoneTrack/1.0');
      expect(result.content[0].text).toContain('OwnTracks/2.0');
      expect(result.content[0].text).toContain('2');
    });

    it('should handle empty results', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_devices')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No map devices found');
    });
  });

  describe('get_map_device_points', () => {
    it('should return device location points', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([
        { id: 1, lat: 52.52, lng: 13.405, timestamp: 1700000000, altitude: 35, accuracy: 10 },
        { id: 2, lat: 52.53, lng: 13.41, timestamp: 1700003600 },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'get_map_device_points')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('52.52, 13.405');
      expect(result.content[0].text).toContain('alt: 35m');
      expect(result.content[0].text).toContain('2');
    });

    it('should pass pruneBefore param', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'get_map_device_points')!;
      await tool.handler({ id: 1, pruneBefore: 1700000000 });

      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/devices/1', {
        queryParams: { pruneBefore: '1700000000' },
      });
    });

    it('should handle empty results', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'get_map_device_points')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('No points found');
    });
  });

  describe('add_map_device_point', () => {
    it('should log a GPS point successfully', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue({ deviceId: 1, pointId: 42 });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'add_map_device_point')!;
      const result = await tool.handler({
        lat: 52.52,
        lng: 13.405,
        user_agent: 'TestDevice',
        altitude: 35,
      });

      expect(result.content[0].text).toContain('device ID: 1');
      expect(result.content[0].text).toContain('point ID: 42');
      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/devices', {
        method: 'POST',
        body: { lat: 52.52, lng: 13.405, user_agent: 'TestDevice', altitude: 35 },
      });
    });
  });

  describe('update_map_device', () => {
    it('should update device color', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue({
        id: 1,
        user_agent: 'TestDevice',
        color: '#0000ff',
      });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'update_map_device')!;
      const result = await tool.handler({ id: 1, color: '#0000ff' });

      expect(result.content[0].text).toContain('Device 1 updated');
      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/devices/1', {
        method: 'PUT',
        body: { color: '#0000ff' },
      });
    });
  });

  describe('delete_map_device', () => {
    it('should delete a device successfully', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue('DELETED');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'delete_map_device')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Device 1 deleted');
      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/devices/1', { method: 'DELETE' });
    });

    it('should handle deletion errors', async () => {
      mockFetchMapsExternalAPI.mockRejectedValue(new Error('Maps API 400: Not found'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'delete_map_device')!;
      const result = await tool.handler({ id: 9999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting device');
    });
  });

  // ── Tracks ─────────────────────────────────────────────────────────────

  describe('list_map_tracks', () => {
    it('should return formatted track list', async () => {
      mockFetchMapsAPI.mockResolvedValue([
        { id: 1, file_id: 100, color: '#ff0000' },
        { id: 2, file_id: 101, color: '#00ff00' },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_tracks')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('Track ID: 1');
      expect(result.content[0].text).toContain('Track ID: 2');
      expect(result.content[0].text).toContain('2');
    });

    it('should handle empty results', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_tracks')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No tracks found');
    });

    it('should pass myMapId param', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_tracks')!;
      await tool.handler({ myMapId: 5 });

      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/tracks', {
        queryParams: { myMapId: '5' },
      });
    });
  });

  describe('get_map_track', () => {
    it('should return track detail with content', async () => {
      mockFetchMapsAPI.mockResolvedValue({
        metadata: { name: 'Morning Run', distance: 5200 },
        content: '<gpx><trk><name>Morning Run</name></trk></gpx>',
      });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'get_map_track')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Morning Run');
      expect(result.content[0].text).toContain('5200');
      expect(result.content[0].text).toContain('<gpx>');
    });
  });

  describe('update_map_track', () => {
    it('should update track color', async () => {
      mockFetchMapsAPI.mockResolvedValue('EDITED');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'update_map_track')!;
      const result = await tool.handler({ id: 1, color: '#00ff00' });

      expect(result.content[0].text).toContain('Track 1 updated');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/tracks/1', {
        method: 'PUT',
        body: { color: '#00ff00' },
      });
    });
  });

  // ── Photos ─────────────────────────────────────────────────────────────

  describe('list_map_photos', () => {
    it('should return geolocated photos', async () => {
      mockFetchMapsAPI.mockResolvedValue([
        { fileId: 100, lat: 52.52, lng: 13.405, path: '/Photos/sunset.jpg', dateTaken: 1700000000 },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_photos')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('File ID: 100');
      expect(result.content[0].text).toContain('52.52, 13.405');
      expect(result.content[0].text).toContain('sunset.jpg');
    });

    it('should handle empty results', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_photos')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No geolocated photos found');
    });
  });

  describe('list_map_photos_nonlocalized', () => {
    it('should return non-localized photos', async () => {
      mockFetchMapsAPI.mockResolvedValue([
        { fileId: 200, path: '/Photos/indoor.jpg', dateTaken: 1700000000 },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_photos_nonlocalized')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('File ID: 200');
      expect(result.content[0].text).toContain('indoor.jpg');
    });

    it('should pass pagination params', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_photos_nonlocalized')!;
      await tool.handler({ limit: 50, offset: 10, timezone: 'Europe/Berlin' });

      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/photos/nonlocalized', {
        queryParams: { limit: '50', offset: '10', timezone: 'Europe/Berlin' },
      });
    });
  });

  describe('place_map_photos', () => {
    it('should set coordinates on photos', async () => {
      mockFetchMapsAPI.mockResolvedValue({});

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'place_map_photos')!;
      const result = await tool.handler({
        paths: ['/Photos/a.jpg', '/Photos/b.jpg'],
        lats: [52.52, 48.85],
        lngs: [13.405, 2.35],
      });

      expect(result.content[0].text).toContain('2 photo(s)');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/photos', {
        method: 'POST',
        body: {
          paths: ['/Photos/a.jpg', '/Photos/b.jpg'],
          lats: [52.52, 48.85],
          lngs: [13.405, 2.35],
        },
      });
    });
  });

  describe('reset_map_photo_coords', () => {
    it('should remove coordinates from photos', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'reset_map_photo_coords')!;
      const result = await tool.handler({ paths: ['/Photos/a.jpg'] });

      expect(result.content[0].text).toContain('1 photo(s)');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/photos', {
        method: 'DELETE',
        body: { paths: ['/Photos/a.jpg'] },
      });
    });
  });

  // ── My Maps ────────────────────────────────────────────────────────────

  describe('list_maps', () => {
    it('should return custom maps', async () => {
      mockFetchMapsAPI.mockResolvedValue([
        { id: 1, name: 'Europe Trip' },
        { id: 2, name: 'Local Hikes' },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_maps')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('Map ID: 1');
      expect(result.content[0].text).toContain('Europe Trip');
      expect(result.content[0].text).toContain('Map ID: 2');
      expect(result.content[0].text).toContain('2');
    });

    it('should handle empty results', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_maps')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No custom maps found');
    });
  });

  describe('create_map', () => {
    it('should create a map successfully', async () => {
      mockFetchMapsAPI.mockResolvedValue({ id: 10, name: 'Vacation' });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'create_map')!;
      const result = await tool.handler({ name: 'Vacation' });

      expect(result.content[0].text).toContain('Map created');
      expect(result.content[0].text).toContain('ID: 10');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/maps', {
        method: 'POST',
        body: { values: { newName: 'Vacation' } },
      });
    });

    it('should use default name', async () => {
      mockFetchMapsAPI.mockResolvedValue({ id: 11, name: 'New Map' });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'create_map')!;
      await tool.handler({});

      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/maps', {
        method: 'POST',
        body: { values: { newName: 'New Map' } },
      });
    });
  });

  describe('update_map', () => {
    it('should update a map successfully', async () => {
      mockFetchMapsAPI.mockResolvedValue({ id: 1, name: 'Renamed Trip' });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'update_map')!;
      const result = await tool.handler({ id: 1, values: { newName: 'Renamed Trip' } });

      expect(result.content[0].text).toContain('Map 1 updated');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/maps/1', {
        method: 'PUT',
        body: { values: { newName: 'Renamed Trip' } },
      });
    });
  });

  describe('delete_map', () => {
    it('should delete a map successfully', async () => {
      mockFetchMapsAPI.mockResolvedValue({});

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'delete_map')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Map 1 deleted');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/maps/1', { method: 'DELETE' });
    });

    it('should handle deletion errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 404: Not found'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'delete_map')!;
      const result = await tool.handler({ id: 9999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting map');
    });
  });

  // ── Routing ────────────────────────────────────────────────────────────

  describe('export_map_route', () => {
    it('should export a route as GPX', async () => {
      mockFetchMapsAPI.mockResolvedValue({ id: 5, file_id: 200 });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'export_map_route')!;
      const result = await tool.handler({
        name: 'Morning Walk',
        type: 'track',
        coords: [
          { lat: 52.52, lng: 13.405 },
          { lat: 52.53, lng: 13.41 },
        ],
      });

      expect(result.content[0].text).toContain('Morning Walk');
      expect(result.content[0].text).toContain('exported');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/exportRoute', {
        method: 'POST',
        body: {
          name: 'Morning Walk',
          type: 'track',
          coords: [
            { lat: 52.52, lng: 13.405 },
            { lat: 52.53, lng: 13.41 },
          ],
        },
      });
    });
  });

  // ── Import/Export ──────────────────────────────────────────────────────

  describe('export_map_favorites', () => {
    it('should export favorites as GPX', async () => {
      mockFetchMapsAPI.mockResolvedValue('/Maps/2024-01-01 favorites.gpx');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'export_map_favorites')!;
      const result = await tool.handler({ categoryList: ['Restaurant', 'Home'] });

      expect(result.content[0].text).toContain('exported');
      expect(result.content[0].text).toContain('favorites.gpx');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/export/favorites', {
        method: 'POST',
        body: { categoryList: ['Restaurant', 'Home'] },
      });
    });
  });

  describe('import_map_favorites', () => {
    it('should import favorites from file', async () => {
      mockFetchMapsAPI.mockResolvedValue({ imported: 5 });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'import_map_favorites')!;
      const result = await tool.handler({ path: '/Maps/favorites.gpx' });

      expect(result.content[0].text).toContain('imported');
      expect(result.content[0].text).toContain('favorites.gpx');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/import/favorites', {
        method: 'POST',
        body: { path: '/Maps/favorites.gpx' },
      });
    });

    it('should handle import errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 400: Unsupported format'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'import_map_favorites')!;
      const result = await tool.handler({ path: '/Maps/bad.txt' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error importing favorites');
    });
  });

  describe('export_map_devices', () => {
    it('should export device data as GPX', async () => {
      mockFetchMapsAPI.mockResolvedValue('/Maps/2024-01-01 devices.gpx');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'export_map_devices')!;
      const result = await tool.handler({ deviceIdList: [1, 2] });

      expect(result.content[0].text).toContain('exported');
      expect(result.content[0].text).toContain('devices.gpx');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/export/devices', {
        method: 'POST',
        body: { deviceIdList: [1, 2] },
      });
    });
  });

  describe('import_map_devices', () => {
    it('should import device data from file', async () => {
      mockFetchMapsAPI.mockResolvedValue(42);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'import_map_devices')!;
      const result = await tool.handler({ path: '/Maps/track.gpx' });

      expect(result.content[0].text).toContain('42 device point(s)');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/import/devices', {
        method: 'POST',
        body: { path: '/Maps/track.gpx' },
      });
    });

    it('should handle import errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 400: File not found'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'import_map_devices')!;
      const result = await tool.handler({ path: '/Maps/missing.gpx' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error importing devices');
    });
  });
});
