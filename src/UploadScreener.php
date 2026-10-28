<?php

namespace MediaWiki\Extension\UploadSafety;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\UploadSafety\Check\CheckResult;
use MediaWiki\Extension\UploadSafety\Check\CheckVerdict;
use MediaWiki\Extension\UploadSafety\Check\ImageCheck;
use Psr\Log\LoggerInterface;

/**
 * Runs every configured ImageCheck against an upload and decides whether it is allowed.
 * Any rejecting check rejects the upload. An unavailable check rejects it only when UploadSafetyFailClosed is on.
 */
class UploadScreener {

	public const CONSTRUCTOR_OPTIONS = [
		'UploadSafetyAlwaysReject',
		'UploadSafetyDebug',
		'UploadSafetyFailClosed',
	];

	public const MSG_REJECTED = 'uploadsafety-upload-rejected';
	public const MSG_UNAVAILABLE = 'uploadsafety-upload-unavailable';

	private ?ScreeningResult $last_result = null;

	/**
	 * @param ServiceOptions $options
	 * @param list<ImageCheck> $checks
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		private readonly ServiceOptions $options,
		private readonly array $checks,
		private readonly LoggerInterface $logger,
	) {
		$this->options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function debug_enabled(): bool {
		return (bool)$this->options->get( 'UploadSafetyDebug' );
	}

	public function screen( string $file_path, string $mime = '', string $filename = '' ): ScreeningResult {
		$result = $this->decide( $file_path, $mime );
		$this->log_result( $result, $file_path, $mime, $filename );

		return $result;
	}

	/**
	 * Screening detail for an allowed image in the current request, or null when debug is off.
	 */
	public function last_approved_debug_summary(): ?string {
		if ( !$this->debug_enabled() || $this->last_result === null || $this->last_result->rejected || $this->last_result->skipped() ) {
			return null;
		}

		return 'rejected=0 ' . $this->last_result->debug_summary();
	}

	/**
	 * @return array{0: string}|array{0: string, 1: string}
	 */
	public function rejection_error( ScreeningResult $result ): array {
		if ( $this->debug_enabled() ) {
			return [ $result->message_key . '-debug', $result->debug_summary() ];
		}

		return [ $result->message_key ];
	}

	private function decide( string $file_path, string $mime ): ScreeningResult {
		if ( $this->options->get( 'UploadSafetyAlwaysReject' ) ) {
			return new ScreeningResult( true, self::MSG_REJECTED, [], 'mode=always-reject' );
		}

		if ( $mime !== '' && !ImageTypes::is_screenable_mime( $mime ) ) {
			return new ScreeningResult( false, '', [], 'skipped=not-screenable-mime' );
		}

		if ( $file_path === '' || !is_readable( $file_path ) ) {
			return $this->unavailable( [], 'reason=unreadable-temp-file' );
		}

		if ( !ImageTypes::is_raster_image( $file_path ) ) {
			return new ScreeningResult( false, '', [], 'skipped=not-readable-image' );
		}

		$results = [];
		$failed_open = false;
		foreach ( $this->checks as $check ) {
			$check_result = $check->check( $file_path, $mime );
			$results[$check->name()] = $check_result;

			if ( $check_result->verdict === CheckVerdict::Reject ) {
				return new ScreeningResult( true, self::MSG_REJECTED, $results );
			}

			if ( $check_result->verdict === CheckVerdict::Unavailable ) {
				if ( $this->fail_closed() ) {
					return $this->unavailable( $results );
				}
				$failed_open = true;
			}
		}

		return new ScreeningResult( false, '', $results, $failed_open ? 'fail=open' : '' );
	}

	private function fail_closed(): bool {
		return (bool)$this->options->get( 'UploadSafetyFailClosed' );
	}

	/**
	 * @param array<string, CheckResult> $results
	 */
	private function unavailable( array $results, string $detail = '' ): ScreeningResult {
		$fail_closed = $this->fail_closed();
		$parts = [ $fail_closed ? 'fail=closed' : 'fail=open' ];
		if ( $detail !== '' ) {
			$parts[] = $detail;
		}

		return new ScreeningResult(
			$fail_closed,
			$fail_closed ? self::MSG_UNAVAILABLE : '',
			$results,
			implode( ' ', $parts )
		);
	}

	private function log_result( ScreeningResult $result, string $file_path, string $mime, string $filename ): void {
		$this->last_result = $result;

		if ( !$this->debug_enabled() ) {
			return;
		}

		$this->logger->info( 'screened {summary}', [
			'summary' => implode( ' ', array_filter( [
				'file=' . ( $filename !== '' ? $filename : basename( $file_path ) ),
				$mime !== '' ? 'mime=' . $mime : '',
				'rejected=' . ( $result->rejected ? '1' : '0' ),
				$result->debug_summary()
			] ) ),
		] );
	}

}
