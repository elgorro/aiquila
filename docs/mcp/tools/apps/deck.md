# Deck Tools

Work with [Deck](https://apps.nextcloud.com/apps/deck) kanban boards: boards, stacks (columns), cards, labels and assignments.

## Prerequisites

- Deck installed and enabled (app ID: `deck`)
- For a team board: Nextcloud 35, and membership in the team

## API

Uses the [Deck REST API v1.0](https://deck.readthedocs.io/en/latest/API/) at `/index.php/apps/deck/api/v1.0`. A team board is created through Deck's OCS endpoint `/ocs/v2.php/apps/deck/api/v1.0/boards/team`.

## Available Tools

IDs come from the tool before: board IDs from `deck_list_boards`, stack and card IDs from `deck_list_stacks`, label IDs from `deck_get_board`.

### Boards

#### `deck_list_boards`
List the boards with ID, title, owner and label count.

#### `deck_get_board`
Get a board with its labels, its sharing (ACL) and, for a team board, the team. Use `deck_list_stacks` for the columns and cards.

**Parameters:**
- `boardId` (integer, required)

#### `deck_create_board`
Create a board.

**Parameters:**
- `title` (string, required)
- `color` (string, optional) — hex colour without `#`. Default `0800fd`.
- `teamId` (string, optional) — a team you belong to. All its members can edit the board, and it appears in the team's workspace. Nextcloud 35; on older versions the parameter is refused.

### Stacks

#### `deck_list_stacks`
List a board's stacks with the cards in each: the whole board at a glance.

**Parameters:**
- `boardId` (integer, required)

#### `deck_create_stack`
Add a stack to a board.

**Parameters:**
- `boardId` (integer, required)
- `title` (string, required)
- `order` (integer, optional) — position, from 0.

### Cards

Every card tool takes `boardId`, `stackId` and `cardId` (integers, required), except `deck_create_card`, which has no `cardId`.

#### `deck_get_card`
Get a card in full: description, due date, labels and assigned users.

#### `deck_create_card`
Create a card in a stack.

**Parameters:**
- `title` (string, required)
- `description` (string, optional) — Markdown.
- `duedate` (string, optional) — ISO 8601, such as `2026-12-31`.

#### `deck_update_card`
Change a card. Only the fields passed change; the rest are read from the card and kept.

**Parameters:**
- `title`, `description` (string, optional)
- `duedate` (string or null, optional) — `null` removes the due date.
- `done` (boolean, optional) — mark the card done or not done.

#### `deck_move_card`
Move a card to another stack on the same board, such as from *Doing* to *Done*.

**Parameters:**
- `targetStackId` (integer, required)
- `order` (integer, optional) — position in the target stack. Default `0`, the top.

**Example:** "Move the invoice card to Done."

#### `deck_archive_card`
Archive a card, or bring it back.

**Parameters:**
- `archive` (boolean, optional) — `false` to unarchive. Default `true`.

#### `deck_assign_label`
Put a label on a card.

**Parameters:**
- `labelId` (integer, required)

#### `deck_assign_user`
Assign a user to a card.

**Parameters:**
- `userId` (string, required) — the login name.
