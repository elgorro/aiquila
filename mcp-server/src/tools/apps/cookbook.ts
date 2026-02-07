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
 * Export all Cookbook app tools
 */
export const cookbookTools = [addRecipeTool];
