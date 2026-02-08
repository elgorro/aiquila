import { z } from "zod";
import { getWebDAVClient } from "../../client/webdav.js";

/**
 * Nextcloud Cookbook App Tools
 * Manages recipes as schema.org Recipe JSON files in per-recipe subfolders
 */

const RECIPES_ROOT = "/Recipes";

interface RecipeNutrition {
  "@type": "NutritionInformation";
  calories?: string;
  carbohydrateContent?: string;
  proteinContent?: string;
  sugarContent?: string;
  fatContent?: string;
}

interface Recipe {
  id: string;
  name: string;
  description: string;
  url: string;
  image: string;
  prepTime: string;
  cookTime: string;
  totalTime: string;
  recipeCategory: string;
  keywords: string;
  recipeYield: number;
  tool: string[];
  recipeIngredient: string[];
  recipeInstructions: string[];
  nutrition: RecipeNutrition;
  "@context": "http://schema.org";
  "@type": "Recipe";
  dateModified: string;
  dateCreated: string;
  datePublished: string | null;
  printImage: boolean;
  imageUrl: string;
}

function slugify(name: string): string {
  let slug = name
    .toLowerCase()
    .replace(/[^a-z0-9\u00C0-\u024F-]/g, "-")
    .replace(/-+/g, "-")
    .replace(/^-|-$/g, "");
  return slug || `recipe-${Date.now()}`;
}

function isoNow(): string {
  return new Date().toISOString().replace(/\.\d{3}Z$/, "+0000");
}

function buildRecipeJson(
  fields: Partial<Recipe> & { name: string }
): Recipe {
  const now = isoNow();
  return {
    id: String(Date.now()),
    name: fields.name,
    description: fields.description ?? "",
    url: fields.url ?? "",
    image: fields.image ?? "",
    prepTime: fields.prepTime ?? "PT0H0M0S",
    cookTime: fields.cookTime ?? "PT0H0M0S",
    totalTime: fields.totalTime ?? "PT0H0M0S",
    recipeCategory: fields.recipeCategory ?? "",
    keywords: fields.keywords ?? "",
    recipeYield: fields.recipeYield ?? 1,
    tool: fields.tool ?? [],
    recipeIngredient: fields.recipeIngredient ?? [],
    recipeInstructions: fields.recipeInstructions ?? [],
    nutrition: fields.nutrition ?? { "@type": "NutritionInformation" },
    "@context": "http://schema.org",
    "@type": "Recipe",
    dateModified: now,
    dateCreated: now,
    datePublished: null,
    printImage: true,
    imageUrl: "",
  };
}

function formatRecipeSummary(
  folderName: string,
  recipe: Recipe
): string {
  const parts = [`- **${recipe.name}** (folder: ${folderName})`];
  if (recipe.recipeCategory) parts.push(`  Category: ${recipe.recipeCategory}`);
  if (recipe.keywords) parts.push(`  Keywords: ${recipe.keywords}`);
  if (recipe.recipeYield) parts.push(`  Servings: ${recipe.recipeYield}`);
  return parts.join("\n");
}

