<?php

namespace MediaWiki\Extension\UploadSafety\Sidecar;

use RuntimeException;

/**
 * The sidecar could not be reached or returned something outside the contract.
 * The message returned is debug details (e.g. `reason=http-error status=503`).
 */
class SidecarUnavailableException extends RuntimeException {
}
