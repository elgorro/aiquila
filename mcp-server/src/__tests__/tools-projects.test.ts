import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchAiquilaAPI = vi.fn();

vi.mock('../client/aiquila.js', async () => {
  const actual =
    await vi.importActual<typeof import('../client/aiquila.js')>('../client/aiquila.js');
  return {
    ...actual,
    fetchAiquilaAPI: (...args: unknown[]) => mockFetchAiquilaAPI(...args),
  };
});

const sampleProject = {
  id: 1,
  userId: 'admin',
  title: 'My Project',
  description: 'A test project',
  systemPrompt: 'You are helpful',
  isActive: true,
  createdAt: 1700000000,
  updatedAt: 1700000000,
  paths: [
    { id: 10, projectId: 1, path: '/Documents', pathType: 'directory', createdAt: 1700000000 },
  ],
};

describe('Project Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_projects', () => {
    it('should return formatted project list', async () => {
      mockFetchAiquilaAPI.mockResolvedValue([sampleProject]);

      const { listProjectsTool } = await import('../tools/apps/projects.js');
      const result = await listProjectsTool.handler();

      expect(result.content[0].text).toContain('My Project');
      expect(result.content[0].text).toContain('/Documents');
      expect(result.content[0].text).toContain('Projects (1)');
    });

    it('should handle empty list', async () => {
      mockFetchAiquilaAPI.mockResolvedValue([]);

      const { listProjectsTool } = await import('../tools/apps/projects.js');
      const result = await listProjectsTool.handler();

      expect(result.content[0].text).toContain('No projects found');
    });

    it('should handle errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('Connection refused'));

      const { listProjectsTool } = await import('../tools/apps/projects.js');
      const result = await listProjectsTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Connection refused');
    });
  });

  describe('create_project', () => {
    it('should create project with all fields', async () => {
      mockFetchAiquilaAPI.mockResolvedValue(sampleProject);

      const { createProjectTool } = await import('../tools/apps/projects.js');
      const result = await createProjectTool.handler({
        title: 'My Project',
        description: 'A test project',
        systemPrompt: 'You are helpful',
      });

      expect(result.content[0].text).toContain('Project created');
      expect(result.content[0].text).toContain('My Project');
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/projects', {
        method: 'POST',
        body: {
          title: 'My Project',
          description: 'A test project',
          systemPrompt: 'You are helpful',
        },
      });
    });

    it('should handle errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('Validation error'));

      const { createProjectTool } = await import('../tools/apps/projects.js');
      const result = await createProjectTool.handler({ title: '' });

      expect(result.isError).toBe(true);
    });
  });

  describe('get_project', () => {
    it('should return formatted project', async () => {
      mockFetchAiquilaAPI.mockResolvedValue(sampleProject);

      const { getProjectTool } = await import('../tools/apps/projects.js');
      const result = await getProjectTool.handler({ id: 1 });

      expect(result.content[0].text).toContain('My Project');
      expect(result.content[0].text).toContain('You are helpful');
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/projects/1');
    });

    it('should handle errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('Not found'));

      const { getProjectTool } = await import('../tools/apps/projects.js');
      const result = await getProjectTool.handler({ id: 999 });

      expect(result.isError).toBe(true);
    });
  });

  describe('update_project', () => {
    it('should only send defined fields', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({ ...sampleProject, title: 'Updated' });

      const { updateProjectTool } = await import('../tools/apps/projects.js');
      const result = await updateProjectTool.handler({ id: 1, title: 'Updated' });

      expect(result.content[0].text).toContain('Project updated');
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/projects/1', {
        method: 'PUT',
        body: { title: 'Updated' },
      });
    });

    it('should handle errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('Forbidden'));

      const { updateProjectTool } = await import('../tools/apps/projects.js');
      const result = await updateProjectTool.handler({ id: 1, title: 'x' });

      expect(result.isError).toBe(true);
    });
  });

  describe('delete_project', () => {
    it('should delete and return success', async () => {
      mockFetchAiquilaAPI.mockResolvedValue(undefined);

      const { deleteProjectTool } = await import('../tools/apps/projects.js');
      const result = await deleteProjectTool.handler({ id: 1 });

      expect(result.content[0].text).toContain('deleted');
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/projects/1', { method: 'DELETE' });
    });

    it('should handle errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('Not found'));

      const { deleteProjectTool } = await import('../tools/apps/projects.js');
      const result = await deleteProjectTool.handler({ id: 999 });

      expect(result.isError).toBe(true);
    });
  });

  describe('add_project_path', () => {
    it('should add path and return details', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        id: 10,
        projectId: 1,
        path: '/Documents/report.pdf',
        pathType: 'file',
        createdAt: 1700000000,
      });

      const { addProjectPathTool } = await import('../tools/apps/projects.js');
      const result = await addProjectPathTool.handler({
        id: 1,
        path: '/Documents/report.pdf',
        pathType: 'file',
      });

      expect(result.content[0].text).toContain('/Documents/report.pdf');
      expect(result.content[0].text).toContain('file');
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/projects/1/paths', {
        method: 'POST',
        body: { path: '/Documents/report.pdf', pathType: 'file' },
      });
    });

    it('should handle errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('Path not found'));

      const { addProjectPathTool } = await import('../tools/apps/projects.js');
      const result = await addProjectPathTool.handler({
        id: 1,
        path: '/nonexistent',
        pathType: 'file',
      });

      expect(result.isError).toBe(true);
    });
  });

  describe('remove_project_path', () => {
    it('should remove path and return success', async () => {
      mockFetchAiquilaAPI.mockResolvedValue(undefined);

      const { removeProjectPathTool } = await import('../tools/apps/projects.js');
      const result = await removeProjectPathTool.handler({ id: 1, pathId: 10 });

      expect(result.content[0].text).toContain('removed');
      expect(result.content[0].text).toContain('#10');
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/projects/1/paths/10', {
        method: 'DELETE',
      });
    });

    it('should handle errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('Not found'));

      const { removeProjectPathTool } = await import('../tools/apps/projects.js');
      const result = await removeProjectPathTool.handler({ id: 1, pathId: 999 });

      expect(result.isError).toBe(true);
    });
  });
});
