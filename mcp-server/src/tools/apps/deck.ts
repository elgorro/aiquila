import { z } from 'zod';
import {
  fetchDeckAPI,
  type DeckBoard,
  type DeckStack,
  type DeckCard,
  type DeckLabel,
} from '../../client/deck.js';
import { ApiError } from '../../client/aiquila.js';
import { handleAppError } from '../error-utils.js';

/**
 * Nextcloud Deck App Tools
 * Uses the Deck REST API v1.0 (/index.php/apps/deck/api/v1.0)
 */

const deckStatusMap: Record<number, string | ((e: ApiError) => string)> = {
  404: 'Not found.',
  403: 'Permission denied.',
  400: (e) => `Bad request: ${e.responseBody}`,
};

function formatBoard(board: DeckBoard): string {
  const flags = [
    board.archived ? 'archived' : null,
    board.shared ? 'shared' : null,
    `${board.labels.length} labels`,
  ]
    .filter(Boolean)
    .join(', ');
  return `[${board.id}] ${board.title} (owner: ${board.owner.displayname}, ${flags})`;
}

function formatStack(stack: DeckStack): string {
  const cardCount = stack.cards?.length ?? 0;
  return `[${stack.id}] ${stack.title} (${cardCount} cards)`;
}

function formatCard(card: DeckCard): string {
  const parts: string[] = [];
  if (card.labels?.length) {
    parts.push(`labels: ${card.labels.map((l: DeckLabel) => l.title).join(', ')}`);
  }
  if (card.assignedUsers?.length) {
    parts.push(`assigned: ${card.assignedUsers.map((u) => u.participant.displayname).join(', ')}`);
  }
  if (card.duedate) {
    parts.push(`due: ${card.duedate}`);
  }
  if (card.archived) {
    parts.push('archived');
  }
  if (card.done) {
    parts.push('done');
  }
  const meta = parts.length ? ` (${parts.join(', ')})` : '';
  return `[${card.id}] ${card.title}${meta}`;
}

function formatCardDetail(card: DeckCard): string {
  const lines: string[] = [`# ${card.title}`, ''];
  if (card.description) {
    lines.push(card.description, '');
  }
  lines.push(`ID: ${card.id}`);
  lines.push(`Stack: ${card.stackId}`);
  lines.push(`Owner: ${card.owner.displayname}`);
  if (card.duedate) lines.push(`Due: ${card.duedate}`);
  if (card.labels?.length) {
    lines.push(`Labels: ${card.labels.map((l: DeckLabel) => l.title).join(', ')}`);
  }
  if (card.assignedUsers?.length) {
    lines.push(`Assigned: ${card.assignedUsers.map((u) => u.participant.displayname).join(', ')}`);
  }
  lines.push(`Archived: ${card.archived}`);
  if (card.done !== null) lines.push(`Done: ${card.done}`);
  lines.push(`Created: ${new Date(card.createdAt * 1000).toISOString()}`);
  lines.push(`Modified: ${new Date(card.lastModified * 1000).toISOString()}`);
  return lines.join('\n');
}

export const listBoardsTool = {
  name: 'deck_list_boards',
  description: 'List all Deck boards. Returns id, title, owner, and label count for each board.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const boards = await fetchDeckAPI<DeckBoard[]>('/boards');

      if (boards.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No boards found.' }] };
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Boards (${boards.length}):\n\n${boards.map(formatBoard).join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error listing boards', deckStatusMap);
    }
  },
};

