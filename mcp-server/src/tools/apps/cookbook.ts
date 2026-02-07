import { z } from "zod";
import { getWebDAVClient } from "../../client/webdav.js";

/**
 * Nextcloud Cookbook App Tools
 * Provides recipe management via markdown files
 */

/**
 * Add a recipe to Nextcloud Cookbook
 */
export const addRecipeTool = {
  name: "add_recipe",
  description: "Add a recipe to Nextcloud Cookbook",
  inputSchema: z.object({
    name: z.string().describe("The name of the recipe"),
    ingredients: z.string().describe("The ingredients for the recipe"),
    instructions: z.string().describe("The cooking instructions"),
    prepTime: z.string().optional().describe("Preparation time (e.g., '15 minutes')"),
    cookTime: z.string().optional().describe("Cooking time (e.g., '30 minutes')"),
    servings: z.string().optional().describe("Number of servings (e.g., '4')"),
  }),
  handler: async (args: {
    name: string;
    ingredients: string;
    instructions: string;
    prepTime?: string;
    cookTime?: string;
    servings?: string;
  }) => {
    const client = getWebDAVClient();

    let recipeContent = `# ${args.name}\n\n`;

    if (args.prepTime || args.cookTime || args.servings) {
      recipeContent += "## Info\n\n";
      if (args.prepTime) recipeContent += `- **Prep Time:** ${args.prepTime}\n`;
      if (args.cookTime) recipeContent += `- **Cook Time:** ${args.cookTime}\n`;
      if (args.servings) recipeContent += `- **Servings:** ${args.servings}\n`;
      recipeContent += "\n";
    }

    recipeContent += `## Ingredients\n\n${args.ingredients}\n\n`;
    recipeContent += `## Instructions\n\n${args.instructions}\n`;

    const recipePath = `/Recipes/${args.name}.md`;

    await client.putFileContents(recipePath, recipeContent, {
      overwrite: true,
    });

    return {
      content: [
        {
          type: "text",
          text: `Recipe "${args.name}" added successfully to ${recipePath}`,
        },
      ],
    };
  },
};

/**
 * List all recipes in Nextcloud Cookbook
 */
export const listRecipesTool = {
  name: "list_recipes",
  description:
    "List all recipes in Nextcloud Cookbook. Returns recipe names, sizes, and modification dates.",
  inputSchema: z.object({
    search: z
      .string()
      .optional()
      .describe("Optional search string to filter recipes by name"),
  }),
  handler: async (args: { search?: string }) => {
    try {
      const client = getWebDAVClient();
      const items = await client.getDirectoryContents("/Recipes/");
      const files = (Array.isArray(items) ? items : []).filter(
        (item: { type: string; basename: string }) =>
          item.type === "file" && item.basename.endsWith(".md")
      );

      let recipes = files.map(
        (f: { basename: string; size: number; lastmod: string }) => ({
          name: f.basename.replace(/\.md$/, ""),
          size: f.size,
          lastmod: f.lastmod,
        })
      );

      if (args.search) {
        const searchLower = args.search.toLowerCase();
        recipes = recipes.filter((r: { name: string }) =>
          r.name.toLowerCase().includes(searchLower)
        );
      }

      if (recipes.length === 0) {
        return {
          content: [
            {
              type: "text" as const,
              text: args.search
                ? `No recipes found matching "${args.search}".`
                : "No recipes found.",
            },
          ],
        };
      }

      const formatted = recipes
        .map((r: { name: string; size: number; lastmod: string }) => {
          const sizeKB = (r.size / 1024).toFixed(1);
          return `- ${r.name} (${sizeKB} KB, modified: ${r.lastmod})`;
        })
        .join("\n");

      return {
        content: [
          {
            type: "text" as const,
            text: `Recipes (${recipes.length} found):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error listing recipes: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Read a specific recipe from Nextcloud Cookbook
 */
export const getRecipeTool = {
  name: "get_recipe",
  description: "Read the content of a specific recipe from Nextcloud Cookbook",
  inputSchema: z.object({
    name: z.string().describe("The name of the recipe to read"),
  }),
  handler: async (args: { name: string }) => {
    try {
      const client = getWebDAVClient();
      const recipePath = `/Recipes/${args.name}.md`;
      const content = await client.getFileContents(recipePath, {
        format: "text",
      });

      return {
        content: [
          {
            type: "text" as const,
            text: content as string,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error reading recipe "${args.name}": ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Export all Cookbook app tools
 */
export const cookbookTools = [listRecipesTool, getRecipeTool, addRecipeTool];
