<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Batch;

use OCA\AIquila\Cowork\UserPath;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\UsageStatService;
use OCP\Files\Conversion\IConversionManager;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * The batch document pipeline: walk files, ask one question about each, write
 * each answer to a file.
 *
 * On the Anthropic provider the whole set goes out as a single message batch:
 * half the token price, and one request instead of N. A batch may take up to
 * 24 hours, so {@see process()} does not wait for it — it submits and returns
 * `pending` with the state {@see resume()} needs to collect the results once
 * the batch has ended. Every other provider falls back to a synchronous loop,
 * slower and at full price.
 *
 * It knows nothing about coworkers or schedules. The caller owns the run row,
 * passes a {@see BatchContext}, and persists whatever comes back.
 *
 * @psalm-type BatchSkip = array{item: string, reason: string}
 * @psalm-type BatchRunResult = array{itemsTotal: int, itemsProcessed: int, itemsFailed?: int, itemsSkipped?: int, summary: string, pending?: bool, state?: array<string, mixed>, outputs?: list<string>}
 * @psalm-type BatchSelection = array{files: list<File>, skipped: list<BatchSkip>, capped: bool}
 * @psalm-type BatchDocument = array{kind: 'text'|'pdf', data: string}
 */
class BatchDocumentRunner {

    /** Hard cap on files per run, to bound cost and runtime. */
    public const MAX_ITEMS_PER_RUN = 200;

    /** Files larger than this are skipped rather than truncated. */
    public const DEFAULT_MAX_BYTES = 1048576;

    /**
     * Anthropic expires a batch at 24 hours. This is the point at which we
     * stop believing a batch record that has neither ended nor expired, so a
     * run cannot stay pending forever if its batch is lost.
     */
    public const BATCH_DEADLINE_SECONDS = 26 * 3600;

    /**
     * Mime prefixes and exact types read by default.
     *
     * Text goes into the prompt. A PDF goes as its own `document` block, which
     * only the Anthropic provider builds; on any other provider a walk passes
     * PDFs over ({@see pdfUnsupportedReason()}).
     *
     * @var list<string>
     */
    public const DEFAULT_MIME_TYPES = ['text/', 'application/json', self::PDF_MIME, ...self::OFFICE_MIME_TYPES];

    public const PDF_MIME = 'application/pdf';

    /**
     * Office documents, read by converting them to PDF through Nextcloud's
     * file conversion API ({@see IConversionManager}), which Nextcloud Office
     * (richdocuments, backed by Collabora) provides. The PDF then takes the
     * PDF path, so the model sees the layout, tables and images as well.
     *
     * @var list<string>
     */
    public const OFFICE_MIME_TYPES = [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.text-template',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.spreadsheet-template',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.presentationml.template',
        'application/vnd.oasis.opendocument.presentation',
        'application/vnd.oasis.opendocument.presentation-template',
    ];

    /**
     * Where a conversion's PDF is written, under the app's appdata, and read
     * back and deleted straight away. The conversion API always writes its
     * result as a file; the default place, beside the source, would leave a
     * PDF in the user's folder.
     */
    private const CONVERSION_FOLDER = 'aiquila/conversions';

    /** A conversion left behind by a worker that died is removed after this. */
    private const CONVERSION_STALE_SECONDS = 3600;

    /**
     * Largest PDF read. A PDF is sent whole, and the API reads at most 600
     * pages of it (100 under a 1M-token context); past this size a PDF is
     * almost always longer than that, or mostly images.
     */
    public const MAX_PDF_BYTES = 32 * 1048576;

    /**
     * Most base64 PDF bytes one batch carries inline, when PDFs cannot go
     * through the Files API. A batch is limited to 256 MB in all, and this
     * leaves room for the prompts and the request envelopes.
     */
    public const MAX_INLINE_PDF_BYTES_PER_BATCH = 192 * 1048576;

    /**
     * Lifetime of a PDF uploaded for a batch. A batch ends or expires within
     * 24 hours, and the run deletes its uploads once it collects the results
     * (or is cancelled); the expiry only covers a run that never comes back.
     * The Files API accepts 3600 – 7776000 seconds.
     */
    public const UPLOAD_TTL_SECONDS = 30 * 3600;

    /** Why a PDF was passed over, per user and provider; null when PDFs are read. */
    private array $pdfReasons = [];

    /** @var array<string, true>|null source mime types a converter turns into PDF */
    private ?array $convertible = null;

    /**
     * Hidden system tag on every output file. A folder walk skips files that
     * carry it, so one operation's output is never another's input: without
     * it, translating a folder that holds `a.txt.summary.md` writes
     * `a.txt.summary.md.german.md`, and the next summary run summarises both.
     * The own-suffix check still covers outputs written before this tag.
     */
    public const OUTPUT_TAG = 'aiquila:output';

    /**
     * File metadata key on an output: the id of the source it was written
     * for. With one output folder for many input folders, `A/notes.md` and
     * `B/notes.md` map to the same name, and this says whose it is.
     */
    public const SOURCE_METADATA_KEY = 'aiquila-source';

    /** The output tag's id once looked up; '' when it does not exist yet. */
    private ?string $outputTagId = null;

