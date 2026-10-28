<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\UploadSafety\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\UploadSafety\Check\CheckResult;
use MediaWiki\Extension\UploadSafety\Check\CheckVerdict;
use MediaWiki\Extension\UploadSafety\Check\ImageCheck;
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
	 * @param list<ImageCheck> $checks
	 * @param array<string, mixed> $config
	 */
	private function new_screener( array $checks, array $config = [] ): UploadScreener {
		$options = new ServiceOptions( UploadScreener::CONSTRUCTOR_OPTIONS, new HashConfig( $config + [
			'UploadSafetyAlwaysReject' => false,
			'UploadSafetyDebug' => false,
			'UploadSafetyFailClosed' => true,
		] ) );

		return new UploadScreener( $options, $checks, new NullLogger() );
	}

	private function check( string $name, CheckVerdict $verdict, ?callable $on_run = null ): ImageCheck {
		return new class( $name, $verdict, $on_run ) implements ImageCheck {

			public function __construct(
				private readonly string $name,
				private readonly CheckVerdict $verdict,
				private readonly mixed $on_run,
			) {
			}

			public function name(): string {
				return $this->name;
			}

			public function check( string $file_path, string $mime ): CheckResult {
				if ( $this->on_run !== null ) {
					( $this->on_run )();
				}

				return new CheckResult( $this->verdict, 'label', 0.5, [], 'from=' . $this->name );
			}

		};
	}

	public function test_all_passing_checks_allow(): void {
		$result = $this->new_screener( [
			$this->check( 'a', CheckVerdict::Pass ),
			$this->check( 'b', CheckVerdict::Pass ),
		] )->screen( $this->png_path, 'image/png' );

		$this->assertFalse( $result->rejected );
		$this->assertSame( [ 'a', 'b' ], array_keys( $result->checks ) );
	}

	public function test_rejecting_check_rejects_and_stops(): void {
		$ran_after = false;
		$result = $this->new_screener( [
			$this->check( 'a', CheckVerdict::Reject ),
			$this->check( 'b', CheckVerdict::Pass, static function () use ( &$ran_after ) {
				$ran_after = true;
			} ),
		] )->screen( $this->png_path, 'image/png' );

		$this->assertTrue( $result->rejected );
		$this->assertSame( UploadScreener::MSG_REJECTED, $result->message_key );
		$this->assertFalse( $ran_after );
	}

	public function test_unavailable_check_rejects_when_fail_closed(): void {
		$result = $this->new_screener( [ $this->check( 'a', CheckVerdict::Unavailable ) ] )
			->screen( $this->png_path, 'image/png' );

		$this->assertTrue( $result->rejected );
		$this->assertSame( UploadScreener::MSG_UNAVAILABLE, $result->message_key );
		$this->assertStringStartsWith( 'fail=closed', $result->debug_summary() );
	}

	public function test_unavailable_check_continues_when_fail_open(): void {
		$result = $this->new_screener(
			[ $this->check( 'a', CheckVerdict::Unavailable ), $this->check( 'b', CheckVerdict::Reject ) ],
			[ 'UploadSafetyFailClosed' => false ]
		)->screen( $this->png_path, 'image/png' );

		$this->assertTrue( $result->rejected );
		$this->assertSame( UploadScreener::MSG_REJECTED, $result->message_key );
	}

	public function test_always_reject_skips_checks(): void {
		$ran = false;
		$result = $this->new_screener(
			[ $this->check( 'a', CheckVerdict::Pass, static function () use ( &$ran ) {
				$ran = true;
			} ) ],
			[ 'UploadSafetyAlwaysReject' => true ]
		)->screen( $this->png_path, 'image/png' );

		$this->assertTrue( $result->rejected );
		$this->assertFalse( $ran );
	}

	public function test_unreadable_file_follows_fail_policy(): void {
		$path = $this->png_path . '-missing';

		$this->assertTrue( $this->new_screener( [] )->screen( $path, 'image/png' )->rejected );
		$this->assertFalse(
			$this->new_screener( [], [ 'UploadSafetyFailClosed' => false ] )->screen( $path, 'image/png' )->rejected
		);
	}

	public function test_non_raster_content_is_skipped(): void {
		file_put_contents( $this->png_path, '<svg xmlns="http://www.w3.org/2000/svg"/>' );
		$result = $this->new_screener( [ $this->check( 'a', CheckVerdict::Reject ) ] )
			->screen( $this->png_path, 'image/png' );

		$this->assertFalse( $result->rejected );
		$this->assertTrue( $result->skipped() );
	}

	public function test_non_screenable_mime_is_skipped(): void {
		$result = $this->new_screener( [ $this->check( 'a', CheckVerdict::Reject ) ] )
			->screen( $this->png_path, 'image/svg+xml' );

		$this->assertFalse( $result->rejected );
		$this->assertTrue( $result->skipped() );
	}

	public function test_rejection_error_hides_detail_unless_debug(): void {
		$checks = [ $this->check( 'a', CheckVerdict::Reject ) ];

		$screener = $this->new_screener( $checks );
		$this->assertSame(
			[ UploadScreener::MSG_REJECTED ],
			$screener->rejection_error( $screener->screen( $this->png_path, 'image/png' ) )
		);

		$screener = $this->new_screener( $checks, [ 'UploadSafetyDebug' => true ] );
		$error = $screener->rejection_error( $screener->screen( $this->png_path, 'image/png' ) );
		$this->assertSame( UploadScreener::MSG_REJECTED . '-debug', $error[0] );
		$this->assertStringContainsString( 'check=a verdict=reject from=a', $error[1] );
	}

	public function test_last_approved_debug_summary(): void {
		$screener = $this->new_screener( [ $this->check( 'a', CheckVerdict::Pass ) ] );
		$screener->screen( $this->png_path, 'image/png' );
		$this->assertNull( $screener->last_approved_debug_summary(), 'debug off' );

		$screener = $this->new_screener( [ $this->check( 'a', CheckVerdict::Pass ) ], [ 'UploadSafetyDebug' => true ] );
		$screener->screen( $this->png_path, 'image/png' );
		$this->assertStringStartsWith( 'rejected=0 check=a verdict=pass', (string)$screener->last_approved_debug_summary() );

		$screener->screen( $this->png_path, 'image/svg+xml' );
		$this->assertNull( $screener->last_approved_debug_summary(), 'skipped uploads have no summary' );
	}

}