function formatRecipeDetail(
  folderName: string,
  recipe: Recipe
): string {
  const lines: string[] = [];
  lines.push(`# ${recipe.name}`);
  lines.push(`Folder: ${folderName}`);
  lines.push("");

  if (recipe.description) lines.push(`${recipe.description}\n`);

  lines.push("## Info");
  if (recipe.recipeCategory) lines.push(`- Category: ${recipe.recipeCategory}`);
  if (recipe.keywords) lines.push(`- Keywords: ${recipe.keywords}`);
  if (recipe.recipeYield) lines.push(`- Servings: ${recipe.recipeYield}`);
  if (recipe.prepTime && recipe.prepTime !== "PT0H0M0S")
    lines.push(`- Prep Time: ${recipe.prepTime}`);
  if (recipe.cookTime && recipe.cookTime !== "PT0H0M0S")
    lines.push(`- Cook Time: ${recipe.cookTime}`);
  if (recipe.totalTime && recipe.totalTime !== "PT0H0M0S")
    lines.push(`- Total Time: ${recipe.totalTime}`);
  if (recipe.url) lines.push(`- Source: ${recipe.url}`);
  lines.push("");

  if (recipe.tool.length > 0) {
    lines.push("## Tools");
    recipe.tool.forEach((t) => lines.push(`- ${t}`));
    lines.push("");
  }

  if (recipe.recipeIngredient.length > 0) {
    lines.push("## Ingredients");
    recipe.recipeIngredient.forEach((i) => lines.push(`- ${i}`));
    lines.push("");
  }

  if (recipe.recipeInstructions.length > 0) {
    lines.push("## Instructions");
    recipe.recipeInstructions.forEach((step, idx) =>
      lines.push(`${idx + 1}. ${step}`)
    );
    lines.push("");
  }

  const n = recipe.nutrition;
  if (n.calories || n.carbohydrateContent || n.proteinContent || n.sugarContent || n.fatContent) {
    lines.push("## Nutrition");
    if (n.calories) lines.push(`- Calories: ${n.calories}`);
    if (n.carbohydrateContent) lines.push(`- Carbohydrates: ${n.carbohydrateContent}`);
    if (n.proteinContent) lines.push(`- Protein: ${n.proteinContent}`);
    if (n.sugarContent) lines.push(`- Sugar: ${n.sugarContent}`);
    if (n.fatContent) lines.push(`- Fat: ${n.fatContent}`);
    lines.push("");
  }

  return lines.join("\n");
}

async function readAllRecipes(): Promise<
  { folderName: string; recipe: Recipe }[]
> {
  const client = getWebDAVClient();
  const items = await client.getDirectoryContents(`${RECIPES_ROOT}/`);
  const dirs = (Array.isArray(items) ? items : []).filter(
    (item: { type: string }) => item.type === "directory"
  );

  const results = await Promise.allSettled(
    dirs.map(async (dir: { basename: string }) => {
      const json = await client.getFileContents(
        `${RECIPES_ROOT}/${dir.basename}/recipe.json`,
        { format: "text" }
      );
      return {
        folderName: dir.basename,
        recipe: JSON.parse(json as string) as Recipe,
      };
    })
  );

  return results
    .filter(
      (r): r is PromiseFulfilledResult<{ folderName: string; recipe: Recipe }> =>
        r.status === "fulfilled"
    )
    .map((r) => r.value);
}

/**
 * List all recipes in Nextcloud Cookbook
 */
