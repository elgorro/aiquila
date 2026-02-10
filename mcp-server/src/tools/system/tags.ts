import { z } from "zod";
import { fetchCalDAV } from "../../client/caldav.js";
import { getNextcloudConfig } from "../types.js";

/**
 * File Tag Tools for Nextcloud
 * Provides personal tag operations via WebDAV PROPPATCH/PROPFIND
 * and system tag operations via systemtags-relations API
 */

/**
 * Get personal tags for a file
 */
export const getFileTagsTool = {
  name: "get_file_tags",
  description:
    "Get the personal tags assigned to a file or folder in Nextcloud",
  inputSchema: z.object({
    path: z
      .string()
      .describe("The file or folder path in Nextcloud (e.g., '/Photos/test.png')"),
  }),
  handler: async (args: { path: string }) => {
    try {
      const config = getNextcloudConfig();
      const url = `${config.url}/remote.php/dav/files/${config.user}/${args.path.replace(/^\//, "")}`;

      const body = `<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <oc:tags />
  </d:prop>
</d:propfind>`;

      const response = await fetchCalDAV(url, {
        method: "PROPFIND",
        body,
        headers: { Depth: "0" },
      });

      const text = await response.text();

      if (!response.ok && response.status !== 207) {
        return {
          content: [
            {
              type: "text" as const,
              text: `Error getting tags: ${response.status} ${response.statusText}`,
            },
          ],
          isError: true,
        };
      }

      // Parse <oc:tag> elements from response
      const tagMatches = text.match(/<oc:tag[^>]*>([^<]*)<\/oc:tag>/g) || [];
      const tags = tagMatches.map((m) =>
        m.replace(/<\/?oc:tag[^>]*>/g, "").trim()
      );

      return {
        content: [
          {
            type: "text" as const,
            text:
              tags.length > 0
                ? `Tags for ${args.path}: ${tags.join(", ")}`
                : `No tags found for ${args.path}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error getting file tags: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Set personal tags on a file (replaces all existing personal tags)
 */
export const setFileTagsTool = {
  name: "set_file_tags",
  description:
    "Set personal tags on a file or folder in Nextcloud. Replaces all existing personal tags. Use an empty array to clear all tags.",
  inputSchema: z.object({
    path: z
      .string()
      .describe("The file or folder path in Nextcloud (e.g., '/Photos/test.png')"),
    tags: z
      .array(z.string())
      .describe("Array of tag names to set (e.g., ['Wallpapers', 'Nature'])"),
  }),
  handler: async (args: { path: string; tags: string[] }) => {
    try {
      const config = getNextcloudConfig();
      const url = `${config.url}/remote.php/dav/files/${config.user}/${args.path.replace(/^\//, "")}`;

      const tagElements =
        args.tags.length > 0
          ? args.tags.map((t) => `<oc:tag>${t}</oc:tag>`).join("\n                ")
          : "";

      const body = `<?xml version="1.0"?>
<d:propertyupdate xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
    <d:set>
        <d:prop>
            <oc:tags>
                ${tagElements}
            </oc:tags>
        </d:prop>
    </d:set>
</d:propertyupdate>`;

      const response = await fetchCalDAV(url, {
        method: "PROPPATCH",
        body,
      });

      if (!response.ok && response.status !== 207) {
        const text = await response.text();
        return {
          content: [
            {
              type: "text" as const,
              text: `Error setting tags: ${response.status} ${response.statusText} - ${text}`,
            },
          ],
          isError: true,
        };
      }

      return {
        content: [
          {
            type: "text" as const,
            text:
              args.tags.length > 0
                ? `Tags set on ${args.path}: ${args.tags.join(", ")}`
                : `All tags cleared from ${args.path}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error setting file tags: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Assign a system tag to a file
 */
export const assignSystemTagTool = {
  name: "assign_system_tag",
  description:
    "Assign a system tag to a file by file ID and tag ID. Use get_file_info to obtain the file ID.",
  inputSchema: z.object({
    fileId: z.number().describe("The Nextcloud file ID"),
    tagId: z.number().describe("The system tag ID to assign"),
  }),
  handler: async (args: { fileId: number; tagId: number }) => {
    try {
      const config = getNextcloudConfig();
      const url = `${config.url}/remote.php/dav/systemtags-relations/files/${args.fileId}/${args.tagId}`;

      const response = await fetchCalDAV(url, {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
        },
      });

      if (!response.ok && response.status !== 409) {
        const text = await response.text();
        return {
          content: [
            {
              type: "text" as const,
              text: `Error assigning system tag: ${response.status} ${response.statusText} - ${text}`,
            },
          ],
          isError: true,
        };
      }

      return {
        content: [
          {
            type: "text" as const,
            text: `System tag ${args.tagId} assigned to file ${args.fileId}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error assigning system tag: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Remove a system tag from a file
 */
export const removeSystemTagTool = {
  name: "remove_system_tag",
  description:
    "Remove a system tag from a file by file ID and tag ID",
  inputSchema: z.object({
    fileId: z.number().describe("The Nextcloud file ID"),
    tagId: z.number().describe("The system tag ID to remove"),
  }),
  handler: async (args: { fileId: number; tagId: number }) => {
    try {
      const config = getNextcloudConfig();
      const url = `${config.url}/remote.php/dav/systemtags-relations/files/${args.fileId}/${args.tagId}`;

      const response = await fetchCalDAV(url, {
        method: "DELETE",
        headers: {
          "Content-Type": "application/json",
        },
      });

      if (!response.ok) {
        const text = await response.text();
        return {
          content: [
            {
              type: "text" as const,
              text: `Error removing system tag: ${response.status} ${response.statusText} - ${text}`,
            },
          ],
          isError: true,
        };
      }

      return {
        content: [
          {
            type: "text" as const,
            text: `System tag ${args.tagId} removed from file ${args.fileId}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error removing system tag: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * List all available system tags
 */
export const listSystemTagsTool = {
  name: "list_system_tags",
  description:
    "List all available system tags in Nextcloud with their IDs, names, and properties. Use this to find tag IDs before assigning them to files.",
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const config = getNextcloudConfig();
      const url = `${config.url}/remote.php/dav/systemtags/`;

      const body = `<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <oc:id />
    <oc:display-name />
    <oc:user-visible />
    <oc:user-assignable />
    <oc:can-assign />
  </d:prop>
</d:propfind>`;

      const response = await fetchCalDAV(url, {
        method: "PROPFIND",
        body,
        headers: { Depth: "1" },
      });

      const text = await response.text();

      if (!response.ok && response.status !== 207) {
        return {
          content: [
            {
              type: "text" as const,
              text: `Error listing system tags: ${response.status} ${response.statusText}`,
            },
          ],
          isError: true,
        };
      }

      // Parse each <d:response> block for tag properties
      const responses = text.split(/<d:response>/g).slice(1);
      const tags: { id: string; name: string; userVisible: string; userAssignable: string; canAssign: string }[] = [];

      for (const resp of responses) {
        const id = resp.match(/<oc:id>([^<]*)<\/oc:id>/)?.[1];
        const name = resp.match(/<oc:display-name>([^<]*)<\/oc:display-name>/)?.[1];
        const userVisible = resp.match(/<oc:user-visible>([^<]*)<\/oc:user-visible>/)?.[1];
        const userAssignable = resp.match(/<oc:user-assignable>([^<]*)<\/oc:user-assignable>/)?.[1];
        const canAssign = resp.match(/<oc:can-assign>([^<]*)<\/oc:can-assign>/)?.[1];

        if (id && name) {
          tags.push({
            id,
            name,
            userVisible: userVisible || "true",
            userAssignable: userAssignable || "true",
            canAssign: canAssign || "true",
          });
        }
      }

      if (tags.length === 0) {
        return {
          content: [
            { type: "text" as const, text: "No system tags found." },
          ],
        };
      }

      const lines = tags.map(
        (t) => `- ID: ${t.id}, Name: "${t.name}", Visible: ${t.userVisible}, Assignable: ${t.userAssignable}, CanAssign: ${t.canAssign}`
      );

      return {
        content: [
          {
            type: "text" as const,
            text: `System tags (${tags.length}):\n${lines.join("\n")}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error listing system tags: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Create a new system tag
 */
export const createSystemTagTool = {
  name: "create_system_tag",
  description:
    "Create a new system tag in Nextcloud. Returns the created tag info.",
  inputSchema: z.object({
    name: z.string().describe("The name for the new system tag"),
    userVisible: z
      .boolean()
      .default(true)
      .describe("Whether the tag is visible to users (default: true)"),
    userAssignable: z
      .boolean()
      .default(true)
      .describe("Whether users can assign this tag (default: true)"),
  }),
  handler: async (args: { name: string; userVisible: boolean; userAssignable: boolean }) => {
    try {
      const config = getNextcloudConfig();
      const url = `${config.url}/remote.php/dav/systemtags/`;

      const response = await fetchCalDAV(url, {
        method: "POST",
        body: JSON.stringify({
          userVisible: args.userVisible,
          userAssignable: args.userAssignable,
          canAssign: true,
          name: args.name,
        }),
        headers: {
          "Content-Type": "application/json",
        },
      });

      if (!response.ok) {
        const text = await response.text();
        return {
          content: [
            {
              type: "text" as const,
              text: `Error creating system tag: ${response.status} ${response.statusText} - ${text}`,
            },
          ],
          isError: true,
        };
      }

      // The new tag ID is in the Content-Location header
      const location = response.headers.get("Content-Location") || "";
      const tagId = location.split("/").filter(Boolean).pop() || "unknown";

      return {
        content: [
          {
            type: "text" as const,
            text: `System tag created: "${args.name}" (ID: ${tagId})`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error creating system tag: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Export all tag tools
 */
export const tagsTools = [
  getFileTagsTool,
  setFileTagsTool,
  listSystemTagsTool,
  createSystemTagTool,
  assignSystemTagTool,
  removeSystemTagTool,
];
