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
 * Export all Notes app tools
 */
export const notesTools = [createNoteTool];