export const listRecipesTool = {
  name: "list_recipes",
  description:
    "List all recipes in Nextcloud Cookbook. Optionally filter by name, category, or keyword.",
  inputSchema: z.object({
    search: z
      .string()
      .optional()
      .describe("Search term to filter recipes by name (case-insensitive)"),
    category: z
      .string()
      .optional()
      .describe("Filter by recipe category"),
    keyword: z
      .string()
      .optional()
      .describe("Filter by keyword"),
  }),
  handler: async (args: {
    search?: string;
    category?: string;
    keyword?: string;
  }) => {
    try {
      let entries = await readAllRecipes();

      if (args.search) {
        const s = args.search.toLowerCase();
        entries = entries.filter((e) =>
          e.recipe.name.toLowerCase().includes(s)
        );
      }
      if (args.category) {
        const c = args.category.toLowerCase();
        entries = entries.filter(
          (e) => e.recipe.recipeCategory.toLowerCase() === c
        );
      }
      if (args.keyword) {
        const k = args.keyword.toLowerCase();
        entries = entries.filter((e) =>
          e.recipe.keywords
            .toLowerCase()
            .split(",")
            .some((kw) => kw.trim() === k)
        );
      }

      if (entries.length === 0) {
        return {
          content: [
            {
              type: "text" as const,
              text: args.search || args.category || args.keyword
                ? "No recipes found matching the given filters."
                : "No recipes found.",
            },
          ],
        };
      }

      const formatted = entries
        .map((e) => formatRecipeSummary(e.folderName, e.recipe))
        .join("\n");

      return {
        content: [
          {
            type: "text" as const,
            text: `Recipes (${entries.length} found):\n\n${formatted}`,
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
 * List unique recipe categories
 */
export const listRecipeCategoriesTool = {
  name: "list_recipe_categories",
  description:
    "List all unique recipe categories found across recipes in Nextcloud Cookbook.",
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const entries = await readAllRecipes();
      const categories = [
        ...new Set(
          entries
            .map((e) => e.recipe.recipeCategory)
            .filter((c) => c !== "")
        ),
      ].sort();

      if (categories.length === 0) {
        return {
          content: [
            { type: "text" as const, text: "No recipe categories found." },
          ],
        };
      }

      return {
        content: [
          {
            type: "text" as const,
            text: `Recipe categories (${categories.length}):\n\n${categories.map((c) => `- ${c}`).join("\n")}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error listing categories: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Get full recipe details
 */
export const getRecipeTool = {
  name: "get_recipe",
  description:
    "Get full details of a recipe from Nextcloud Cookbook by its folder name.",
  inputSchema: z.object({
    folderName: z
      .string()
      .describe("The recipe folder name (as shown in list_recipes)"),
  }),
  handler: async (args: { folderName: string }) => {
    try {
      const client = getWebDAVClient();
      const json = await client.getFileContents(
        `${RECIPES_ROOT}/${args.folderName}/recipe.json`,
        { format: "text" }
      );
      const recipe = JSON.parse(json as string) as Recipe;

      return {
        content: [
          {
            type: "text" as const,
            text: formatRecipeDetail(args.folderName, recipe),
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error reading recipe "${args.folderName}": ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

const nutritionSchema = z
  .object({
    calories: z.string().optional().describe("Calories (e.g., '450 kJ')"),
    carbohydrateContent: z
      .string()
      .optional()
      .describe("Carbohydrates (e.g., '2 g')"),
    proteinContent: z
      .string()
      .optional()
      .describe("Protein (e.g., '3 g')"),
    sugarContent: z.string().optional().describe("Sugar (e.g., '12 g')"),
    fatContent: z.string().optional().describe("Fat (e.g., '10 g')"),
  })
  .optional()
  .describe("Nutrition information");

/**
 * Create a new recipe
 */
export const createRecipeTool = {
  name: "create_recipe",
  description:
    "Create a new recipe in Nextcloud Cookbook with schema.org Recipe format.",
  inputSchema: z.object({
    name: z.string().describe("Recipe name"),
    description: z.string().optional().describe("Brief description"),
    url: z.string().optional().describe("Source URL"),
    image: z.string().optional().describe("Image URL"),
    prepTime: z
      .string()
      .optional()
      .describe("Preparation time in ISO 8601 duration (e.g., PT30M)"),
    cookTime: z
      .string()
      .optional()
      .describe("Cooking time in ISO 8601 duration (e.g., PT1H)"),
    totalTime: z
      .string()
      .optional()
      .describe("Total time in ISO 8601 duration"),
    recipeCategory: z
      .string()
      .optional()
      .describe("Category (e.g., 'Easy', 'Dessert')"),
    keywords: z
      .string()
      .optional()
      .describe("Comma-separated keywords (e.g., 'pasta,italian')"),
    recipeYield: z.number().optional().describe("Number of servings"),
    tool: z.array(z.string()).optional().describe("Kitchen tools needed"),
    recipeIngredient: z
      .array(z.string())
      .optional()
      .describe("List of ingredients"),
    recipeInstructions: z
      .array(z.string())
      .optional()
      .describe("Step-by-step instructions"),
    nutrition: nutritionSchema,
  }),
  handler: async (args: {
    name: string;
    description?: string;
    url?: string;
    image?: string;
    prepTime?: string;
    cookTime?: string;
    totalTime?: string;
    recipeCategory?: string;
    keywords?: string;
    recipeYield?: number;
    tool?: string[];
    recipeIngredient?: string[];
    recipeInstructions?: string[];
    nutrition?: {
      calories?: string;
      carbohydrateContent?: string;
      proteinContent?: string;
      sugarContent?: string;
      fatContent?: string;
    };
  }) => {
    try {
      const client = getWebDAVClient();

      // Find unique folder name
      let folderName = slugify(args.name);
      const items = await client.getDirectoryContents(`${RECIPES_ROOT}/`);
      const existing = new Set(
        (Array.isArray(items) ? items : [])
          .filter((i: { type: string }) => i.type === "directory")
          .map((i: { basename: string }) => i.basename)
      );

      if (existing.has(folderName)) {
        let suffix = 2;
        while (existing.has(`${folderName}-${suffix}`)) suffix++;
        folderName = `${folderName}-${suffix}`;
      }

      const nutrition: RecipeNutrition | undefined = args.nutrition
        ? { "@type": "NutritionInformation" as const, ...args.nutrition }
        : undefined;

      const recipe = buildRecipeJson({
        ...args,
        nutrition,
      });

      await client.createDirectory(`${RECIPES_ROOT}/${folderName}`);
      await client.putFileContents(
        `${RECIPES_ROOT}/${folderName}/recipe.json`,
        JSON.stringify(recipe, null, 2),
        { overwrite: true }
      );

      return {
        content: [
          {
            type: "text" as const,
            text: `Recipe "${args.name}" created successfully in folder "${folderName}".`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error creating recipe: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Update an existing recipe
 */
export const updateRecipeTool = {
  name: "update_recipe",
  description:
    "Update an existing recipe in Nextcloud Cookbook. Only provided fields are changed.",
  inputSchema: z.object({
    folderName: z.string().describe("The recipe folder name"),
    name: z.string().optional().describe("New recipe name"),
    description: z.string().optional().describe("New description"),
    url: z.string().optional().describe("New source URL"),
    image: z.string().optional().describe("New image URL"),
    prepTime: z.string().optional().describe("New prep time (ISO 8601 duration)"),
    cookTime: z.string().optional().describe("New cook time (ISO 8601 duration)"),
    totalTime: z
      .string()
      .optional()
      .describe("New total time (ISO 8601 duration)"),
    recipeCategory: z.string().optional().describe("New category"),
    keywords: z.string().optional().describe("New keywords"),
    recipeYield: z.number().optional().describe("New servings count"),
    tool: z.array(z.string()).optional().describe("New tools list"),
    recipeIngredient: z
      .array(z.string())
      .optional()
      .describe("New ingredients list"),
    recipeInstructions: z
      .array(z.string())
      .optional()
      .describe("New instructions list"),
    nutrition: nutritionSchema,
  }),
  handler: async (args: {
    folderName: string;
    name?: string;
    description?: string;
    url?: string;
    image?: string;
    prepTime?: string;
    cookTime?: string;
    totalTime?: string;
    recipeCategory?: string;
    keywords?: string;
    recipeYield?: number;
    tool?: string[];
    recipeIngredient?: string[];
    recipeInstructions?: string[];
    nutrition?: {
      calories?: string;
      carbohydrateContent?: string;
      proteinContent?: string;
      sugarContent?: string;
      fatContent?: string;
    };
  }) => {
    try {
      const client = getWebDAVClient();
      const recipePath = `${RECIPES_ROOT}/${args.folderName}/recipe.json`;

      const json = await client.getFileContents(recipePath, {
        format: "text",
      });
      const recipe = JSON.parse(json as string) as Recipe;

      // Merge provided fields
      const { folderName: _, ...updates } = args;
      for (const [key, value] of Object.entries(updates)) {
        if (value !== undefined) {
          if (key === "nutrition") {
            recipe.nutrition = {
              "@type": "NutritionInformation",
              ...recipe.nutrition,
              ...value,
            };
          } else {
            (recipe as Record<string, unknown>)[key] = value;
          }
        }
      }

      recipe.dateModified = isoNow();

      await client.putFileContents(
        recipePath,
        JSON.stringify(recipe, null, 2),
        { overwrite: true }
      );

      return {
        content: [
          {
            type: "text" as const,
            text: `Recipe "${recipe.name}" updated successfully.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error updating recipe "${args.folderName}": ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Delete a recipe
 */
export const deleteRecipeTool = {
  name: "delete_recipe",
  description:
    "Delete a recipe from Nextcloud Cookbook. This removes the entire recipe folder including images. This action is irreversible.",
  inputSchema: z.object({
    folderName: z
      .string()
      .describe("The recipe folder name to delete"),
  }),
  handler: async (args: { folderName: string }) => {
    try {
      const client = getWebDAVClient();
      await client.deleteFile(`${RECIPES_ROOT}/${args.folderName}`);

      return {
        content: [
          {
            type: "text" as const,
            text: `Recipe folder "${args.folderName}" deleted successfully.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error deleting recipe "${args.folderName}": ${error instanceof Error ? error.message : String(error)}`,
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
export const cookbookTools = [
  listRecipesTool,
  listRecipeCategoriesTool,
  getRecipeTool,
  createRecipeTool,
  updateRecipeTool,
  deleteRecipeTool,
];
