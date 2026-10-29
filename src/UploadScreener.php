<?php

namespace MediaWiki\Extension\UploadSafety;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\UploadSafety\Check\CheckEvaluator;
use MediaWiki\Extension\UploadSafety\Check\CheckResult;
use MediaWiki\Extension\UploadSafety\Check\CheckVerdict;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarClient;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * Sends an upload to the sidecar once for every check in $wgUploadSafetyChecks, then evaluates each signal.
 * Any rejecting check rejects the upload. An unavailable check rejects it only when UploadSafetyFailClosed is on.
 */
class UploadScreener {

	public const CONSTRUCTOR_OPTIONS = [
		'UploadSafetyAlwaysReject',
		'UploadSafetyDebug',
		'UploadSafetyFailClosed',
		'UploadSafetyChecks',
	];

	public const MSG_REJECTED = 'uploadsafety-upload-rejected';
	public const MSG_UNAVAILABLE = 'uploadsafety-upload-unavailable';

	/** @var array<string, CheckEvaluator> */
	private readonly array $evaluators;

	private ?ScreeningResult $last_result = null;

	/**
	 * @param ServiceOptions $options
	 * @param SidecarClient $sidecar
	 * @param list<CheckEvaluator> $evaluators
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		private readonly ServiceOptions $options,
		private readonly SidecarClient $sidecar,
		array $evaluators,
		private readonly LoggerInterface $logger,
	) {
		$this->options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$by_name = [];
		foreach ( $evaluators as $evaluator ) {
			$by_name[$evaluator->name()] = $evaluator;
		}
		$this->evaluators = $by_name;
	}

	public function debug_enabled(): bool {
		return (bool)$this->options->get( 'UploadSafetyDebug' );
	}

	/**
	 * @param string $file_path Uploaded file
	 * @param string $mime MediaWiki-detected MIME type
	 * @param string $filename Destination name, for logs
	 * @param string $surface Where the image came from (upload, avatar, banner, ...)
	 */
	public function screen( string $file_path, string $mime = '', string $filename = '', string $surface = 'upload' ): ScreeningResult {
		$result = $this->decide( $file_path, $mime, $surface );
		$this->log_result( $result, $file_path, $mime, $filename, $surface );

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

	/**
	 * Enabled checks and their parameters, from $wgUploadSafetyChecks. Accepts `[ 'nsfw' ]` as shorthand for `[ 'nsfw' => [] ]`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function enabled_checks(): array {
		$config = $this->options->get( 'UploadSafetyChecks' );
		if ( !is_array( $config ) ) {
			return [];
		}

		$checks = [];
		foreach ( $config as $key => $value ) {
			if ( is_int( $key ) && is_string( $value ) ) {
				$checks[$value] = [];
			} elseif ( is_string( $key ) && $value !== false && $value !== null ) {
				$checks[$key] = is_array( $value ) ? $value : [];
			}
		}

		return $checks;
	}

	private function decide( string $file_path, string $mime, string $surface ): ScreeningResult {
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

		$checks = $this->enabled_checks();
		if ( $checks === [] ) {
			return new ScreeningResult( false, '', [], 'skipped=no-checks' );
		}

		try {
			$response = $this->sidecar->check( $file_path, array_keys( $checks ), $mime, $surface );
		} catch ( SidecarUnavailableException $e ) {
			return $this->unavailable( [], $e->getMessage() );
		}

		$results = [];
		foreach ( $checks as $name => $params ) {
			$results[$name] = $this->evaluate( $name, $response->signal( $name ), $params );
		}

		$frames = $response->frames_summary();
		$detail = $frames !== '' ? 'frames=' . $frames : '';

		foreach ( $results as $result ) {
			if ( $result->verdict === CheckVerdict::Reject ) {
				return new ScreeningResult( true, self::MSG_REJECTED, $results, $detail );
			}
		}

		foreach ( $results as $result ) {
			if ( $result->verdict === CheckVerdict::Unavailable ) {
				return $this->unavailable( $results, $detail );
			}
		}

		return new ScreeningResult( false, '', $results, $detail );
	}

	/**
	 * @param string $name
	 * @param array<string, mixed>|null $signal
	 * @param array<string, mixed> $params
	 */
	private function evaluate( string $name, ?array $signal, array $params ): CheckResult {
		if ( $signal === null ) {
			return CheckResult::unavailable( 'reason=missing-from-response' );
		}

		$status = $signal['status'] ?? null;
		if ( $status === 'unsupported' ) {
			return CheckResult::unavailable( 'reason=unsupported-by-sidecar' );
		}
		if ( $status === 'error' ) {
			$code = is_array( $signal['error'] ?? null ) && is_string( $signal['error']['code'] ?? null ) ? $signal['error']['code'] : 'unknown';

			return CheckResult::unavailable( 'reason=check-error code=' . $code );
		}
		if ( $status !== 'ok' ) {
			return CheckResult::unavailable( 'reason=invalid-status' );
		}

		$evaluator = $this->evaluators[$name] ?? null;
		if ( $evaluator === null ) {
			$this->logger->warning( 'No evaluator for configured check {check}', [ 'check' => $name ] );

			return CheckResult::unavailable( 'reason=unknown-check' );
		}

		return $evaluator->evaluate( $signal, $params );
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

	private function log_result( ScreeningResult $result, string $file_path, string $mime, string $filename, string $surface ): void {
		$this->last_result = $result;

		if ( !$this->debug_enabled() ) {
			return;
		}

		$this->logger->info( 'screened {summary}', [
			'summary' => implode( ' ', array_filter( [
				'surface=' . $surface,
				'file=' . ( $filename !== '' ? $filename : basename( $file_path ) ),
				$mime !== '' ? 'mime=' . $mime : '',
				'rejected=' . ( $result->rejected ? '1' : '0' ),
				$result->debug_summary()
			] ) ),
		] );
	}

}
