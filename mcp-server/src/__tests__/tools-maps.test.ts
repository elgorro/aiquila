// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the Maps API client module
const mockFetchMapsAPI = vi.fn();

vi.mock('../client/maps.js', () => ({
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
      mockFetchMapsAPI.mockResolvedValue([
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

    it('should filter by pruneBefore client-side', async () => {
      mockFetchMapsAPI.mockResolvedValue([
        { id: 1, name: 'Old', lat: 1, lng: 2, date_created: 1, date_modified: 1699999999 },
        { id: 2, name: 'Fresh', lat: 3, lng: 4, date_created: 1, date_modified: 1700000001 },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_favorites')!;
      const result = await tool.handler({ pruneBefore: 1700000000 });

      expect(result.content[0].text).toContain('Fresh');
      expect(result.content[0].text).not.toContain('Old');
    });

    it('should scope to a custom map', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_favorites')!;
      await tool.handler({ myMapId: 77 });

      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/favorites', {
        queryParams: { myMapId: '77' },
      });
    });

    it('should handle empty results', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_favorites')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No map favorites found');
    });

    it('should handle API errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 500: Internal Server Error'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_favorites')!;
      const result = await tool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('500');
    });
  });

  describe('create_map_favorite', () => {
    it('should create a favorite successfully', async () => {
      mockFetchMapsAPI.mockResolvedValue({
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
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/favorite', {
        method: 'POST',
        body: { name: 'Cafe', lat: 52.52, lng: 13.405, category: 'Food', comment: 'Great coffee' },
      });
    });

    it('should handle creation errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 400: Bad Request'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'create_map_favorite')!;
      const result = await tool.handler({ lat: NaN, lng: NaN });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error creating map favorite');
    });
  });

  describe('update_map_favorite', () => {
    it('should update a favorite successfully', async () => {
      mockFetchMapsAPI.mockResolvedValue({
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
      const result = await tool.handler({ id: 1, name: 'Updated Name', lat: 52.52, lng: 13.405 });

      expect(result.content[0].text).toContain('Favorite 1 updated');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/favorites/1', {
        method: 'PUT',
        body: { lat: 52.52, lng: 13.405, name: 'Updated Name' },
      });
    });

    it('should look up current coordinates when lat/lng are omitted', async () => {
      mockFetchMapsAPI
        .mockResolvedValueOnce([{ id: 1, name: 'Home', lat: 52.52, lng: 13.405 }])
        .mockResolvedValueOnce({
          id: 1,
          name: 'Updated Name',
          lat: 52.52,
          lng: 13.405,
          date_created: 1700000000,
        });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'update_map_favorite')!;
      const result = await tool.handler({ id: 1, name: 'Updated Name' });

      expect(mockFetchMapsAPI).toHaveBeenNthCalledWith(1, '/favorites', { queryParams: {} });
      expect(mockFetchMapsAPI).toHaveBeenNthCalledWith(2, '/favorites/1', {
        method: 'PUT',
        body: { lat: 52.52, lng: 13.405, name: 'Updated Name' },
      });
      expect(result.content[0].text).toContain('Favorite 1 updated');
    });

    it('should error when the favorite does not exist', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'update_map_favorite')!;
      const result = await tool.handler({ id: 9999, name: 'Nope' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('No favorite with ID 9999');
    });
  });

  describe('delete_map_favorite', () => {
    it('should delete a favorite successfully', async () => {
      mockFetchMapsAPI.mockResolvedValue('DELETED');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'delete_map_favorite')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Favorite 1 deleted');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/favorites/1', {
        method: 'DELETE',
        queryParams: {},
      });
    });

    it('should handle deletion errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 400: Not found'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'delete_map_favorite')!;
      const result = await tool.handler({ id: 9999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting map favorite');
    });
  });

  describe('rename_map_favorite_category', () => {
    it('should rename categories', async () => {
      mockFetchMapsAPI.mockResolvedValue('RENAMED');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'rename_map_favorite_category')!;
      const result = await tool.handler({ categories: ['Food', 'Eats'], newName: 'Restaurants' });

      expect(result.content[0].text).toContain('Renamed 2 categories');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/favorites-category', {
        method: 'PUT',
        body: { categories: ['Food', 'Eats'], newName: 'Restaurants' },
      });
    });

    it('should pass myMapId', async () => {
      mockFetchMapsAPI.mockResolvedValue('RENAMED');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'rename_map_favorite_category')!;
      await tool.handler({ categories: ['Food'], newName: 'Restaurants', myMapId: 42 });

      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/favorites-category', {
        method: 'PUT',
        body: { categories: ['Food'], newName: 'Restaurants', myMapId: 42 },
      });
    });

    it('should handle API errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 500: Internal Server Error'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'rename_map_favorite_category')!;
      const result = await tool.handler({ categories: ['Food'], newName: 'Restaurants' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error renaming favorite categories');
    });
  });

  // ── Favorite category sharing ──────────────────────────────────────────

  describe('list_shared_map_categories', () => {
    it('should return formatted shares', async () => {
      mockFetchMapsAPI.mockResolvedValue([
        { id: 1, category: 'Food', token: 'abc123', owner: 'testuser' },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_shared_map_categories')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('Food');
      expect(result.content[0].text).toContain('abc123');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/favorites-category/shared', {
        queryParams: {},
      });
    });

    it('should scope to a custom map', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_shared_map_categories')!;
      await tool.handler({ myMapId: 42 });

      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/favorites-category/shared', {
        queryParams: { myMapId: '42' },
      });
    });

    it('should handle empty results', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_shared_map_categories')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No shared favorite categories');
    });
  });

  describe('share_map_category', () => {
    it('should share a category', async () => {
      mockFetchMapsAPI.mockResolvedValue({ id: 1, category: 'Food', token: 'tok1' });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'share_map_category')!;
      const result = await tool.handler({ category: 'Food' });

      expect(result.content[0].text).toContain('shared');
      expect(result.content[0].text).toContain('tok1');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/favorites-category/Food/share', {
        method: 'POST',
      });
    });

    it('should url-encode the category name', async () => {
      mockFetchMapsAPI.mockResolvedValue({ category: 'My Food', token: 'tok1' });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'share_map_category')!;
      await tool.handler({ category: 'My Food' });

      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/favorites-category/My%20Food/share', {
        method: 'POST',
      });
    });

    it('should handle API errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 400: Unknown category'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'share_map_category')!;
      const result = await tool.handler({ category: 'Nope' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error sharing category');
    });
  });

  describe('unshare_map_category', () => {
    it('should report a removed share', async () => {
      mockFetchMapsAPI.mockResolvedValue({ did_exist: true });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'unshare_map_category')!;
      const result = await tool.handler({ category: 'Food' });

      expect(result.content[0].text).toContain('unshared');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/favorites-category/Food/un-share', {
        method: 'POST',
      });
    });

    it('should report when nothing was shared', async () => {
      mockFetchMapsAPI.mockResolvedValue({ did_exist: false });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'unshare_map_category')!;
      const result = await tool.handler({ category: 'Food' });

      expect(result.content[0].text).toContain('was not shared');
    });
  });

  describe('add_shared_category_to_map', () => {
    it('should add a shared category to a map', async () => {
      mockFetchMapsAPI.mockResolvedValue('Done');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'add_shared_category_to_map')!;
      const result = await tool.handler({ category: 'Food', targetMapId: 99 });

      expect(result.content[0].text).toContain('added to map 99');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/favorites-category/Food/add-to-map/99', {
        method: 'PUT',
        queryParams: {},
      });
    });

    it('should handle API errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 404: Map not Found'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'add_shared_category_to_map')!;
      const result = await tool.handler({ category: 'Food', targetMapId: 99 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  // ── Device sharing ─────────────────────────────────────────────────────

  describe('share_map_device', () => {
    it('should share a device', async () => {
      mockFetchMapsAPI.mockResolvedValue({
        id: 1,
        token: 'devtok',
        deviceId: 7,
        timestampFrom: 1700000000,
        timestampTo: 1700003600,
      });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'share_map_device')!;
      const result = await tool.handler({
        id: 7,
        timestampFrom: 1700000000,
        timestampTo: 1700003600,
      });

      expect(result.content[0].text).toContain('Device 7 shared');
      expect(result.content[0].text).toContain('devtok');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/devices/7/share', {
        method: 'POST',
        body: { timestampFrom: 1700000000, timestampTo: 1700003600 },
      });
    });

    it('should handle API errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 400: No such device'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'share_map_device')!;
      const result = await tool.handler({ id: 9999, timestampFrom: 0, timestampTo: 1 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error sharing device 9999');
    });
  });

  describe('list_shared_map_devices', () => {
    it('should list device shares on a map', async () => {
      mockFetchMapsAPI.mockResolvedValue([{ token: 'devtok', device_id: 7 }]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_shared_map_devices')!;
      const result = await tool.handler({ myMapId: 42 });

      expect(result.content[0].text).toContain('devtok');
      expect(result.content[0].text).toContain('Device ID: 7');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/devices/s/', {
        queryParams: { myMapId: '42' },
      });
    });

    it('should handle empty results', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_shared_map_devices')!;
      const result = await tool.handler({ myMapId: 42 });

      expect(result.content[0].text).toContain('No shared devices on map 42');
    });
  });

  describe('remove_map_device_share', () => {
    it('should revoke a share', async () => {
      mockFetchMapsAPI.mockResolvedValue(true);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'remove_map_device_share')!;
      const result = await tool.handler({ token: 'devtok' });

      expect(result.content[0].text).toContain('Device share devtok removed');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/devices/s/devtok', { method: 'DELETE' });
    });

    it('should handle API errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 404: Not Found'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'remove_map_device_share')!;
      const result = await tool.handler({ token: 'nope' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error removing device share');
    });
  });

  describe('add_shared_device_to_map', () => {
    it('should add a shared device to a map', async () => {
      mockFetchMapsAPI.mockResolvedValue('Done');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'add_shared_device_to_map')!;
      const result = await tool.handler({ token: 'devtok', targetMapId: 99 });

      expect(result.content[0].text).toContain('added to map 99');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/devices/s/devtok/map-link/99', {
        method: 'POST',
      });
    });
  });

  // ── Devices ────────────────────────────────────────────────────────────

  describe('list_map_devices', () => {
    it('should return formatted device list', async () => {
      mockFetchMapsAPI.mockResolvedValue([
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
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_devices')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No map devices found');
    });
  });

  describe('get_map_device_points', () => {
    it('should return device location points', async () => {
      mockFetchMapsAPI.mockResolvedValue([
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
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'get_map_device_points')!;
      await tool.handler({ id: 1, pruneBefore: 1700000000 });

      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/devices/1', {
        queryParams: { pruneBefore: '1700000000' },
      });
    });

    it('should handle empty results', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'get_map_device_points')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('No points found');
    });
  });

  describe('add_map_device_point', () => {
    it('should log a GPS point successfully', async () => {
      mockFetchMapsAPI.mockResolvedValue({ deviceId: 1, pointId: 42 });

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
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/devices', {
        method: 'POST',
        body: { lat: 52.52, lng: 13.405, user_agent: 'TestDevice', altitude: 35 },
      });
    });
  });

  describe('update_map_device', () => {
    it('should update device color', async () => {
      mockFetchMapsAPI.mockResolvedValue({
        id: 1,
        user_agent: 'TestDevice',
        color: '#0000ff',
      });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'update_map_device')!;
      const result = await tool.handler({ id: 1, color: '#0000ff' });

      expect(result.content[0].text).toContain('Device 1 updated');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/devices/1', {
        method: 'PUT',
        body: { color: '#0000ff', name: '' },
      });
    });
  });

  describe('delete_map_device', () => {
    it('should delete a device successfully', async () => {
      mockFetchMapsAPI.mockResolvedValue('DELETED');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'delete_map_device')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Device 1 deleted');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/devices/1', { method: 'DELETE' });
    });

    it('should handle deletion errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 400: Not found'));

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

  describe('get_map_photo_job_status', () => {
    it('should report the job status', async () => {
      mockFetchMapsAPI.mockResolvedValue({ running: true, done: 12 });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'get_map_photo_job_status')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('running: true');
      expect(result.content[0].text).toContain('done: 12');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/photos/backgroundJobStatus');
    });

    it('should handle an empty status', async () => {
      mockFetchMapsAPI.mockResolvedValue({});

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'get_map_photo_job_status')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No photo background job status');
    });
  });

  // ── Contacts ───────────────────────────────────────────────────────────

  describe('list_map_contacts', () => {
    it('should return formatted contacts', async () => {
      mockFetchMapsAPI.mockResolvedValue([
        {
          FN: 'Ada Lovelace',
          URI: 'ada.vcf',
          UID: 'ada',
          BOOKID: 1,
          ADR: ';;Main St 1;Berlin;;10115;Germany',
          ADRTYPE: 'HOME',
          GEO: '52.52;13.405',
        },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_contacts')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('Ada Lovelace');
      expect(result.content[0].text).toContain('52.52;13.405');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/contacts', { queryParams: {} });
    });

    it('should scope to a custom map', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_contacts')!;
      await tool.handler({ myMapId: 42 });

      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/contacts', {
        queryParams: { myMapId: '42' },
      });
    });

    it('should handle empty results', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'list_map_contacts')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No contacts with addresses found');
    });
  });

  describe('search_map_contacts', () => {
    it('should return matching contacts', async () => {
      mockFetchMapsAPI.mockResolvedValue([
        { FN: 'Ada Lovelace', URI: 'ada.vcf', UID: 'ada', BOOKID: 1 },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'search_map_contacts')!;
      const result = await tool.handler({ query: 'Ada' });

      expect(result.content[0].text).toContain('Ada Lovelace');
      expect(result.content[0].text).toContain('ada.vcf');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/contacts-search', {
        queryParams: { query: 'Ada' },
      });
    });

    it('should handle empty results', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'search_map_contacts')!;
      const result = await tool.handler({ query: 'Nobody' });

      expect(result.content[0].text).toContain('No contacts matching "Nobody"');
    });
  });

  describe('place_map_contact', () => {
    it('should place a contact', async () => {
      mockFetchMapsAPI.mockResolvedValue('EDITED');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'place_map_contact')!;
      const result = await tool.handler({
        bookid: '1',
        uri: 'ada.vcf',
        uid: 'ada',
        lat: 52.52,
        lng: 13.405,
        city: 'Berlin',
      });

      expect(result.content[0].text).toContain('Contact ada placed at 52.52, 13.405');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/contacts/1/ada.vcf', {
        method: 'PUT',
        body: { uid: 'ada', lat: 52.52, lng: 13.405, city: 'Berlin' },
      });
    });

    it('should surface a non-EDITED status as an error', async () => {
      mockFetchMapsAPI.mockResolvedValue('CONTACT NOT WRITABLE');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'place_map_contact')!;
      const result = await tool.handler({
        bookid: '1',
        uri: 'ada.vcf',
        uid: 'ada',
        lat: 1,
        lng: 2,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('CONTACT NOT WRITABLE');
    });
  });

  describe('add_contact_to_map', () => {
    it('should add a contact to a map', async () => {
      mockFetchMapsAPI.mockResolvedValue('DONE');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'add_contact_to_map')!;
      const result = await tool.handler({ bookid: '1', uri: 'ada.vcf', myMapId: 42 });

      expect(result.content[0].text).toContain('added to map 42');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/contacts/1/ada.vcf/add-to-map/', {
        method: 'PUT',
        body: { myMapId: 42 },
      });
    });

    it('should surface a non-DONE status as an error', async () => {
      mockFetchMapsAPI.mockResolvedValue('MAP NOT FOUND');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'add_contact_to_map')!;
      const result = await tool.handler({ bookid: '1', uri: 'ada.vcf', myMapId: 9999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('MAP NOT FOUND');
    });
  });

  describe('delete_map_contact_address', () => {
    it('should remove an address', async () => {
      mockFetchMapsAPI.mockResolvedValue('DELETED');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'delete_map_contact_address')!;
      const result = await tool.handler({
        bookid: '1',
        uri: 'ada.vcf',
        uid: 'ada',
        adr: ';;Main St 1;Berlin;;10115;Germany',
        geo: '52.52;13.405',
      });

      expect(result.content[0].text).toContain('Address removed from contact ada');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/contacts/1/ada.vcf', {
        method: 'DELETE',
        body: {
          uid: 'ada',
          adr: ';;Main St 1;Berlin;;10115;Germany',
          geo: '52.52;13.405',
        },
      });
    });

    it('should surface a READONLY status as an error', async () => {
      mockFetchMapsAPI.mockResolvedValue('READONLY');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find((t) => t.name === 'delete_map_contact_address')!;
      const result = await tool.handler({
        bookid: '1',
        uri: 'ada.vcf',
        uid: 'ada',
        adr: 'x',
        geo: 'y',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('READONLY');
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
