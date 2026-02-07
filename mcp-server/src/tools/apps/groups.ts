import { z } from "zod";

/**
 * Nextcloud Group Management Tools
 * Provides group management via OCC commands
 */

/**
 * List all groups in the Nextcloud instance
 */
export const listGroupsTool = {
  name: 'list_groups',
  description: 'List all groups in the Nextcloud instance',
  inputSchema: z.object({}),
  handler: async () => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ group:list`;
    const sshCommand = `php occ group:list`;

    const helpText = `To list all groups, run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

This will display:
- Group name
- List of members in each group

**Example output:**
\`\`\`
- admin: alice, bob
- users: alice, bob, charlie, diana
- marketing: charlie, diana
- developers: alice, bob
\`\`\`

**For more detailed group information:**
- To count users: \`php occ group:list --output=json | jq\`
- To see specific group: Check group info using user:info for members`;

    return {
      content: [{
        type: 'text',
        text: helpText,
      }],
    };
  },
};

/**
 * Get detailed information about a specific group
 */
export const getGroupInfoTool = {
  name: 'get_group_info',
  description: 'Get detailed information about a specific Nextcloud group',
  inputSchema: z.object({
    groupId: z.string().describe('The group ID (name) to get information about'),
  }),
  handler: async (args: { groupId: string }) => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ group:list ${args.groupId}`;
    const sshCommand = `php occ group:list ${args.groupId}`;

    const helpText = `To get information about group "${args.groupId}", run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

This will display:
- Group name
- All members of the group

**Example output:**
\`\`\`
- ${args.groupId}: alice, bob, charlie
\`\`\`

**Additional information:**
To see detailed info about each member, use \`user:info\` command for individual users.

**Common groups:**
- \`admin\` - Administrators with full access
- \`users\` - Default group for regular users
- Custom groups - Department or team-based groups`;

    return {
      content: [{
        type: 'text',
        text: helpText,
      }],
    };
  },
};

/**
 * Add a user to a group
 */
export const addUserToGroupTool = {
  name: 'add_user_to_group',
  description: 'Add a Nextcloud user to a group',
  inputSchema: z.object({
    userId: z.string().describe('The user ID (login name) to add to the group'),
    groupId: z.string().describe('The group ID (name) to add the user to'),
  }),
  handler: async (args: { userId: string; groupId: string }) => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ group:adduser ${args.groupId} ${args.userId}`;
    const sshCommand = `php occ group:adduser ${args.groupId} ${args.userId}`;

    const helpText = `To add user "${args.userId}" to group "${args.groupId}", run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

**What this does:**
- Adds user to the specified group
- User inherits group permissions
- User gains access to group folders and resources
- User can see shared items from group members

**Success output:**
\`\`\`
User "${args.userId}" added to group "${args.groupId}"
\`\`\`

**Important notes:**
- If the group doesn't exist, you'll need to create it first:
  - Docker: \`docker exec -u www-data aiquila-nextcloud php occ group:add ${args.groupId}\`
  - SSH: \`php occ group:add ${args.groupId}\`
- Adding user to \`admin\` group grants administrator privileges
- User can be member of multiple groups simultaneously

**Common use cases:**
- Add user to \`admin\` for administrative access
- Add user to department groups for team collaboration
- Add user to project-specific groups for resource access`;

    return {
      content: [{
        type: 'text',
        text: helpText,
      }],
    };
  },
};

/**
 * Remove a user from a group
 */
export const removeUserFromGroupTool = {
  name: 'remove_user_from_group',
  description: 'Remove a Nextcloud user from a group',
  inputSchema: z.object({
    userId: z.string().describe('The user ID (login name) to remove from the group'),
    groupId: z.string().describe('The group ID (name) to remove the user from'),
  }),
  handler: async (args: { userId: string; groupId: string }) => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ group:removeuser ${args.groupId} ${args.userId}`;
    const sshCommand = `php occ group:removeuser ${args.groupId} ${args.userId}`;

    const helpText = `⚠️  **CAUTION:** This will remove user "${args.userId}" from group "${args.groupId}".

To remove this user from the group, run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

**What this does:**
- Removes user from the specified group
- User loses group permissions
- User may lose access to group folders and resources
- User's shared items from this group may become inaccessible

**Success output:**
\`\`\`
User "${args.userId}" removed from group "${args.groupId}"
\`\`\`

**Important warnings:**
- ⚠️  Removing from \`admin\` group removes administrator privileges
- ⚠️  User may lose access to files shared with the group
- ⚠️  Cannot remove the last admin from the \`admin\` group
- ⚠️  Group folder access will be revoked

**Does NOT:**
- Delete the user's personal files
- Remove the user's account
- Delete the group itself

**To delete a group entirely:**
- Docker: \`docker exec -u www-data aiquila-nextcloud php occ group:delete ${args.groupId}\`
- SSH: \`php occ group:delete ${args.groupId}\``;

    return {
      content: [{
        type: 'text',
        text: helpText,
      }],
    };
  },
};

/**
 * Export all Group Management tools
 */
export const groupsTools = [
  listGroupsTool,
  getGroupInfoTool,
  addUserToGroupTool,
  removeUserFromGroupTool,
];
