# Nextcloud Cookbook Tools

Integration with Nextcloud Cookbook app. Add recipes by creating markdown files in the `/Recipes` folder.

## Prerequisites

- Nextcloud Cookbook app installed (optional but recommended)
- `/Recipes` folder will be created automatically if it doesn't exist

## Overview

The Cookbook tool creates recipe files in markdown format that are compatible with the Nextcloud Cookbook app. Even without the Cookbook app installed, recipes are stored as readable markdown files.

## Available Tools

### add_recipe

Add a recipe to your Nextcloud Cookbook.

**Parameters:**
- `name` (string, required): The recipe name/title
- `ingredients` (string, required): List of ingredients
- `instructions` (string, required): Cooking instructions
- `prepTime` (string, optional): Preparation time (e.g., "15 minutes")
- `cookTime` (string, optional): Cooking time (e.g., "30 minutes")
- `servings` (string, optional): Number of servings (e.g., "4")

**Returns:**
Success message with the file path where the recipe was saved.

**Example Usage:**
```
Ask Claude: "Add a recipe for chocolate chip cookies"
Ask Claude: "Save this spaghetti carbonara recipe to my cookbook"
Ask Claude: "Create a recipe for vegetable soup with prep time 10 minutes"
```

**Example with minimal parameters:**
```json
{
  "name": "Simple Pancakes",
  "ingredients": "2 cups flour\n1 cup milk\n2 eggs\n1 tbsp sugar",
  "instructions": "1. Mix dry ingredients\n2. Add wet ingredients\n3. Cook on griddle"
}
```

**Example with all parameters:**
```json
{
  "name": "Chocolate Chip Cookies",
  "ingredients": "2 1/4 cups flour\n1 cup butter\n3/4 cup sugar\n2 eggs\n2 cups chocolate chips",
  "instructions": "1. Preheat oven to 375°F\n2. Cream butter and sugar\n3. Add eggs\n4. Mix in flour\n5. Fold in chocolate chips\n6. Bake 9-11 minutes",
  "prepTime": "15 minutes",
  "cookTime": "10 minutes",
  "servings": "48 cookies"
}
```

---

## Recipe File Format

Recipes are saved as markdown files in `/Recipes/{recipe-name}.md` with the following structure:

```markdown
# Chocolate Chip Cookies

## Info

- **Prep Time:** 15 minutes
- **Cook Time:** 10 minutes
- **Servings:** 48 cookies

## Ingredients

2 1/4 cups flour
1 cup butter
3/4 cup sugar
2 eggs
2 cups chocolate chips

## Instructions

1. Preheat oven to 375°F
2. Cream butter and sugar
3. Add eggs
4. Mix in flour
5. Fold in chocolate chips
6. Bake 9-11 minutes
```

## File Naming

Recipe file names are generated from the recipe name:
- Spaces and special characters are preserved in the title
- File names maintain readability

**Examples:**
- "Chocolate Chip Cookies" → `/Recipes/Chocolate Chip Cookies.md`
- "Mom's Lasagna" → `/Recipes/Mom's Lasagna.md`

## Organizing Recipes

### Adding Categories
You can organize recipes by creating subdirectories:

```
Ask Claude: "Create a folder /Recipes/Desserts"
Ask Claude: "Add a chocolate cake recipe and save it to /Recipes/Desserts/"
```

### Batch Adding
```
User: "Add these 3 recipes: pancakes, waffles, and french toast"
Claude: Creates three separate recipe files with appropriate ingredients and instructions
```

## Integration with Nextcloud Cookbook App

If you have the Nextcloud Cookbook app installed:

1. **Automatic Import**: Cookbook app scans the `/Recipes` folder
2. **Rich Interface**: View recipes with photos and ratings
3. **Meal Planning**: Use Cookbook features for planning
4. **Shopping Lists**: Generate shopping lists from recipes

Even without the app, recipes are accessible as markdown files through:
- Nextcloud Files web interface
- Mobile apps
- WebDAV access
- Other markdown editors

## Formatting Tips

