<?php

namespace MediaWiki\Extension\UploadSafety\Check;

/**
 * Turns one check's sidecar signal into a verdict, using that check's wiki configuration.
 * Sidecars only report signals.
 *
 * @see docs/sidecar-api.md#checks
 */
interface CheckEvaluator {

	/**
	 * Check name as used in the sidecar contract and $wgUploadSafetyChecks.
	 */
	public function name(): string;

	/**
	 * @param array<string, mixed> $signal The check's entry from the sidecar response, with status `ok`
	 * @param array<string, mixed> $params The check's $wgUploadSafetyChecks entry
	 */
	public function evaluate( array $signal, array $params ): CheckResult;

}
