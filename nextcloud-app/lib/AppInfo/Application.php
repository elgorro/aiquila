<?php

namespace OCA\AIquila\AppInfo;

use OCA\AIquila\Public\IAIquila;
use OCA\AIquila\Service\AIquilaService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;

class Application extends App implements IBootstrap {
    public const APP_ID = 'aiquila';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Register AIquila service for use by other apps
        $context->registerService(IAIquila::class, function ($c) {
            return $c->get(AIquilaService::class);
        });
    }

    public function boot(IBootContext $context): void {
        // Register file actions script on Files app
        Util::addScript(self::APP_ID, 'fileactions');
    }
}