### Ingredients
Use clear, line-separated format:
```
2 cups all-purpose flour
1 tsp baking powder
1/2 tsp salt
1 cup milk
```

Or grouped format:
```
Dry ingredients:
- 2 cups flour
- 1 tsp baking powder

Wet ingredients:
- 1 cup milk
- 2 eggs
```

### Instructions
Number your steps clearly:
```
1. Preheat oven to 350°F
2. Grease a 9x13 baking pan
3. Mix dry ingredients in a bowl
4. In separate bowl, combine wet ingredients
5. Fold wet into dry ingredients
6. Pour into pan and bake 25-30 minutes
```

### Time Format
Be specific and consistent:
- ✅ "15 minutes"
- ✅ "1 hour 30 minutes"
- ✅ "30-45 minutes"
- ❌ "15 min" (use full word)
- ❌ "1.5h" (spell it out)

## Workflow Examples

### Quick Recipe Entry
```
User: "Save this cookie recipe: mix butter, sugar, flour, and chocolate chips. Bake at 350 for 10 minutes."
Claude: Creates a properly formatted recipe with structured ingredients and instructions
```

### Detailed Recipe with Metadata
```
User: "Add a lasagna recipe with 20 minutes prep, 45 minutes cook time, serves 8"
Claude: Creates recipe with full info section including all timing and serving details
```

### Recipe from External Source
```
User: "I found a great pasta recipe online. [pastes recipe text]"
Claude: Parses the text and creates a properly structured recipe file
```

## Editing Recipes

To modify an existing recipe:

1. **Read the recipe**: `read_file /Recipes/Recipe Name.md`
2. **Make changes**: Have Claude update the content
3. **Write back**: `write_file /Recipes/Recipe Name.md` with new content

Example:
```
User: "Update my pancake recipe to add vanilla extract"
Claude: Reads the file, adds "1 tsp vanilla extract" to ingredients, writes it back
```

## Limitations

### Current Capabilities
- ✅ Create recipes
- ✅ Set basic metadata (prep time, cook time, servings)
- ✅ Format as markdown
- ✅ Organize in folders

### Not Yet Supported
- ❌ Recipe photos/images
- ❌ Nutrition information
- ❌ Recipe ratings
- ❌ Recipe categories (within Cookbook app)
- ❌ Tags
- ❌ Direct Cookbook app API integration

For these features, use the Nextcloud Cookbook web interface.

## Troubleshooting

### Recipe not appearing in Cookbook app
**Problem**: Created recipe file but it doesn't show in Cookbook

**Solution**:
- Refresh the Cookbook app
- Check that file is in `/Recipes` folder
- Verify file has `.md` extension
- Rescan recipe folder in Cookbook settings

---

### Special characters in recipe name
**Problem**: Recipe name has special characters causing issues

**Solution**:
- Recipe names with most special characters are supported
- Avoid using: `/` `\` `:` `*` `?` `"` `<` `>` `|`

## Integration with Other Tools

### With File System Tools
```
User: "List all my recipes"
Claude: Uses list_files on /Recipes folder

User: "Read my chocolate cake recipe"
Claude: Uses read_file on /Recipes/Chocolate Cake.md
```

### With Notes Tool
```
User: "Convert my cooking notes to a proper recipe"
Claude: Reads note, extracts recipe details, creates formatted recipe
```

## Security

- Recipes are stored with your Nextcloud user permissions
- Files are created via WebDAV with HTTPS encryption
- Use app passwords for better security

## Development

To extend cookbook tools:
- See [Adding Tools Guide](../../development/adding-tools.md)
- Source code: [mcp-server/src/tools/apps/cookbook.ts](../../../../mcp-server/src/tools/apps/cookbook.ts)
- Uses WebDAV client: [mcp-server/src/client/webdav.ts](../../../../mcp-server/src/client/webdav.ts)

## References

- [Nextcloud Cookbook App](https://apps.nextcloud.com/apps/cookbook)
- [Cookbook Documentation](https://github.com/nextcloud/cookbook)
- [Markdown Format](https://www.markdownguide.org/)
