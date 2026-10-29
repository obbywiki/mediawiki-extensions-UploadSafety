<?php

namespace MediaWiki\Extension\UploadSafety;

use MediaWiki\Extension\UploadSafety\Check\CheckResult;
use MediaWiki\Extension\UploadSafety\Check\CheckVerdict;

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

	/**
	 * Name and result of the first check that rejected the upload, or null when no check did
	 * (allowed, unavailable, or rejected by the screener itself).
	 *
	 * @return array{0: string, 1: CheckResult}|null
	 */
	public function rejecting_check(): ?array {
		foreach ( $this->checks as $name => $result ) {
			if ( $result->verdict === CheckVerdict::Reject ) {
				return [ $name, $result ];
			}
		}

		return null;
	}

	/**
	 * Whether this result can be reused for the same file: a final verdict from the checks, not a skip,
	 * a screener mode or an unavailable check.
	 */
	public function cacheable(): bool {
		if ( $this->checks === [] || ( $this->detail !== '' && !str_starts_with( $this->detail, 'frames=' ) ) ) {
			return false;
		}

		foreach ( $this->checks as $result ) {
			if ( $result->verdict === CheckVerdict::Unavailable ) {
				return false;
			}
		}

		return true;
	}

	public function with_detail( string $detail ): self {
		return new self( $this->rejected, $this->message_key, $this->checks, $detail );
	}

	/**
	 * @return array{rejected: bool, message_key: string, checks: array<string, array>, detail: string}
	 */
	public function to_array(): array {
		return [
			'rejected' => $this->rejected,
			'message_key' => $this->message_key,
			'checks' => array_map( static fn ( CheckResult $result ) => $result->to_array(), $this->checks ),
			'detail' => $this->detail,
		];
	}

	/**
	 * Inverse of to_array(), or null when $data is not a valid result.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( !is_array( $data ) || !is_bool( $data['rejected'] ?? null ) || !is_array( $data['checks'] ?? null ) ) {
			return null;
		}

		$checks = [];
		foreach ( $data['checks'] as $name => $check ) {
			$result = CheckResult::from_array( $check );
			if ( !is_string( $name ) || $result === null ) {
				return null;
			}
			$checks[$name] = $result;
		}

		return new self(
			$data['rejected'],
			is_string( $data['message_key'] ?? null ) ? $data['message_key'] : '',
			$checks,
			is_string( $data['detail'] ?? null ) ? $data['detail'] : ''
		);
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
