import { z } from "zod";

/**
 * Nextcloud Security & Integrity Tools
 * These require server-side OCC access and cannot be performed via the REST API.
 */

/**
 * Check Nextcloud core system integrity
 */
export const checkCoreIntegrityTool = {
  name: 'check_core_integrity',
  description: 'Get instructions for verifying Nextcloud core system integrity (requires server-side OCC access)',
  inputSchema: z.object({}),
  handler: async () => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ integrity:check-core`;
    const sshCommand = `php occ integrity:check-core`;

    const helpText = `**Note:** Integrity checks require server-side OCC access and cannot be performed via the REST API.

To check Nextcloud core integrity, run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

**What this checks:**
- Verifies core Nextcloud files haven't been modified
- Checks file signatures against official Nextcloud release
- Detects unauthorized changes or tampering

**Expected output (if integrity is OK):**
\`\`\`
No errors have been found.
\`\`\`

**Result types:**
- **EXTRA_FILE:** File exists but shouldn't -- review and remove if suspicious
- **INVALID_HASH:** File has been modified -- compare with official version
- **MISSING_FILE:** Required file is missing -- reinstall may be needed`;

    return {
      content: [{
        type: 'text' as const,
        text: helpText,
      }],
    };
  },
};

/**
 * Check integrity of a specific app
 */
export const checkAppIntegrityTool = {
  name: 'check_app_integrity',
  description: 'Get instructions for verifying integrity of a specific Nextcloud app (requires server-side OCC access)',
  inputSchema: z.object({
    appId: z.string().describe('The app ID to check (e.g., "tasks", "deck", "photos")'),
  }),
  handler: async (args: { appId: string }) => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ integrity:check-app ${args.appId}`;
    const sshCommand = `php occ integrity:check-app ${args.appId}`;

    const helpText = `**Note:** Integrity checks require server-side OCC access and cannot be performed via the REST API.

To check integrity of app "${args.appId}", run this command on your Nextcloud server:

**Docker:**
\`\`\`bash
${dockerCommand}
\`\`\`

**SSH:**
\`\`\`bash
${sshCommand}
\`\`\`

**What this checks:**
- Verifies app files haven't been modified
- Checks file signatures against app's official release
- Detects unauthorized changes

**Expected output (if integrity is OK):**
\`\`\`
No errors have been found.
\`\`\`

**Note:** Only apps with signatures can be checked. Custom/unsigned apps cannot be verified.`;

    return {
      content: [{
        type: 'text' as const,
        text: helpText,
      }],
    };
  },
};

/**
 * Export all Security & Integrity tools
 */
export const securityTools = [
  checkCoreIntegrityTool,
  checkAppIntegrityTool,
];