export const getBoardTool = {
  name: 'deck_get_board',
  description:
    'Get details of a Deck board including its labels and access control list (ACL). Use deck_list_stacks to see the columns and cards.',
  inputSchema: z.object({
    boardId: z.number().int().describe('Board ID (from deck_list_boards)'),
  }),
  handler: async (args: { boardId: number }) => {
    try {
      const board = await fetchDeckAPI<DeckBoard>(`/boards/${args.boardId}`);

      const lines: string[] = [
        `# ${board.title}`,
        '',
        `ID: ${board.id}`,
        `Owner: ${board.owner.displayname}`,
        `Color: #${board.color}`,
        `Archived: ${board.archived}`,
        `Modified: ${new Date(board.lastModified * 1000).toISOString()}`,
      ];

      if (board.labels.length) {
        lines.push('', `Labels (${board.labels.length}):`);
        for (const label of board.labels) {
          lines.push(`  [${label.id}] ${label.title} (#${label.color})`);
        }
      }

      if (board.acl.length) {
        lines.push('', `Shared with (${board.acl.length}):`);
        for (const acl of board.acl) {
          const perms = [
            acl.permissionEdit ? 'edit' : null,
            acl.permissionShare ? 'share' : null,
            acl.permissionManage ? 'manage' : null,
          ]
            .filter(Boolean)
            .join(', ');
          lines.push(`  ${acl.participant.displayname} (${perms})`);
        }
      }

      return { content: [{ type: 'text' as const, text: lines.join('\n') }] };
    } catch (error) {
      return handleAppError(error, 'Error getting board', deckStatusMap);
    }
  },
};

