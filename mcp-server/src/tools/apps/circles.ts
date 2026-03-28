import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';

/**
 * Nextcloud Circles (Teams) Tools
 *
 * Manages circles/teams and their members via the Circles OCS API.
 */

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const API_BASE = '/ocs/v2.php/apps/circles';

const MEMBER_TYPE_LABELS: Record<number, string> = {
  1: 'user',
  2: 'group',
  4: 'mail',
  8: 'contact',
  16: 'circle',
};

const MEMBER_LEVEL_LABELS: Record<number, string> = {
  1: 'member',
  4: 'moderator',
  8: 'admin',
  9: 'owner',
};

const CONFIG_FLAGS: [number, string][] = [
  [2, 'personal'],
  [4, 'system'],
  [8, 'visible'],
  [16, 'open'],
  [32, 'invite'],
  [64, 'request-to-join'],
  [128, 'friend'],
  [256, 'password-protected'],
  [512, 'no-owner'],
  [1024, 'hidden'],
  [4096, 'local'],
  [32768, 'federated'],
];

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface Circle {
  id: string;
  name: string;
  displayName: string;
  description: string;
  config: number;
  owner: { userId: string; displayName: string };
  members?: CircleMember[];
}

interface CircleMember {
  id: string;
  circleId: string;
  userId: string;
  displayName: string;
  userType: number;
  level: number;
  status: string;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function text(t: string) {
  return { content: [{ type: 'text' as const, text: t }] };
}

function error(msg: string) {
  return { content: [{ type: 'text' as const, text: msg }], isError: true };
}

function wrapError(action: string, err: unknown) {
  return error(`Error ${action}: ${err instanceof Error ? err.message : String(err)}`);
}

function formatConfigFlags(config: number): string {
  const active = CONFIG_FLAGS.filter(([bit]) => config & bit).map(([, label]) => label);
  return active.length ? active.join(', ') : 'default';
}

function formatCircle(c: Circle): string {
  const owner = c.owner?.displayName ?? c.owner?.userId ?? 'unknown';
  return `[${c.id}] ${c.displayName || c.name} (owner: ${owner}, config: ${formatConfigFlags(c.config)})`;
}

function formatMember(m: CircleMember): string {
  const type = MEMBER_TYPE_LABELS[m.userType] ?? `type-${m.userType}`;
  const level = MEMBER_LEVEL_LABELS[m.level] ?? `level-${m.level}`;
  return `[${m.id}] ${m.displayName || m.userId} (${type}, ${level}, status: ${m.status})`;
}

// ---------------------------------------------------------------------------
// circles_list
// ---------------------------------------------------------------------------

export const circlesListTool = {
  name: 'circles_list',
  description:
    'List all circles/teams accessible to the current user. Returns circle IDs, names, owners, and configuration.',
  inputSchema: z.object({
    limit: z.number().optional().describe('Maximum number of circles to return'),
    offset: z.number().optional().describe('Offset for pagination'),
  }),
  handler: async (args: { limit?: number; offset?: number }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.limit !== undefined) queryParams.limit = String(args.limit);
      if (args.offset !== undefined) queryParams.offset = String(args.offset);

      const result = await fetchOCS<Circle[]>(`${API_BASE}/circles`, { queryParams });
      const circles = result.ocs.data;

      if (!circles.length) return text('No circles found.');
      return text(`Circles (${circles.length}):\n${circles.map(formatCircle).join('\n')}`);
    } catch (err) {
      return wrapError('listing circles', err);
    }
  },
};

// ---------------------------------------------------------------------------
// circles_get
// ---------------------------------------------------------------------------

export const circlesGetTool = {
  name: 'circles_get',
  description:
    'Get detailed information about a specific circle/team, including its description, owner, and configuration flags.',
  inputSchema: z.object({
    circleId: z.string().describe('The circle ID to retrieve'),
  }),
  handler: async (args: { circleId: string }) => {
    try {
      const result = await fetchOCS<Circle>(
        `${API_BASE}/circles/${encodeURIComponent(args.circleId)}`
      );
      const c = result.ocs.data;

      const lines: string[] = [
        `# ${c.displayName || c.name}`,
        '',
        `ID: ${c.id}`,
        `Owner: ${c.owner?.displayName ?? c.owner?.userId ?? 'unknown'}`,
        `Config: ${formatConfigFlags(c.config)}`,
      ];
      if (c.description) lines.push(`Description: ${c.description}`);
      if (c.members?.length) {
        lines.push('', `Members (${c.members.length}):`);
        lines.push(...c.members.map(formatMember));
      }
      return text(lines.join('\n'));
    } catch (err) {
      return wrapError(`getting circle "${args.circleId}"`, err);
    }
  },
};

// ---------------------------------------------------------------------------
// circles_create
// ---------------------------------------------------------------------------

export const circlesCreateTool = {
  name: 'circles_create',
  description:
    'Create a new circle/team. The current user becomes the owner. Set personal=true for a private circle only visible to the owner.',
  inputSchema: z.object({
    name: z.string().describe('Name for the new circle'),
    personal: z.boolean().optional().describe('Create as a personal circle (default: false)'),
    local: z
      .boolean()
      .optional()
      .describe('Restrict to local instance, no federation (default: false)'),
  }),
  handler: async (args: { name: string; personal?: boolean; local?: boolean }) => {
    try {
      const body: Record<string, unknown> = { name: args.name };
      if (args.personal) body.personal = 1;
      if (args.local) body.local = 1;

      const result = await fetchOCS<Circle>(`${API_BASE}/circles`, {
        method: 'POST',
        jsonBody: body,
      });
      const c = result.ocs.data;
      return text(`Circle created: [${c.id}] ${c.displayName || c.name}`);
    } catch (err) {
      return wrapError('creating circle', err);
    }
  },
};

