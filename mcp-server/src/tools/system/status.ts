import { z } from "zod";

/**
 * Nextcloud System Status Tools
 * Provides system diagnostics and monitoring via OCC commands
 */

/**
 * Get overall system status
 */
export const systemStatusTool = {
  name: 'system_status',
  description: 'Get Nextcloud system status including version, installation path, and configuration',
  inputSchema: z.object({}),
  handler: async () => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ status`;
    const sshCommand = `php occ status`;

    const helpText = `To check Nextcloud system status, run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

This will display:
- Nextcloud installation status
- Nextcloud version
- Version string
- Installation directory
- Configuration directory
- Data directory

**Example output:**
\`\`\`json
{
  "installed": true,
  "version": "28.0.1.1",
  "versionstring": "28.0.1",
  "edition": "",
  "maintenance": false,
  "needsDbUpgrade": false,
  "productname": "Nextcloud",
  "extendedSupport": false
}
\`\`\`

**For more detailed system information**, you can also run:
- \`php occ --version\` - Show Nextcloud version
- \`php occ check\` - Check dependencies
- \`php occ integrity:check-core\` - Check core integrity`;

    return {
      content: [{
        type: 'text',
        text: helpText,
      }],
    };
  },
};

/**
 * Run setup checks to verify system configuration
 */
export const setupChecksTool = {
  name: 'run_setup_checks',
  description: 'Run Nextcloud setup checks to verify security and configuration',
  inputSchema: z.object({}),
  handler: async () => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ setupchecks`;
    const sshCommand = `php occ setupchecks`;

    const helpText = `To run Nextcloud setup checks, run this command on your Nextcloud server:

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
- File locking setup

**Output levels:**
- ✅ **SUCCESS** - Everything is configured correctly
- ⚠️  **WARNING** - Minor issues or recommendations
- ❌ **ERROR** - Critical issues that should be fixed

**Example output:**
\`\`\`
[✅] PHP version: 8.2.15 - OK
[✅] Database: MySQL 8.0.35 - OK
[⚠️ ] PHP memory limit: 512M (recommended: 1024M)
[✅] HTTPS configured correctly
[❌] Cron not configured - using AJAX
[✅] All required PHP modules installed
\`\`\`

**Common warnings and how to fix them:**
- **Memory limit too low**: Increase \`memory_limit\` in php.ini
- **Cron not configured**: Set up system cron job
- **Missing PHP modules**: Install required extensions
- **HTTPS not configured**: Configure SSL/TLS certificate`;

    return {
      content: [{
        type: 'text',
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
