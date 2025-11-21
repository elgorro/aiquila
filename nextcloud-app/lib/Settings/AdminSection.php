<?php

namespace OCA\NextClaude\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
    private IL10N $l;
    private IURLGenerator $urlGenerator;

    public function __construct(IL10N $l, IURLGenerator $urlGenerator) {
        $this->l = $l;
        $this->urlGenerator = $urlGenerator;
    }

    public function getID(): string {
        return 'nextclaude';
    }

    public function getName(): string {
        return $this->l->t('NextClaude');
    }

    public function getPriority(): int {
        return 80;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('nextclaude', 'app-dark.svg');
    }
}
