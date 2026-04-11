// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchDeckAPI = vi.fn();
vi.mock('../client/deck.js', () => ({
  fetchDeckAPI: (...args: unknown[]) => mockFetchDeckAPI(...args),
}));

const sampleOwner = { primaryKey: 'admin', uid: 'admin', displayname: 'Admin' };

const sampleLabel = { id: 10, title: 'Urgent', color: 'ff0000', boardId: 1 };

const sampleBoard = {
  id: 1,
  title: 'Project Board',
  owner: sampleOwner,
  color: '0800fd',
  archived: false,
  labels: [sampleLabel],
  acl: [],
  shared: 0,
  deletedAt: 0,
  lastModified: 1734220800,
};

const sampleCard = {
  id: 100,
  title: 'Fix login bug',
  description: 'Users cannot log in with SSO',
  stackId: 5,
  type: 'plain',
  order: 0,
  archived: false,
  done: false,
  duedate: '2025-12-31T00:00:00+00:00',
  labels: [sampleLabel],
  assignedUsers: [{ id: 1, participant: sampleOwner, type: 0 }],
  owner: sampleOwner,
  createdAt: 1734220800,
  lastModified: 1734220800,
  deletedAt: 0,
};

const sampleStack = {
  id: 5,
  title: 'To Do',
  boardId: 1,
  order: 0,
  cards: [sampleCard],
  deletedAt: 0,
  lastModified: 1734220800,
};

