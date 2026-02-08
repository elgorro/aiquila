<?php

namespace OCA\AIquila\Controller;

use OCA\AIquila\Service\FileService;
use OCP\AppFramework\Controller;
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
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function info(): JSONResponse {
        $path = $this->request->getParam('path', '');
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
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function listDir(): JSONResponse {
        $path = $this->request->getParam('path', '/');

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
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function content(): JSONResponse {
        $path = $this->request->getParam('path', '');
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
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function download(): DataDownloadResponse {
        $path = $this->request->getParam('path', '');
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
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function search(): JSONResponse {
        $query = $this->request->getParam('query', '');
        $mime = $this->request->getParam('mime');
        $basePath = $this->request->getParam('path', '/');

        if (empty($query)) {
            return new JSONResponse(['error' => 'No search query provided'], 400);
        }

        try {
            return new JSONResponse(
                $this->fileService->search($query, $this->userId, $mime, $basePath)
            );
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'Base path not found: ' . $basePath], 404);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->logger->error('FileController::search error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function preview(): JSONResponse {
        $path = $this->request->getParam('path', '');
        $width = max(16, min((int)$this->request->getParam('width', '256'), 1024));
        $height = max(16, min((int)$this->request->getParam('height', '256'), 1024));

        if (empty($path)) {
            return new JSONResponse(['error' => 'No path provided'], 400);
        }

        try {
            return new JSONResponse(
                $this->fileService->getPreview($path, $this->userId, $width, $height)
            );
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'File not found: ' . $path], 404);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->logger->error('FileController::preview error', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}
