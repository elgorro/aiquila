import { z } from "zod";

/**
 * Nextcloud User Management Tools
 * Provides user account management via OCC commands
 */

/**
 * List all users in the Nextcloud instance
 */
export const listUsersTool = {
  name: 'list_users',
  description: 'List all users in the Nextcloud instance',
  inputSchema: z.object({
    showDisabled: z.boolean().optional().describe('Include disabled users in the list (default: false)'),
  }),
  handler: async (args: { showDisabled?: boolean }) => {
    const showDisabledFlag = args.showDisabled ? ' --disabled' : '';
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ user:list${showDisabledFlag}`;
    const sshCommand = `php occ user:list${showDisabledFlag}`;

    const helpText = `To list all users, run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

This will display:
- User ID (login name)
- Display name
- Email address
${args.showDisabled ? '- Disabled users (if any)' : ''}

**Example output:**
\`\`\`
- alice: Alice Smith (alice@example.com)
- bob: Bob Jones (bob@example.com)
- admin: Administrator (admin@example.com)
\`\`\``;

    return {
      content: [{
        type: 'text',
        text: helpText,
      }],
    };
  },
};

/**
 * Get detailed information about a specific user
 */
export const getUserInfoTool = {
  name: 'get_user_info',
  description: 'Get detailed information about a specific Nextcloud user',
  inputSchema: z.object({
    userId: z.string().describe('The user ID (login name) to get information about'),
  }),
  handler: async (args: { userId: string }) => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ user:info ${args.userId}`;
    const sshCommand = `php occ user:info ${args.userId}`;

    const helpText = `To get information about user "${args.userId}", run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

This will display:
- User ID (login name)
- Display name
- Email address
- Groups the user belongs to
- Quota settings
- Last login time
- Account enabled status
- Backend (database, LDAP, etc.)

**Example output:**
\`\`\`
  - user_id: ${args.userId}
  - display_name: Alice Smith
  - email: alice@example.com
  - groups: admin, users
  - quota: 10 GB
  - last_seen: 2024-02-07 10:30:00
  - enabled: true
  - backend: Database
\`\`\``;

    return {
      content: [{
        type: 'text',
        text: helpText,
      }],
    };
  },
};

/**
 * Enable a disabled user account
 */
export const enableUserTool = {
  name: 'enable_user',
  description: 'Enable a disabled Nextcloud user account',
  inputSchema: z.object({
    userId: z.string().describe('The user ID (login name) to enable'),
  }),
  handler: async (args: { userId: string }) => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ user:enable ${args.userId}`;
    const sshCommand = `php occ user:enable ${args.userId}`;

    const helpText = `To enable user "${args.userId}", run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

**What this does:**
- Re-enables the user account
- User can log in again
- User's files and data remain intact
- User regains access to all their resources

**Success output:**
\`\`\`
The specified user is enabled
\`\`\`

**Note:** If the user is already enabled, you'll see a message indicating the user is already enabled.`;

    return {
      content: [{
        type: 'text',
        text: helpText,
      }],
    };
  },
};

/**
 * Disable a user account
 */
export const disableUserTool = {
  name: 'disable_user',
  description: 'Disable a Nextcloud user account (prevents login but preserves data)',
  inputSchema: z.object({
    userId: z.string().describe('The user ID (login name) to disable'),
  }),
  handler: async (args: { userId: string }) => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ user:disable ${args.userId}`;
    const sshCommand = `php occ user:disable ${args.userId}`;

    const helpText = `⚠️  **CAUTION:** This will disable user "${args.userId}" and prevent them from logging in.

To disable this user, run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

**What this does:**
- Prevents the user from logging in
- User's files and data are preserved
- User's shares remain active
- Can be reversed with \`user:enable\`

**Does NOT:**
- Delete the user's files
- Remove the user's account
- Delete the user's shares

**Success output:**
\`\`\`
The specified user is disabled
\`\`\`

**To permanently delete a user instead**, use:
- Docker: \`docker exec -u www-data aiquila-nextcloud php occ user:delete ${args.userId}\`
- SSH: \`php occ user:delete ${args.userId}\`

**Note:** Cannot disable admin users while they are the only admin.`;

    return {
      content: [{
        type: 'text',
        text: helpText,
      }],
    };
  },
};

/**
 * Export all User Management tools
 */
export const usersTools = [
  listUsersTool,
  getUserInfoTool,
  enableUserTool,
  disableUserTool,
];
