<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

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

    private const MAX_ARCHIVE_SIZE = 512 * 1024 * 1024; // 512MB total uncompressed

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

    /**
     * Create a zip archive from one or more files/folders.
     *
     * @param list<string> $sourcePaths Paths to include in the archive
     * @return array{archive: string, entries: int, size: int}
     * @throws NotFoundException if a source path does not exist
     * @throws \InvalidArgumentException if no sources given or destination exists without overwrite
     * @throws \RuntimeException if the archive cannot be created or exceeds the size limit
     */
    public function compress(array $sourcePaths, string $destinationPath, string $userId, bool $overwrite = false): array {
        if (count($sourcePaths) === 0) {
            throw new \InvalidArgumentException('No source paths provided');
        }

        $userFolder = $this->getUserFolder($userId);

        if ($userFolder->nodeExists($destinationPath) && !$overwrite) {
            throw new \RuntimeException("Destination already exists: $destinationPath");
        }

        // Resolve all sources up front so a missing path fails before any work.
        $sources = [];
        foreach ($sourcePaths as $sourcePath) {
            $sources[] = $userFolder->get($sourcePath);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'aiquila-zip-');
        if ($tmpFile === false) {
            throw new \RuntimeException('Could not create temporary file');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            throw new \RuntimeException('Could not open archive for writing');
        }

        $entryCount = 0;
        $totalSize = 0;
        try {
            foreach ($sources as $node) {
                $this->addNodeToZip($zip, $node, $node->getName(), $entryCount, $totalSize);
            }
            $zip->close();

            if ($userFolder->nodeExists($destinationPath)) {
                $dest = $userFolder->get($destinationPath);
                if (!($dest instanceof File)) {
                    throw new \InvalidArgumentException("Destination is not a file: $destinationPath");
                }
            } else {
                $dest = $userFolder->newFile($destinationPath);
            }

            $contents = file_get_contents($tmpFile);
            if ($contents === false) {
                throw new \RuntimeException('Could not read generated archive');
            }
            $dest->putContent($contents);

            return [
                'archive' => $dest->getPath(),
                'entries' => $entryCount,
                'size' => $dest->getSize(),
            ];
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Recursively add a node (file or folder) to an open zip archive.
     *
     * @throws \RuntimeException if the uncompressed total exceeds the size limit
     */
    private function addNodeToZip(\ZipArchive $zip, Node $node, string $entryName, int &$entryCount, int &$totalSize): void {
        if ($node instanceof Folder) {
            $zip->addEmptyDir($entryName);
            foreach ($node->getDirectoryListing() as $child) {
                $this->addNodeToZip($zip, $child, $entryName . '/' . $child->getName(), $entryCount, $totalSize);
            }
            return;
        }

        if ($node instanceof File) {
            $totalSize += $node->getSize();
            if ($totalSize > self::MAX_ARCHIVE_SIZE) {
                throw new \RuntimeException(
                    'Archive contents exceed maximum size of ' . self::MAX_ARCHIVE_SIZE . ' bytes'
                );
            }
            $zip->addFromString($entryName, $node->getContent());
            $entryCount++;
        }
    }

    /**
     * Extract a zip archive into a destination folder.
     *
     * @return array{destination: string, extracted: int}
     * @throws NotFoundException if the archive does not exist
     * @throws \InvalidArgumentException if the path is not a file
     * @throws \RuntimeException on malformed archive or unsafe entry paths
     */
    public function extract(string $archivePath, string $destinationPath, string $userId, bool $overwrite = false): array {
        $userFolder = $this->getUserFolder($userId);
        $archiveNode = $userFolder->get($archivePath);

        if (!($archiveNode instanceof File)) {
            throw new \InvalidArgumentException("Path '$archivePath' is not a file");
        }

        // Ensure destination folder exists.
        if ($userFolder->nodeExists($destinationPath)) {
            $destFolder = $userFolder->get($destinationPath);
            if (!($destFolder instanceof Folder)) {
                throw new \InvalidArgumentException("Destination '$destinationPath' is not a folder");
            }
        } else {
            $destFolder = $userFolder->newFolder($destinationPath);
        }

        $zip = $this->openArchiveFromNode($archiveNode, $tmpFile);

        $extracted = 0;
        $totalSize = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $name = $stat['name'];
                $safe = $this->sanitizeEntryName($name);

                // Directory entry.
                if (str_ends_with($name, '/')) {
                    if ($safe !== '' && !$destFolder->nodeExists($safe)) {
                        $destFolder->newFolder($safe);
                    }
                    continue;
                }

                $totalSize += (int)$stat['size'];
                if ($totalSize > self::MAX_ARCHIVE_SIZE) {
                    throw new \RuntimeException(
                        'Extracted contents exceed maximum size of ' . self::MAX_ARCHIVE_SIZE . ' bytes'
                    );
                }

                // Ensure parent folders exist.
                $parent = dirname($safe);
                if ($parent !== '.' && $parent !== '' && !$destFolder->nodeExists($parent)) {
                    $destFolder->newFolder($parent);
                }

                $content = $zip->getFromIndex($i);
                if ($content === false) {
                    throw new \RuntimeException("Could not read archive entry: $name");
                }

                if ($destFolder->nodeExists($safe)) {
                    if (!$overwrite) {
                        throw new \RuntimeException("Entry already exists: $safe (use overwrite)");
                    }
                    $target = $destFolder->get($safe);
                    if (!($target instanceof File)) {
                        throw new \InvalidArgumentException("Cannot overwrite non-file: $safe");
                    }
                } else {
                    $target = $destFolder->newFile($safe);
                }
                $target->putContent($content);
                $extracted++;
            }

            return [
                'destination' => $destFolder->getPath(),
                'extracted' => $extracted,
            ];
        } finally {
            $zip->close();
            @unlink($tmpFile);
        }
    }

    /**
     * List the contents of a zip archive without extracting.
     *
     * @return array{archive: string, count: int, entries: list<array{name: string, size: int, compressedSize: int, isDirectory: bool}>}
     * @throws NotFoundException if the archive does not exist
     * @throws \InvalidArgumentException if the path is not a file
     * @throws \RuntimeException on malformed archive
     */
    public function listArchive(string $archivePath, string $userId): array {
        $userFolder = $this->getUserFolder($userId);
        $archiveNode = $userFolder->get($archivePath);

        if (!($archiveNode instanceof File)) {
            throw new \InvalidArgumentException("Path '$archivePath' is not a file");
        }

        $zip = $this->openArchiveFromNode($archiveNode, $tmpFile);

        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $entries[] = [
                    'name' => $stat['name'],
                    'size' => (int)$stat['size'],
                    'compressedSize' => (int)$stat['comp_size'],
                    'isDirectory' => str_ends_with($stat['name'], '/'),
                ];
            }

            return [
                'archive' => $archiveNode->getPath(),
                'count' => count($entries),
                'entries' => $entries,
            ];
        } finally {
            $zip->close();
            @unlink($tmpFile);
        }
    }

    /**
     * Copy an archive File node to a temp file and open it with ZipArchive.
     * ZipArchive requires a real filesystem path. Sets $tmpFile by reference so
     * callers can clean it up.
     *
     * @throws \RuntimeException if the archive cannot be staged or opened
     */
    private function openArchiveFromNode(File $archiveNode, ?string &$tmpFile): \ZipArchive {
        $tmpFile = tempnam(sys_get_temp_dir(), 'aiquila-unzip-');
        if ($tmpFile === false) {
            throw new \RuntimeException('Could not create temporary file');
        }

        if (file_put_contents($tmpFile, $archiveNode->getContent()) === false) {
            @unlink($tmpFile);
            throw new \RuntimeException('Could not stage archive for reading');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile) !== true) {
            @unlink($tmpFile);
            throw new \RuntimeException('Could not open archive (not a valid zip file?)');
        }

        return $zip;
    }

    /**
     * Sanitize a zip entry name to prevent path traversal (Zip Slip).
     * Rejects absolute paths and any entry that escapes the destination.
     *
     * @throws \RuntimeException if the entry name is unsafe
     */
    private function sanitizeEntryName(string $name): string {
        // Normalize backslashes (Windows-created zips) to forward slashes.
        $normalized = str_replace('\\', '/', $name);

        if (str_starts_with($normalized, '/')) {
            throw new \RuntimeException("Unsafe absolute path in archive: $name");
        }

        $parts = [];
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new \RuntimeException("Unsafe path traversal in archive: $name");
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }
}
