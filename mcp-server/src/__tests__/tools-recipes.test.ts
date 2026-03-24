import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockClient = {
  getDirectoryContents: vi.fn(),
  getFileContents: vi.fn(),
  putFileContents: vi.fn(),
  createDirectory: vi.fn(),
  deleteFile: vi.fn(),
  moveFile: vi.fn(),
  copyFile: vi.fn(),
};
vi.mock('webdav', () => ({ createClient: vi.fn(() => mockClient) }));

describe('Recipe Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_recipes', () => {
    const sampleRecipeJson = (name: string, category: string, keywords: string) =>
      JSON.stringify({
        id: '123',
        name,
        description: '',
        url: '',
        image: '',
        prepTime: 'PT30M',
        cookTime: 'PT1H',
        totalTime: 'PT1H30M',
        recipeCategory: category,
        keywords,
        recipeYield: 4,
        tool: [],
        recipeIngredient: [],
        recipeInstructions: [],
        nutrition: { '@type': 'NutritionInformation' },
        '@context': 'http://schema.org',
        '@type': 'Recipe',
        dateModified: '2024-12-01T00:00:00+0000',
        dateCreated: '2024-11-01T00:00:00+0000',
        datePublished: null,
        printImage: true,
        imageUrl: '',
      });

    it('should list recipe folders and parse recipe.json', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'pasta-carbonara' },
        { type: 'directory', basename: 'chicken-curry' },
      ]);
      mockClient.getFileContents
        .mockResolvedValueOnce(sampleRecipeJson('Pasta Carbonara', 'Italian', 'pasta'))
        .mockResolvedValueOnce(sampleRecipeJson('Chicken Curry', 'Indian', 'curry,spicy'));

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({});

      expect(result.content[0].text).toContain('Pasta Carbonara');
      expect(result.content[0].text).toContain('Chicken Curry');
      expect(result.content[0].text).toContain('2 found');
    });

    it('should filter recipes by search term', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'pasta-carbonara' },
        { type: 'directory', basename: 'chicken-curry' },
      ]);
      mockClient.getFileContents
        .mockResolvedValueOnce(sampleRecipeJson('Pasta Carbonara', 'Italian', 'pasta'))
        .mockResolvedValueOnce(sampleRecipeJson('Chicken Curry', 'Indian', 'curry,spicy'));

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({ search: 'pasta' });

      expect(result.content[0].text).toContain('Pasta Carbonara');
      expect(result.content[0].text).not.toContain('Chicken Curry');
      expect(result.content[0].text).toContain('1 found');
    });

    it('should filter recipes by category', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'pasta-carbonara' },
        { type: 'directory', basename: 'chicken-curry' },
      ]);
      mockClient.getFileContents
        .mockResolvedValueOnce(sampleRecipeJson('Pasta Carbonara', 'Italian', 'pasta'))
        .mockResolvedValueOnce(sampleRecipeJson('Chicken Curry', 'Indian', 'curry,spicy'));

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({ category: 'indian' });

      expect(result.content[0].text).toContain('Chicken Curry');
      expect(result.content[0].text).not.toContain('Pasta Carbonara');
    });

    it('should filter recipes by keyword', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'pasta-carbonara' },
        { type: 'directory', basename: 'chicken-curry' },
      ]);
      mockClient.getFileContents
        .mockResolvedValueOnce(sampleRecipeJson('Pasta Carbonara', 'Italian', 'pasta'))
        .mockResolvedValueOnce(sampleRecipeJson('Chicken Curry', 'Indian', 'curry,spicy'));

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({ keyword: 'spicy' });

      expect(result.content[0].text).toContain('Chicken Curry');
      expect(result.content[0].text).not.toContain('Pasta Carbonara');
    });

    it('should handle empty recipes folder', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([]);

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({});

      expect(result.content[0].text).toContain('No recipes found');
    });

    it('should skip folders without valid recipe.json', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'valid-recipe' },
        { type: 'directory', basename: 'broken-recipe' },
      ]);
      mockClient.getFileContents
        .mockResolvedValueOnce(sampleRecipeJson('Valid Recipe', 'Easy', ''))
        .mockRejectedValueOnce(new Error('404 Not Found'));

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({});

      expect(result.content[0].text).toContain('Valid Recipe');
      expect(result.content[0].text).toContain('1 found');
    });

    it('should handle errors', async () => {
      mockClient.getDirectoryContents.mockRejectedValue(new Error('WebDAV error'));

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('WebDAV error');
    });
  });

  describe('get_recipe', () => {
    it('should return formatted recipe details', async () => {
      const recipeJson = JSON.stringify({
        id: '123',
        name: 'Pasta Carbonara',
        description: 'Classic Italian',
        url: '',
        image: '',
        prepTime: 'PT15M',
        cookTime: 'PT20M',
        totalTime: 'PT35M',
        recipeCategory: 'Italian',
        keywords: 'pasta,quick',
        recipeYield: 4,
        tool: ['Pot'],
        recipeIngredient: ['400g spaghetti', '200g pancetta'],
        recipeInstructions: ['Cook pasta', 'Fry pancetta'],
        nutrition: { '@type': 'NutritionInformation', calories: '500 kJ' },
        '@context': 'http://schema.org',
        '@type': 'Recipe',
        dateModified: '2024-12-01T00:00:00+0000',
        dateCreated: '2024-11-01T00:00:00+0000',
        datePublished: null,
        printImage: true,
        imageUrl: '',
      });
      mockClient.getFileContents.mockResolvedValue(recipeJson);

      const { getRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await getRecipeTool.handler({ folderName: 'pasta-carbonara' });

      expect(result.content[0].text).toContain('Pasta Carbonara');
      expect(result.content[0].text).toContain('400g spaghetti');
      expect(result.content[0].text).toContain('Cook pasta');
      expect(result.content[0].text).toContain('Italian');
      expect(mockClient.getFileContents).toHaveBeenCalledWith(
        '/Recipes/pasta-carbonara/recipe.json',
        { format: 'text' }
      );
    });

    it('should handle nonexistent recipe', async () => {
      mockClient.getFileContents.mockRejectedValue(new Error('404 Not Found'));

      const { getRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await getRecipeTool.handler({ folderName: 'nonexistent' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('update_recipe', () => {
    it('should merge provided fields into existing recipe', async () => {
      const existingJson = JSON.stringify({
        id: '123',
        name: 'Pasta Carbonara',
        description: 'Classic',
        url: '',
        image: '',
        prepTime: 'PT15M',
        cookTime: 'PT20M',
        totalTime: 'PT35M',
        recipeCategory: 'Italian',
        keywords: 'pasta',
        recipeYield: 4,
        tool: [],
        recipeIngredient: ['400g spaghetti'],
        recipeInstructions: ['Cook pasta'],
        nutrition: { '@type': 'NutritionInformation' },
        '@context': 'http://schema.org',
        '@type': 'Recipe',
        dateModified: '2024-12-01T00:00:00+0000',
        dateCreated: '2024-11-01T00:00:00+0000',
        datePublished: null,
        printImage: true,
        imageUrl: '',
      });
      mockClient.getFileContents.mockResolvedValue(existingJson);
      mockClient.putFileContents.mockResolvedValue(undefined);

      const { updateRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await updateRecipeTool.handler({
        folderName: 'pasta-carbonara',
        description: 'Updated description',
        recipeYield: 6,
      });

      expect(result.content[0].text).toContain('updated successfully');
      const writtenJson = JSON.parse(mockClient.putFileContents.mock.calls[0][1]);
      expect(writtenJson.description).toBe('Updated description');
      expect(writtenJson.recipeYield).toBe(6);
      expect(writtenJson.name).toBe('Pasta Carbonara'); // preserved
      expect(writtenJson.recipeIngredient).toEqual(['400g spaghetti']); // preserved
    });

    it('should handle nonexistent recipe', async () => {
      mockClient.getFileContents.mockRejectedValue(new Error('404 Not Found'));

      const { updateRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await updateRecipeTool.handler({
        folderName: 'nonexistent',
        name: 'New Name',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('delete_recipe', () => {
    it('should delete recipe folder', async () => {
      mockClient.deleteFile.mockResolvedValue(undefined);

      const { deleteRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await deleteRecipeTool.handler({ folderName: 'pasta-carbonara' });

      expect(mockClient.deleteFile).toHaveBeenCalledWith('/Recipes/pasta-carbonara');
      expect(result.content[0].text).toContain('deleted successfully');
    });

    it('should handle nonexistent recipe folder', async () => {
      mockClient.deleteFile.mockRejectedValue(new Error('404 Not Found'));

      const { deleteRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await deleteRecipeTool.handler({ folderName: 'nonexistent' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('list_recipe_categories', () => {
    it('should return unique categories', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'pasta' },
        { type: 'directory', basename: 'curry' },
        { type: 'directory', basename: 'salad' },
      ]);
      mockClient.getFileContents
        .mockResolvedValueOnce(
          JSON.stringify({ name: 'Pasta', recipeCategory: 'Italian', keywords: '', recipeYield: 0 })
        )
        .mockResolvedValueOnce(
          JSON.stringify({ name: 'Curry', recipeCategory: 'Indian', keywords: '', recipeYield: 0 })
        )
        .mockResolvedValueOnce(
          JSON.stringify({ name: 'Salad', recipeCategory: 'Italian', keywords: '', recipeYield: 0 })
        );

      const { listRecipeCategoriesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipeCategoriesTool.handler();

      expect(result.content[0].text).toContain('Indian');
      expect(result.content[0].text).toContain('Italian');
      expect(result.content[0].text).toContain('2'); // 2 unique categories
    });
  });
});