export const createBoardTool = {
  name: 'deck_create_board',
  description: 'Create a new Deck board.',
  inputSchema: z.object({
    title: z.string().describe('Board title'),
    color: z
      .string()
      .optional()
      .describe('Hex color without # (e.g. "0800fd"). Defaults to "0800fd"'),
  }),
  handler: async (args: { title: string; color?: string }) => {
    try {
      const board = await fetchDeckAPI<DeckBoard>('/boards', {
        method: 'POST',
        body: { title: args.title, color: args.color ?? '0800fd' },
      });

      return {
        content: [{ type: 'text' as const, text: `Board created: ${formatBoard(board)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error creating board', deckStatusMap);
    }
  },
};

export const listStacksTool = {
  name: 'deck_list_stacks',
  description:
    'List all stacks (columns) of a Deck board, including the cards in each stack. This gives a full overview of the board layout.',
  inputSchema: z.object({
    boardId: z.number().int().describe('Board ID (from deck_list_boards)'),
  }),
  handler: async (args: { boardId: number }) => {
    try {
      const stacks = await fetchDeckAPI<DeckStack[]>(`/boards/${args.boardId}/stacks`);

      if (stacks.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No stacks found on this board.' }] };
      }

      const lines: string[] = [];
      for (const stack of stacks) {
        lines.push(`## ${formatStack(stack)}`);
        const cards = stack.cards ?? [];
        if (cards.length === 0) {
          lines.push('  (empty)');
        } else {
          for (const card of cards) {
            lines.push(`  ${formatCard(card)}`);
          }
        }
        lines.push('');
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Board stacks (${stacks.length}):\n\n${lines.join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error listing stacks', deckStatusMap);
    }
  },
};

export const createStackTool = {
  name: 'deck_create_stack',
  description: 'Create a new stack (column) on a Deck board.',
  inputSchema: z.object({
    boardId: z.number().int().describe('Board ID (from deck_list_boards)'),
    title: z.string().describe('Stack title'),
    order: z.number().int().optional().describe('Position order (0-based)'),
  }),
  handler: async (args: { boardId: number; title: string; order?: number }) => {
    try {
      const stack = await fetchDeckAPI<DeckStack>(`/boards/${args.boardId}/stacks`, {
        method: 'POST',
        body: { title: args.title, order: args.order ?? 0 },
      });

      return {
        content: [{ type: 'text' as const, text: `Stack created: ${formatStack(stack)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error creating stack', deckStatusMap);
    }
  },
};

export const getCardTool = {
  name: 'deck_get_card',
  description: 'Get full details of a Deck card including description, labels, and assigned users.',
  inputSchema: z.object({
    boardId: z.number().int().describe('Board ID'),
    stackId: z.number().int().describe('Stack ID (from deck_list_stacks)'),
    cardId: z.number().int().describe('Card ID (from deck_list_stacks)'),
  }),
  handler: async (args: { boardId: number; stackId: number; cardId: number }) => {
    try {
      const card = await fetchDeckAPI<DeckCard>(
        `/boards/${args.boardId}/stacks/${args.stackId}/cards/${args.cardId}`
      );

      return { content: [{ type: 'text' as const, text: formatCardDetail(card) }] };
    } catch (error) {
      return handleAppError(error, 'Error getting card', deckStatusMap);
    }
  },
};

export const createCardTool = {
  name: 'deck_create_card',
  description: 'Create a new card in a Deck stack.',
  inputSchema: z.object({
    boardId: z.number().int().describe('Board ID'),
    stackId: z.number().int().describe('Stack ID (from deck_list_stacks)'),
    title: z.string().describe('Card title'),
    description: z.string().optional().describe('Card description (Markdown)'),
    duedate: z.string().optional().describe('Due date in ISO 8601 format (e.g. 2025-12-31)'),
  }),
  handler: async (args: {
    boardId: number;
    stackId: number;
    title: string;
    description?: string;
    duedate?: string;
  }) => {
    try {
      const body: Record<string, unknown> = {
        title: args.title,
        type: 'plain',
        order: 0,
      };
      if (args.description !== undefined) body.description = args.description;
      if (args.duedate !== undefined) body.duedate = args.duedate;

      const card = await fetchDeckAPI<DeckCard>(
        `/boards/${args.boardId}/stacks/${args.stackId}/cards`,
        { method: 'POST', body }
      );

      return {
        content: [{ type: 'text' as const, text: `Card created: ${formatCard(card)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error creating card', deckStatusMap);
    }
  },
};

export const updateCardTool = {
  name: 'deck_update_card',
  description:
    'Update an existing Deck card. Provide only the fields you want to change. Fetches the current card first to preserve unchanged fields.',
  inputSchema: z.object({
    boardId: z.number().int().describe('Board ID'),
    stackId: z.number().int().describe('Stack ID (from deck_list_stacks)'),
    cardId: z.number().int().describe('Card ID (from deck_list_stacks)'),
    title: z.string().optional().describe('New title'),
    description: z.string().optional().describe('New description (Markdown)'),
    duedate: z.string().nullable().optional().describe('Due date (ISO 8601) or null to clear'),
    done: z.boolean().optional().describe('Mark card as done'),
  }),
  handler: async (args: {
    boardId: number;
    stackId: number;
    cardId: number;
    title?: string;
    description?: string;
    duedate?: string | null;
    done?: boolean;
  }) => {
    try {
      const path = `/boards/${args.boardId}/stacks/${args.stackId}/cards/${args.cardId}`;
      const current = await fetchDeckAPI<DeckCard>(path);

      const updated = await fetchDeckAPI<DeckCard>(path, {
        method: 'PUT',
        body: {
          title: args.title ?? current.title,
          description: args.description ?? current.description,
          type: current.type,
          order: current.order,
          duedate: args.duedate !== undefined ? args.duedate : current.duedate,
          done: args.done ?? current.done,
          owner: current.owner.uid,
        },
      });

      return {
        content: [{ type: 'text' as const, text: `Card updated: ${formatCard(updated)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error updating card', deckStatusMap);
    }
  },
};

export const moveCardTool = {
  name: 'deck_move_card',
  description:
    'Move a card to a different stack (column) on the same board. This is the core kanban action for changing card status.',
  inputSchema: z.object({
    boardId: z.number().int().describe('Board ID'),
    stackId: z.number().int().describe('Current stack ID of the card'),
    cardId: z.number().int().describe('Card ID'),
    targetStackId: z.number().int().describe('Destination stack ID'),
    order: z
      .number()
      .int()
      .optional()
      .describe('Position in target stack (0 = top). Defaults to 0'),
  }),
  handler: async (args: {
    boardId: number;
    stackId: number;
    cardId: number;
    targetStackId: number;
    order?: number;
  }) => {
    try {
      const card = await fetchDeckAPI<DeckCard>(
        `/boards/${args.boardId}/stacks/${args.stackId}/cards/${args.cardId}/reorder`,
        {
          method: 'PUT',
          body: { order: args.order ?? 0, stackId: args.targetStackId },
        }
      );

      return {
        content: [
          {
            type: 'text' as const,
            text: `Card moved to stack ${args.targetStackId}: ${formatCard(card)}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error moving card', deckStatusMap);
    }
  },
};

export const archiveCardTool = {
  name: 'deck_archive_card',
  description: 'Archive or unarchive a Deck card.',
  inputSchema: z.object({
    boardId: z.number().int().describe('Board ID'),
    stackId: z.number().int().describe('Stack ID'),
    cardId: z.number().int().describe('Card ID'),
    archive: z.boolean().default(true).describe('true to archive, false to unarchive'),
  }),
  handler: async (args: { boardId: number; stackId: number; cardId: number; archive: boolean }) => {
    try {
      const action = args.archive ? 'archive' : 'unarchive';
      const card = await fetchDeckAPI<DeckCard>(
        `/boards/${args.boardId}/stacks/${args.stackId}/cards/${args.cardId}/${action}`,
        { method: 'PUT' }
      );

      return {
        content: [
          {
            type: 'text' as const,
            text: `Card ${args.archive ? 'archived' : 'unarchived'}: ${formatCard(card)}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(
        error,
        `Error ${args.archive ? 'archiving' : 'unarchiving'} card`,
        deckStatusMap
      );
    }
  },
};

export const assignLabelTool = {
  name: 'deck_assign_label',
  description:
    'Assign a label to a Deck card. Use deck_get_board to see available labels and their IDs.',
  inputSchema: z.object({
    boardId: z.number().int().describe('Board ID'),
    stackId: z.number().int().describe('Stack ID'),
    cardId: z.number().int().describe('Card ID'),
    labelId: z.number().int().describe('Label ID (from deck_get_board)'),
  }),
  handler: async (args: { boardId: number; stackId: number; cardId: number; labelId: number }) => {
    try {
      await fetchDeckAPI(
        `/boards/${args.boardId}/stacks/${args.stackId}/cards/${args.cardId}/assignLabel`,
        { method: 'PUT', body: { labelId: args.labelId } }
      );

      return {
        content: [
          {
            type: 'text' as const,
            text: `Label ${args.labelId} assigned to card ${args.cardId}.`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error assigning label', deckStatusMap);
    }
  },
};

export const assignUserTool = {
  name: 'deck_assign_user',
  description: 'Assign a user to a Deck card.',
  inputSchema: z.object({
    boardId: z.number().int().describe('Board ID'),
    stackId: z.number().int().describe('Stack ID'),
    cardId: z.number().int().describe('Card ID'),
    userId: z.string().describe('User ID (login name) to assign'),
  }),
  handler: async (args: { boardId: number; stackId: number; cardId: number; userId: string }) => {
    try {
      await fetchDeckAPI(
        `/boards/${args.boardId}/stacks/${args.stackId}/cards/${args.cardId}/assignUser`,
        { method: 'PUT', body: { userId: args.userId } }
      );

      return {
        content: [
          {
            type: 'text' as const,
            text: `User "${args.userId}" assigned to card ${args.cardId}.`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error assigning user', deckStatusMap);
    }
  },
};

export const deckTools = [
  listBoardsTool,
  getBoardTool,
  createBoardTool,
  listStacksTool,
  createStackTool,
  getCardTool,
  createCardTool,
  updateCardTool,
  moveCardTool,
  archiveCardTool,
  assignLabelTool,
  assignUserTool,
];
