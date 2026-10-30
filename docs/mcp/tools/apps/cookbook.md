# Nextcloud Cookbook Tools

Integration with the [Nextcloud Cookbook](https://apps.nextcloud.com/apps/cookbook) app: list,
read, create, update and delete recipes.

## Prerequisites

- Cookbook app installed and enabled (app ID: `cookbook`)

## How recipes are stored

Cookbook keeps each recipe as a [schema.org Recipe](https://schema.org/Recipe) `recipe.json`
in its own subfolder of the user's recipe folder. The tools work on those files over WebDAV:

- The recipe folder is the user's Cookbook setting (`GET /apps/cookbook/api/v1/config`,
  default `/Recipes`). It is created with the first recipe.
- A new recipe goes into a folder named after it (`Pasta Carbonara` → `pasta-carbonara`, with a
  numeric suffix if that folder exists). The folder name is the recipe's identifier in the
  other tools.
- After a create, update or delete the tools ask Cookbook to reindex, so the change shows up in
  the app at once rather than at its next scheduled scan.

## Available Tools

### list_recipes

List recipes, optionally filtered.

**Parameters:**
- `search` (string, optional): substring of the recipe name
- `category` (string, optional): category (case-insensitive)
- `keyword` (string, optional): one of the recipe's comma-separated keywords

### list_recipe_categories

List the categories used across all recipes.

### get_recipe

Full details of one recipe: info, times, ingredients, instructions, tools and nutrition.

**Parameters:**
- `folderName` (string, required)

### create_recipe

Create a recipe.

**Parameters:**
- `name` (string, required)
- `description`, `url`, `image`, `recipeCategory`, `keywords` (string, optional)
- `prepTime`, `cookTime`, `totalTime` (string, optional): ISO 8601 durations, e.g. `PT30M`
- `recipeYield` (number, optional): servings
- `tool`, `recipeIngredient`, `recipeInstructions` (string arrays, optional)
- `nutrition` (object, optional): schema.org `NutritionInformation` fields

### update_recipe

Change the given fields of a recipe; the others are kept.

**Parameters:**
- `folderName` (string, required)
- any of the `create_recipe` fields

### delete_recipe

Delete a recipe folder, including its images. This cannot be undone.

**Parameters:**
- `folderName` (string, required)

## References

- [Cookbook app](https://github.com/nextcloud/cookbook)
- [schema.org Recipe](https://schema.org/Recipe)
