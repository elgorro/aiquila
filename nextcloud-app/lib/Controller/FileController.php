<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Controller;

use OCA\AIquila\Service\Exception\ContentTooLargeException;
use OCA\AIquila\Service\Exception\DestinationExistsException;
use OCA\AIquila\Service\FileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class FileController extends Controller {
    use RequiresUserIdTrait;
    use ErrorResponseTrait;

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
     * 500: Reading the file metadata failed
     *
     * @return JSONResponse<Http::STATUS_OK, array{name: string, path: string, size: int, mimeType: string, mtime: int, etag: string, permissions: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function info(string $path = ''): JSONResponse {
        if (empty($path)) {
            return $this->clientError(400, 'No path provided');
        }
        try {
            return new JSONResponse($this->fileService->getInfo($path, $this->requireUserId()));
        } catch (NotFoundException $e) {
            return $this->clientError(404, 'File not found: ' . $path);
        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'Could not read file metadata', 'FileController::info');
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
     * 500: Reading the directory failed
     *
     * @return JSONResponse<Http::STATUS_OK, array{files: list<array{name: string, path: string, size: int, mimeType: string, mtime: int, type: string}>}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function listDir(string $path = '/'): JSONResponse {
        try {
            return new JSONResponse($this->fileService->listDirectory($path, $this->requireUserId()));
        } catch (NotFoundException $e) {
            return $this->clientError(404, 'Directory not found: ' . $path);
        } catch (\InvalidArgumentException $e) {
            return $this->clientError(400, 'Path is not a directory');
        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'Could not list the directory', 'FileController::listDir');
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
     * 413: File exceeds the size limit
     * 500: Reading the file failed
     *
     * @return JSONResponse<Http::STATUS_OK, array{content: string, mimeType: string, name: string, size: int, encoding: string}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function content(string $path = ''): JSONResponse {
        if (empty($path)) {
            return $this->clientError(400, 'No path provided');
        }
        try {
            return new JSONResponse($this->fileService->getContent($path, $this->requireUserId()));
        } catch (NotFoundException $e) {
            return $this->clientError(404, 'File not found: ' . $path);
        } catch (\InvalidArgumentException $e) {
            return $this->clientError(400, 'Path does not refer to a file');
        } catch (ContentTooLargeException $e) {
            return $this->clientError(413, 'File is too large to read');
        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'Could not read the file', 'FileController::content');
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
            $file = $this->fileService->getFile($path, $this->requireUserId());
            return new DataDownloadResponse($file->getContent(), $file->getName(), $file->getMimetype());
        } catch (NotFoundException $e) {
            return new DataDownloadResponse('File not found', 'error.txt', 'text/plain');
        } catch (\Exception $e) {
            // The body of this response is a file the user downloads, so an
            // exception message here would land on disk as well as on screen.
            $errorId = $this->newErrorId();
            $this->logger->error('FileController::download failed [' . $errorId . ']', ['exception' => $e, 'errorId' => $errorId]);
            return new DataDownloadResponse('Download failed (ref ' . $errorId . ')', 'error.txt', 'text/plain');
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
     * 500: The search failed
     *
     * @return JSONResponse<Http::STATUS_OK, array{results: list<array{name: string, path: string, mimeType: string, size: int}>}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function search(string $query = '', ?string $mime = null, string $path = '/'): JSONResponse {
        if (empty($query)) {
            return $this->clientError(400, 'No search query provided');
        }
        try {
            return new JSONResponse($this->fileService->search($query, $this->requireUserId(), $mime, $path));
        } catch (NotFoundException $e) {
            return $this->clientError(404, 'Base path not found: ' . $path);
        } catch (\InvalidArgumentException $e) {
            return $this->clientError(400, 'Search base path is not a directory');
        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'The search failed', 'FileController::search');
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
     * 500: Generating the preview failed
     *
     * @return JSONResponse<Http::STATUS_OK, array{preview: string, mimeType: string}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function preview(string $path = '', int $width = 256, int $height = 256): JSONResponse {
        if (empty($path)) {
            return $this->clientError(400, 'No path provided');
        }
        $width = max(16, min($width, 1024));
        $height = max(16, min($height, 1024));
        try {
            return new JSONResponse($this->fileService->getPreview($path, $this->requireUserId(), $width, $height));
        } catch (NotFoundException $e) {
            return $this->clientError(404, 'File not found: ' . $path);
        } catch (\RuntimeException $e) {
            return $this->clientError(400, 'Preview not available for this file type');
        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'Could not generate a preview', 'FileController::preview');
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
     * 409: Destination already exists and overwrite was not requested
     * 413: Archive exceeds the maximum size
     * 500: Creating the archive failed
     *
     * @return JSONResponse<Http::STATUS_OK, array{archive: string, entries: int, size: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_CONFLICT, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function compress(array $sources = [], string $destination = '', bool $overwrite = false): JSONResponse {
        if (empty($sources)) {
            return $this->clientError(400, 'No source paths provided');
        }
        if (empty($destination)) {
            return $this->clientError(400, 'No destination provided');
        }
        try {
            return new JSONResponse($this->fileService->compress($sources, $destination, $this->requireUserId(), $overwrite));
        } catch (NotFoundException $e) {
            return $this->clientError(404, 'One of the source paths was not found');
        } catch (\InvalidArgumentException $e) {
            return $this->clientError(400, 'A source or destination path is invalid');
        } catch (DestinationExistsException $e) {
            return $this->clientError(409, 'Destination already exists');
        } catch (ContentTooLargeException $e) {
            return $this->clientError(413, 'Archive exceeds the maximum allowed size');
        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'Could not create the archive', 'FileController::compress');
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
     * 409: An entry already exists and overwrite was not requested
     * 413: Archive exceeds the maximum size
     * 500: Extracting the archive failed
     *
     * @return JSONResponse<Http::STATUS_OK, array{destination: string, extracted: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_CONFLICT, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function extract(string $archive = '', string $destination = '', bool $overwrite = false): JSONResponse {
        if (empty($archive)) {
            return $this->clientError(400, 'No archive path provided');
        }
        if (empty($destination)) {
            return $this->clientError(400, 'No destination provided');
        }
        try {
            return new JSONResponse($this->fileService->extract($archive, $destination, $this->requireUserId(), $overwrite));
        } catch (NotFoundException $e) {
            return $this->clientError(404, 'Archive not found: ' . $archive);
        } catch (\InvalidArgumentException $e) {
            return $this->clientError(400, 'Archive or destination path is invalid');
        } catch (DestinationExistsException $e) {
            return $this->clientError(409, 'An entry already exists at the destination');
        } catch (ContentTooLargeException $e) {
            return $this->clientError(413, 'Extracted contents exceed the maximum allowed size');
        } catch (\RuntimeException $e) {
            // Unsafe entry names (zip slip) and unreadable archives.
            return $this->clientError(400, 'The archive could not be extracted');
        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'Could not extract the archive', 'FileController::extract');
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
     * 500: Reading the archive failed
     *
     * @return JSONResponse<Http::STATUS_OK, array{archive: string, count: int, entries: list<array{name: string, size: int, compressedSize: int, isDirectory: bool}>}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function listArchive(string $archive = ''): JSONResponse {
        if (empty($archive)) {
            return $this->clientError(400, 'No archive path provided');
        }
        try {
            return new JSONResponse($this->fileService->listArchive($archive, $this->requireUserId()));
        } catch (NotFoundException $e) {
            return $this->clientError(404, 'Archive not found: ' . $archive);
        } catch (\InvalidArgumentException $e) {
            return $this->clientError(400, 'Path does not refer to a file');
        } catch (\RuntimeException $e) {
            return $this->clientError(400, 'The archive could not be read');
        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'Could not read the archive', 'FileController::listArchive');
        }
    }
}
