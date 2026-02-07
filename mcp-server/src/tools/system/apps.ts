import { z } from "zod";

/**
 * Nextcloud App Management Tools
 * Provides app management via OCC commands
 */

/**
 * List all installed apps with their status
 */
export const listAppsTool = {
  name: 'list_apps',
  description: 'List all installed Nextcloud apps with their enabled/disabled status',
  inputSchema: z.object({
    showDisabled: z.boolean().optional().describe('Show only disabled apps (default: show all)'),
  }),
  handler: async (args: { showDisabled?: boolean }) => {
    const flag = args.showDisabled ? ' --disabled' : '';
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ app:list${flag}`;
    const sshCommand = `php occ app:list${flag}`;

    const helpText = `To list installed apps, run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

This will display:
- **Enabled apps**: Apps currently active
- **Disabled apps**: Apps installed but not active${args.showDisabled ? '' : '\n- Use \`showDisabled: true\` to see only disabled apps'}

**Example output:**
\`\`\`
Enabled:
  - activity: 2.19.0
  - files: 2.0.0
  - photos: 2.3.0
  - tasks: 0.15.0

Disabled:
  - survey_client: 1.16.0
  - firstrunwizard: 2.16.0
\`\`\`

**Common apps:**
- \`files\` - File management (core)
- \`activity\` - Activity feed
- \`photos\` - Photo gallery
- \`tasks\` - Task management
- \`notes\` - Note taking
- \`deck\` - Kanban boards
- \`mail\` - Email client`;

    return {
      content: [{
        type: 'text',
        text: helpText,
      }],
    };
  },
};

/**
 * Get detailed information about a specific app
 */
export const getAppInfoTool = {
  name: 'get_app_info',
  description: 'Get detailed information about a specific Nextcloud app',
  inputSchema: z.object({
    appId: z.string().describe('The app ID (e.g., "tasks", "deck", "photos")'),
  }),
  handler: async (args: { appId: string }) => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ app:getpath ${args.appId}`;
    const sshCommand = `php occ app:getpath ${args.appId}`;

    const helpText = `To get information about app "${args.appId}", run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

This will display the app's installation path.

**For more detailed information:**
\`\`\`bash
# Get app version and status
php occ app:list | grep ${args.appId}

# Check if app is enabled
php occ app:list --enabled | grep ${args.appId}

# View app info from app store
https://apps.nextcloud.com/apps/${args.appId}
\`\`\`

**Example output:**
\`\`\`
/var/www/nextcloud/apps/${args.appId}
\`\`\`

**Common app IDs:**
- \`files\` - File management
- \`activity\` - Activity tracking
- \`photos\` - Photo gallery
- \`tasks\` - Task management
- \`notes\` - Notes app
- \`deck\` - Project boards
- \`mail\` - Email client
- \`calendar\` - Calendar
- \`contacts\` - Contacts`;

    return {
      content: [{
        type: 'text',
        text: helpText,
      }],
    };
  },
};

/**
 * Enable a disabled app
 */
export const enableAppTool = {
  name: 'enable_app',
  description: 'Enable a disabled Nextcloud app',
  inputSchema: z.object({
    appId: z.string().describe('The app ID to enable (e.g., "tasks", "deck", "photos")'),
  }),
  handler: async (args: { appId: string }) => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ app:enable ${args.appId}`;
    const sshCommand = `php occ app:enable ${args.appId}`;

    const helpText = `To enable app "${args.appId}", run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

**What this does:**
- Activates the app
- App becomes available in Nextcloud interface
- App functionality is restored
- App settings are preserved

**Success output:**
\`\`\`
${args.appId} enabled
\`\`\`

**Important notes:**
- App must already be installed
- May require database migrations on first enable
- Some apps may require configuration after enabling
- Check app requirements (PHP version, dependencies)

**If app is not installed:**
Install it first via:
- Web UI: Apps → Search for app → Install
- OCC: \`php occ app:install ${args.appId}\` (if available)

**Common apps to enable:**
- \`tasks\` - Task management
- \`notes\` - Note taking
- \`deck\` - Kanban boards
- \`photos\` - Photo gallery
- \`mail\` - Email client`;

    return {
      content: [{
        type: 'text',
        text: helpText,
      }],
    };
  },
};

/**
 * Disable an app
 */
export const disableAppTool = {
  name: 'disable_app',
  description: 'Disable an enabled Nextcloud app (preserves data but removes functionality)',
  inputSchema: z.object({
    appId: z.string().describe('The app ID to disable (e.g., "tasks", "deck", "photos")'),
  }),
  handler: async (args: { appId: string }) => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ app:disable ${args.appId}`;
    const sshCommand = `php occ app:disable ${args.appId}`;

    const helpText = `⚠️  **CAUTION:** This will disable app "${args.appId}" and remove its functionality.

To disable this app, run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

**What this does:**
- Deactivates the app
- Removes app from Nextcloud interface
- App functionality becomes unavailable
- **Data is preserved** (can be re-enabled)

**Does NOT:**
- Delete the app files
- Remove app data from database
- Uninstall the app
- Delete user data created by the app

**Success output:**
\`\`\`
${args.appId} disabled
\`\`\`

**Important warnings:**
- ⚠️  Users lose access to app features immediately
- ⚠️  Cannot disable core apps (files, settings, etc.)
- ⚠️  Some apps are required by other apps
- ⚠️  App can be re-enabled with \`app:enable\`

**To permanently remove an app:**
1. First disable: \`php occ app:disable ${args.appId}\`
2. Then remove: \`php occ app:remove ${args.appId}\`

**Common reasons to disable apps:**
- Troubleshooting conflicts
- Reducing resource usage
- Security concerns
- Unused functionality`;

    return {
      content: [{
        type: 'text',
        text: helpText,
      }],
    };
  },
};

/**
 * Export all App Management tools
 */
export const appsTools = [
  listAppsTool,
  getAppInfoTool,
  enableAppTool,
  disableAppTool,
];
