import { z } from "zod";

/**
 * Nextcloud Share Management Tools
 * Provides share listing and diagnostics via OCC commands
 */

/**
 * List all file shares (for diagnostics and auditing)
 */
export const listSharesTool = {
  name: 'list_shares',
  description: 'List all file shares in Nextcloud (for diagnostics and security auditing)',
  inputSchema: z.object({
    userId: z.string().optional().describe('Filter shares by specific user (optional)'),
  }),
  handler: async (args: { userId?: string }) => {
    const userFilter = args.userId ? ` --user=${args.userId}` : '';
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ sharing:list${userFilter}`;
    const sshCommand = `php occ sharing:list${userFilter}`;

    const helpText = `To list file shares${args.userId ? ` for user "${args.userId}"` : ''}, run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

**What this shows:**
- All active file/folder shares
- Share types (user, group, link, email)
- Share permissions
- Share owners
- Shared paths
${args.userId ? '- Only shares for specified user' : '- All shares across system'}

**Example output:**
\`\`\`json
{
  "shares": [
    {
      "id": "123",
      "share_type": 0,
      "share_with": "bob",
      "share_with_displayname": "Bob Jones",
      "path": "/Documents/project.pdf",
      "permissions": 19,
      "stime": 1707312000,
      "uid_owner": "alice",
      "displayname_owner": "Alice Smith"
    },
    {
      "id": "124",
      "share_type": 3,
      "share_with": "https://cloud.example.com/s/AbCd123",
      "path": "/Photos/vacation.jpg",
      "permissions": 1,
      "stime": 1707312100,
      "uid_owner": "alice",
      "token": "AbCd123",
      "expiration": "2024-03-01"
    }
  ]
}
\`\`\`

**Share Types:**
- \`0\` - User share (shared with specific user)
- \`1\` - Group share (shared with group)
- \`3\` - Public link (shareable URL)
- \`4\` - Email share (sent via email)
- \`6\` - Federated share (remote server)

**Permissions (bitwise):**
- \`1\` - Read
- \`2\` - Update
- \`4\` - Create
- \`8\` - Delete
- \`16\` - Share
- \`19\` - All permissions (1+2+16)
- \`31\` - Full permissions (1+2+4+8+16)

**Common use cases:**

**Security audit:**
\`\`\`
# List all shares to review permissions
php occ sharing:list

# Check specific user's shares
php occ sharing:list --user=alice
\`\`\`

**Find public links:**
Look for \`share_type: 3\` in the output

**Check expired shares:**
Look for \`expiration\` field and compare to current date

**Review permissions:**
Check \`permissions\` values to ensure appropriate access levels

**Troubleshooting shares:**
- User can't access: Check permissions and share_with
- Link not working: Verify token and expiration
- Wrong permissions: Check permission bits

**Important notes:**
- This is a read-only diagnostic tool
- Does not modify or delete shares
- Large installations may have many shares
- Use user filter for targeted analysis

**To manage shares:**
- Create shares: Use web UI or WebDAV
- Delete shares: Use web UI or \`php occ sharing:delete <share-id>\`
- Modify shares: Use web UI or sharing API

**Related commands:**
\`\`\`bash
# Delete a specific share
php occ sharing:delete <share-id>

# Clean up expired shares
php occ sharing:cleanup-remote-storages

# List external storages
php occ files_external:list
\`\`\`

**Privacy considerations:**
- Share list may contain sensitive paths
- Review who has access to share information
- Audit public links regularly
- Set expiration dates on sensitive shares`;

    return {
      content: [{
        type: 'text',
        text: helpText,
      }],
    };
  },
};

/**
 * Export all Share Management tools
 */
export const sharesTools = [listSharesTool];
