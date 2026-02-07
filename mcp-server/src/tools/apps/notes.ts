import { z } from "zod";
import { getWebDAVClient } from "../../client/webdav.js";

/**
 * Nextcloud Notes App Tools
 * Provides note management via markdown files
 */

/**
 * Create a note in Nextcloud Notes
 */
export const createNoteTool = {
  name: "create_note",
  description: "Create a note in Nextcloud Notes",
  inputSchema: z.object({
    title: z.string().describe("The title of the note"),
    content: z.string().describe("The content of the note"),
  }),
  handler: async (args: { title: string; content: string }) => {
    const client = getWebDAVClient();

    const noteContent = `# ${args.title}\n\n${args.content}\n`;
    const notePath = `/Notes/${args.title}.md`;

    await client.putFileContents(notePath, noteContent, {
      overwrite: true,
    });

    return {
      content: [
        {
          type: "text",
          text: `Note "${args.title}" created successfully at ${notePath}`,
        },
      ],
    };
  },
};

/**
 * List all notes in Nextcloud Notes
 */
export const listNotesTool = {
  name: "list_notes",
  description:
    "List all notes in Nextcloud Notes. Returns note titles, sizes, and modification dates.",
  inputSchema: z.object({
    search: z
      .string()
      .optional()
      .describe("Optional search string to filter notes by title"),
  }),
  handler: async (args: { search?: string }) => {
    try {
      const client = getWebDAVClient();
      const items = await client.getDirectoryContents("/Notes/");
      const files = (Array.isArray(items) ? items : []).filter(
        (item: { type: string; basename: string }) =>
          item.type === "file" && item.basename.endsWith(".md")
      );

      let notes = files.map(
        (f: { basename: string; size: number; lastmod: string }) => ({
          title: f.basename.replace(/\.md$/, ""),
          size: f.size,
          lastmod: f.lastmod,
        })
      );

      if (args.search) {
        const searchLower = args.search.toLowerCase();
        notes = notes.filter((n: { title: string }) =>
          n.title.toLowerCase().includes(searchLower)
        );
      }

      if (notes.length === 0) {
        return {
          content: [
            {
              type: "text" as const,
              text: args.search
                ? `No notes found matching "${args.search}".`
                : "No notes found.",
            },
          ],
        };
      }

      const formatted = notes
        .map((n: { title: string; size: number; lastmod: string }) => {
          const sizeKB = (n.size / 1024).toFixed(1);
          return `- ${n.title} (${sizeKB} KB, modified: ${n.lastmod})`;
        })
        .join("\n");

      return {
        content: [
          {
            type: "text" as const,
            text: `Notes (${notes.length} found):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error listing notes: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Read a specific note from Nextcloud Notes
 */
export const getNoteTool = {
  name: "get_note",
  description: "Read the content of a specific note from Nextcloud Notes",
  inputSchema: z.object({
    title: z.string().describe("The title of the note to read"),
  }),
  handler: async (args: { title: string }) => {
    try {
      const client = getWebDAVClient();
      const notePath = `/Notes/${args.title}.md`;
      const content = await client.getFileContents(notePath, {
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
            text: `Error reading note "${args.title}": ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Export all Notes app tools
 */
export const notesTools = [listNotesTool, getNoteTool, createNoteTool];
