<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\UploadSafety\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\UploadSafety\AutoScreening;
use MediaWiki\Extension\UploadSafety\Check\NsfwEvaluator;
use MediaWiki\Extension\UploadSafety\RejectionLog;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarClient;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarResponse;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarUnavailableException;
use MediaWiki\Extension\UploadSafety\UploadScreener;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Permissions\RateLimiter;
use MediaWiki\Permissions\RateLimitSubject;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @group UploadSafety
 * @covers \MediaWiki\Extension\UploadSafety\AutoScreening
 * @covers \MediaWiki\Extension\UploadSafety\ScreeningResult
 * @covers \MediaWiki\Extension\UploadSafety\Check\CheckResult
 */
class AutoScreeningTest extends MediaWikiUnitTestCase {

	private const PNG_1X1 = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89" . "\x00\x00\x00\rIDATx\xdac\xfc\xcf\xc0P\x0f\x00\x04\x85\x01\x80\x84\xa9\x8c!\x00\x00\x00\x00IEND\xaeB`\x82";

	private string $png_path;

	/** @var array<string, int> Counter per rate limit action */
	private array $counts = [];

	/** @var array<string, int> Limit per rate limit action */
	private array $limits = [];

	/** @var list<float|SidecarUnavailableException> Scores the sidecar returns, in order */
	private array $scores = [];

	private int $sidecar_calls = 0;

	/** @var list<array{0: string, 1: array}> Logged [action, args] */
	private array $logged = [];

	private WANObjectCache $cache;

	protected function setUp(): void {
		parent::setUp();
		$this->png_path = tempnam( sys_get_temp_dir(), 'us-auto-' );
		file_put_contents( $this->png_path, self::PNG_1X1 );
		$this->cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$this->limits = [
			AutoScreening::LIMIT_SCREEN => 100,
			AutoScreening::LIMIT_REJECT => 3,
			AutoScreening::LIMIT_REJECT_DAILY => 5,
		];
	}

	protected function tearDown(): void {
		if ( is_file( $this->png_path ) ) {
			unlink( $this->png_path );
		}
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function new_auto( array $config = [] ): AutoScreening {
		$sidecar = $this->createMock( SidecarClient::class );
		$sidecar->method( 'check' )->willReturnCallback( function () {
			$this->sidecar_calls++;
			$score = array_shift( $this->scores ) ?? 0.0;
			if ( $score instanceof SidecarUnavailableException ) {
				throw $score;
			}

			return new SidecarResponse( [ 'nsfw' => [ 'status' => 'ok', 'score' => $score, 'frame' => 0 ] ] );
		} );

		$options = new ServiceOptions( UploadScreener::CONSTRUCTOR_OPTIONS, new HashConfig( $config + [
			'UploadSafetyAlwaysReject' => false,
			'UploadSafetyDebug' => false,
			'UploadSafetyFailClosed' => true,
			'UploadSafetyChecks' => [ 'nsfw' => [ 'threshold' => 0.5 ] ],
			'UploadSafetyAutoScreen' => [],
			'UploadSafetyMaxFrames' => 64,
			'UploadSafetySuspectsToReject' => 2,
		] ) );
		$screener = new UploadScreener( $options, $sidecar, [ new NsfwEvaluator() ], new NullLogger() );

		$rate_limiter = $this->createMock( RateLimiter::class );
		$rate_limiter->method( 'limit' )->willReturnCallback( function ( $subject, string $action, int $incr = 1 ) {
			$count = ( $this->counts[$action] ?? 0 ) + max( $incr, 1 );
			if ( $incr > 0 ) {
				$this->counts[$action] = $count;
			}

			return $count > $this->limits[$action];
		} );

		$log = $this->createMock( RejectionLog::class );
		foreach ( [ 'reject', 'suspect', 'limited' ] as $action ) {
			$log->method( $action )->willReturnCallback( function ( ...$args ) use ( $action ) {
				$this->logged[] = [ $action, $args ];
			} );
		}

		return new AutoScreening( $screener, $rate_limiter, $this->cache, $log );
	}

	private function performer(): User {
		$user = $this->createMock( User::class );
		$user->method( 'getName' )->willReturn( 'Uploader' );
		$user->method( 'toRateLimitSubject' )->willReturn(
			new RateLimitSubject( new UserIdentityValue( 1, 'Uploader' ), '127.0.0.1', [] )
		);

		return $user;
	}

	private function upload( AutoScreening $auto, string $contents = self::PNG_1X1 ): \MediaWiki\Extension\UploadSafety\ScreeningResult {
		file_put_contents( $this->png_path, $contents );

		return $auto->screen(
			$this->performer(),
			$this->png_path,
			'image/png',
			'Test.png',
			'upload',
			PageReferenceValue::localReference( NS_FILE, 'Test.png' )
		);
	}

	/**
	 * A different valid PNG per $n, so each one is a new file for the verdict cache.
	 */
	private static function png( int $n ): string {
		return self::PNG_1X1 . str_repeat( "\0", $n );
	}

	public function test_clean_upload_is_allowed_and_only_counted_towards_the_screen_limit(): void {
		$result = $this->upload( $this->new_auto() );

		$this->assertFalse( $result->rejected );
		$this->assertSame( [ AutoScreening::LIMIT_SCREEN => 1 ], $this->counts );
		$this->assertSame( [], $this->logged );
	}

	public function test_rejection_is_logged_and_counted(): void {
		$this->scores = [ 0.9 ];
		$result = $this->upload( $this->new_auto() );

		$this->assertTrue( $result->rejected );
		$this->assertSame( 1, $this->counts[AutoScreening::LIMIT_REJECT] );
		$this->assertSame( 1, $this->counts[AutoScreening::LIMIT_REJECT_DAILY] );

		[ $action, $args ] = $this->logged[0];
		$this->assertSame( 'reject', $action );
		$this->assertSame( 'upload', $args[2] );
		$this->assertSame( sha1( self::PNG_1X1 ), $args[4] );
		$this->assertFalse( $args[5], 'not a repeat' );
	}

	public function test_doubtful_upload_is_allowed_and_logged_for_review(): void {
		$auto = $this->new_auto( [ 'UploadSafetyChecks' => [ 'nsfw' => [ 'threshold' => 0.98, 'suspect' => 0.5 ] ] ] );
		$this->scores = [ 0.7 ];
		$result = $this->upload( $auto );
		$this->upload( $auto );

		$this->assertFalse( $result->rejected );
		$this->assertArrayNotHasKey( AutoScreening::LIMIT_REJECT, $this->counts );
		$this->assertSame( [ 'suspect', 'suspect' ], array_column( $this->logged, 0 ) );
		$this->assertFalse( $this->logged[0][1][5], 'not a repeat' );
		$this->assertTrue( $this->logged[1][1][5], 'cached verdict logged as a repeat' );
		$this->assertSame( 1, $this->sidecar_calls );
	}

	public function test_doubtful_checks_that_reject_are_logged_and_counted_as_rejections(): void {
		$auto = $this->new_auto( [
			'UploadSafetyChecks' => [ 'nsfw' => [ 'threshold' => 0.98, 'suspect' => 0.5 ] ],
			'UploadSafetySuspectsToReject' => 1,
		] );
		$this->scores = [ 0.7 ];

		$this->assertTrue( $this->upload( $auto )->rejected );
		$this->assertSame( [ 'reject' ], array_column( $this->logged, 0 ) );
		$this->assertSame( 1, $this->counts[AutoScreening::LIMIT_REJECT] );
	}

	public function test_same_file_reuses_the_verdict_without_counting(): void {
		$auto = $this->new_auto();
		$this->scores = [ 0.9 ];
		$this->upload( $auto );
		$result = $this->upload( $auto );

		$this->assertTrue( $result->rejected );
		$this->assertStringStartsWith( 'cached=1', $result->debug_summary() );
		$this->assertSame( 1, $this->sidecar_calls );
		$this->assertSame( 1, $this->counts[AutoScreening::LIMIT_REJECT] );
		$this->assertTrue( $this->logged[1][1][5], 'logged as a repeat' );
	}

	public function test_changing_checks_invalidates_cached_verdicts(): void {
		$this->scores = [ 0.9, 0.9 ];
		$this->upload( $this->new_auto() );
		$result = $this->upload( $this->new_auto( [ 'UploadSafetyChecks' => [ 'nsfw' => [ 'threshold' => 0.95 ] ] ] ) );

		$this->assertFalse( $result->rejected );
		$this->assertSame( 2, $this->sidecar_calls );
	}

	public function test_unavailable_verdicts_are_not_cached_or_counted(): void {
		$auto = $this->new_auto();
		$this->scores = [ new SidecarUnavailableException( 'reason=request-failed' ), 0.1 ];

		$this->assertTrue( $this->upload( $auto )->rejected, 'fail closed' );
		$this->assertFalse( $this->upload( $auto )->rejected );
		$this->assertSame( 2, $this->sidecar_calls );
		$this->assertArrayNotHasKey( AutoScreening::LIMIT_REJECT, $this->counts );
		$this->assertSame( [], $this->logged );
	}

	public function test_reaching_the_reject_limit_logs_once_and_refuses_further_uploads(): void {
		$auto = $this->new_auto();
		$this->scores = [ 0.9, 0.9, 0.9 ];
		foreach ( [ 1, 2, 3 ] as $n ) {
			$this->assertSame( UploadScreener::MSG_REJECTED, $this->upload( $auto, self::png( $n ) )->message_key );
		}

		$limited = array_values( array_filter( $this->logged, static fn ( $entry ) => $entry[0] === 'limited' ) );
		$this->assertCount( 1, $limited );
		$this->assertSame( AutoScreening::LIMIT_REJECT, $limited[0][1][3] );

		$result = $this->upload( $auto, self::png( 4 ) );
		$this->assertTrue( $result->rejected );
		$this->assertSame( AutoScreening::MSG_REJECT_LIMITED, $result->message_key );
		$this->assertSame( 3, $this->sidecar_calls, 'refused without screening' );
	}

	public function test_screen_limit_refuses_new_files(): void {
		$this->limits[AutoScreening::LIMIT_SCREEN] = 1;
		$auto = $this->new_auto();

		$this->assertFalse( $this->upload( $auto, self::png( 1 ) )->rejected );
		$this->assertFalse( $this->upload( $auto, self::png( 1 ) )->rejected, 'cached verdicts are not screened again' );

		$result = $this->upload( $auto, self::png( 2 ) );
		$this->assertTrue( $result->rejected );
		$this->assertSame( AutoScreening::MSG_SCREEN_LIMITED, $result->message_key );
		$this->assertSame( 1, $this->sidecar_calls );
	}

	public function test_always_reject_is_not_logged_or_counted(): void {
		$result = $this->upload( $this->new_auto( [ 'UploadSafetyAlwaysReject' => true ] ) );

		$this->assertTrue( $result->rejected );
		$this->assertArrayNotHasKey( AutoScreening::LIMIT_REJECT, $this->counts );
		$this->assertSame( [], $this->logged );
	}

	public function test_last_approved_debug_summary(): void {
		$auto = $this->new_auto();
		$this->upload( $auto );
		$this->assertNull( $auto->last_approved_debug_summary(), 'debug off' );

		$auto = $this->new_auto( [ 'UploadSafetyDebug' => true ] );
		$this->upload( $auto, self::png( 1 ) );
		$this->assertStringStartsWith( 'rejected=0 check=nsfw verdict=pass', (string)$auto->last_approved_debug_summary() );
	}

}
