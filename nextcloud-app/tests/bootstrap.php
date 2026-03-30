<?php

/**
 * PHPUnit bootstrap for AIquila tests
 *
 * For standalone testing, mock the Nextcloud interfaces.
 * For integration testing, this should be run within Nextcloud's test framework.
 */

// Autoload app classes
spl_autoload_register(function ($class) {
    $prefix = 'OCA\\AIquila\\';
    $baseDir = __DIR__ . '/../lib/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Mock Nextcloud interfaces for standalone testing
if (!interface_exists('OCP\IConfig')) {
    interface OCP_IConfig {
        public function getAppValue(string $appName, string $key, string $default = ''): string;
        public function setAppValue(string $appName, string $key, string $value): void;
        public function deleteAppValue(string $appName, string $key): void;
        public function getUserValue(string $userId, string $appName, string $key, string $default = ''): string;
        public function setUserValue(string $userId, string $appName, string $key, string $value): void;
        public function deleteUserValue(string $userId, string $appName, string $key): void;
    }
    class_alias('OCP_IConfig', 'OCP\IConfig');
}

if (!interface_exists('OCP\Http\Client\IClientService')) {
    interface OCP_Http_Client_IClientService {
        public function newClient();
    }
    class_alias('OCP_Http_Client_IClientService', 'OCP\Http\Client\IClientService');
}

if (!interface_exists('OCP\Http\Client\IClient')) {
    interface OCP_Http_Client_IClient {
        public function post(string $uri, array $options = []);
    }
    class_alias('OCP_Http_Client_IClient', 'OCP\Http\Client\IClient');
}

if (!interface_exists('OCP\Http\Client\IResponse')) {
    interface OCP_Http_Client_IResponse {
        public function getBody();
    }
    class_alias('OCP_Http_Client_IResponse', 'OCP\Http\Client\IResponse');
}

if (!interface_exists('OCP\IRequest')) {
    interface OCP_IRequest {
        public function getParam(string $key, mixed $default = null): mixed;
    }
    class_alias('OCP_IRequest', 'OCP\IRequest');
}

if (!interface_exists('OCP\ICache')) {
    interface OCP_ICache {
        public function get(string $key): mixed;
        public function set(string $key, mixed $value, int $ttl = 0): bool;
        public function remove(string $key): bool;
    }
    class_alias('OCP_ICache', 'OCP\ICache');
}

if (!interface_exists('OCP\ICacheFactory')) {
    interface OCP_ICacheFactory {
        public function createDistributed(string $prefix): \OCP\ICache;
    }
    class_alias('OCP_ICacheFactory', 'OCP\ICacheFactory');
}

if (!interface_exists('OCP\AppFramework\Services\IInitialState')) {
    interface OCP_AppFramework_Services_IInitialState {
        public function provideInitialState(string $key, mixed $data): void;
    }
    class_alias('OCP_AppFramework_Services_IInitialState', 'OCP\AppFramework\Services\IInitialState');
}

if (!class_exists('OCP\AppFramework\Controller')) {
    abstract class OCP_AppFramework_Controller {
        protected string $appName;
        protected \OCP\IRequest $request;
        public function __construct(string $appName, \OCP\IRequest $request) {
            $this->appName = $appName;
            $this->request = $request;
        }
    }
    class_alias('OCP_AppFramework_Controller', 'OCP\AppFramework\Controller');
}

if (!class_exists('OCP\AppFramework\Http\JSONResponse')) {
    class OCP_AppFramework_Http_JSONResponse {
        private array $data;
        private int $status;
        public function __construct(array $data = [], int $status = 200) {
            $this->data   = $data;
            $this->status = $status;
        }
        public function getData(): array { return $this->data; }
        public function getStatus(): int { return $this->status; }
    }
    class_alias('OCP_AppFramework_Http_JSONResponse', 'OCP\AppFramework\Http\JSONResponse');
}

if (!class_exists('OCP\AppFramework\Http\TemplateResponse')) {
    class OCP_AppFramework_Http_TemplateResponse {
        private string $app;
        private string $template;
        private array $params;
        private string $renderAs;
        public function __construct(string $app, string $template, array $params = [], string $renderAs = 'user') {
            $this->app      = $app;
            $this->template = $template;
            $this->params   = $params;
            $this->renderAs = $renderAs;
        }
        public function getTemplateName(): string { return $this->template; }
    }
    class_alias('OCP_AppFramework_Http_TemplateResponse', 'OCP\AppFramework\Http\TemplateResponse');
}

if (!class_exists('OCP\AppFramework\Http\DataDownloadResponse')) {
    class OCP_AppFramework_Http_DataDownloadResponse {
        public function __construct(string $data, string $filename, string $contentType) {}
    }
    class_alias('OCP_AppFramework_Http_DataDownloadResponse', 'OCP\AppFramework\Http\DataDownloadResponse');
}

if (!class_exists('OCP\Files\NotFoundException')) {
    class OCP_Files_NotFoundException extends \Exception {}
    class_alias('OCP_Files_NotFoundException', 'OCP\Files\NotFoundException');
}

// ── OCP\AppFramework\Db layer ──────────────────────────────────────────────

if (!class_exists('OCP\AppFramework\Db\DoesNotExistException')) {
    class OCP_AppFramework_Db_DoesNotExistException extends \Exception {}
    class_alias('OCP_AppFramework_Db_DoesNotExistException', 'OCP\AppFramework\Db\DoesNotExistException');
}

if (!class_exists('OCP\AppFramework\Db\Entity')) {
    abstract class OCP_AppFramework_Db_Entity {
        private array $_types = [];
        protected ?int $id = null;

        public function getId(): ?int { return $this->id; }

        protected function addType(string $fieldName, string $type): void {
            $this->_types[$fieldName] = $type;
        }

        public function getFieldTypes(): array { return $this->_types; }

        public function __call(string $name, array $args): mixed {
            if (strncmp('get', $name, 3) === 0) {
                $field = lcfirst(substr($name, 3));
                return $this->$field ?? null;
            }
            if (strncmp('set', $name, 3) === 0) {
                $field = lcfirst(substr($name, 3));
                $this->$field = $args[0];
                return null;
            }
            throw new \BadMethodCallException("Method $name does not exist");
        }
    }
    class_alias('OCP_AppFramework_Db_Entity', 'OCP\AppFramework\Db\Entity');
}

// ── OCP\DB\QueryBuilder ────────────────────────────────────────────────────

if (!interface_exists('OCP\DB\QueryBuilder\IExpressionBuilder')) {
    interface OCP_DB_QueryBuilder_IExpressionBuilder {
        public function eq(string $x, mixed $y): string;
        public function lte(string $x, mixed $y): string;
    }
    class_alias('OCP_DB_QueryBuilder_IExpressionBuilder', 'OCP\DB\QueryBuilder\IExpressionBuilder');
}

if (!interface_exists('OCP\DB\QueryBuilder\IFunctionBuilder')) {
    interface OCP_DB_QueryBuilder_IFunctionBuilder {
        public function sum(string $column, string $alias): string;
    }
    class_alias('OCP_DB_QueryBuilder_IFunctionBuilder', 'OCP\DB\QueryBuilder\IFunctionBuilder');
}

if (!interface_exists('OCP\DB\IResult')) {
    interface OCP_DB_IResult {
        public function fetch(): array|false;
        public function closeCursor(): bool;
    }
    class_alias('OCP_DB_IResult', 'OCP\DB\IResult');
}

if (!interface_exists('OCP\DB\QueryBuilder\IQueryBuilder')) {
    interface OCP_DB_QueryBuilder_IQueryBuilder {
        const PARAM_NULL = 0;
        const PARAM_INT  = 1;
        const PARAM_STR  = 2;
        const PARAM_BOOL = 5;

        public function select(mixed ...$selects): static;
        public function from(string $from, ?string $alias = null): static;
        public function where(mixed $predicate): static;
        public function andWhere(mixed $predicate): static;
        public function orderBy(string $sort, ?string $order = null): static;
        public function setMaxResults(?int $maxResults): static;
        public function setFirstResult(int $firstResult): static;
        public function delete(string $delete, ?string $alias = null): static;
        public function expr(): \OCP\DB\QueryBuilder\IExpressionBuilder;
        public function func(): \OCP\DB\QueryBuilder\IFunctionBuilder;
        public function createNamedParameter(mixed $value, int $type = 2, ?string $placeHolder = null): string;
        public function executeQuery(): \OCP\DB\IResult;
        public function executeStatement(): int;
    }
    class_alias('OCP_DB_QueryBuilder_IQueryBuilder', 'OCP\DB\QueryBuilder\IQueryBuilder');
}

if (!interface_exists('OCP\IDBConnection')) {
    interface OCP_IDBConnection {
        public function getQueryBuilder(): \OCP\DB\QueryBuilder\IQueryBuilder;
    }
    class_alias('OCP_IDBConnection', 'OCP\IDBConnection');
}

if (!class_exists('OCP\AppFramework\Db\QBMapper')) {
    abstract class OCP_AppFramework_Db_QBMapper {
        protected \OCP\IDBConnection $db;
        private string $_tableName;
        private string $_entityClass;

        public function __construct(\OCP\IDBConnection $db, string $tableName, string $entityClass) {
            $this->db           = $db;
            $this->_tableName   = $tableName;
            $this->_entityClass = $entityClass;
        }

        protected function getTableName(): string { return $this->_tableName; }

        /** @return \OCP\AppFramework\Db\Entity[] */
        protected function findEntities(\OCP\DB\QueryBuilder\IQueryBuilder $qb): array { return []; }

        protected function findEntity(\OCP\DB\QueryBuilder\IQueryBuilder $qb): \OCP\AppFramework\Db\Entity {
            throw new \OCP\AppFramework\Db\DoesNotExistException('Entity not found');
        }

        public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity { return $entity; }
        public function update(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity { return $entity; }
        public function delete(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity { return $entity; }
    }
    class_alias('OCP_AppFramework_Db_QBMapper', 'OCP\AppFramework\Db\QBMapper');
}

if (!class_exists('OCP\Util')) {
    class OCP_Util {
        public static function addScript(string $appName, string $scriptName): void {}
    }
    class_alias('OCP_Util', 'OCP\Util');
}

if (!interface_exists('OCP\IURLGenerator')) {
    interface OCP_IURLGenerator {
        public function linkToRoute(string $routeName, array $arguments = []): string;
        public function linkToRouteAbsolute(string $routeName, array $arguments = []): string;
        public function linkTo(string $appName, string $file, array $args = []): string;
        public function getAbsoluteURL(string $url): string;
        public function imagePath(string $appName, string $image): string;
    }
    class_alias('OCP_IURLGenerator', 'OCP\IURLGenerator');
}

if (!class_exists('OCP\AppFramework\Http\DataDisplayResponse')) {
    class OCP_AppFramework_Http_DataDisplayResponse {
        private string $data;
        private int $status;
        private array $headers = [];
        public function __construct(string $data = '', int $status = 200, array $headers = []) {
            $this->data = $data;
            $this->status = $status;
            $this->headers = $headers;
        }
        public function getData(): string { return $this->data; }
        public function getStatus(): int { return $this->status; }
        public function addHeader(string $name, string $value): void { $this->headers[$name] = $value; }
    }
    class_alias('OCP_AppFramework_Http_DataDisplayResponse', 'OCP\AppFramework\Http\DataDisplayResponse');
}

if (!class_exists('OCP\AppFramework\Http\Attribute\NoCSRFRequired')) {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    class OCP_AppFramework_Http_Attribute_NoCSRFRequired {}
    class_alias('OCP_AppFramework_Http_Attribute_NoCSRFRequired', 'OCP\AppFramework\Http\Attribute\NoCSRFRequired');
}

if (!interface_exists('Psr\Log\LoggerInterface')) {
    interface Psr_Log_LoggerInterface {
        public function emergency(string|\Stringable $message, array $context = []): void;
        public function alert(string|\Stringable $message, array $context = []): void;
        public function critical(string|\Stringable $message, array $context = []): void;
        public function error(string|\Stringable $message, array $context = []): void;
        public function warning(string|\Stringable $message, array $context = []): void;
        public function notice(string|\Stringable $message, array $context = []): void;
        public function info(string|\Stringable $message, array $context = []): void;
        public function debug(string|\Stringable $message, array $context = []): void;
        public function log(mixed $level, string|\Stringable $message, array $context = []): void;
    }
    class_alias('Psr_Log_LoggerInterface', 'Psr\Log\LoggerInterface');
}

if (!interface_exists('OCP\Security\ICredentialsManager')) {
    interface OCP_Security_ICredentialsManager {
        public function store(string $userId, string $identifier, mixed $credentials): void;
        public function retrieve(string $userId, string $identifier): mixed;
        public function delete(string $userId, string $identifier): void;
    }
    class_alias('OCP_Security_ICredentialsManager', 'OCP\Security\ICredentialsManager');
}

if (!interface_exists('OCP\Security\ICrypto')) {
    interface OCP_Security_ICrypto {
        public function encrypt(string $plaintext, string $password = ''): string;
        public function decrypt(string $encryptedContent, string $password = ''): string;
    }
    class_alias('OCP_Security_ICrypto', 'OCP\Security\ICrypto');
}

if (!interface_exists('OCP\Migration\IOutput')) {
    interface OCP_Migration_IOutput {
        public function info(string $message): void;
        public function warning(string $message): void;
        public function startProgress(int $max = 0): void;
        public function advance(int $step = 1, string $description = ''): void;
        public function finishProgress(): void;
    }
    class_alias('OCP_Migration_IOutput', 'OCP\Migration\IOutput');
}

if (!interface_exists('OCP\Migration\IRepairStep')) {
    interface OCP_Migration_IRepairStep {
        public function getName(): string;
        public function run(\OCP\Migration\IOutput $output): void;
    }
    class_alias('OCP_Migration_IRepairStep', 'OCP\Migration\IRepairStep');
}

if (!interface_exists('OCP\App\IAppManager')) {
    interface OCP_App_IAppManager {
        public function getAppVersion(string $appId): string;
    }
    class_alias('OCP_App_IAppManager', 'OCP\App\IAppManager');
}

if (!interface_exists('OCP\Capabilities\ICapability')) {
    interface OCP_Capabilities_ICapability {
        public function getCapabilities(): array;
    }
    class_alias('OCP_Capabilities_ICapability', 'OCP\Capabilities\ICapability');
}

// ── OCP\Notification ─────────────────────────────────────────────────────

if (!interface_exists('OCP\Notification\INotification')) {
    interface OCP_Notification_INotification {
        public function getApp(): string;
        public function setApp(string $app): static;
        public function getUser(): string;
        public function setUser(string $user): static;
        public function setDateTime(\DateTimeInterface $dateTime): static;
        public function getSubject(): string;
        public function setSubject(string $subject, array $parameters = []): static;
        public function getSubjectParameters(): array;
        public function setParsedSubject(string $subject): static;
        public function setParsedMessage(string $message): static;
        public function setIcon(string $icon): static;
        public function setObject(string $type, string $id): static;
        public function getObjectType(): string;
        public function getObjectId(): string;
    }
    class_alias('OCP_Notification_INotification', 'OCP\Notification\INotification');
}

if (!interface_exists('OCP\Notification\INotifier')) {
    interface OCP_Notification_INotifier {
        public function getID(): string;
        public function getName(): string;
        public function prepare(\OCP\Notification\INotification $notification, string $languageCode): \OCP\Notification\INotification;
    }
    class_alias('OCP_Notification_INotifier', 'OCP\Notification\INotifier');
}

if (!interface_exists('OCP\Notification\IManager')) {
    interface OCP_Notification_IManager {
        public function createNotification(): \OCP\Notification\INotification;
        public function notify(\OCP\Notification\INotification $notification): void;
    }
    class_alias('OCP_Notification_IManager', 'OCP\Notification\IManager');
}

// ── OCP\EventDispatcher ──────────────────────────────────────────────────

if (!class_exists('OCP\EventDispatcher\Event')) {
    class OCP_EventDispatcher_Event {}
    class_alias('OCP_EventDispatcher_Event', 'OCP\EventDispatcher\Event');
}

if (!interface_exists('OCP\EventDispatcher\IEventListener')) {
    interface OCP_EventDispatcher_IEventListener {
        public function handle(\OCP\EventDispatcher\Event $event): void;
    }
    class_alias('OCP_EventDispatcher_IEventListener', 'OCP\EventDispatcher\IEventListener');
}

// ── OCP\TaskProcessing ───────────────────────────────────────────────────

if (!class_exists('OCP\TaskProcessing\Task')) {
    class OCP_TaskProcessing_Task {
        public function getId(): ?int { return null; }
        public function getTaskTypeId(): string { return ''; }
        public function getAppId(): string { return ''; }
        public function getUserId(): ?string { return null; }
        public function getProviderId(): ?string { return null; }
        public function getErrorMessage(): ?string { return null; }
    }
    class_alias('OCP_TaskProcessing_Task', 'OCP\TaskProcessing\Task');
}

if (!class_exists('OCP\TaskProcessing\Events\TaskSuccessfulEvent')) {
    class OCP_TaskProcessing_Events_TaskSuccessfulEvent extends \OCP\EventDispatcher\Event {
        public function __construct(private \OCP\TaskProcessing\Task $task) { }
        public function getTask(): \OCP\TaskProcessing\Task { return $this->task; }
    }
    class_alias('OCP_TaskProcessing_Events_TaskSuccessfulEvent', 'OCP\TaskProcessing\Events\TaskSuccessfulEvent');
}

if (!class_exists('OCP\TaskProcessing\Events\TaskFailedEvent')) {
    class OCP_TaskProcessing_Events_TaskFailedEvent extends \OCP\EventDispatcher\Event {
        public function __construct(private \OCP\TaskProcessing\Task $task) { }
        public function getTask(): \OCP\TaskProcessing\Task { return $this->task; }
    }
    class_alias('OCP_TaskProcessing_Events_TaskFailedEvent', 'OCP\TaskProcessing\Events\TaskFailedEvent');
}

// ── OCP\IL10N ─────────────────────────────────────────────────────────────

if (!interface_exists('OCP\IL10N')) {
    interface OCP_IL10N {
        public function t(string $text, array $parameters = []): string;
        public function n(string $textSingular, string $textPlural, int $count, array $parameters = []): string;
    }
    class_alias('OCP_IL10N', 'OCP\IL10N');
}

// ── OCP\SetupCheck ────────────────────────────────────────────────────────

if (!interface_exists('OCP\SetupCheck\ISetupCheck')) {
    interface OCP_SetupCheck_ISetupCheck {
        public function getName(): string;
        public function getCategory(): string;
        public function run(): \OCP\SetupCheck\SetupResult;
    }
    class_alias('OCP_SetupCheck_ISetupCheck', 'OCP\SetupCheck\ISetupCheck');
}

if (!class_exists('OCP\SetupCheck\SetupResult')) {
    class OCP_SetupCheck_SetupResult {
        private function __construct(
            private string $severity,
            private ?string $description = null,
        ) {
        }

        public static function success(?string $description = null): self {
            return new self('success', $description);
        }

        public static function info(?string $description = null): self {
            return new self('info', $description);
        }

        public static function warning(?string $description = null): self {
            return new self('warning', $description);
        }

        public static function error(?string $description = null): self {
            return new self('error', $description);
        }

        public function getSeverity(): string {
            return $this->severity;
        }

        public function getDescription(): ?string {
            return $this->description;
        }
    }
    class_alias('OCP_SetupCheck_SetupResult', 'OCP\SetupCheck\SetupResult');
}
