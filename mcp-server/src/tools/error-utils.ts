// SPDX-License-Identifier: MIT

import { ApiError } from '../client/aiquila.js';

type ErrorResult = { content: { type: 'text'; text: string }[]; isError: true };

/**
 * Shared error handler for app tool modules.
 *
 * @param error     The caught error
 * @param context   Human-readable context prefixed to generic messages (e.g. "Error listing notes")
 * @param statusMap Optional map of HTTP status codes to user-facing messages.
 *                  When the error is an ApiError whose status matches a key, the corresponding
 *                  message is returned. The message may be a string or a function receiving the
 *                  ApiError for dynamic messages (e.g. including the response body).
 */
export function handleAppError(
  error: unknown,
  context: string,
  statusMap: Record<number, string | ((e: ApiError) => string)> = {}
): ErrorResult {
  if (error instanceof ApiError) {
    const entry = statusMap[error.statusCode];
    if (entry !== undefined) {
      const text = typeof entry === 'function' ? entry(error) : entry;
      return { content: [{ type: 'text', text }], isError: true };
    }
  }
  return {
    content: [
      {
        type: 'text',
        text: `${context}: ${error instanceof Error ? error.message : String(error)}`,
      },
    ],
    isError: true,
  };
}
