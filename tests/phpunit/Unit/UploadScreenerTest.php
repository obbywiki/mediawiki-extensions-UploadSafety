<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\UploadSafety\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\UploadSafety\Check\CheckVerdict;
use MediaWiki\Extension\UploadSafety\Check\NsfwEvaluator;
use MediaWiki\Extension\UploadSafety\Check\NudityRegionsEvaluator;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarClient;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarResponse;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarUnavailableException;
use MediaWiki\Extension\UploadSafety\UploadScreener;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;

/**
 * @group UploadSafety
 * @covers \MediaWiki\Extension\UploadSafety\UploadScreener
 * @covers \MediaWiki\Extension\UploadSafety\ScreeningResult
 * @covers \MediaWiki\Extension\UploadSafety\ImageTypes
 */
class UploadScreenerTest extends MediaWikiUnitTestCase {

	private const PNG_1X1 = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89" . "\x00\x00\x00\rIDATx\xdac\xfc\xcf\xc0P\x0f\x00\x04\x85\x01\x80\x84\xa9\x8c!\x00\x00\x00\x00IEND\xaeB`\x82";

	private const IMAGE = [ 'format' => 'png', 'width' => 1, 'height' => 1, 'frames' => [ 'total' => 1, 'distinct' => 1, 'analyzed' => 1 ] ];

	private string $png_path;

	protected function setUp(): void {
		parent::setUp();
		$this->png_path = tempnam( sys_get_temp_dir(), 'us-screener-' );
		file_put_contents( $this->png_path, self::PNG_1X1 );
	}

	protected function tearDown(): void {
		if ( is_file( $this->png_path ) ) {
			unlink( $this->png_path );
		}
		parent::tearDown();
	}

	/**
	 * @param SidecarResponse|SidecarUnavailableException|null $response Null when the sidecar must not be called
	 * @param array<string, mixed> $config
	 */
	private function new_screener( SidecarResponse|SidecarUnavailableException|null $response, array $config = [] ): UploadScreener {
		$sidecar = $this->createMock( SidecarClient::class );
		if ( $response === null ) {
			$sidecar->expects( $this->never() )->method( 'check' );
		} elseif ( $response instanceof SidecarUnavailableException ) {
			$sidecar->method( 'check' )->willThrowException( $response );
		} else {
			$sidecar->method( 'check' )->willReturn( $response );
		}

		$options = new ServiceOptions( UploadScreener::CONSTRUCTOR_OPTIONS, new HashConfig( $config + [
			'UploadSafetyAlwaysReject' => false,
			'UploadSafetyDebug' => false,
			'UploadSafetyFailClosed' => true,
			'UploadSafetyChecks' => [ 'nsfw' => [ 'threshold' => 0.5 ] ],
			'UploadSafetyAutoScreen' => [ 'upload' => true, 'avatar' => true, 'banner' => true ],
			'UploadSafetyMaxFrames' => 64,
			'UploadSafetySuspectsToReject' => 2,
		] ) );

		return new UploadScreener( $options, $sidecar, [ new NsfwEvaluator(), new NudityRegionsEvaluator() ], new NullLogger() );
	}

	/**
	 * @param array<string, mixed> $checks
	 */
	private static function response( array $checks ): SidecarResponse {
		return new SidecarResponse( $checks, self::IMAGE );
	}

	private static function nsfw( float $score ): array {
		return [ 'status' => 'ok', 'score' => $score, 'frame' => 0 ];
	}

	public function test_passing_signal_allows(): void {
		$result = $this->new_screener( self::response( [ 'nsfw' => self::nsfw( 0.1 ) ] ) )
			->screen( $this->png_path, 'image/png' );

		$this->assertFalse( $result->rejected );
		$this->assertSame( [ 'nsfw' ], array_keys( $result->checks ) );
		$this->assertStringContainsString( 'frames=1/1/1', $result->debug_summary() );
	}

	public function test_signal_over_threshold_rejects(): void {
		$result = $this->new_screener( self::response( [ 'nsfw' => self::nsfw( 0.9 ) ] ) )
			->screen( $this->png_path, 'image/png' );

		$this->assertTrue( $result->rejected );
		$this->assertSame( UploadScreener::MSG_REJECTED, $result->message_key );
	}

	private const BOTH_CHECKS = [
		'nsfw' => [ 'threshold' => 0.98, 'suspect' => 0.5 ],
		'nudity_regions' => [ 'thresholds' => [ 'breast_exposed' => 0.3 ], 'suspect' => 0.2 ],
	];

	private static function regions( float ...$scores ): array {
		return [
			'status' => 'ok',
			'min_score' => 0.2,
			'regions' => array_map( static fn ( float $score ) => [ 'label' => 'breast_exposed', 'score' => $score, 'frame' => 0, 'box' => [ 0, 0, 1, 1 ] ], $scores ),
		];
	}

	public function test_one_doubtful_check_allows_and_is_reported(): void {
		$result = $this->new_screener(
			self::response( [ 'nsfw' => self::nsfw( 0.95 ), 'nudity_regions' => self::regions() ] ),
			[ 'UploadSafetyChecks' => self::BOTH_CHECKS ]
		)->screen( $this->png_path, 'image/png' );

		$this->assertFalse( $result->rejected );
		$this->assertSame( CheckVerdict::Suspect, $result->checks['nsfw']->verdict );
		$this->assertSame( [ 'nsfw' ], array_keys( $result->suspect_checks() ) );
		$this->assertSame( [], $result->rejecting_checks() );
		$this->assertTrue( $result->cacheable() );
	}

	public function test_doubtful_checks_together_reject(): void {
		$result = $this->new_screener(
			self::response( [ 'nsfw' => self::nsfw( 0.6 ), 'nudity_regions' => self::regions( 0.25 ) ] ),
			[ 'UploadSafetyChecks' => self::BOTH_CHECKS ]
		)->screen( $this->png_path, 'image/png' );

		$this->assertTrue( $result->rejected );
		$this->assertSame( UploadScreener::MSG_REJECTED, $result->message_key );
		$this->assertSame( [ 'nsfw', 'nudity_regions' ], array_keys( $result->rejecting_checks() ) );
		$this->assertSame( [], $result->suspect_checks() );
		$this->assertStringContainsString( 'frames=1/1/1 suspects=2', $result->debug_summary() );
		$this->assertTrue( $result->cacheable() );
	}

	public function test_one_check_over_its_threshold_rejects_alone(): void {
		$result = $this->new_screener(
			self::response( [ 'nsfw' => self::nsfw( 0.0 ), 'nudity_regions' => self::regions( 0.31 ) ] ),
			[ 'UploadSafetyChecks' => self::BOTH_CHECKS ]
		)->screen( $this->png_path, 'image/png' );

		$this->assertTrue( $result->rejected );
		$this->assertSame( [ 'nudity_regions' ], array_keys( $result->rejecting_checks() ) );
	}

	public function test_one_doubtful_check_rejects_when_configured(): void {
		$result = $this->new_screener(
			self::response( [ 'nsfw' => self::nsfw( 0.6 ), 'nudity_regions' => self::regions() ] ),
			[ 'UploadSafetyChecks' => self::BOTH_CHECKS, 'UploadSafetySuspectsToReject' => 1 ]
		)->screen( $this->png_path, 'image/png' );

		$this->assertTrue( $result->rejected );
		$this->assertSame( [ 'nsfw' ], array_keys( $result->rejecting_checks() ) );
	}

	public function test_doubtful_check_with_an_unavailable_one_follows_fail_policy(): void {
		$response = self::response( [ 'nsfw' => self::nsfw( 0.6 ), 'nudity_regions' => [ 'status' => 'unsupported' ] ] );

		$closed = $this->new_screener( $response, [ 'UploadSafetyChecks' => self::BOTH_CHECKS ] )->screen( $this->png_path, 'image/png' );
		$this->assertTrue( $closed->rejected );
		$this->assertSame( UploadScreener::MSG_UNAVAILABLE, $closed->message_key );
		$this->assertSame( [], $closed->rejecting_checks(), 'rejected because a check was unavailable, not by the checks' );

		$open = $this->new_screener( $response, [ 'UploadSafetyChecks' => self::BOTH_CHECKS, 'UploadSafetyFailClosed' => false ] )
			->screen( $this->png_path, 'image/png' );
		$this->assertFalse( $open->rejected );
		$this->assertSame( [ 'nsfw' ], array_keys( $open->suspect_checks() ) );
	}

	public function test_reject_wins_over_unavailable(): void {
		$result = $this->new_screener(
			self::response( [ 'nsfw' => self::nsfw( 0.9 ), 'ocr' => [ 'status' => 'unsupported' ] ] ),
			[ 'UploadSafetyChecks' => [ 'ocr' => [], 'nsfw' => [] ] ]
		)->screen( $this->png_path, 'image/png' );

		$this->assertTrue( $result->rejected );
		$this->assertSame( UploadScreener::MSG_REJECTED, $result->message_key );
	}

	public static function provide_unavailable_signals(): iterable {
		yield 'missing from response' => [ [], 'reason=missing-from-response' ];
		yield 'unsupported' => [ [ 'nsfw' => [ 'status' => 'unsupported' ] ], 'reason=unsupported-by-sidecar' ];
		yield 'check error' => [
			[ 'nsfw' => [ 'status' => 'error', 'error' => [ 'code' => 'inference_failed', 'message' => 'x' ] ] ],
			'reason=check-error code=inference_failed',
		];
		yield 'unknown status' => [ [ 'nsfw' => [ 'status' => 'maybe' ] ], 'reason=invalid-status' ];
		yield 'malformed signal' => [ [ 'nsfw' => [ 'status' => 'ok', 'score' => 'high' ] ], 'reason=invalid-signal' ];
	}

	/**
	 * @dataProvider provide_unavailable_signals
	 */
	public function test_unavailable_signal_follows_fail_policy( array $checks, string $reason ): void {
		$closed = $this->new_screener( self::response( $checks ) )->screen( $this->png_path, 'image/png' );
		$this->assertTrue( $closed->rejected );
		$this->assertSame( UploadScreener::MSG_UNAVAILABLE, $closed->message_key );
		$this->assertStringContainsString( $reason, $closed->debug_summary() );

		$open = $this->new_screener( self::response( $checks ), [ 'UploadSafetyFailClosed' => false ] )
			->screen( $this->png_path, 'image/png' );
		$this->assertFalse( $open->rejected );
		$this->assertStringStartsWith( 'fail=open', $open->debug_summary() );
	}

	public function test_check_without_evaluator_is_unavailable(): void {
		$result = $this->new_screener(
			self::response( [ 'typo' => [ 'status' => 'ok' ] ] ),
			[ 'UploadSafetyChecks' => [ 'typo' => [] ] ]
		)->screen( $this->png_path, 'image/png' );

		$this->assertTrue( $result->rejected );
		$this->assertStringContainsString( 'reason=unknown-check', $result->debug_summary() );
	}

	public function test_sidecar_failure_follows_fail_policy(): void {
		$error = new SidecarUnavailableException( 'reason=http-error status=503' );

		$closed = $this->new_screener( $error )->screen( $this->png_path, 'image/png' );
		$this->assertTrue( $closed->rejected );
		$this->assertSame( 'fail=closed reason=http-error status=503', $closed->debug_summary() );

		$this->assertFalse(
			$this->new_screener( $error, [ 'UploadSafetyFailClosed' => false ] )->screen( $this->png_path, 'image/png' )->rejected
		);
	}

	public function test_always_reject_skips_sidecar(): void {
		$result = $this->new_screener( null, [ 'UploadSafetyAlwaysReject' => true ] )->screen( $this->png_path, 'image/png' );

		$this->assertTrue( $result->rejected );
	}

	public function test_no_checks_skips_sidecar(): void {
		$result = $this->new_screener( null, [ 'UploadSafetyChecks' => [] ] )->screen( $this->png_path, 'image/png' );

		$this->assertFalse( $result->rejected );
		$this->assertTrue( $result->skipped() );
	}

	public function test_enabled_checks_accepts_shorthand_and_disabled_entries(): void {
		$screener = $this->new_screener( null, [ 'UploadSafetyChecks' => [ 'nsfw', 'ocr' => false, 'qr' => [ 'x' => 1 ] ] ] );

		$this->assertSame( [ 'nsfw' => [], 'qr' => [ 'x' => 1 ] ], $screener->enabled_checks() );
	}

	public function test_unreadable_file_follows_fail_policy(): void {
		$path = $this->png_path . '-missing';

		$this->assertTrue( $this->new_screener( null )->screen( $path, 'image/png' )->rejected );
		$this->assertFalse(
			$this->new_screener( null, [ 'UploadSafetyFailClosed' => false ] )->screen( $path, 'image/png' )->rejected
		);
	}

	public function test_non_raster_content_under_a_raster_mime_follows_fail_policy(): void {
		file_put_contents( $this->png_path, '<svg xmlns="http://www.w3.org/2000/svg"/>' );

		$this->assertTrue( $this->new_screener( null )->screen( $this->png_path, 'image/png' )->rejected );
		$this->assertFalse( $this->new_screener( null, [ 'UploadSafetyFailClosed' => false ] )->screen( $this->png_path, 'image/png' )->rejected );
	}

	public function test_non_raster_content_without_a_mime_is_skipped(): void {
		file_put_contents( $this->png_path, '<svg xmlns="http://www.w3.org/2000/svg"/>' );
		$result = $this->new_screener( null )->screen( $this->png_path );

		$this->assertFalse( $result->rejected );
		$this->assertTrue( $result->skipped() );
	}

	public function test_avif_is_screened(): void {
		// 8x8 AVIF written by Pillow
		file_put_contents( $this->png_path, base64_decode( 'AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAADrbWV0YQAAAAAAAAAhaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAAAAAAAAAOcGl0bQAAAAAAAQAAAB5pbG9jAAAAAEQAAAEAAQAAAAEAAAETAAAAJwAAAChpaW5mAAAAAAABAAAAGmluZmUCAAAAAAEAAGF2MDFDb2xvcgAAAABqaXBycAAAAEtpcGNvAAAAFGlzcGUAAAAAAAAACAAAAAgAAAAQcGl4aQAAAAADCAgIAAAADGF2MUOBAAwAAAAAE2NvbHJuY2x4AAEADQAGgAAAABdpcG1hAAAAAAAAAAEAAQQBAoMEAAAAL21kYXQSAAoIGAi/aICGg0IyGRlHh4Yhh5555oAAAJBAyRxhSgnBjgTNEYw=' ) );
		$result = $this->new_screener( self::response( [ 'nsfw' => self::nsfw( 0.9 ) ] ) )->screen( $this->png_path, 'image/avif' );

		$this->assertTrue( $result->rejected );
	}

	public function test_non_screenable_mime_is_skipped(): void {
		$result = $this->new_screener( null )->screen( $this->png_path, 'image/svg+xml' );

		$this->assertFalse( $result->rejected );
		$this->assertTrue( $result->skipped() );
	}

	public function test_rejection_error_hides_detail_unless_debug(): void {
		$response = self::response( [ 'nsfw' => self::nsfw( 0.9 ) ] );

		$screener = $this->new_screener( $response );
		$this->assertSame(
			[ UploadScreener::MSG_REJECTED ],
			$screener->rejection_error( $screener->screen( $this->png_path, 'image/png' ) )
		);

		$screener = $this->new_screener( $response, [ 'UploadSafetyDebug' => true ] );
		$error = $screener->rejection_error( $screener->screen( $this->png_path, 'image/png' ) );
		$this->assertSame( UploadScreener::MSG_REJECTED . '-debug', $error[0] );
		$this->assertStringContainsString( 'check=nsfw verdict=reject threshold=0.5 frame=0', $error[1] );
	}

	public function test_auto_screens(): void {
		$screener = $this->new_screener( null, [ 'UploadSafetyAutoScreen' => [ 'upload' => true, 'avatar' => false ] ] );

		$this->assertTrue( $screener->auto_screens( 'upload' ) );
		$this->assertFalse( $screener->auto_screens( 'avatar' ) );
		$this->assertTrue( $screener->auto_screens( 'banner' ), 'unlisted surfaces are screened' );
	}

}
