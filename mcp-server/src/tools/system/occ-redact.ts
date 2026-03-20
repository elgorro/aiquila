/**
 * Redacts sensitive information from OCC command output.
 * Applied to stdout/stderr before returning results to the MCP client.
 */

const SENSITIVE_KEYS = [
  'password',
  'passwordsalt',
  'dbpassword',
  'secret',
  'mail_smtppassword',
  'token',
  'auth\\.token',
  'api_key',
  'apikey',
  'app_secret',
  'encryption_key',
  'redis_password',
  'objectstore\\.arguments\\.key',
];

const SENSITIVE_KEY_PATTERN = SENSITIVE_KEYS.join('|');

// PHP array format: "key" => "value"
const PHP_ARRAY_RE = new RegExp(
  `(["'](?:${SENSITIVE_KEY_PATTERN})["'])\\s*=>\\s*(["'])(?:(?!\\2).)+\\2`,
  'gi'
);

// JSON format: "key": "value"
const JSON_KV_RE = new RegExp(
  `("(?:${SENSITIVE_KEY_PATTERN})"\\s*:\\s*)(["'])(?:(?!\\2).)+\\2`,
  'gi'
);

// Database URI: mysql://user:pass@host or pgsql://user:pass@host
const DB_URI_RE = /(\b(?:mysql|pgsql|postgres|postgresql|sqlite|mariadb):\/\/[^:]+):([^@]+)@/gi;

// PHP stack trace paths: /var/www/html/... with optional :linenum
const PHP_PATH_RE = /\/var\/www\/html\/\S+/g;

export function redactSensitiveOutput(output: string): string {
  if (!output) return output;

  let result = output;

  // Key-value patterns
  result = result.replace(PHP_ARRAY_RE, '$1 => "[REDACTED]"');
  result = result.replace(JSON_KV_RE, '$1"[REDACTED]"');

  // Database URIs
  result = result.replace(DB_URI_RE, '$1:[REDACTED]@');

  // PHP file paths
  result = result.replace(PHP_PATH_RE, '[REDACTED_PATH]');

  return result;
}