    /**
     * Which files a walk found to be outputs, by file id, so the check beside
     * each source costs no query of its own. A write never trusts it.
     *
     * @var array<int, bool>
     */
    private array $knownOutputs = [];

    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly LLMProviderFactory $providerFactory,
        private readonly ISystemTagManager $tagManager,
        private readonly ISystemTagObjectMapper $tagObjectMapper,
        private readonly LoggerInterface $logger,
        private readonly UsageStatService $usageStats,
        private readonly ?IConversionManager $conversions = null,
        private readonly ?IFilesMetadataManager $metadata = null,
    ) {
    }

    /**
     * Validate the options every operation shares.
     *
     * @param array<string, mixed> $options
     * @throws \InvalidArgumentException
     */
    public static function validateOptions(array $options): void {
        if (isset($options['recursive']) && !is_bool($options['recursive'])) {
            throw new \InvalidArgumentException('recursive must be a boolean');
        }
        if (isset($options['force']) && !is_bool($options['force'])) {
            throw new \InvalidArgumentException('force must be a boolean');
        }
        if (isset($options['maxBytesPerFile'])) {
            $bytes = $options['maxBytesPerFile'];
            if (!is_int($bytes) || $bytes < 1024 || $bytes > 20 * 1048576) {
                throw new \InvalidArgumentException('maxBytesPerFile must be between 1024 and 20971520');
            }
        }
        if (isset($options['outputFolder'])) {
            $folder = $options['outputFolder'];
            if (!is_string($folder) || $folder === '' || str_contains($folder, '..')) {
                throw new \InvalidArgumentException('outputFolder must be a path without ".."');
            }
        }
        if (isset($options['mimeTypes'])) {
            if (!is_array($options['mimeTypes']) || $options['mimeTypes'] === []) {
                throw new \InvalidArgumentException('mimeTypes must be a non-empty list');
            }
            foreach ($options['mimeTypes'] as $mime) {
                if (!is_string($mime) || $mime === '') {
                    throw new \InvalidArgumentException('mimeTypes entries must be non-empty strings');
                }
            }
        }
    }

    /**
     * The output file's name for a source, e.g. `report.pdf.summary.md`. The
     * source's extension stays in it, so `report.pdf` and `report.docx` beside
     * each other never share an output.
     */
    public static function outputName(BatchOperation $operation, File $source, array $options): string {
        return $source->getName() . $operation->outputSuffix($options);
    }

    /** The name outputs had before they kept the source's extension, e.g. `report.summary.md`. */
    private static function legacyOutputName(BatchContext $ctx, File $source): string {
        return pathinfo($source->getName(), PATHINFO_FILENAME) . $ctx->suffix();
    }

    /**
     * The name a source's output has in $folder: {@see outputName()}, unless
     * only an output under the legacy name exists and can be no other file's.
     * That one is kept up to date where it is, so folders processed before are
     * neither billed again nor left with a second, stale output.
     *
     * Every place that reads or writes an output must ask here, so the skip
     * check and the write agree.
     */
    private function resolveOutputName(BatchContext $ctx, Folder $folder, File $source): string {
        $name = self::outputName($ctx->operation, $source, $ctx->options);
        if ($this->belongsToAnotherSource($ctx, $folder, $name, $source)) {
            return self::distinctOutputName($ctx, $source);
        }
        $legacy = self::legacyOutputName($ctx, $source);
        if ($legacy === $name || $folder->nodeExists($name) || !$folder->nodeExists($legacy)) {
            return $name;
        }
        if ($this->belongsToAnotherSource($ctx, $folder, $legacy, $source)) {
            return $name;
        }
        $existing = $folder->get($legacy);
        return $existing instanceof File && $this->isOutput($existing) && !$this->sharesLegacyName($ctx, $source) ? $legacy : $name;
    }

    /**
     * The name a source's output takes when its usual one is another
     * source's, e.g. `notes.md.1234.summary.md`: the file id keeps it stable
     * from run to run and unique in the folder.
     */
    private static function distinctOutputName(BatchContext $ctx, File $source): string {
        return $source->getName() . '.' . $source->getId() . $ctx->suffix();
    }

    /**
     * Whether $name in a configured output folder is an output written for
     * another source. Only a configured folder gathers sources from several
     * folders; beside the source, names cannot clash this way. An output with
     * no source recorded (written by an earlier release) counts as anyone's,
     * as it did then.
     */
    private function belongsToAnotherSource(BatchContext $ctx, Folder $folder, string $name, File $source): bool {
        if ($ctx->outputFolder === '' || !$folder->nodeExists($name)) {
            return false;
        }
        $existing = $folder->get($name);
        if (!$existing instanceof File || !$this->isOutput($existing)) {
            return false;
        }
        $owner = $this->sourceOf($existing);
        return $owner !== null && $owner !== (int)$source->getId();
    }

    /** The source an output was written for, or null when none is recorded. */
    private function sourceOf(File $output): ?int {
        if ($this->metadata === null) {
            return null;
        }
        try {
            $metadata = $this->metadata->getMetadata((int)$output->getId());
            return $metadata->hasKey(self::SOURCE_METADATA_KEY) ? $metadata->getInt(self::SOURCE_METADATA_KEY) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Record which source an output was written for. Best effort, like the tags. */
    private function recordSource(File $output, File $source): void {
        if ($this->metadata === null) {
            return;
        }
        try {
            $metadata = $this->metadata->getMetadata((int)$output->getId(), true);
            $metadata->setInt(self::SOURCE_METADATA_KEY, (int)$source->getId());
            $this->metadata->saveMetadata($metadata);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila batch: could not record an output\'s source', [
                'file' => $output->getPath(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether a source has an output from before outputs carried the output
     * tag: a file under the legacy name that can be no other source's, beside
     * the done-tag the run left on the source. It counts as done, so an
     * upgrade bills nothing again, but nothing proves the user did not replace
     * it since, so it is never written to.
     */
    private function hasUntaggedLegacyOutput(BatchContext $ctx, Folder $folder, File $source): bool {
        $legacy = self::legacyOutputName($ctx, $source);
        return $folder->nodeExists($legacy)
            && $folder->get($legacy)->getMTime() >= $source->getMTime()
            && !$this->sharesLegacyName($ctx, $source)
            && $this->hasDoneTag($ctx, $source);
    }

    /**
     * Why a source's output cannot be written without replacing something the
     * runner did not write, or null when it can. Asked before anything is
     * sent, so a file the user owns costs neither its content nor a request.
     */
    private function occupiedReason(BatchContext $ctx, File $source): ?string {
        try {
            $folder = $this->outputFolder($ctx, $source);
            if ($ctx->operation instanceof TableOperation) {
                $name = $ctx->operation->tableFileName($ctx->options);
                $path = $folder->getPath() . '/' . $name;
                if (!isset($this->tableOccupied[$path])) {
                    $node = $folder->nodeExists($name) ? $folder->get($name) : null;
                    $this->tableOccupied[$path] = $node !== null && !($node instanceof File && $this->isTable($node));
                }
                return $this->tableOccupied[$path] ? self::occupied($name) : null;
            }
            $name = $this->resolveOutputName($ctx, $folder, $source);
            if (!$folder->nodeExists($name)) {
                return null;
            }
            $node = $folder->get($name);
            return $node instanceof File && $this->isOutput($node) ? null : self::occupied($name);
        } catch (\Throwable) {
            // Whatever is wrong here, the write reports it.
            return null;
        }
    }

    private static function occupied(string $name): string {
        return "$name already exists and was not written by AIquila; rename it or choose an output folder";
    }

    /**
     * Whether another document beside the source maps to the same legacy name,
     * so that nothing says which of them an output under it describes: one
     * with the same base name, or one whose whole name is that base name.
     */
    private function sharesLegacyName(BatchContext $ctx, File $source): bool {
        $base = pathinfo($source->getName(), PATHINFO_FILENAME);
        $parent = $source->getParent();
        if (!$parent instanceof Folder) {
            return false;
        }
        foreach ($parent->getDirectoryListing() as $sibling) {
            if (!$sibling instanceof File || $sibling->getId() === $source->getId()) {
                continue;
            }
            if ($sibling->getName() !== $base && pathinfo($sibling->getName(), PATHINFO_FILENAME) !== $base) {
                continue;
            }
            if ($this->matchesMime($sibling, $ctx->mimeTypes())) {
                return true;
            }
        }
        return false;
    }

    // ── Collection ────────────────────────────────────────────────────────

    /**
     * The documents under $root that still need work, at most
     * MAX_ITEMS_PER_RUN of them.
     *
     * A source whose output is at least as new as itself is skipped, as is
     * anything the operation itself wrote: without either check a nightly run
     * would re-bill the whole folder and then start summarising its own
     * summaries.
     *
     * A source that is due but whose output's name is taken by a file the
     * runner did not write is left out and reported in $skipped.
     *
     * @param list<BatchSkip> $skipped
     * @param-out list<BatchSkip> $skipped
     * @return list<File>
     */
    public function collect(BatchContext $ctx, Node $root, array &$skipped = []): array {
        $matches = [];
        $this->startWalk($ctx);
        if ($root instanceof File) {
            if ($this->isCandidate($ctx, $root, $skipped)) {
                $matches[] = $root;
            }
        } elseif ($root instanceof Folder) {
            $seen = [];
            $this->gather($ctx, $root, $this->configuredOutputFolderId($ctx), $matches, self::MAX_ITEMS_PER_RUN, $seen, false, $skipped);
        }

        return $matches;
    }

    /**
     * The work for a manual run over files and folders picked by the user,
     * at most MAX_ITEMS_PER_RUN files.
     *
     * Files picked one by one are what the user asked for, so they skip only
     * the mime filter: they are processed even when their output is fresh or
     * they look like an earlier output. Folders are walked with the same
     * rules as a coworker's input folder. A file reached twice counts once.
     *
     * Unlike a coworker's nightly walk, every file the walk passes over for
     * its type is reported in `skipped`: the user picked that folder and
     * should hear that its images were left alone. Finished files and earlier
     * outputs are not: leaving those is the walk doing its job.
     *
     * @param list<File> $files
     * @param list<Folder> $folders
     * @return BatchSelection
     */
    public function collectSelection(BatchContext $ctx, array $files, array $folders): array {
        $matches = [];
        $skipped = [];
        $seen = [];
        $this->startWalk($ctx);

        foreach ($files as $file) {
            if (isset($seen[$file->getId()])) {
                continue;
            }
            $seen[$file->getId()] = true;
            if (!$this->matchesMime($file, $ctx->mimeTypes())) {
                $skipped[] = ['item' => UserPath::of($file), 'reason' => self::unsupportedType($file)];
                continue;
            }
            if (($reason = $this->unreadableReason($ctx, $file)) !== null) {
                $skipped[] = ['item' => UserPath::of($file), 'reason' => $reason];
                continue;
            }
            if (($reason = $this->occupiedReason($ctx, $file)) !== null) {
                $skipped[] = ['item' => UserPath::of($file), 'reason' => $reason];
                continue;
            }
            $matches[] = $file;
        }

        $exclude = $this->configuredOutputFolderId($ctx);
        foreach ($folders as $folder) {
            if (count($matches) >= self::MAX_ITEMS_PER_RUN) {
                break;
            }
            $found = [];
            $this->gather($ctx, $folder, $exclude, $found, self::MAX_ITEMS_PER_RUN - count($matches), $seen, true, $skipped);
            array_push($matches, ...$found);
        }

        $capped = count($matches) >= self::MAX_ITEMS_PER_RUN;
        $matches = array_slice($matches, 0, self::MAX_ITEMS_PER_RUN);

        if ($ctx->requireWritableOutput) {
            $writable = [];
            foreach ($matches as $file) {
                if ($this->canWriteOutput($ctx, $file)) {
                    $writable[] = $file;
                } else {
                    $skipped[] = ['item' => UserPath::of($file), 'reason' => 'Its folder is read-only; choose an output folder'];
                }
            }
            $matches = $writable;
        }

        return ['files' => $matches, 'skipped' => $skipped, 'capped' => $capped];
    }

    /**
     * Whether the output of a source can be created where it would go. Only
     * the folder beside the source is checked here; a configured output
     * folder is checked once, when the run is started.
     */
    private function canWriteOutput(BatchContext $ctx, File $file): bool {
        if ($ctx->outputFolder !== '') {
            return true;
        }
        try {
            $parent = $file->getParent();
        } catch (\Throwable) {
            return false;
        }
        return $parent instanceof Folder && $parent->isCreatable();
    }

    /**
     * Whether one file found by the walk is work this run should do.
     *
     * Three separate reasons to say no, and they have to be asked here rather
     * than after the walk, because the walk stops at MAX_ITEMS_PER_RUN: a
     * filter applied afterwards would let the first 200 already-finished files
     * fill the quota and permanently starve everything after them.
     *
     * @param list<BatchSkip> $skipped collects a due file whose output's name is taken
     * @param-out list<BatchSkip> $skipped
     */
    private function isCandidate(BatchContext $ctx, File $file, array &$skipped): bool {
        if (!$this->matchesMime($file, $ctx->mimeTypes())) {
            return false;
        }
        // A nightly walk that kept listing PDFs it cannot send would spend the
        // run's quota on them every time; they wait for a provider that can.
        if ($this->unreadableReason($ctx, $file) !== null) {
            return false;
        }
        // Our own output is text too, and it lands in the tree we are walking.
        // Left in, each run would feed the previous run's output back through
        // and grow another generation of a.txt.summary.md.summary.md every night.
        $suffix = $ctx->suffix();
        if ($suffix !== '' && str_ends_with($file->getName(), $suffix)) {
            return false;
        }
        if (!$ctx->force() && $this->alreadyDone($ctx, $file)) {
            return false;
        }
        $reason = $this->occupiedReason($ctx, $file);
        if ($reason !== null) {
            $skipped[] = ['item' => UserPath::of($file), 'reason' => $reason];
            return false;
        }
        return true;
    }

    /**
     * Forget what an earlier walk found, and look up the configured output
     * folder's outputs in one query: the walk itself passes that folder over.
     */
    private function startWalk(BatchContext $ctx): void {
        $this->knownOutputs = [];
        $this->tableOccupied = [];
        if ($ctx->outputFolder === '') {
            return;
        }
        try {
            $node = $this->rootFolder->getUserFolder($ctx->userId)->get($ctx->outputFolder);
            if ($node instanceof Folder) {
                $this->outputIds($node->getDirectoryListing());
            }
        } catch (\Throwable) {
            // Not there yet, so it holds no outputs.
        }
    }

    /** @param list<string> $mimeTypes */
    private function matchesMime(File $file, array $mimeTypes): bool {
        $mime = $file->getMimetype();
        foreach ($mimeTypes as $candidate) {
            // A trailing slash means a family ("text/"); anything else is exact.
            if (str_ends_with($candidate, '/') ? str_starts_with($mime, $candidate) : $mime === $candidate) {
                return true;
            }
        }
        return false;
    }

    /**
     * The configured output folder's id, so the walk can skip it.
     *
     * The shipped summarize template writes to /Documents/Summaries while
     * reading /Documents recursively, so without this the output folder is
     * part of its own input.
     */
    private function configuredOutputFolderId(BatchContext $ctx): ?int {
        if ($ctx->outputFolder === '') {
            return null;
        }
        try {
            $node = $this->rootFolder->getUserFolder($ctx->userId)->get($ctx->outputFolder);
        } catch (\Throwable) {
            return null;
        }
        return $node instanceof Folder ? $node->getId() : null;
    }

    /**
     * @param list<File> $matches
     * @param array<int, true> $seen file ids already taken, by id
     * @param list<BatchSkip> $skipped collects the files passed over: due ones whose output's
     *        name is taken, and with $report those of a type the run does not read
     * @param-out list<BatchSkip> $skipped
     */
    private function gather(BatchContext $ctx, Folder $folder, ?int $excludeFolderId, array &$matches, int $limit = self::MAX_ITEMS_PER_RUN, array &$seen = [], bool $report = false, array &$skipped = []): void {
        $listing = $folder->getDirectoryListing();
        $outputs = $this->outputIds($listing);
        foreach ($listing as $child) {
            if (count($matches) >= $limit) {
                return;
            }
            if ($child instanceof File) {
                if (isset($seen[$child->getId()]) || isset($outputs[$child->getId()])) {
                    continue;
                }
                if ($report && !$this->matchesMime($child, $ctx->mimeTypes())) {
                    $seen[$child->getId()] = true;
                    $skipped[] = ['item' => UserPath::of($child), 'reason' => self::unsupportedType($child)];
                    continue;
                }
                if ($report && ($reason = $this->unreadableReason($ctx, $child)) !== null) {
                    $seen[$child->getId()] = true;
                    $skipped[] = ['item' => UserPath::of($child), 'reason' => $reason];
                    continue;
                }
                if ($this->isCandidate($ctx, $child, $skipped)) {
                    $seen[$child->getId()] = true;
                    $matches[] = $child;
                }
            } elseif ($ctx->recursive() && $child instanceof Folder) {
                if ($excludeFolderId !== null && $child->getId() === $excludeFolderId) {
                    continue;
                }
                $this->gather($ctx, $child, $excludeFolderId, $matches, $limit, $seen, $report, $skipped);
            }
        }
    }

    private static function unsupportedType(File $file): string {
        return 'Not a text, PDF or Office document (' . $file->getMimetype() . ')';
    }

    private static function isPdf(File $file): bool {
        return $file->getMimetype() === self::PDF_MIME;
    }

    private static function isOffice(File $file): bool {
        return in_array($file->getMimetype(), self::OFFICE_MIME_TYPES, true);
    }

    /**
     * Why this run cannot read a file that passed the mime filter, or null
     * when it can: a PDF needs the Anthropic provider, and an Office document
     * that as well as a converter for its type.
     */
    private function unreadableReason(BatchContext $ctx, File $file): ?string {
        $office = self::isOffice($file);
        if (!$office && !self::isPdf($file)) {
            return null;
        }
        $reason = $this->pdfUnsupportedReason($ctx);
        if ($reason !== null) {
            return $office ? 'Office documents are read as PDF, which needs the Anthropic provider' : $reason;
        }
        return $office ? $this->conversionUnavailableReason($file) : null;
    }

    /** Why an Office document cannot be converted to PDF here, or null when it can. */
    private function conversionUnavailableReason(File $file): ?string {
        if ($this->convertible === null) {
            $this->convertible = [];
            try {
                if ($this->conversions?->hasProviders()) {
                    foreach ($this->conversions->getProviders() as $pair) {
                        if ($pair->getTo() === self::PDF_MIME) {
                            $this->convertible[$pair->getFrom()] = true;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('AIquila batch: could not list the file converters', ['error' => $e->getMessage()]);
            }
        }
        if ($this->convertible === []) {
            return 'Office documents need a file converter; install Nextcloud Office (richdocuments)';
        }
        return isset($this->convertible[$file->getMimetype()])
            ? null
            : 'No installed converter turns ' . $file->getMimetype() . ' into PDF';
    }

    /**
     * Why this run cannot send PDFs, or null when it can: the run's provider
     * must be the Anthropic one, which takes a PDF as a `document` block.
     * Asked once per user and provider.
     */
    public function pdfUnsupportedReason(BatchContext $ctx): ?string {
        $key = $ctx->userId . "\0" . ($ctx->providerId ?? '');
        if (!array_key_exists($key, $this->pdfReasons)) {
            try {
                $provider = $this->providerFactory->getProviderForUser($ctx->userId, $ctx->providerId);
                $this->pdfReasons[$key] = $provider instanceof ClaudeSDKService
                    ? null
                    : 'PDF input needs the Anthropic provider; ' . $provider->getLabel() . ' is configured';
            } catch (\Throwable) {
                $this->pdfReasons[$key] = 'PDF input needs the Anthropic provider';
            }
        }
        return $this->pdfReasons[$key];
    }

    private function alreadyDone(BatchContext $ctx, File $file): bool {
        if ($ctx->operation instanceof TableOperation) {
            return $this->rowIsCurrent($ctx, $file);
        }
        try {
            $folder = $this->outputFolder($ctx, $file);
            $name = $this->resolveOutputName($ctx, $folder, $file);
            if (!$folder->nodeExists($name)) {
                return $this->hasUntaggedLegacyOutput($ctx, $folder, $file);
            }
            $existing = $folder->get($name);
            if (!$existing instanceof File || !$this->isOutput($existing)) {
                return false;
            }
            // The source is what the output describes, so an output older than
            // its source is stale and the file is worth doing again.
            if ($existing->getMTime() < $file->getMTime()) {
                return false;
            }
            // An answer to an earlier question is not an answer to this one.
            return !$ctx->operation instanceof PromptScopedOperation || $this->hasDoneTag($ctx, $file);
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Submission ────────────────────────────────────────────────────────

    /**
     * Process collected files: submit one batch on a provider that has a
     * batch API (the result is `pending`), or work through them one by one.
     *
     * @param list<File> $files at least one
     * @param callable(int, int): void $progress
     * @param list<BatchSkip> $skipped files the caller already passed over
     *        (and logged), so the summary and `itemsSkipped` account for them
     * @return BatchRunResult
     */
    public function process(BatchContext $ctx, CoworkerRun $run, array $files, callable $progress, array $skipped = []): array {
        $provider = $this->providerFactory->getProviderForUser($ctx->userId, $ctx->providerId);

        if (!$ctx->allowBatch || !$provider instanceof ClaudeSDKService || !$provider->supportsBatch()) {
            return $this->runSynchronously($ctx, $run, $provider, $files, $progress, $skipped);
        }

        // One batch for the whole set. custom_id is the file id, which is
        // what results come back keyed by — the API returns them in any order.
        $requests = [];
        $fileIds = [];
        $uploaded = [];
        $inlineBytes = 0;
        $total = count($files);
        $useFilesApi = $provider->supportsFilesApi();
        foreach ($files as $file) {
            $document = $this->readDocument($ctx, $file, $reason);
            if ($document === null) {
                $skipped[] = $this->skip($run, $file, $reason);
                continue;
            }
            $source = null;
            if ($document['kind'] === 'pdf') {
                if ($useFilesApi) {
                    // A file_id keeps the batch small whatever the PDFs weigh.
                    try {
                        $source = ['type' => 'file', 'file_id' => $provider->uploadFile(
                            $document['data'],
                            self::isPdf($file) ? $file->getName() : pathinfo($file->getName(), PATHINFO_FILENAME) . '.pdf',
                            self::PDF_MIME,
                            $ctx->userId,
                            self::UPLOAD_TTL_SECONDS,
                        )['id']];
                    } catch (\Throwable $e) {
                        $skipped[] = $this->skip($run, $file, 'Could not be uploaded: ' . $e->getMessage());
                        continue;
                    }
                    $uploaded[] = $source['file_id'];
                } else {
                    $encoded = base64_encode($document['data']);
                    if ($inlineBytes + strlen($encoded) > self::MAX_INLINE_PDF_BYTES_PER_BATCH) {
                        $skipped[] = $this->skip($run, $file, 'Left for the next run: this batch is at its size limit');
                        continue;
                    }
                    $inlineBytes += strlen($encoded);
                    $source = ['type' => 'base64', 'media_type' => self::PDF_MIME, 'data' => $encoded];
                }
            }
            $customId = 'f' . $file->getId();
            $requests[] = [
                'custom_id' => $customId,
                'messages' => [['role' => 'user', 'content' => self::userContent($ctx, $document, $source)]],
                'options' => $this->requestOptionsFor($ctx, $provider),
            ];
            $fileIds[$customId] = $file->getId();
        }

        if ($requests === []) {
            return [
                'itemsTotal' => $total,
                'itemsProcessed' => 0,
                'itemsSkipped' => count($skipped),
                'summary' => sprintf('Read none of the %d document(s).', $total) . self::describeSkips($skipped),
            ];
        }

        try {
            $batchId = $provider->submitBatch($requests, $ctx->userId);
        } catch (\Throwable $e) {
            $this->deleteUploads($provider, $uploaded, $ctx->userId);
            throw $e;
        }
        $run->appendLog(CoworkerRun::LOG_INFO, sprintf('Submitted %d document(s) to batch %s', count($requests), $batchId));

        return [
            'itemsTotal' => count($requests),
            'itemsProcessed' => 0,
            'itemsSkipped' => count($skipped),
            'summary' => sprintf(
                'Submitted %d document(s) to batch %s. Results arrive within 24 hours.',
                count($requests),
                $batchId
            ),
            'pending' => true,
            'state' => [
                'batch_id' => $batchId,
                'file_ids' => $fileIds,
                'submitted_at' => time(),
                'skipped' => $skipped,
                'options' => $ctx->options,
                'uploaded_files' => $uploaded,
            ],
        ];
    }

    /**
     * Continue a run that {@see process()} left `pending`.
     *
     * $ctx carries the options the run was submitted with (`state.options`),
     * so a coworker edited meanwhile still writes where it said it would.
     *
     * @param array<string, mixed> $state
     * @param callable(int, int): void $progress
     * @return BatchRunResult
     */
    public function resume(BatchContext $ctx, CoworkerRun $run, array $state, callable $progress): array {
        $batchId = (string)($state['batch_id'] ?? '');
        /** @var array<string, int> $fileIds */
        $fileIds = is_array($state['file_ids'] ?? null) ? $state['file_ids'] : [];
        $submittedAt = (int)($state['submitted_at'] ?? 0);
        $total = count($fileIds);

        if ($batchId === '' || $fileIds === []) {
            throw new \RuntimeException('Pending run carries no batch to resume');
        }

        $provider = $this->providerFactory->getProviderForUser($ctx->userId, $ctx->providerId);
        if (!$provider instanceof ClaudeSDKService || !$provider->supportsBatch()) {
            throw new \RuntimeException('The provider that submitted this batch is no longer available');
        }

        $status = $provider->getBatchStatus($batchId, $ctx->userId);
        if (!$status['ended']) {
            // Anthropic expires a batch at 24 h, so past the deadline the
            // record itself is gone and nothing will ever end this run.
            if ($submittedAt > 0 && (time() - $submittedAt) > self::BATCH_DEADLINE_SECONDS) {
                $this->deleteUploads($provider, self::uploadsFromState($state), $ctx->userId);
                throw new \RuntimeException("Batch $batchId did not end within 26 hours");
            }
            return [
                'itemsTotal' => $total,
                'itemsProcessed' => 0,
                'itemsSkipped' => count(self::skipsFromState($state)),
                'summary' => sprintf('Waiting for batch %s (%s).', $batchId, $status['status']),
                'pending' => true,
                'state' => $state,
            ];
        }

        try {
            return $this->collectResults($ctx, $run, $provider, $batchId, $fileIds, $state, $progress);
        } finally {
            // The batch has ended, so nothing reads the uploads any more.
            $this->deleteUploads($provider, self::uploadsFromState($state), $ctx->userId);
        }
    }

    /**
     * The Files API ids a pending run uploaded its PDFs as.
     *
     * @param array<string, mixed> $state
     * @return list<string>
     */
    public static function uploadsFromState(array $state): array {
        $ids = [];
        foreach (is_array($state['uploaded_files'] ?? null) ? $state['uploaded_files'] : [] as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Delete a run's uploads from the Files API. Best effort: a file left
     * behind expires by itself ({@see UPLOAD_TTL_SECONDS}).
     *
     * @param list<string> $fileIds
     */
    public function deleteUploads(ClaudeSDKService $provider, array $fileIds, string $userId): void {
        self::deleteUploadsWith($provider, $fileIds, $userId, $this->logger);
    }

    /**
     * {@see deleteUploads()} for a caller that holds no runner, such as a
     * cancel.
     *
     * @param list<string> $fileIds
     */
    public static function deleteUploadsWith(ClaudeSDKService $provider, array $fileIds, string $userId, LoggerInterface $logger): void {
        foreach ($fileIds as $fileId) {
            try {
                $provider->deleteFile($fileId, $userId);
            } catch (\Throwable $e) {
                $logger->warning('AIquila batch: could not delete an uploaded PDF; it expires by itself', [
                    'file_id' => $fileId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Write one output file per succeeded request and account for everything
     * that did not produce one.
     *
     * @param array<string, int> $fileIds custom_id => file id
     * @param array<string, mixed> $state
     * @param callable(int, int): void $progress
     * @return BatchRunResult
     */
    private function collectResults(
        BatchContext $ctx,
        CoworkerRun $run,
        ClaudeSDKService $provider,
        string $batchId,
        array $fileIds,
        array $state,
        callable $progress,
    ): array {
        $userId = $ctx->userId;
        $results = $provider->fetchBatchResults($batchId, $userId);
        $total = count($fileIds);
        $verb = $ctx->operation->summaryVerb();

        $written = 0;
        $outputs = [];
        $failures = self::emptyFailures();
        $tables = [];

        $progress(0, $total);

        foreach ($fileIds as $customId => $fileId) {
            $name = 'file ' . $fileId;
            $item = null;
            try {
                $file = $this->fileById($userId, (int)$fileId);
                $name = $file?->getName() ?? $name;
                $item = $file !== null ? UserPath::of($file) : null;

                if (!isset($results[$customId])) {
                    $failures['missing'][] = $name;
                    $run->appendLog(CoworkerRun::LOG_ERROR, 'No result came back from the batch', $item ?? $name);
                    continue;
                }
                $result = $results[$customId];
                if (isset($result['error'])) {
                    $bucket = (string)($result['error_type'] ?? 'errored');
                    $failures[$bucket][] = $name . ' — ' . $result['error'];
                    $run->appendLog(self::logLevelFor($bucket), ucfirst($bucket) . ': ' . $result['error'], $item ?? $name);
                    continue;
                }
                // Billed whether or not the output can still be written.
                $this->usageStats->record($userId, $provider, $result, $ctx->usageType);
                if ($file === null) {
                    $failures['unsaved'][] = $name . ' — source no longer exists';
                    $run->appendLog(CoworkerRun::LOG_ERROR, 'Source no longer exists', $name);
                    continue;
                }

                $this->accept($ctx, $file, (string)($result['response'] ?? ''), $outputs, $tables);
                $written++;
                $run->appendLog(CoworkerRun::LOG_INFO, $verb, $item);
            } catch (InvalidAnswerException $e) {
                $failures['invalid'][] = $name . ' — ' . $e->getMessage();
                $run->appendLog(CoworkerRun::LOG_ERROR, 'Invalid answer: ' . $e->getMessage(), $item ?? $name);
            } catch (\Throwable $e) {
                $failures['unsaved'][] = $name . ' — ' . $e->getMessage();
                $run->appendLog(CoworkerRun::LOG_ERROR, 'Not saved: ' . $e->getMessage(), $item ?? $name);
                $this->logger->warning('AIquila batch: could not save a result', $ctx->logContext + [
                    'custom_id' => $customId,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $progress($written, $total);
            }
        }

        $written -= $this->writeTables($ctx, $run, $tables, $outputs, $failures);
        $skipped = self::skipsFromState($state);

        return [
            'itemsTotal' => $total,
            'itemsProcessed' => $written,
            'itemsFailed' => array_sum(array_map('count', $failures)),
            'itemsSkipped' => count($skipped),
            'summary' => $this->buildSummary($verb, $written, $total, 'anthropic (batch, 50% discount)', $failures, $skipped),
            'outputs' => $outputs,
        ];
    }

    /**
     * Fallback for providers with no batch API: one synchronous request per
     * file, at full price. Slower, but the operation stays usable.
     *
     * @param list<File> $files
     * @param callable(int, int): void $progress
     * @param list<BatchSkip> $skipped
     * @return BatchRunResult
     */
    private function runSynchronously(
        BatchContext $ctx,
        CoworkerRun $run,
        LLMProviderInterface $provider,
        array $files,
        callable $progress,
        array $skipped,
    ): array {
        $userId = $ctx->userId;
        $verb = $ctx->operation->summaryVerb();
        $total = count($files);
        $written = 0;
        $outputs = [];
        $failures = self::emptyFailures();
        $tables = [];
        $skippedBefore = count($skipped);

        $progress(0, $total);

        foreach ($files as $file) {
            try {
                $document = $this->readDocument($ctx, $file, $reason);
                if ($document === null) {
                    $skipped[] = $this->skip($run, $file, $reason);
                    continue;
                }

                if ($document['kind'] === 'pdf') {
                    if (!$provider instanceof ClaudeSDKService) {
                        $skipped[] = $this->skip($run, $file, $this->pdfUnsupportedReason($ctx));
                        continue;
                    }
                    // One request carries one PDF, so it goes inline.
                    $result = $provider->chat(
                        [['role' => 'user', 'content' => self::userContent($ctx, $document, [
                            'type' => 'base64',
                            'media_type' => self::PDF_MIME,
                            'data' => base64_encode($document['data']),
                        ])]],
                        null,
                        $userId,
                        $this->requestOptionsFor($ctx, $provider),
                    );
                } else {
                    $result = $provider->ask(
                        $ctx->operation->buildPrompt($document['data'], $ctx->options),
                        '',
                        $userId,
                        $this->requestOptionsFor($ctx, $provider),
                    );
                }
                $this->usageStats->record($userId, $provider, $result, $ctx->usageType);
                if (isset($result['error'])) {
                    // A refusal is reported as such, as on the batch path.
                    $bucket = ($result['error_type'] ?? null) === 'refused' ? 'refused' : 'errored';
                    $failures[$bucket][] = $file->getName() . ' — ' . $result['error'];
                    $run->appendLog(self::logLevelFor($bucket), ucfirst($bucket) . ': ' . $result['error'], UserPath::of($file));
                    continue;
                }

                $this->accept($ctx, $file, (string)($result['response'] ?? ''), $outputs, $tables);
                $written++;
                $run->appendLog(CoworkerRun::LOG_INFO, $verb, UserPath::of($file));
            } catch (InvalidAnswerException $e) {
                $failures['invalid'][] = $file->getName() . ' — ' . $e->getMessage();
                $run->appendLog(CoworkerRun::LOG_ERROR, 'Invalid answer: ' . $e->getMessage(), UserPath::of($file));
            } catch (\Throwable $e) {
                $failures['unsaved'][] = $file->getName() . ' — ' . $e->getMessage();
                $run->appendLog(CoworkerRun::LOG_ERROR, 'Not saved: ' . $e->getMessage(), UserPath::of($file));
                $this->logger->warning('AIquila batch: item failed', $ctx->logContext + [
                    'file' => $file->getPath(),
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $progress($written, $total);
            }
        }

        $written -= $this->writeTables($ctx, $run, $tables, $outputs, $failures);
        $label = $provider->getId();
        // As on the batch path, the total is what was sent to the model: a
        // file skipped on reading counts in itemsSkipped, not here as well.
        $total -= count($skipped) - $skippedBefore;

        return [
            'itemsTotal' => $total,
            'itemsProcessed' => $written,
            'itemsFailed' => array_sum(array_map('count', $failures)),
            'itemsSkipped' => count($skipped),
            'summary' => $this->buildSummary($verb, $written, $total, $label . ($ctx->allowBatch ? ' (no batch API — full price)' : ' (immediate — full price)'), $failures, $skipped),
            'outputs' => $outputs,
        ];
    }

    /** @return array<string, list<string>> */
    private static function emptyFailures(): array {
        return ['refused' => [], 'errored' => [], 'invalid' => [], 'expired' => [], 'canceled' => [], 'unsaved' => [], 'missing' => []];
    }

    /**
     * Log level for a failure bucket: a refusal or an expired/cancelled batch
     * item is an outcome to note, everything else went wrong.
     *
     * @return 'warn'|'error'
     */
    private static function logLevelFor(string $bucket): string {
        return in_array($bucket, ['refused', 'expired', 'canceled'], true) ? CoworkerRun::LOG_WARN : CoworkerRun::LOG_ERROR;
    }

    /**
     * @param array<string, list<string>> $failures
     * @param list<BatchSkip> $skipped
     */
    private function buildSummary(string $verb, int $written, int $total, string $via, array $failures, array $skipped): string {
        $summary = sprintf('%s %d/%d document(s) via %s.', $verb, $written, $total, $via);

        $counts = [];
        foreach ($failures as $kind => $entries) {
            if ($entries !== []) {
                $counts[] = count($entries) . ' ' . $kind;
            }
        }
        if ($skipped !== []) {
            $counts[] = count($skipped) . ' skipped';
        }
        if ($counts !== []) {
            $summary .= "\n" . implode(', ', $counts) . '.';
        }

        $lines = [];
        foreach ($failures as $kind => $entries) {
            foreach ($entries as $entry) {
                $lines[] = ucfirst($kind) . ': ' . $entry;
            }
        }
        foreach ($skipped as $skip) {
            $lines[] = self::skipLine($skip);
        }
        if ($lines !== []) {
            $summary .= "\n" . implode("\n", array_slice($lines, 0, self::SUMMARY_LINES));
            if (count($lines) > self::SUMMARY_LINES) {
                $summary .= "\n" . sprintf('… and %d more; the run log has them.', count($lines) - self::SUMMARY_LINES);
            }
        }

        return $summary;
    }

    /** Most per-file lines a summary lists; the run log keeps more. */
    private const SUMMARY_LINES = 50;

    /**
     * The skip lines a summary ends with, for a caller that has no other
     * summary to append them to. Empty when nothing was skipped.
     *
     * @param list<BatchSkip> $skipped
     */
    public static function describeSkips(array $skipped): string {
        if ($skipped === []) {
            return '';
        }
        $lines = array_map(self::skipLine(...), array_slice($skipped, 0, self::SUMMARY_LINES));
        $text = "\n" . count($skipped) . ' skipped.' . "\n" . implode("\n", $lines);
        if (count($skipped) > self::SUMMARY_LINES) {
            $text .= "\n" . sprintf('… and %d more; the run log has them.', count($skipped) - self::SUMMARY_LINES);
        }
        return $text;
    }

    /** @param BatchSkip $skip */
    private static function skipLine(array $skip): string {
        return 'Skipped: ' . basename($skip['item']) . ' — ' . $skip['reason'];
    }

    /**
     * Log one file the run passes over and return it for the summary.
     *
     * @return BatchSkip
     */
    private function skip(CoworkerRun $run, File $file, ?string $reason): array {
        $skip = ['item' => UserPath::of($file), 'reason' => $reason ?? 'Could not be read'];
        $run->appendLog(CoworkerRun::LOG_WARN, 'Skipped: ' . $skip['reason'], $skip['item']);
        return $skip;
    }

    /**
     * The skips a pending run carried over from submission. Runs submitted
     * before skips had reasons stored bare file names.
     *
     * @param array<string, mixed> $state
     * @return list<BatchSkip>
     */
    private static function skipsFromState(array $state): array {
        $skips = [];
        foreach (is_array($state['skipped'] ?? null) ? $state['skipped'] : [] as $entry) {
            if (is_string($entry)) {
                $skips[] = ['item' => $entry, 'reason' => 'Unreadable or too large'];
            } elseif (is_array($entry) && is_string($entry['item'] ?? null) && is_string($entry['reason'] ?? null)) {
                $skips[] = ['item' => $entry['item'], 'reason' => $entry['reason']];
            }
        }
        return $skips;
    }

    // ── Files ─────────────────────────────────────────────────────────────

    /**
     * Read a document: a PDF's bytes, or a text document's text. Null when it
     * is too large, unreadable, not what its type says or empty; $reason then
     * says which, for the run log.
     *
     * Oversized files are skipped rather than truncated: half a contract
     * summarised as if it were the whole thing is worse than no summary.
     *
     * @return BatchDocument|null
     * @param-out string|null $reason
     */
    private function readDocument(BatchContext $ctx, File $file, ?string &$reason = null): ?array {
        $reason = null;
        if (self::isOffice($file)) {
            return $this->readOffice($ctx, $file, $reason);
        }
        $pdf = self::isPdf($file);
        $limit = $pdf ? self::MAX_PDF_BYTES : $ctx->maxBytes();
        $size = (int)$file->getSize();
        if ($size > $limit) {
            $reason = sprintf('Too large (%s; the limit is %s)', self::humanBytes($size), self::humanBytes($limit));
            return null;
        }
        try {
            $content = $file->getContent();
        } catch (\Throwable $e) {
            $reason = 'Could not be read: ' . $e->getMessage();
            return null;
        }
        if ($pdf) {
            // The type comes from the name. A file that is not a PDF would be
            // rejected by the API, and in a batch it would fail on its own,
            // but there is no reason to upload or bill for it.
            if (!str_starts_with(ltrim(substr($content, 0, 1024)), '%PDF-')) {
                $reason = 'Not a PDF, although its name says so';
                return null;
            }
            return ['kind' => 'pdf', 'data' => $content];
        }
        // A batch is submitted as one request for the whole set, outside any
        // per-file guard, so one binary file reaching the payload would take
        // the entire run down rather than just itself.
        if (!mb_check_encoding($content, 'UTF-8')) {
            $reason = 'Not UTF-8 text';
            return null;
        }
        if (trim($content) === '') {
            $reason = 'Empty';
            return null;
        }
        return ['kind' => 'text', 'data' => $content];
    }

    /**
     * An Office document as the PDF a converter makes of it.
     *
     * @return BatchDocument|null
     * @param-out string|null $reason
     */
    private function readOffice(BatchContext $ctx, File $file, ?string &$reason = null): ?array {
        $reason = $this->unreadableReason($ctx, $file);
        if ($reason !== null) {
            return null;
        }
        $size = (int)$file->getSize();
        if ($size > self::MAX_PDF_BYTES) {
            $reason = sprintf('Too large (%s; the limit is %s)', self::humanBytes($size), self::humanBytes(self::MAX_PDF_BYTES));
            return null;
        }
        try {
            $pdf = $this->convertToPdf($file);
        } catch (\Throwable $e) {
            $reason = 'Could not be converted to PDF: ' . $e->getMessage();
            return null;
        }
        if (strlen($pdf) > self::MAX_PDF_BYTES) {
            $reason = sprintf('Too large as PDF (%s; the limit is %s)', self::humanBytes(strlen($pdf)), self::humanBytes(self::MAX_PDF_BYTES));
            return null;
        }
        if (!str_starts_with(ltrim(substr($pdf, 0, 1024)), '%PDF-')) {
            $reason = 'The converter did not return a PDF';
            return null;
        }
        return ['kind' => 'pdf', 'data' => $pdf];
    }

    /**
     * Convert a file to PDF and return the PDF's bytes. The conversion API
     * writes its result as a file, so it goes to the app's appdata, is read
     * back, and is deleted whether or not that works.
     */
    private function convertToPdf(File $file): string {
        if ($this->conversions === null) {
            throw new \RuntimeException('No file converter');
        }
        $folder = $this->conversionFolder();
        $name = 'c' . $file->getId() . '-' . bin2hex(random_bytes(6)) . '.pdf';
        $path = $this->conversions->convert($file, self::PDF_MIME, $folder->getPath() . '/' . $name);
        try {
            $converted = $this->rootFolder->get($path);
            if (!$converted instanceof File) {
                throw new \RuntimeException('The converter wrote no file');
            }
            return $converted->getContent();
        } finally {
            try {
                $this->rootFolder->get($path)->delete();
            } catch (\Throwable $e) {
                $this->logger->warning('AIquila batch: could not delete a converted PDF', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }
    }

    /** The appdata folder conversions are written to, with stale leftovers removed. */
    private function conversionFolder(): Folder {
        $path = '/' . $this->rootFolder->getAppDataDirectoryName() . '/' . self::CONVERSION_FOLDER;
        try {
            $folder = $this->rootFolder->get($path);
        } catch (NotFoundException) {
            $folder = $this->rootFolder->newFolder($path);
        }
        if (!$folder instanceof Folder) {
            throw new \RuntimeException('The conversion folder is not a folder');
        }
        $cutoff = time() - self::CONVERSION_STALE_SECONDS;
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node->getMTime() < $cutoff) {
                try {
                    $node->delete();
                } catch (\Throwable) {
                }
            }
        }
        return $folder;
    }

    /**
     * The user message for one document: the prompt with the text in it, or
     * a PDF's `document` block followed by the question about it. The block
     * comes first, as the PDF guide recommends.
     *
     * @param BatchDocument $document
     * @param array<string, string>|null $source the PDF's `source`
     * @return string|list<array<string, mixed>>
     */
    private static function userContent(BatchContext $ctx, array $document, ?array $source): string|array {
        if ($document['kind'] === 'text') {
            return $ctx->operation->buildPrompt($document['data'], $ctx->options);
        }
        return [
            ['type' => 'document', 'source' => $source],
            ['type' => 'text', 'text' => $ctx->operation->buildDocumentPrompt($ctx->options)],
        ];
    }

    private static function humanBytes(int $bytes): string {
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.') . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * Write the model's answer beside the source (or into the configured
     * output folder), creating the folder if it does not exist. An earlier
     * output is replaced; any other file is left alone.
     *
     * @throws \RuntimeException when a file the runner did not write has the name
     * @return string the output's path in the user's Files
     */
    private function writeOutput(BatchContext $ctx, File $source, string $response): string {
        $folder = $this->outputFolder($ctx, $source);
        $name = $this->resolveOutputName($ctx, $folder, $source);

        $output = null;
        if ($folder->nodeExists($name)) {
            $existing = $folder->get($name);
            // The walk checked this too, but a batch may take a day to end.
            if (!$existing instanceof File || !$this->isOutput($existing, true)) {
                throw new \RuntimeException(self::occupied($name));
            }
            $existing->putContent($response);
            $output = $existing;
        }
        $output ??= $folder->newFile($name, $response);
        $this->markOutput($output);
        $this->recordSource($output, $source);
        return UserPath::of($output);
    }

    /**
     * Tag a written output so later walks leave it alone. Best effort, like
     * the done-tag: the output exists either way.
     */
    private function markOutput(File $output): void {
        try {
            $tagId = $this->outputTagId(true);
            if ($tagId !== '') {
                $this->tagObjectMapper->assignTags((string)$output->getId(), 'files', [$tagId]);
                $this->knownOutputs[(int)$output->getId()] = true;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila batch: could not tag an output file', [
                'file' => $output->getPath(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The ids among a folder listing's files that are outputs, in one query
     * per folder rather than one per file.
     *
     * @param array<array-key, Node> $listing
     * @return array<int, true>
     */
    private function outputIds(array $listing): array {
        $tagId = $this->outputTagId(false);
        if ($tagId === '') {
            return [];
        }
        $ids = [];
        foreach ($listing as $node) {
            if ($node instanceof File) {
                $ids[] = (string)$node->getId();
            }
        }
        if ($ids === []) {
            return [];
        }
        try {
            $tags = $this->tagObjectMapper->getTagIdsForObjects($ids, 'files');
        } catch (\Throwable) {
            return [];
        }
        $outputs = [];
        foreach ($tags as $objectId => $tagIds) {
            if (in_array($tagId, array_map('strval', $tagIds), true)) {
                $outputs[(int)$objectId] = true;
            }
        }
        foreach ($ids as $id) {
            $this->knownOutputs[(int)$id] = isset($outputs[(int)$id]);
        }
        return $outputs;
    }

    /**
     * Whether the runner wrote a file, which the output tag says.
     *
     * @param bool $fresh ask again instead of using what the walk found
     */
    private function isOutput(File $file, bool $fresh = false): bool {
        $id = (int)$file->getId();
        if ($fresh || !isset($this->knownOutputs[$id])) {
            return isset($this->outputIds([$file])[$id]);
        }
        return $this->knownOutputs[$id];
    }

    /**
     * Whether a file is one of the runner's tables: it is tagged as an output,
     * or, should tagging have failed, it has a table's header.
     */
    private function isTable(File $file): bool {
        if ($this->isOutput($file, true)) {
            return true;
        }
        $line = strtok($file->getContent(), "\r\n");
        $header = str_getcsv($line === false ? '' : $line, ',', '"', '');
        return array_slice($header, 0, 2) === [self::TABLE_KEY, 'file'] && end($header) === 'extracted_at';
    }

    /**
     * The output tag's id. Hidden and not assignable: it is bookkeeping, not
     * something to show in Files or let users set.
     *
     * @param bool $create create the tag when it does not exist yet
     * @return string '' when it does not exist (and $create is false)
     */
    private function outputTagId(bool $create): string {
        if ($this->outputTagId !== null && ($this->outputTagId !== '' || !$create)) {
            return $this->outputTagId;
        }
        try {
            $this->outputTagId = $this->tagManager->getTag(self::OUTPUT_TAG, false, false)->getId();
        } catch (TagNotFoundException) {
            $this->outputTagId = $create
                ? $this->tagManager->createTag(self::OUTPUT_TAG, false, false)->getId()
                : '';
        }
        return $this->outputTagId;
    }

    /** The folder a source's output goes to. */
    public function outputFolder(BatchContext $ctx, File $source): Folder {
        if ($ctx->outputFolder === '') {
            $parent = $source->getParent();
            if (!$parent instanceof Folder) {
                throw new \RuntimeException('Source file has no parent folder');
            }
            return $parent;
        }

        $userFolder = $this->rootFolder->getUserFolder($ctx->userId);
        try {
            $node = $userFolder->get($ctx->outputFolder);
        } catch (NotFoundException) {
            return $userFolder->newFolder($ctx->outputFolder);
        }
        if (!$node instanceof Folder) {
            throw new \RuntimeException("Output path {$ctx->outputFolder} is not a folder");
        }
        return $node;
    }

    /**
     * Tag the source so a user can filter what has already been processed.
     * A tagging failure must not lose an output file that was written.
     */
    private function markDone(BatchContext $ctx, File $file): void {
        try {
            $this->tagObjectMapper->assignTags((string)$file->getId(), 'files', [$this->resolveTag($ctx->operation->doneTag($ctx->options))->getId()]);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila batch: could not tag a source file', [
                'file' => $file->getPath(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Tables ────────────────────────────────────────────────────────────

    /** Header columns around a table operation's fields. */
    private const TABLE_KEY = 'file_id';

    /** @var array<string, bool> table path => a file that is no table has its name, read once per walk */
    private array $tableOccupied = [];

    /** @var array<string, array<array-key, int>> table path => file id => extracted-at (unix), read once per run */
    private array $tableIndex = [];

    /**
     * The request options of one call, with the table's JSON Schema for a
     * provider that constrains answers to one.
     *
     * @return array<string, mixed>
     */
    private function requestOptionsFor(BatchContext $ctx, LLMProviderInterface $provider): array {
        $options = ($ctx->requestOptions)($provider);
        if ($ctx->operation instanceof TableOperation && $provider instanceof ClaudeSDKService) {
            $options['json_schema'] = $ctx->operation->jsonSchema($ctx->options);
        }
        return $options;
    }

    /**
     * Take one answer: write its output file, or, for a table operation,
     * check it and hold its row until the run writes the tables.
     *
     * @param list<string> $outputs
     * @param array<string, array{folder: Folder, rows: array<int, array{file: File, row: array<string, string|int|float|bool|null>}>}> $tables
     * @param-out list<string> $outputs
     * @throws InvalidAnswerException when a table operation's answer is not a valid row
     */
    private function accept(BatchContext $ctx, File $file, string $response, array &$outputs, array &$tables): void {
        $op = $ctx->operation;
        if (!$op instanceof TableOperation) {
            $outputs[] = $this->writeOutput($ctx, $file, $response);
            $this->markDone($ctx, $file);
            return;
        }
        try {
            $row = $op->parseRow($response, $ctx->options);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidAnswerException($e->getMessage(), 0, $e);
        }
        $folder = $this->outputFolder($ctx, $file);
        $key = $folder->getPath();
        $tables[$key] ??= ['folder' => $folder, 'rows' => []];
        $tables[$key]['rows'][$file->getId()] = ['file' => $file, 'row' => $row];
    }

    /**
     * Upsert the held rows into each folder's table, then tag their sources.
     * A table that cannot be written loses its rows for this run: they are
     * reported as unsaved, and the number of them is returned.
     *
     * @param array<string, array{folder: Folder, rows: array<int, array{file: File, row: array<string, string|int|float|bool|null>}>}> $tables
     * @param list<string> $outputs
     * @param array<string, list<string>> $failures
     * @param-out list<string> $outputs
     */
    private function writeTables(BatchContext $ctx, CoworkerRun $run, array $tables, array &$outputs, array &$failures): int {
        $op = $ctx->operation;
        if (!$op instanceof TableOperation || $tables === []) {
            return 0;
        }
        $lost = 0;
        $name = $op->tableFileName($ctx->options);
        $header = [self::TABLE_KEY, 'file', ...$op->columns($ctx->options), 'extracted_at'];
        foreach ($tables as $table) {
            $folder = $table['folder'];
            try {
                $existing = null;
                if ($folder->nodeExists($name)) {
                    $node = $folder->get($name);
                    if (!$node instanceof File) {
                        throw new \RuntimeException("$name in " . UserPath::of($folder) . ' is not a file');
                    }
                    if (!$this->isTable($node)) {
                        throw new \RuntimeException(self::occupied($name));
                    }
                    $existing = $node;
                }
                $rows = $existing !== null ? self::csvRows($existing->getContent()) : [];
                $now = gmdate('Y-m-d\TH:i:s\Z');
                foreach ($table['rows'] as $fileId => $entry) {
                    $cells = [self::TABLE_KEY => (string)$fileId, 'file' => UserPath::of($entry['file']), 'extracted_at' => $now];
                    foreach ($entry['row'] as $column => $value) {
                        $cells[$column] = self::cell($value);
                    }
                    $rows[(string)$fileId] = $cells;
                }
                $csv = self::csvEncode($header, $rows);
                if ($existing !== null) {
                    $existing->putContent($csv);
                    $output = $existing;
                } else {
                    $output = $folder->newFile($name, $csv);
                }
                $this->markOutput($output);
                $outputs[] = UserPath::of($output);
                unset($this->tableIndex[$output->getPath()]);
                foreach ($table['rows'] as $entry) {
                    $this->markDone($ctx, $entry['file']);
                }
            } catch (\Throwable $e) {
                foreach ($table['rows'] as $entry) {
                    $failures['unsaved'][] = $entry['file']->getName() . ' — ' . $e->getMessage();
                    $lost++;
                }
                $run->appendLog(CoworkerRun::LOG_ERROR, 'Table not saved: ' . $e->getMessage(), UserPath::of($folder) . '/' . $name);
                $this->logger->warning('AIquila batch: could not write a table', $ctx->logContext + ['error' => $e->getMessage()]);
            }
        }
        return $lost;
    }

    /**
     * Whether a source's row is in its table, newer than the source, and from
     * the current schema (the done-tag).
     */
    private function rowIsCurrent(BatchContext $ctx, File $file): bool {
        $op = $ctx->operation;
        if (!$op instanceof TableOperation || !$this->hasDoneTag($ctx, $file)) {
            return false;
        }
        try {
            $folder = $this->outputFolder($ctx, $file);
            $path = $folder->getPath() . '/' . $op->tableFileName($ctx->options);
            if (!isset($this->tableIndex[$path])) {
                $index = [];
                $name = $op->tableFileName($ctx->options);
                $node = $folder->nodeExists($name) ? $folder->get($name) : null;
                if ($node instanceof File) {
                    foreach (self::csvRows($node->getContent()) as $id => $cells) {
                        $at = strtotime($cells['extracted_at'] ?? '');
                        if ($at !== false) {
                            $index[$id] = $at;
                        }
                    }
                }
                $this->tableIndex[$path] = $index;
            }
            $at = $this->tableIndex[$path][(string)$file->getId()] ?? null;
            return $at !== null && $at >= $file->getMTime();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A value as a CSV cell. Text that a spreadsheet would run as a formula
     * (starting with =, +, -, @, tab or carriage return) gets a leading
     * apostrophe: the documents are not trusted, and the table is opened in
     * spreadsheets. Numbers are left alone.
     */
    public static function cell(string|int|float|bool|null $value): string {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return self::neutralize($value);
    }

    private static function neutralize(string $text): string {
        return $text !== '' && strpbrk($text[0], "=+-@\t\r") !== false ? "'" . $text : $text;
    }

    /**
     * A table's rows by file id, each keyed by its header's column names.
     * Rows without a file id are dropped.
     *
     * @return array<array-key, array<string, string>>
     */
    public static function csvRows(string $csv): array {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return [];
        }
        fwrite($stream, $csv);
        rewind($stream);
        $header = null;
        $rows = [];
        while (is_array($line = fgetcsv($stream, null, ',', '"', ''))) {
            if ($line === [null]) {
                continue;
            }
            $line = array_map(static fn ($c): string => (string)$c, $line);
            if ($header === null) {
                $header = $line;
                continue;
            }
            $cells = [];
            foreach ($header as $i => $column) {
                $cells[$column] = $line[$i] ?? '';
            }
            if (($cells[self::TABLE_KEY] ?? '') !== '') {
                $rows[$cells[self::TABLE_KEY]] = $cells;
            }
        }
        fclose($stream);
        return $rows;
    }

    /**
     * RFC 4180 CSV: CRLF line ends, fields with commas, quotes or line breaks
     * quoted, quotes doubled. Rows are written under the header given; a
     * column a row lacks (from an older schema) stays empty, and one the
     * header no longer has is dropped.
     *
     * @param list<string> $header
     * @param array<array-key, array<string, string>> $rows
     */
    public static function csvEncode(array $header, array $rows): string {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Could not open a buffer for the table');
        }
        fputcsv($stream, $header, ',', '"', '', "\r\n");
        foreach ($rows as $cells) {
            $line = [];
            foreach ($header as $column) {
                $line[] = $cells[$column] ?? '';
            }
            fputcsv($stream, $line, ',', '"', '', "\r\n");
        }
        rewind($stream);
        $csv = (string)stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }

    /** Whether the source carries the operation's current done-tag. */
    private function hasDoneTag(BatchContext $ctx, File $file): bool {
        try {
            $tag = $this->tagManager->getTag($ctx->operation->doneTag($ctx->options), true, true);
            return $this->tagObjectMapper->haveTag([(string)$file->getId()], 'files', $tag->getId());
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveTag(string $name): ISystemTag {
        try {
            return $this->tagManager->getTag($name, true, true);
        } catch (TagNotFoundException) {
            return $this->tagManager->createTag($name, true, true);
        }
    }

    /**
     * A file whose output we are about to write. Null when it was deleted
     * while the batch was in flight.
     */
    private function fileById(string $userId, int $fileId): ?File {
        $nodes = $this->rootFolder->getUserFolder($userId)->getById($fileId);
        foreach ($nodes as $node) {
            if ($node instanceof File) {
                return $node;
            }
        }
        return null;
    }
}
