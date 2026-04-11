// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// Mock webdav client (needed for bulk file operations)
const mockClient = {
  getDirectoryContents: vi.fn(),
  getFileContents: vi.fn(),
  putFileContents: vi.fn(),
  createDirectory: vi.fn(),
  deleteFile: vi.fn(),
  moveFile: vi.fn(),
  copyFile: vi.fn(),
};

vi.mock('webdav', () => ({
  createClient: vi.fn(() => mockClient),
}));

// Mock fetch for CalDAV (trash & versions)
global.fetch = vi.fn();

// Mock the OCS client module
const mockFetchOCS = vi.fn();
const mockFetchStatus = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
  fetchStatus: (...args: unknown[]) => mockFetchStatus(...args),
}));

// Mock the AIquila API client module
const mockExecuteOCC = vi.fn();

vi.mock('../client/aiquila.js', async () => {
  const actual =
    await vi.importActual<typeof import('../client/aiquila.js')>('../client/aiquila.js');
  return {
    ...actual,
    executeOCC: (...args: unknown[]) => mockExecuteOCC(...args),
  };
});

describe('OCS-based Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('system_status', () => {
    it('should return combined status and capabilities', async () => {
      mockFetchStatus.mockResolvedValue({
        installed: true,
        maintenance: false,
        version: '28.0.1.1',
        versionstring: '28.0.1',
        productname: 'Nextcloud',
      });
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { version: { major: 28, minor: 0, micro: 1 } },
        },
      });

      const { systemStatusTool } = await import('../tools/system/status.js');
      const result = await systemStatusTool.handler();

      expect(result.content[0].text).toContain('28.0.1');
      expect(result.content[0].text).toContain('Nextcloud');
      expect(result).not.toHaveProperty('isError');
    });

    it('should handle errors gracefully', async () => {
      mockFetchStatus.mockRejectedValue(new Error('Connection refused'));

      const { systemStatusTool } = await import('../tools/system/status.js');
      const result = await systemStatusTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Connection refused');
    });
  });

  describe('run_occ', () => {
    afterEach(() => {
      delete process.env.MCP_OCC_ALLOWLIST;
    });

    it('should return success output for a successful command', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: 'app:list output',
        stderr: '',
      });

      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'app:list' });

      expect(result.isError).toBe(false);
      expect(result.content[0].text).toContain('Command completed successfully');
      expect(result.content[0].text).toContain('app:list output');
    });

    it('should reject commands not in the allowlist', async () => {
      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'user:delete' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not in the OCC allowlist');
      expect(result.content[0].text).toContain('user:delete');
      expect(mockExecuteOCC).not.toHaveBeenCalled();
    });

    it('should not allow prefix matching (user:delete vs user:list)', async () => {
      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'user:delete' });

      expect(result.isError).toBe(true);
      expect(mockExecuteOCC).not.toHaveBeenCalled();
    });

    it('should use custom allowlist from MCP_OCC_ALLOWLIST env var', async () => {
      process.env.MCP_OCC_ALLOWLIST = 'custom:cmd,another:cmd';

      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: 'custom output',
        stderr: '',
      });

      const { runOccTool, getOccAllowlist } = await import('../tools/system/occ.js');

      expect(getOccAllowlist()).toEqual(['custom:cmd', 'another:cmd']);

      const result = await runOccTool.handler({ command: 'custom:cmd' });
      expect(result.isError).toBe(false);

      // Default commands should be blocked when custom list is set
      const result2 = await runOccTool.handler({ command: 'app:list' });
      expect(result2.isError).toBe(true);
    });

    it('should return clear permission denied message on 403', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockExecuteOCC.mockRejectedValue(new ApiError(403, 'Forbidden', '<html>forbidden</html>'));

      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'app:list' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Permission denied');
      expect(result.content[0].text).toContain('admin rights');
      expect(result.content[0].text).not.toContain('<html>');
    });

    it('should return clear auth failed message on 401', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockExecuteOCC.mockRejectedValue(new ApiError(401, 'Unauthorized', ''));

      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'app:list' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Authentication failed');
    });

    it('should return clear not found message on 404', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockExecuteOCC.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'app:list' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('OCC endpoint not found');
      expect(result.content[0].text).toContain('AIquila app');
    });

    it('should handle generic errors gracefully', async () => {
      mockExecuteOCC.mockRejectedValue(new Error('Network timeout'));

      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'app:list' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Network timeout');
    });
  });

  describe('OCC output redaction', () => {
    it('should redact PHP array format key-value pairs', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      const input = `"dbpassword" => "s3cret_db_pass"`;
      const result = redactSensitiveOutput(input);
      expect(result).toBe(`"dbpassword" => "[REDACTED]"`);
    });

    it('should redact JSON format key-value pairs', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      const input = `"password": "my_secret_pass"`;
      const result = redactSensitiveOutput(input);
      expect(result).toBe(`"password": "[REDACTED]"`);
    });

    it('should redact multiple sensitive keys', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      const input = [
        `"secret": "abc123"`,
        `"mail_smtppassword": "smtp_pass"`,
        `"api_key": "key-xyz"`,
        `"passwordsalt": "saltyvalue"`,
      ].join('\n');
      const result = redactSensitiveOutput(input);
      expect(result).toContain(`"secret": "[REDACTED]"`);
      expect(result).toContain(`"mail_smtppassword": "[REDACTED]"`);
      expect(result).toContain(`"api_key": "[REDACTED]"`);
      expect(result).toContain(`"passwordsalt": "[REDACTED]"`);
    });

    it('should pass through non-sensitive output unchanged', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      const input = `Nextcloud 28.0.4\ninstalled: true\nmaintenance: false`;
      expect(redactSensitiveOutput(input)).toBe(input);
    });

    it('should redact database URI credentials', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      const input = `mysql://admin:super_secret@localhost/nextcloud`;
      const result = redactSensitiveOutput(input);
      expect(result).toBe(`mysql://admin:[REDACTED]@localhost/nextcloud`);
    });

    it('should redact PHP stack trace paths', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      const input = `Error at /var/www/html/lib/private/App.php:123`;
      const result = redactSensitiveOutput(input);
      expect(result).toBe(`Error at [REDACTED_PATH]`);
    });

    it('should handle a realistic config:list JSON blob', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      const input = JSON.stringify(
        {
          system: {
            version: '28.0.4',
            dbpassword: 'real_db_password',
            secret: 'instance_secret_value',
            mail_smtppassword: 'smtp_pw',
            installed: true,
            overwrite_cli_url: 'https://cloud.example.com',
          },
        },
        null,
        2
      );
      const result = redactSensitiveOutput(input);
      expect(result).toContain(`"version": "28.0.4"`);
      expect(result).toContain(`"installed": true`);
      expect(result).toContain(`"dbpassword": "[REDACTED]"`);
      expect(result).toContain(`"secret": "[REDACTED]"`);
      expect(result).toContain(`"mail_smtppassword": "[REDACTED]"`);
      expect(result).not.toContain('real_db_password');
      expect(result).not.toContain('instance_secret_value');
    });

    it('should handle empty string input', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      expect(redactSensitiveOutput('')).toBe('');
    });

    it('should redact output in the handler response', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: `"dbpassword": "hunter2"`,
        stderr: `Warning at /var/www/html/lib/foo.php:42`,
      });

      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'config:app:get', args: ['core'] });

      expect(result.content[0].text).toContain(`"dbpassword": "[REDACTED]"`);
      expect(result.content[0].text).not.toContain('hunter2');
      expect(result.content[0].text).toContain('[REDACTED_PATH]');
      expect(result.content[0].text).not.toContain('/var/www/html/lib/foo.php');
    });

    it('should not include config:list in default allowlist', async () => {
      delete process.env.MCP_OCC_ALLOWLIST;
      const { getOccAllowlist } = await import('../tools/system/occ.js');
      const allowlist = getOccAllowlist();
      expect(allowlist).not.toContain('config:list');
    });
  });

  // ─── Setup Checks ─────────────────────────────────────────────────────

  describe('run_setup_checks', () => {
    it('should return success output', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: 'All checks passed',
        stderr: '',
      });

      const { setupChecksTool } = await import('../tools/system/status.js');
      const result = await setupChecksTool.handler();

      expect(result.content[0].text).toContain('Setup checks completed successfully');
      expect(result.content[0].text).toContain('All checks passed');
      expect(result.isError).toBe(false);
    });

    it('should report failure with exit code and stderr', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: false,
        exitCode: 1,
        stdout: 'PHP module missing: imagick',
        stderr: 'Warning: performance degraded',
      });

      const { setupChecksTool } = await import('../tools/system/status.js');
      const result = await setupChecksTool.handler();

      expect(result.content[0].text).toContain('found issues');
      expect(result.content[0].text).toContain('imagick');
      expect(result.content[0].text).toContain('stderr');
      expect(result.content[0].text).toContain('performance degraded');
      expect(result.isError).toBe(true);
    });

    it('should handle thrown errors', async () => {
      mockExecuteOCC.mockRejectedValue(new Error('OCC unavailable'));

      const { setupChecksTool } = await import('../tools/system/status.js');
      const result = await setupChecksTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error running setup checks');
    });
  });

  // ─── Get Local Time ───────────────────────────────────────────────────

  describe('get_local_time', () => {
    it('should return timezone, offset, and time info', async () => {
      const { getLocalTimeTool } = await import('../tools/system/status.js');
      const result = await getLocalTimeTool.handler();

      const parsed = JSON.parse(result.content[0].text);
      expect(parsed).toHaveProperty('localTime');
      expect(parsed).toHaveProperty('utcTime');
      expect(parsed).toHaveProperty('timezone');
      expect(parsed).toHaveProperty('utcOffset');
      expect(parsed.utcOffset).toMatch(/^[+-]\d{2}:\d{2}$/);
      expect(parsed.utcTime).toMatch(/^\d{4}-\d{2}-\d{2}T/);
    });
  });

  // ─── Bulk File Operations ─────────────────────────────────────────────

  describe('bulk_file_operations', () => {
    it('should execute multiple operations', async () => {
      mockClient.moveFile.mockResolvedValue(undefined);
      mockClient.copyFile.mockResolvedValue(undefined);
      mockClient.deleteFile.mockResolvedValue(undefined);

      const { bulkFileOperationsTool } = await import('../tools/system/files.js');
      const result = await bulkFileOperationsTool.handler({
        operations: [
          { action: 'move', source: '/a.txt', destination: '/b.txt' },
          { action: 'copy', source: '/c.txt', destination: '/d.txt' },
          { action: 'delete', source: '/e.txt' },
        ],
      });

      expect(result.content[0].text).toContain('3/3 succeeded');
      expect(result.content[0].text).toContain('move /a.txt');
      expect(result.content[0].text).toContain('delete /e.txt');
    });

    it('should handle partial failures', async () => {
      mockClient.moveFile.mockResolvedValue(undefined);
      mockClient.deleteFile.mockRejectedValue(new Error('Not found'));

      const { bulkFileOperationsTool } = await import('../tools/system/files.js');
      const result = await bulkFileOperationsTool.handler({
        operations: [
          { action: 'move', source: '/a.txt', destination: '/b.txt' },
          { action: 'delete', source: '/missing.txt' },
        ],
      });

      expect(result.content[0].text).toContain('1/2 succeeded');
      expect(result.isError).toBe(true);
    });

    it('should reject move/copy without destination', async () => {
      const { bulkFileOperationsTool } = await import('../tools/system/files.js');
      const result = await bulkFileOperationsTool.handler({
        operations: [{ action: 'move', source: '/a.txt' }],
      });

      expect(result.content[0].text).toContain('missing destination');
    });
  });

  // ─── Trash Tools ─────────────────────────────────────────────────────

  describe('list_trash', () => {
    it('should list trash items', async () => {
      const xml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
    <d:propstat><d:prop></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/trashbin/admin/trash/doc.pdf.d1711234567</d:href>
    <d:propstat><d:prop>
      <d:displayname>doc.pdf</d:displayname>
      <d:getcontentlength>10240</d:getcontentlength>
      <oc:trashbin-original-location>Documents/doc.pdf</oc:trashbin-original-location>
      <oc:trashbin-delete-timestamp>1711234567</oc:trashbin-delete-timestamp>
    </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        statusText: 'Multi-Status',
        text: () => Promise.resolve(xml),
        headers: new Headers(),
      });

      const { listTrashTool } = await import('../tools/apps/trash.js');
      const result = await listTrashTool.handler();

      expect(result.content[0].text).toContain('doc.pdf');
      expect(result.content[0].text).toContain('Documents/doc.pdf');
    });

    it('should handle empty trash', async () => {
      const xml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
    <d:propstat><d:prop></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve(xml),
        headers: new Headers(),
      });

      const { listTrashTool } = await import('../tools/apps/trash.js');
      const result = await listTrashTool.handler();

      expect(result.content[0].text).toContain('empty');
    });
  });

  describe('restore_from_trash', () => {
    it('should restore a trash item', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 201,
        statusText: 'Created',
        headers: new Headers(),
      });

      const { restoreFromTrashTool } = await import('../tools/apps/trash.js');
      const result = await restoreFromTrashTool.handler({
        trashKey: 'doc.pdf.d1711234567',
      });

      expect(result.content[0].text).toContain('Restored');
    });
  });

  describe('empty_trash', () => {
    it('should empty the trash', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 204,
        statusText: 'No Content',
        headers: new Headers(),
      });

      const { emptyTrashTool } = await import('../tools/apps/trash.js');
      const result = await emptyTrashTool.handler();

      expect(result.content[0].text).toContain('emptied');
    });
  });

  // ─── File Versioning Tools ──────────────────────────────────────────────

  describe('list_file_versions', () => {
    it('should list file versions', async () => {
      const xml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/versions/admin/versions/12345</d:href>
    <d:propstat><d:prop></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/versions/admin/versions/12345/1711234567</d:href>
    <d:propstat><d:prop>
      <d:getlastmodified>Sat, 23 Mar 2024 15:16:07 GMT</d:getlastmodified>
      <d:getcontentlength>5120</d:getcontentlength>
    </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve(xml),
        headers: new Headers(),
      });

      const { listFileVersionsTool } = await import('../tools/apps/versions.js');
      const result = await listFileVersionsTool.handler({ fileId: 12345 });

      expect(result.content[0].text).toContain('1711234567');
      expect(result.content[0].text).toContain('Sat, 23 Mar 2024');
    });

    it('should handle no versions', async () => {
      const xml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/versions/admin/versions/12345</d:href>
    <d:propstat><d:prop></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve(xml),
        headers: new Headers(),
      });

      const { listFileVersionsTool } = await import('../tools/apps/versions.js');
      const result = await listFileVersionsTool.handler({ fileId: 12345 });

      expect(result.content[0].text).toContain('No previous versions');
    });
  });

  describe('restore_file_version', () => {
    it('should restore a file version', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 201,
        statusText: 'Created',
        headers: new Headers(),
      });

      const { restoreFileVersionTool } = await import('../tools/apps/versions.js');
      const result = await restoreFileVersionTool.handler({
        fileId: 12345,
        versionId: '1711234567',
      });

      expect(result.content[0].text).toContain('Restored version 1711234567');
    });
  });
});
