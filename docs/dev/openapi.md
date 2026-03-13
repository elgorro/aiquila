# OpenAPI Spec

The Nextcloud app ships three committed OpenAPI spec files generated from PHP annotations.

## Output files

| File | Scope | Description |
|------|-------|-------------|
| `openapi.json` | `default` | User-facing endpoints (no admin required) |
| `openapi-administration.json` | `administration` | Admin-only endpoints |
| `openapi-full.json` | all | Combined — both scopes merged |

## Regenerating

From `nextcloud-app/`:

```bash
make openapi
# or directly:
vendor/bin/generate-spec
```

Commit all three output files together with any annotation changes.

## Annotating a new endpoint

### User endpoint (default scope)

```php
use OpenAPI\Attributes as OA;

#[OA\OpenAPI]
public function myAction(string $param): JSONResponse {
    // ...
}
```

### Admin endpoint

```php
use OpenAPI\Attributes as OA;

#[OA\OpenAPI(scope: OA\OpenAPI::SCOPE_ADMINISTRATION)]
public function adminAction(string $param): JSONResponse {
    // ...
}
```

### Full annotation example

```php
/**
 * Short description of the action
 *
 * @param string $fileId The file ID to process
 * @return JSONResponse<Http::STATUS_OK, array{result: string}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
 *
 * 200: Success response with result
 * 400: Invalid input
 */
#[OA\OpenAPI]
public function process(string $fileId): JSONResponse {
    // Implementation — no Http::STATUS_* constants needed here
    return new JSONResponse(['result' => $output]);
}
```

### Rules

- **`#[OpenAPI]`** attribute controls which scope the endpoint appears in.
- **Typed parameters** — use PHP type hints; NC dependency injection resolves them from the request automatically.
- **`@return` PHPDoc** — use the `JSONResponse<Http::STATUS_*, array{...}, array{}>` union form so the extractor can infer response schemas.
- **Status-code description lines** (`200: ...`, `400: ...`) are required for every response code below 500.
- **`Http::STATUS_*` constants** belong only in the PHPDoc `@return` — not in the method body (avoids needing an OCP import in unit tests).
- The `download()` method is intentionally excluded — it returns `DataDownloadResponse` (binary), which has no JSON schema.

## CI enforcement

`.github/workflows/test.yml` regenerates the spec after every test run and fails the build if the committed files differ:

```
OpenAPI spec is out of date. Run 'make openapi' and commit the result.
```

If you see this error on a PR, run `make openapi` locally and push the updated spec files.
