<?php

namespace MediaWiki\Extension\UploadSafety;

use MediaWiki\Extension\UploadSafety\Check\CheckResult;

/**
 * Final outcome of screening one upload, with the result of every check that ran.
 */
class ScreeningResult {

	/**
	 * @param bool $rejected
	 * @param string $message_key Rejection message, empty when allowed
	 * @param array<string, CheckResult> $checks Results keyed by check name, in the order they ran
	 * @param string $detail Screener-level detail (mode, fail policy, skip reason)
	 */
	public function __construct(
		public readonly bool $rejected,
		public readonly string $message_key = '',
		public readonly array $checks = [],
		public readonly string $detail = '',
	) {
	}

	public function skipped(): bool {
		return str_starts_with( $this->detail, 'skipped=' );
	}

	public function debug_summary(): string {
		$parts = [];
		if ( $this->detail !== '' ) {
			$parts[] = $this->detail;
		}
		foreach ( $this->checks as $name => $result ) {
			$summary = $result->debug_summary();
			$parts[] = 'check=' . $name . ' verdict=' . $result->verdict->value . ( $summary !== '' ? ' ' . $summary : '' );
		}

		return $parts === [] ? 'no-details' : implode( ' ', $parts );
	}

}
