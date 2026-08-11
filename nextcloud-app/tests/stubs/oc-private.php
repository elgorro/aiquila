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
