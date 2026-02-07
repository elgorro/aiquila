import { z } from "zod";
import { fetchOCS, fetchStatus } from "../../client/ocs.js";

/**
 * Nextcloud System Status Tools
 * Provides system diagnostics and monitoring via OCS API
 */

/**
 * Get overall system status
 */
export const systemStatusTool = {
  name: 'system_status',
  description: 'Get Nextcloud system status including version, installation path, and configuration',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const [status, capabilities] = await Promise.all([
        fetchStatus(),
        fetchOCS<Record<string, unknown>>("/ocs/v2.php/cloud/capabilities"),
      ]);

      const result = {
        status,
        capabilities: capabilities.ocs.data,
      };

      return {
        content: [{
          type: 'text' as const,
          text: JSON.stringify(result, null, 2),
        }],
      };
    } catch (error) {
      return {
        content: [{
          type: 'text' as const,
          text: `Error fetching system status: ${error instanceof Error ? error.message : String(error)}`,
        }],
        isError: true,
      };
    }
  },
};

/**
 * Run setup checks to verify system configuration
 * Note: This requires server-side OCC access and cannot be performed via the REST API.
 */
export const setupChecksTool = {
  name: 'run_setup_checks',
  description: 'Get instructions for running Nextcloud setup checks (requires server-side OCC access)',
  inputSchema: z.object({}),
  handler: async () => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ setupchecks`;
    const sshCommand = `php occ setupchecks`;

    const helpText = `**Note:** Setup checks require server-side OCC access and cannot be performed via the REST API.

To run Nextcloud setup checks, run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

This will perform comprehensive checks on:

**Security:**
- HTTPS configuration
- Security headers
- PHP security settings
- Database security
- File permissions

**Performance:**
- PHP memory limits
- PHP opcache configuration
- Database configuration
- Caching setup

**Configuration:**
- PHP modules and extensions
- Database compatibility
- Cron job configuration
- File locking setup`;

    return {
      content: [{
        type: 'text' as const,
        text: helpText,
      }],
    };
  },
};

/**
 * Export all System Status tools
 */
export const statusTools = [
  systemStatusTool,
  setupChecksTool,
];
