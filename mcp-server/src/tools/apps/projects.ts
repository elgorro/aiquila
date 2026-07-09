// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchAiquilaAPI } from '../../client/aiquila.js';

/**
 * Project Tools
 * CRUD operations for AIquila projects (bundles of files/directories + system prompt)
 */

interface ProjectPath {
  id: number;
  projectId: number;
  path: string;
  pathType: string;
  createdAt: number;
}

interface Project {
  id: number;
  userId: string;
  title: string;
  description: string | null;
  systemPrompt: string | null;
  isActive: boolean;
  createdAt: number;
  updatedAt: number;
  paths: ProjectPath[];
}

function formatProject(p: Project): string {
  const lines = [`Project #${p.id}: ${p.title}`];
  if (p.description) lines.push(`  Description: ${p.description}`);
  if (p.systemPrompt) lines.push(`  System prompt: ${p.systemPrompt}`);
  lines.push(`  Active: ${p.isActive}`);
  if (p.paths && p.paths.length > 0) {
    lines.push(`  Paths:`);
    for (const path of p.paths) {
      lines.push(`    - [${path.pathType}] ${path.path} (id: ${path.id})`);
    }
  }
  return lines.join('\n');
}

export const listProjectsTool = {
  name: 'list_projects',
  title: 'List Projects',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List all AIquila projects for the current user. Projects bundle files/directories with an optional system prompt.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const projects = await fetchAiquilaAPI<Project[]>('/projects');

      if (projects.length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No projects found.' }],
        };
      }

      const text = projects.map(formatProject).join('\n\n');
      return {
        content: [{ type: 'text' as const, text: `Projects (${projects.length}):\n\n${text}` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing projects: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const createProjectTool = {
  name: 'create_project',
  title: 'Create Project',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Create a new AIquila project. Projects bundle files/directories with an optional system prompt that gets injected into conversations.',
  inputSchema: z.object({
    title: z.string().describe('Project title (required)'),
    description: z.string().optional().describe('Project description'),
    systemPrompt: z
      .string()
      .optional()
      .describe('System prompt to inject into conversations using this project'),
  }),
  handler: async (args: { title: string; description?: string; systemPrompt?: string }) => {
    try {
      const project = await fetchAiquilaAPI<Project>('/projects', {
        method: 'POST',
        body: {
          title: args.title,
          description: args.description,
          systemPrompt: args.systemPrompt,
        },
      });

      return {
        content: [{ type: 'text' as const, text: `Project created:\n\n${formatProject(project)}` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error creating project: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const getProjectTool = {
  name: 'get_project',
  title: 'Get Project',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Get details of an AIquila project including its file/directory paths.',
  inputSchema: z.object({
    id: z.number().describe('Project ID'),
  }),
  handler: async (args: { id: number }) => {
    try {
      const project = await fetchAiquilaAPI<Project>(`/projects/${args.id}`);

      return {
        content: [{ type: 'text' as const, text: formatProject(project) }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error getting project: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const updateProjectTool = {
  name: 'update_project',
  title: 'Update Project',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Update an AIquila project. Only provided fields are updated.',
  inputSchema: z.object({
    id: z.number().describe('Project ID'),
    title: z.string().optional().describe('New project title'),
    description: z.string().optional().describe('New project description'),
    systemPrompt: z
      .string()
      .optional()
      .describe('New system prompt for conversations using this project'),
  }),
  handler: async (args: {
    id: number;
    title?: string;
    description?: string;
    systemPrompt?: string;
  }) => {
    try {
      const body: Record<string, string> = {};
      if (args.title !== undefined) body.title = args.title;
      if (args.description !== undefined) body.description = args.description;
      if (args.systemPrompt !== undefined) body.systemPrompt = args.systemPrompt;

      const project = await fetchAiquilaAPI<Project>(`/projects/${args.id}`, {
        method: 'PUT',
        body,
      });

      return {
        content: [{ type: 'text' as const, text: `Project updated:\n\n${formatProject(project)}` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error updating project: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const deleteProjectTool = {
  name: 'delete_project',
  title: 'Delete Project',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Delete an AIquila project. Associated paths are removed and conversations referencing it are unlinked.',
  inputSchema: z.object({
    id: z.number().describe('Project ID'),
  }),
  handler: async (args: { id: number }) => {
    try {
      await fetchAiquilaAPI(`/projects/${args.id}`, { method: 'DELETE' });

      return {
        content: [{ type: 'text' as const, text: `Project #${args.id} deleted.` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error deleting project: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const addProjectPathTool = {
  name: 'add_project_path',
  title: 'Add Project Path',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Add a file or directory path to an AIquila project.',
  inputSchema: z.object({
    id: z.number().describe('Project ID'),
    path: z.string().describe('File or directory path to add'),
    pathType: z
      .enum(['file', 'directory'])
      .default('file')
      .describe('Type of path: "file" or "directory"'),
  }),
  handler: async (args: { id: number; path: string; pathType: string }) => {
    try {
      const projectPath = await fetchAiquilaAPI<ProjectPath>(`/projects/${args.id}/paths`, {
        method: 'POST',
        body: { path: args.path, pathType: args.pathType },
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Path added to project #${args.id}: [${projectPath.pathType}] ${projectPath.path} (id: ${projectPath.id})`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error adding path: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const removeProjectPathTool = {
  name: 'remove_project_path',
  title: 'Remove Project Path',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Remove a file or directory path from an AIquila project.',
  inputSchema: z.object({
    id: z.number().describe('Project ID'),
    pathId: z.number().describe('Path ID to remove'),
  }),
  handler: async (args: { id: number; pathId: number }) => {
    try {
      await fetchAiquilaAPI(`/projects/${args.id}/paths/${args.pathId}`, { method: 'DELETE' });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Path #${args.pathId} removed from project #${args.id}.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error removing path: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const projectsTools = [
  listProjectsTool,
  createProjectTool,
  getProjectTool,
  updateProjectTool,
  deleteProjectTool,
  addProjectPathTool,
  removeProjectPathTool,
];
