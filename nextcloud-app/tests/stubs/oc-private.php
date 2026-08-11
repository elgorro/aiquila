<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Stubs for Nextcloud's *private* `OC\` namespace.
 *
 * `nextcloud/ocp` only ships the public `OCP\` API, but a handful of public
 * interfaces reference private types. Those cannot be resolved from Composer,
 * so they are stubbed here.
 *
 * Keep this file as small as possible: every stub in it is a signature we own
 * and that nothing keeps in sync with upstream — exactly the failure mode that
 * #423 removed from `tests/bootstrap.php`. Only add a stub when a real `OCP\`
 * class cannot be loaded without it.
 */

namespace OC\Hooks;

// Referenced by OCP\Files\IRootFolder.
if (!interface_exists(Emitter::class)) {
    interface Emitter {
    }
}

namespace OC\Security\CSP;

// Reached through OC::$server below; there is no OCP equivalent for the CSP
// nonce, so the whole chain has to be stubbed.
if (!class_exists(ContentSecurityPolicyNonceManager::class)) {
    class ContentSecurityPolicyNonceManager {
        public function getNonce(): string {
            return '';
        }
    }
}

namespace OC;

if (!class_exists(Server::class)) {
    class Server {
        public function getContentSecurityPolicyNonceManager(): \OC\Security\CSP\ContentSecurityPolicyNonceManager {
            return new \OC\Security\CSP\ContentSecurityPolicyNonceManager();
        }
    }
}

namespace OC\Core\Command;

// Base class of every `occ` command. It is private, so nextcloud/ocp does not
// carry it, but lib/Command/*.php extends it. Stubbing it (rather than
// suppressing UndefinedClass for lib/Command/) keeps those commands analysed
// against the real Symfony Command signatures. None of our commands use any
// Base-specific member beyond the constructor, so nothing else is stubbed.
if (!class_exists(Base::class)) {
    abstract class Base extends \Symfony\Component\Console\Command\Command {
    }
}
