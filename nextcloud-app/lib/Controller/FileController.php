<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Controller;

use OCA\AIquila\Service\FileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class FileController extends Controller {
    private FileService $fileService;
    private ?string $userId;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        FileService $fileService,
        ?string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->fileService = $fileService;
        $this->userId = $userId;
        $this->logger = $logger;
    }

    /**
     * Get metadata for a file or directory
     *
     * @param string $path Path within the user's Nextcloud storage
     *
     * 200: File or directory metadata
     * 400: No path provided
     * 404: File or directory not found at the given path
     *
     * @return JSONResponse<Http::STATUS_OK, array{name: string, path: string, size: int, mimeType: string, mtime: int, etag: string, permissions: int}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function info(string $path = ''): JSONResponse {
        if (empty($path)) {
            return new JSONResponse(['error' => 'No path provided'], 400);
        }
        try {
            return new JSONResponse($this->fileService->getInfo($path, $this->userId));
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'File not found: ' . $path], 404);
        } catch (\Exception $e) {
            $this->logger->error('FileController::info error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * List files and directories at the given path
     *
     * @param string $path Directory path within the user's Nextcloud storage (default: root)
     *
     * 200: Directory listing
     * 400: Path is not a directory or is otherwise invalid
     * 404: Directory not found at the given path
     *
     * @return JSONResponse<Http::STATUS_OK, array{files: list<array{name: string, path: string, size: int, mimeType: string, mtime: int, type: string}>}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function listDir(string $path = '/'): JSONResponse {
        try {
            return new JSONResponse($this->fileService->listDirectory($path, $this->userId));
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'Directory not found: ' . $path], 404);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->logger->error('FileController::listDir error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get the content of a file (base64-encoded for binary files)
     *
     * @param string $path Path to the file within the user's Nextcloud storage
     *
     * 200: File content with encoding and MIME type
     * 400: No path provided or path refers to a directory
     * 404: File not found at the given path
     *
     * @return JSONResponse<Http::STATUS_OK, array{content: string, mimeType: string, name: string, size: int, encoding: string}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function content(string $path = ''): JSONResponse {
        if (empty($path)) {
            return new JSONResponse(['error' => 'No path provided'], 400);
        }
        try {
            return new JSONResponse($this->fileService->getContent($path, $this->userId));
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'File not found: ' . $path], 404);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 413);
        } catch (\Exception $e) {
            $this->logger->error('FileController::content error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Download a file directly (binary response - not included in OpenAPI spec)
     *
     * @param string $path Path to the file within the user's Nextcloud storage
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function download(string $path = ''): DataDownloadResponse {
        if (empty($path)) {
            return new DataDownloadResponse('', 'error.txt', 'text/plain');
        }
        try {
            $file = $this->fileService->getFile($path, $this->userId);
            return new DataDownloadResponse($file->getContent(), $file->getName(), $file->getMimetype());
        } catch (NotFoundException $e) {
            return new DataDownloadResponse('File not found', 'error.txt', 'text/plain');
        } catch (\Exception $e) {
            $this->logger->error('FileController::download error', ['exception' => $e->getMessage()]);
            return new DataDownloadResponse('Error: ' . $e->getMessage(), 'error.txt', 'text/plain');
        }
    }

    /**
     * Search for files by name within the user's Nextcloud storage
     *
     * @param string      $query Search term
     * @param string|null $mime  Optional MIME type filter
     * @param string      $path  Base directory to search within (default: root)
     *
     * 200: Search results
     * 400: No search query provided or query is invalid
     * 404: Base path not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{results: list<array{name: string, path: string, mimeType: string, size: int}>}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function search(string $query = '', ?string $mime = null, string $path = '/'): JSONResponse {
        if (empty($query)) {
            return new JSONResponse(['error' => 'No search query provided'], 400);
        }
        try {
            return new JSONResponse($this->fileService->search($query, $this->userId, $mime, $path));
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'Base path not found: ' . $path], 404);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->logger->error('FileController::search error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get a base64-encoded preview image for a file
     *
     * @param string $path   Path to the file within the user's Nextcloud storage
     * @param int    $width  Preview width in pixels (16-1024, default: 256)
     * @param int    $height Preview height in pixels (16-1024, default: 256)
     *
     * 200: Base64-encoded preview image with MIME type
     * 400: No path provided or preview cannot be generated
     * 404: File not found at the given path
     *
     * @return JSONResponse<Http::STATUS_OK, array{preview: string, mimeType: string}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function preview(string $path = '', int $width = 256, int $height = 256): JSONResponse {
        if (empty($path)) {
            return new JSONResponse(['error' => 'No path provided'], 400);
        }
        $width = max(16, min($width, 1024));
        $height = max(16, min($height, 1024));
        try {
            return new JSONResponse($this->fileService->getPreview($path, $this->userId, $width, $height));
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'File not found: ' . $path], 404);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->logger->error('FileController::preview error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a zip archive from one or more files/folders
     *
     * @param list<string> $sources   Paths to include in the archive
     * @param string       $destination Path for the resulting .zip file
     * @param bool         $overwrite Whether to overwrite an existing destination
     *
     * 200: Archive created
     * 400: No sources provided or a path is invalid
     * 404: A source path was not found
     * 409: Destination already exists and overwrite is false
     * 413: Archive contents exceed the size limit
     *
     * @return JSONResponse<Http::STATUS_OK, array{archive: string, entries: int, size: int}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function compress(array $sources = [], string $destination = '', bool $overwrite = false): JSONResponse {
        if (empty($sources)) {
            return new JSONResponse(['error' => 'No source paths provided'], 400);
        }
        if (empty($destination)) {
            return new JSONResponse(['error' => 'No destination provided'], 400);
        }
        try {
            return new JSONResponse($this->fileService->compress($sources, $destination, $this->userId, $overwrite));
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'already exists') ? 409
                : (str_contains($e->getMessage(), 'maximum size') ? 413 : 500);
            return new JSONResponse(['error' => $e->getMessage()], $code);
        } catch (\Exception $e) {
            $this->logger->error('FileController::compress error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Extract a zip archive into a destination folder
     *
     * @param string $archive     Path to the .zip file
     * @param string $destination Folder to extract into
     * @param bool   $overwrite   Whether to overwrite existing entries
     *
     * 200: Archive extracted
     * 400: Invalid path or unsafe archive entry
     * 404: Archive not found
     * 409: An entry already exists and overwrite is false
     * 413: Extracted contents exceed the size limit
     *
     * @return JSONResponse<Http::STATUS_OK, array{destination: string, extracted: int}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function extract(string $archive = '', string $destination = '', bool $overwrite = false): JSONResponse {
        if (empty($archive)) {
            return new JSONResponse(['error' => 'No archive path provided'], 400);
        }
        if (empty($destination)) {
            return new JSONResponse(['error' => 'No destination provided'], 400);
        }
        try {
            return new JSONResponse($this->fileService->extract($archive, $destination, $this->userId, $overwrite));
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'Archive not found: ' . $archive], 404);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'already exists') ? 409
                : (str_contains($e->getMessage(), 'maximum size') ? 413 : 400);
            return new JSONResponse(['error' => $e->getMessage()], $code);
        } catch (\Exception $e) {
            $this->logger->error('FileController::extract error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * List the contents of a zip archive without extracting
     *
     * @param string $archive Path to the .zip file
     *
     * 200: Archive entries
     * 400: No path provided or path is not a file
     * 404: Archive not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{archive: string, count: int, entries: list<array{name: string, size: int, compressedSize: int, isDirectory: bool}>}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function listArchive(string $archive = ''): JSONResponse {
        if (empty($archive)) {
            return new JSONResponse(['error' => 'No archive path provided'], 400);
        }
        try {
            return new JSONResponse($this->fileService->listArchive($archive, $this->userId));
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'Archive not found: ' . $archive], 404);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->logger->error('FileController::listArchive error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}