// ---------------------------------------------------------------------------
// circles_delete
// ---------------------------------------------------------------------------

export const circlesDeleteTool = {
  name: 'circles_delete',
  description: 'Delete a circle/team. Requires owner privileges.',
  inputSchema: z.object({
    circleId: z.string().describe('The circle ID to delete'),
  }),
  handler: async (args: { circleId: string }) => {
    try {
      await fetchOCS(`${API_BASE}/circles/${encodeURIComponent(args.circleId)}`, {
        method: 'DELETE',
      });
      return text(`Circle "${args.circleId}" has been deleted.`);
    } catch (err) {
      return wrapError(`deleting circle "${args.circleId}"`, err);
    }
  },
};

// ---------------------------------------------------------------------------
// circles_list_members
// ---------------------------------------------------------------------------

export const circlesListMembersTool = {
  name: 'circles_list_members',
  description:
    'List all members of a circle/team. Returns member IDs (needed for removal), user IDs, types, and permission levels.',
  inputSchema: z.object({
    circleId: z.string().describe('The circle ID to list members for'),
  }),
  handler: async (args: { circleId: string }) => {
    try {
      const result = await fetchOCS<CircleMember[]>(
        `${API_BASE}/circles/${encodeURIComponent(args.circleId)}/members`
      );
      const members = result.ocs.data;

      if (!members.length) return text('No members found in this circle.');
      return text(`Members (${members.length}):\n${members.map(formatMember).join('\n')}`);
    } catch (err) {
      return wrapError(`listing members of circle "${args.circleId}"`, err);
    }
  },
};

// ---------------------------------------------------------------------------
// circles_add_member
// ---------------------------------------------------------------------------

export const circlesAddMemberTool = {
  name: 'circles_add_member',
  description:
    'Add a member to a circle/team. Requires moderator privileges or higher. Member type: 1=user (default), 2=group, 4=mail, 16=circle.',
  inputSchema: z.object({
    circleId: z.string().describe('The circle ID to add the member to'),
    userId: z.string().describe('The user ID (or entity identifier) to add'),
    type: z
      .number()
      .optional()
      .describe('Member type: 1=user (default), 2=group, 4=mail, 8=contact, 16=circle'),
  }),
  handler: async (args: { circleId: string; userId: string; type?: number }) => {
    try {
      await fetchOCS(`${API_BASE}/circles/${encodeURIComponent(args.circleId)}/members`, {
        method: 'POST',
        jsonBody: { userId: args.userId, type: args.type ?? 1 },
      });
      const typeLabel = MEMBER_TYPE_LABELS[args.type ?? 1] ?? 'user';
      return text(`Added ${typeLabel} "${args.userId}" to circle "${args.circleId}".`);
    } catch (err) {
      return wrapError(`adding member "${args.userId}" to circle "${args.circleId}"`, err);
    }
  },
};

// ---------------------------------------------------------------------------
// circles_remove_member
// ---------------------------------------------------------------------------

export const circlesRemoveMemberTool = {
  name: 'circles_remove_member',
  description:
    'Remove a member from a circle/team. Use circles_list_members to get the memberId. Requires moderator privileges or higher.',
  inputSchema: z.object({
    circleId: z.string().describe('The circle ID to remove the member from'),
    memberId: z.string().describe('The member ID (from circles_list_members), not the user ID'),
  }),
  handler: async (args: { circleId: string; memberId: string }) => {
    try {
      await fetchOCS(
        `${API_BASE}/circles/${encodeURIComponent(args.circleId)}/members/${encodeURIComponent(args.memberId)}`,
        { method: 'DELETE' }
      );
      return text(`Member "${args.memberId}" removed from circle "${args.circleId}".`);
    } catch (err) {
      return wrapError(`removing member "${args.memberId}" from circle "${args.circleId}"`, err);
    }
  },
};

// ---------------------------------------------------------------------------
// circles_search
// ---------------------------------------------------------------------------

export const circlesSearchTool = {
  name: 'circles_search',
  description: 'Search for circles/teams by name.',
  inputSchema: z.object({
    term: z.string().describe('Search term to match against circle names'),
  }),
  handler: async (args: { term: string }) => {
    try {
      const result = await fetchOCS<Circle[]>(`${API_BASE}/search`, {
        queryParams: { term: args.term },
      });
      const circles = result.ocs.data;

      if (!circles.length) return text(`No circles found matching "${args.term}".`);
      return text(`Search results (${circles.length}):\n${circles.map(formatCircle).join('\n')}`);
    } catch (err) {
      return wrapError('searching circles', err);
    }
  },
};

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

export const circlesTools = [
  circlesListTool,
  circlesGetTool,
  circlesCreateTool,
  circlesDeleteTool,
  circlesListMembersTool,
  circlesAddMemberTool,
  circlesRemoveMemberTool,
  circlesSearchTool,
];
