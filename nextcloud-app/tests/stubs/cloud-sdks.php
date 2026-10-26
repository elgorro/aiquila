<?php
/**
 * Minimal stubs for the optional cloud SDKs, for Psalm.
 *
 * aws/aws-sdk-php and google/auth are not dependencies of the app: an admin
 * installs them outside the app directory when a cloud provider needs them
 * (see OCA\AIquila\Service\Provider\Cloud\CloudSdk). Only what the app itself
 * names is declared; the SDK's own references resolve through vendor/.
 */

namespace Aws\Signature {
    class SignatureV4 {
    }
}

namespace Google\Auth {
    interface FetchAuthTokenInterface {
        /** @return array<string, mixed> */
        public function fetchAuthToken(?callable $httpHandler = null);
    }
}

namespace Google\Auth\Credentials {
    class ServiceAccountCredentials implements \Google\Auth\FetchAuthTokenInterface {
        /**
         * @param string|list<string>|null $scope
         * @param string|array<string, mixed> $jsonKey
         */
        public function __construct($scope, $jsonKey, ?string $sub = null, ?string $targetAudience = null) {
        }

        public function fetchAuthToken(?callable $httpHandler = null) {
            return [];
        }
    }
}
