<?php

namespace OCA\AIquila\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IPreview;
use Psr\Log\LoggerInterface;

/**
 * File operations service using Nextcloud's storage APIs.
 *
 * Provides file metadata, content reading, search, and preview generation
 * for use by controllers, other services, and the MCP server.
 */
class FileService {
    private IRootFolder $rootFolder;
    private IPreview $previewManager;
    private LoggerInterface $logger;

    private const MAX_READ_SIZE = 20 * 1024 * 1024; // 20MB

    private const TEXT_MIME_PREFIXES = [
        'text/',
        'application/json',
        'application/xml',
        'application/javascript',
        'application/x-yaml',
        'application/x-sh',
        'application/x-php',
        'application/x-perl',
        'application/x-python',
        'application/x-ruby',
        'application/sql',
        'application/xhtml+xml',
        'application/svg+xml',
    ];

    public function __construct(
        IRootFolder $rootFolder,
        IPreview $previewManager,
        LoggerInterface $logger
    ) {
        $this->rootFolder = $rootFolder;
        $this->previewManager = $previewManager;
        $this->logger = $logger;
    }

    private function getUserFolder(string $userId): Folder {
        return $this->rootFolder->getUserFolder($userId);
    }

    private function nodeToArray(Node $node): array {
        return [
            'name' => $node->getName(),
            'path' => $node->getPath(),
            'size' => $node->getSize(),
            'mimeType' => $node->getMimetype(),
            'mtime' => $node->getMTime(),
            'etag' => $node->getEtag(),
            'permissions' => $node->getPermissions(),
            'type' => $node instanceof Folder ? 'folder' : 'file',
            'isEncrypted' => $node->isEncrypted(),
            'id' => $node->getId(),
        ];
    }

    private function isTextMime(string $mimeType): bool {
        foreach (self::TEXT_MIME_PREFIXES as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get file or folder metadata.
     *
     * @throws NotFoundException
     */
    public function getInfo(string $path, string $userId): array {
        $userFolder = $this->getUserFolder($userId);
        $node = $userFolder->get($path);
        return $this->nodeToArray($node);
    }

    /**
     * List directory contents with metadata.
     *
     * @throws NotFoundException
     * @throws \InvalidArgumentException if path is not a directory
     */
    public function listDirectory(string $path, string $userId): array {
        $userFolder = $this->getUserFolder($userId);
        $folder = $userFolder->get($path);

        if (!($folder instanceof Folder)) {
            throw new \InvalidArgumentException("Path '$path' is not a directory");
        }

        $items = [];
        foreach ($folder->getDirectoryListing() as $node) {
            $items[] = $this->nodeToArray($node);
        }

        return [
            'path' => $path,
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * Read file content. Text files return raw text, binary files return base64.
     *
     * @return array{name: string, mimeType: string, size: int, encoding: string, content: string}
     * @throws NotFoundException
     * @throws \InvalidArgumentException if path is not a file
     * @throws \RuntimeException if file exceeds size limit
     */
    public function getContent(string $path, string $userId): array {
        $userFolder = $this->getUserFolder($userId);
        $node = $userFolder->get($path);

        if (!($node instanceof File)) {
            throw new \InvalidArgumentException("Path '$path' is not a file");
        }

        if ($node->getSize() > self::MAX_READ_SIZE) {
            throw new \RuntimeException(
                'File too large: ' . $node->getSize() . ' bytes (max ' . self::MAX_READ_SIZE . ')'
            );
        }

        $mimeType = $node->getMimetype();
        $rawContent = $node->getContent();
        $isText = $this->isTextMime($mimeType);

        return [
            'name' => $node->getName(),
            'mimeType' => $mimeType,
            'size' => $node->getSize(),
            'encoding' => $isText ? 'text' : 'base64',
            'content' => $isText ? $rawContent : base64_encode($rawContent),
        ];
    }

    /**
     * Get the raw File node for streaming.
     *
     * @throws NotFoundException
     * @throws \InvalidArgumentException if path is not a file
     */
    public function getFile(string $path, string $userId): File {
        $userFolder = $this->getUserFolder($userId);
        $node = $userFolder->get($path);

        if (!($node instanceof File)) {
            throw new \InvalidArgumentException("Path '$path' is not a file");
        }

        return $node;
    }

    /**
     * Search for files by name pattern and optional mime type filter.
     *
     * @throws NotFoundException
     * @throws \InvalidArgumentException if basePath is not a directory
     */
    public function search(string $query, string $userId, ?string $mimeType = null, string $basePath = '/'): array {
        $userFolder = $this->getUserFolder($userId);
        $folder = $userFolder->get($basePath);

        if (!($folder instanceof Folder)) {
            throw new \InvalidArgumentException("Base path '$basePath' is not a directory");
        }

        $nodes = $folder->search($query);

        $results = [];
        foreach ($nodes as $node) {
            if ($mimeType !== null && !str_starts_with($node->getMimetype(), $mimeType)) {
                continue;
            }
            $results[] = $this->nodeToArray($node);
        }

        return [
            'query' => $query,
            'mimeFilter' => $mimeType,
            'count' => count($results),
            'results' => $results,
        ];
    }

    /**
     * Get a preview/thumbnail for a file.
     *
     * @throws NotFoundException
     * @throws \InvalidArgumentException if path is not a file
     * @throws \RuntimeException if preview is not available
     */
    public function getPreview(string $path, string $userId, int $width = 256, int $height = 256): array {
        $userFolder = $this->getUserFolder($userId);
        $node = $userFolder->get($path);

        if (!($node instanceof File)) {
            throw new \InvalidArgumentException("Path '$path' is not a file");
        }

        if (!$this->previewManager->isAvailable($node)) {
            throw new \RuntimeException('Preview not available for this file type');
        }

        $preview = $this->previewManager->getPreview($node, $width, $height);
        $content = $preview->getContent();

        return [
            'mimeType' => $preview->getMimeType(),
            'encoding' => 'base64',
            'content' => base64_encode($content),
            'width' => $width,
            'height' => $height,
        ];
    }
}
