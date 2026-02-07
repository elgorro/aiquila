import { z } from "zod";

/**
 * Nextcloud Security & Integrity Tools
 * Provides security verification via OCC commands
 */

/**
 * Check Nextcloud core system integrity
 */
export const checkCoreIntegrityTool = {
  name: 'check_core_integrity',
  description: 'Verify Nextcloud core system integrity by checking file signatures',
  inputSchema: z.object({}),
  handler: async () => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ integrity:check-core`;
    const sshCommand = `php occ integrity:check-core`;

    const helpText = `To check Nextcloud core integrity, run this command on your Nextcloud server:

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
- Validates system file integrity

**Expected output (if integrity is OK):**
\`\`\`
No errors have been found.
\`\`\`

**If files have been modified:**
\`\`\`
Technical information
=====================
The following list covers which files have failed the integrity check.
Please read the list carefully and determine if the modifications are
expected or not.

- core
  - EXTRA_FILE
    - /path/to/extra/file.php
  - INVALID_HASH
    - /path/to/modified/file.php
      - Expected: abc123...
      - Current: def456...
\`\`\`

**What the results mean:**

**EXTRA_FILE:**
- File exists but shouldn't
- Could be custom code or malware
- Review and remove if suspicious

**INVALID_HASH:**
- File has been modified
- Could be customization or tampering
- Compare with official version

**MISSING_FILE:**
- Required file is missing
- Could indicate corruption or deletion
- Reinstall may be needed

**When to run this check:**
- After Nextcloud updates
- Suspected security incidents
- Regular security audits
- Before/after system maintenance
- Troubleshooting unusual behavior

**Common causes of integrity failures:**
- Custom themes or modifications
- Manual file edits
- Incomplete updates
- File system corruption
- Security breaches

**How to resolve issues:**
1. Review each flagged file
2. If expected (custom code): Document it
3. If unexpected: Investigate immediately
4. Restore from official source if needed
5. Run check again to verify

**Security best practices:**
- Run integrity checks monthly
- Never modify core Nextcloud files
- Use apps for customization
- Keep audit logs of changes
- Document all customizations`;

    return {
      content: [{
        type: 'text',
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
  description: 'Verify integrity of a specific Nextcloud app by checking its file signatures',
  inputSchema: z.object({
    appId: z.string().describe('The app ID to check (e.g., "tasks", "deck", "photos")'),
  }),
  handler: async (args: { appId: string }) => {
    const dockerCommand = `docker exec -u www-data aiquila-nextcloud php occ integrity:check-app ${args.appId}`;
    const sshCommand = `php occ integrity:check-app ${args.appId}`;

    const helpText = `To check integrity of app "${args.appId}", run this command on your Nextcloud server:

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
- Validates app file integrity

**Expected output (if integrity is OK):**
\`\`\`
No errors have been found.
\`\`\`

**If files have been modified:**
\`\`\`
Technical information
=====================
The following list covers which files have failed the integrity check
for app "${args.appId}".

- ${args.appId}
  - EXTRA_FILE
    - /path/to/extra/file.php
  - INVALID_HASH
    - /path/to/modified/file.php
      - Expected: abc123...
      - Current: def456...
\`\`\`

**Common apps to check:**
- \`files\` - File management
- \`activity\` - Activity feed
- \`photos\` - Photo gallery
- \`tasks\` - Task management
- \`notes\` - Notes app
- \`deck\` - Kanban boards
- \`mail\` - Email client
- \`calendar\` - Calendar
- \`contacts\` - Contacts

**When to run app integrity checks:**
- After app updates
- Before important operations
- Suspected security issues
- Regular security audits
- Troubleshooting app errors

**What to do if integrity check fails:**

1. **For official apps** (from app store):
   - Reinstall the app
   - Check app version matches
   - Verify no manual modifications

2. **For custom apps**:
   - Document all modifications
   - Review changes for security
   - Consider maintaining a fork

3. **For suspicious changes**:
   - Investigate immediately
   - Check server logs
   - Look for security incidents
   - Consider system compromise

**Note:** Only apps with signatures can be checked. Custom/unsigned apps cannot be verified.

**To check all apps:**
Run \`php occ integrity:check-core\` to scan entire system including all apps.`;

    return {
      content: [{
        type: 'text',
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