describe('Deck Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('deck_list_boards', () => {
    it('should return formatted board list', async () => {
      mockFetchDeckAPI.mockResolvedValue([sampleBoard]);

      const { listBoardsTool } = await import('../tools/apps/deck.js');
      const result = await listBoardsTool.handler();

      expect(result.content[0].text).toContain('Project Board');
      expect(result.content[0].text).toContain('Boards (1)');
      expect(result.content[0].text).toContain('1 labels');
    });

    it('should handle empty boards', async () => {
      mockFetchDeckAPI.mockResolvedValue([]);

      const { listBoardsTool } = await import('../tools/apps/deck.js');
      const result = await listBoardsTool.handler();

      expect(result.content[0].text).toContain('No boards found');
    });

    it('should handle errors', async () => {
      mockFetchDeckAPI.mockRejectedValue(new Error('Network error'));

      const { listBoardsTool } = await import('../tools/apps/deck.js');
      const result = await listBoardsTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Network error');
    });
  });

  describe('deck_get_board', () => {
    it('should return board details with labels', async () => {
      mockFetchDeckAPI.mockResolvedValue(sampleBoard);

      const { getBoardTool } = await import('../tools/apps/deck.js');
      const result = await getBoardTool.handler({ boardId: 1 });

      expect(result.content[0].text).toContain('Project Board');
      expect(result.content[0].text).toContain('Urgent');
      expect(result.content[0].text).toContain('#0800fd');
    });

    it('should show ACL when shared', async () => {
      const boardWithAcl = {
        ...sampleBoard,
        acl: [
          {
            id: 1,
            participant: { primaryKey: 'bob', uid: 'bob', displayname: 'Bob' },
            type: 0,
            boardId: 1,
            permissionEdit: true,
            permissionShare: false,
            permissionManage: false,
          },
        ],
      };
      mockFetchDeckAPI.mockResolvedValue(boardWithAcl);

      const { getBoardTool } = await import('../tools/apps/deck.js');
      const result = await getBoardTool.handler({ boardId: 1 });

      expect(result.content[0].text).toContain('Bob');
      expect(result.content[0].text).toContain('edit');
    });

    it('should handle 404', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchDeckAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const { getBoardTool } = await import('../tools/apps/deck.js');
      const result = await getBoardTool.handler({ boardId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Not found');
    });
  });

  describe('deck_create_board', () => {
    it('should create a board with title', async () => {
      mockFetchDeckAPI.mockResolvedValue(sampleBoard);

      const { createBoardTool } = await import('../tools/apps/deck.js');
      const result = await createBoardTool.handler({ title: 'Project Board' });

      expect(result.content[0].text).toContain('Board created');
      expect(result.content[0].text).toContain('Project Board');
      expect(mockFetchDeckAPI).toHaveBeenCalledWith('/boards', {
        method: 'POST',
        body: { title: 'Project Board', color: '0800fd' },
      });
    });

    it('should create a board with custom color', async () => {
      mockFetchDeckAPI.mockResolvedValue({ ...sampleBoard, color: 'ff5500' });

      const { createBoardTool } = await import('../tools/apps/deck.js');
      await createBoardTool.handler({ title: 'Board', color: 'ff5500' });

      expect(mockFetchDeckAPI).toHaveBeenCalledWith('/boards', {
        method: 'POST',
        body: { title: 'Board', color: 'ff5500' },
      });
    });
  });

  describe('deck_list_stacks', () => {
    it('should return stacks with cards', async () => {
      mockFetchDeckAPI.mockResolvedValue([sampleStack]);

      const { listStacksTool } = await import('../tools/apps/deck.js');
      const result = await listStacksTool.handler({ boardId: 1 });

      expect(result.content[0].text).toContain('To Do');
      expect(result.content[0].text).toContain('Fix login bug');
      expect(result.content[0].text).toContain('Board stacks (1)');
    });

    it('should handle empty board', async () => {
      mockFetchDeckAPI.mockResolvedValue([]);

      const { listStacksTool } = await import('../tools/apps/deck.js');
      const result = await listStacksTool.handler({ boardId: 1 });

      expect(result.content[0].text).toContain('No stacks found');
    });

    it('should show empty stacks', async () => {
      mockFetchDeckAPI.mockResolvedValue([{ ...sampleStack, cards: [] }]);

      const { listStacksTool } = await import('../tools/apps/deck.js');
      const result = await listStacksTool.handler({ boardId: 1 });

      expect(result.content[0].text).toContain('(empty)');
    });
  });

  describe('deck_create_stack', () => {
    it('should create a stack', async () => {
      mockFetchDeckAPI.mockResolvedValue(sampleStack);

      const { createStackTool } = await import('../tools/apps/deck.js');
      const result = await createStackTool.handler({ boardId: 1, title: 'To Do' });

      expect(result.content[0].text).toContain('Stack created');
      expect(result.content[0].text).toContain('To Do');
    });
  });

  describe('deck_get_card', () => {
    it('should return full card details', async () => {
      mockFetchDeckAPI.mockResolvedValue(sampleCard);

      const { getCardTool } = await import('../tools/apps/deck.js');
      const result = await getCardTool.handler({ boardId: 1, stackId: 5, cardId: 100 });

      expect(result.content[0].text).toContain('Fix login bug');
      expect(result.content[0].text).toContain('Users cannot log in with SSO');
      expect(result.content[0].text).toContain('Urgent');
      expect(result.content[0].text).toContain('Admin');
    });

    it('should handle 404', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchDeckAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const { getCardTool } = await import('../tools/apps/deck.js');
      const result = await getCardTool.handler({ boardId: 1, stackId: 5, cardId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Not found');
    });
  });

  describe('deck_create_card', () => {
    it('should create a card with title only', async () => {
      mockFetchDeckAPI.mockResolvedValue(sampleCard);

      const { createCardTool } = await import('../tools/apps/deck.js');
      const result = await createCardTool.handler({
        boardId: 1,
        stackId: 5,
        title: 'Fix login bug',
      });

      expect(result.content[0].text).toContain('Card created');
      expect(result.content[0].text).toContain('Fix login bug');
    });

    it('should create a card with all fields', async () => {
      mockFetchDeckAPI.mockResolvedValue(sampleCard);

      const { createCardTool } = await import('../tools/apps/deck.js');
      await createCardTool.handler({
        boardId: 1,
        stackId: 5,
        title: 'Fix login bug',
        description: 'SSO issue',
        duedate: '2025-12-31',
      });

      expect(mockFetchDeckAPI).toHaveBeenCalledWith('/boards/1/stacks/5/cards', {
        method: 'POST',
        body: {
          title: 'Fix login bug',
          type: 'plain',
          order: 0,
          description: 'SSO issue',
          duedate: '2025-12-31',
        },
      });
    });
  });

  describe('deck_update_card', () => {
    it('should fetch current card then update', async () => {
      const updated = { ...sampleCard, title: 'Updated title' };
      mockFetchDeckAPI.mockResolvedValueOnce(sampleCard).mockResolvedValueOnce(updated);

      const { updateCardTool } = await import('../tools/apps/deck.js');
      const result = await updateCardTool.handler({
        boardId: 1,
        stackId: 5,
        cardId: 100,
        title: 'Updated title',
      });

      expect(result.content[0].text).toContain('Updated title');
      expect(mockFetchDeckAPI).toHaveBeenCalledTimes(2);
      // Second call should be PUT with merged fields
      expect(mockFetchDeckAPI).toHaveBeenLastCalledWith('/boards/1/stacks/5/cards/100', {
        method: 'PUT',
        body: {
          title: 'Updated title',
          description: sampleCard.description,
          type: sampleCard.type,
          order: sampleCard.order,
          duedate: sampleCard.duedate,
          done: sampleCard.done,
          owner: sampleCard.owner.uid,
        },
      });
    });

    it('should clear duedate when null', async () => {
      mockFetchDeckAPI.mockResolvedValueOnce(sampleCard).mockResolvedValueOnce(sampleCard);

      const { updateCardTool } = await import('../tools/apps/deck.js');
      await updateCardTool.handler({
        boardId: 1,
        stackId: 5,
        cardId: 100,
        duedate: null,
      });

      expect(mockFetchDeckAPI).toHaveBeenLastCalledWith(
        '/boards/1/stacks/5/cards/100',
        expect.objectContaining({
          body: expect.objectContaining({ duedate: null }),
        })
      );
    });

    it('should handle 404 on get', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchDeckAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const { updateCardTool } = await import('../tools/apps/deck.js');
      const result = await updateCardTool.handler({
        boardId: 1,
        stackId: 5,
        cardId: 999,
        title: 'x',
      });

      expect(result.isError).toBe(true);
    });
  });

  describe('deck_move_card', () => {
    it('should move card to target stack', async () => {
      const movedCard = { ...sampleCard, stackId: 6 };
      mockFetchDeckAPI.mockResolvedValue(movedCard);

      const { moveCardTool } = await import('../tools/apps/deck.js');
      const result = await moveCardTool.handler({
        boardId: 1,
        stackId: 5,
        cardId: 100,
        targetStackId: 6,
      });

      expect(result.content[0].text).toContain('Card moved to stack 6');
      expect(mockFetchDeckAPI).toHaveBeenCalledWith('/boards/1/stacks/5/cards/100/reorder', {
        method: 'PUT',
        body: { order: 0, stackId: 6 },
      });
    });

    it('should support custom order', async () => {
      mockFetchDeckAPI.mockResolvedValue(sampleCard);

      const { moveCardTool } = await import('../tools/apps/deck.js');
      await moveCardTool.handler({
        boardId: 1,
        stackId: 5,
        cardId: 100,
        targetStackId: 6,
        order: 3,
      });

      expect(mockFetchDeckAPI).toHaveBeenCalledWith('/boards/1/stacks/5/cards/100/reorder', {
        method: 'PUT',
        body: { order: 3, stackId: 6 },
      });
    });
  });

  describe('deck_archive_card', () => {
    it('should archive a card', async () => {
      mockFetchDeckAPI.mockResolvedValue({ ...sampleCard, archived: true });

      const { archiveCardTool } = await import('../tools/apps/deck.js');
      const result = await archiveCardTool.handler({
        boardId: 1,
        stackId: 5,
        cardId: 100,
        archive: true,
      });

      expect(result.content[0].text).toContain('archived');
      expect(mockFetchDeckAPI).toHaveBeenCalledWith('/boards/1/stacks/5/cards/100/archive', {
        method: 'PUT',
      });
    });

    it('should unarchive a card', async () => {
      mockFetchDeckAPI.mockResolvedValue(sampleCard);

      const { archiveCardTool } = await import('../tools/apps/deck.js');
      const result = await archiveCardTool.handler({
        boardId: 1,
        stackId: 5,
        cardId: 100,
        archive: false,
      });

      expect(result.content[0].text).toContain('unarchived');
      expect(mockFetchDeckAPI).toHaveBeenCalledWith('/boards/1/stacks/5/cards/100/unarchive', {
        method: 'PUT',
      });
    });
  });

  describe('deck_assign_label', () => {
    it('should assign a label to a card', async () => {
      mockFetchDeckAPI.mockResolvedValue(undefined);

      const { assignLabelTool } = await import('../tools/apps/deck.js');
      const result = await assignLabelTool.handler({
        boardId: 1,
        stackId: 5,
        cardId: 100,
        labelId: 10,
      });

      expect(result.content[0].text).toContain('Label 10 assigned to card 100');
      expect(mockFetchDeckAPI).toHaveBeenCalledWith('/boards/1/stacks/5/cards/100/assignLabel', {
        method: 'PUT',
        body: { labelId: 10 },
      });
    });

    it('should handle 404', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchDeckAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const { assignLabelTool } = await import('../tools/apps/deck.js');
      const result = await assignLabelTool.handler({
        boardId: 1,
        stackId: 5,
        cardId: 999,
        labelId: 10,
      });

      expect(result.isError).toBe(true);
    });
  });

  describe('deck_assign_user', () => {
    it('should assign a user to a card', async () => {
      mockFetchDeckAPI.mockResolvedValue(undefined);

      const { assignUserTool } = await import('../tools/apps/deck.js');
      const result = await assignUserTool.handler({
        boardId: 1,
        stackId: 5,
        cardId: 100,
        userId: 'bob',
      });

      expect(result.content[0].text).toContain('"bob" assigned to card 100');
      expect(mockFetchDeckAPI).toHaveBeenCalledWith('/boards/1/stacks/5/cards/100/assignUser', {
        method: 'PUT',
        body: { userId: 'bob' },
      });
    });

    it('should handle 403', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchDeckAPI.mockRejectedValue(new ApiError(403, 'Forbidden', ''));

      const { assignUserTool } = await import('../tools/apps/deck.js');
      const result = await assignUserTool.handler({
        boardId: 1,
        stackId: 5,
        cardId: 100,
        userId: 'bob',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Permission denied');
    });
  });
});
