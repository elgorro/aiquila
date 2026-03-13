<?php

namespace OCA\AIquila\Controller;

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
            return new JSONResponse(['error' => 'No path provided'], Http::STATUS_BAD_REQUEST);
        }

        try {
            return new JSONResponse($this->fileService->getInfo($path, $this->userId));
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'File not found: ' . $path], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('FileController::info error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
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
            return new JSONResponse(['error' => 'Directory not found: ' . $path], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('FileController::listDir error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
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
            return new JSONResponse(['error' => 'No path provided'], Http::STATUS_BAD_REQUEST);
        }

        try {
            return new JSONResponse($this->fileService->getContent($path, $this->userId));
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'File not found: ' . $path], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
        } catch (\Exception $e) {
            $this->logger->error('FileController::content error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download a file directly (binary response — not included in OpenAPI spec)
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
            return new DataDownloadResponse(
                $file->getContent(),
                $file->getName(),
                $file->getMimetype()
            );
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
            return new JSONResponse(['error' => 'No search query provided'], Http::STATUS_BAD_REQUEST);
        }

        try {
            return new JSONResponse(
                $this->fileService->search($query, $this->userId, $mime, $path)
            );
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'Base path not found: ' . $path], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('FileController::search error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get a base64-encoded preview image for a file
     *
     * @param string $path   Path to the file within the user's Nextcloud storage
     * @param int    $width  Preview width in pixels (16–1024, default: 256)
     * @param int    $height Preview height in pixels (16–1024, default: 256)
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
            return new JSONResponse(['error' => 'No path provided'], Http::STATUS_BAD_REQUEST);
        }

        $width = max(16, min($width, 1024));
        $height = max(16, min($height, 1024));

        try {
            return new JSONResponse(
                $this->fileService->getPreview($path, $this->userId, $width, $height)
            );
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'File not found: ' . $path], Http::STATUS_NOT_FOUND);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('FileController::preview error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
