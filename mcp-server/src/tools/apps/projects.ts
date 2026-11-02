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

interface LibraryPrompt {
  id: number;
  scope: 'user' | 'instance';
  title: string;
  description: string | null;
  content: string;
  createdAt: number;
  updatedAt: number;
}

interface ProjectTemplate {
  id: number;
  scope: 'user' | 'instance';
  title: string;
  description: string | null;
  systemPrompt: string | null;
  paths: { path: string; pathType: string }[];
  createdAt: number;
  updatedAt: number;
}

/** "organization" for admin-published entries, "personal" for the user's own. */
function scopeLabel(scope: string): string {
  return scope === 'instance' ? 'organization' : 'personal';
}

function errorResult(action: string, error: unknown) {
  return {
    content: [
      {
        type: 'text' as const,
        text: `Error ${action}: ${error instanceof Error ? error.message : String(error)}`,
      },
    ],
    isError: true,
  };
}

export const listPromptsTool = {
  name: 'list_prompts',
  title: 'List Prompts',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "List the AIquila prompt library: reusable prompts published by the organization and the user's own. Returns titles and descriptions; use get_prompt for the full text.",
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const prompts = await fetchAiquilaAPI<LibraryPrompt[]>('/prompts');
      if (prompts.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No prompts found.' }] };
      }
      const text = prompts
        .map(
          (p) =>
            `Prompt #${p.id}: ${p.title} (${scopeLabel(p.scope)})` +
            (p.description ? `\n  ${p.description}` : '')
        )
        .join('\n');
      return {
        content: [{ type: 'text' as const, text: `Prompts (${prompts.length}):\n\n${text}` }],
      };
    } catch (error) {
      return errorResult('listing prompts', error);
    }
  },
};

export const getPromptTool = {
  name: 'get_prompt',
  title: 'Get Prompt',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Get the full text of a prompt from the AIquila prompt library.',
  inputSchema: z.object({
    id: z.number().describe('Prompt ID from list_prompts'),
  }),
  handler: async (args: { id: number }) => {
    try {
      const prompts = await fetchAiquilaAPI<LibraryPrompt[]>('/prompts');
      const prompt = prompts.find((p) => p.id === args.id);
      if (!prompt) {
        return errorResult('getting prompt', `Prompt #${args.id} not found`);
      }
      const header = [`Prompt #${prompt.id}: ${prompt.title} (${scopeLabel(prompt.scope)})`];
      if (prompt.description) header.push(`Description: ${prompt.description}`);
      return {
        content: [{ type: 'text' as const, text: `${header.join('\n')}\n\n${prompt.content}` }],
      };
    } catch (error) {
      return errorResult('getting prompt', error);
    }
  },
};

export const listProjectTemplatesTool = {
  name: 'list_project_templates',
  title: 'List Project Templates',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "List AIquila project templates: the organization's and the user's own. A template holds a title, description, system prompt and suggested paths.",
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const templates = await fetchAiquilaAPI<ProjectTemplate[]>('/project-templates');
      if (templates.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No project templates found.' }] };
      }
      const text = templates
        .map((t) => {
          const lines = [`Template #${t.id}: ${t.title} (${scopeLabel(t.scope)})`];
          if (t.description) lines.push(`  Description: ${t.description}`);
          if (t.systemPrompt) lines.push(`  System prompt: ${t.systemPrompt}`);
          for (const path of t.paths) lines.push(`  - [${path.pathType}] ${path.path}`);
          return lines.join('\n');
        })
        .join('\n\n');
      return {
        content: [
          { type: 'text' as const, text: `Project templates (${templates.length}):\n\n${text}` },
        ],
      };
    } catch (error) {
      return errorResult('listing project templates', error);
    }
  },
};

export const createProjectFromTemplateTool = {
  name: 'create_project_from_template',
  title: 'Create Project from Template',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Create an AIquila project as a copy of a project template (title, description, system prompt and paths). Later changes to the template do not affect the project.',
  inputSchema: z.object({
    templateId: z.number().describe('Template ID from list_project_templates'),
    title: z.string().optional().describe("Title for the new project (default: the template's)"),
  }),
  handler: async (args: { templateId: number; title?: string }) => {
    try {
      const project = await fetchAiquilaAPI<Project>(
        `/project-templates/${args.templateId}/project`,
        { method: 'POST', body: args.title ? { title: args.title } : {} }
      );
      return {
        content: [{ type: 'text' as const, text: `Project created:\n\n${formatProject(project)}` }],
      };
    } catch (error) {
      return errorResult('creating project from template', error);
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
  listPromptsTool,
  getPromptTool,
  listProjectTemplatesTool,
  createProjectFromTemplateTool,
];
