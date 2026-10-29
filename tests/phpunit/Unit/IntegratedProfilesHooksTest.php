<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\UploadSafety\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\IntegratedProfiles\Hook\IntegratedProfilesVerifyImageUploadHook;
use MediaWiki\Extension\UploadSafety\Check\NsfwEvaluator;
use MediaWiki\Extension\UploadSafety\IntegratedProfilesHooks;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarClient;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarResponse;
use MediaWiki\Extension\UploadSafety\UploadScreener;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;

/**
 * @group UploadSafety
 * @covers \MediaWiki\Extension\UploadSafety\IntegratedProfilesHooks
 * @covers \MediaWiki\Extension\UploadSafety\UploadScreener
 */
class IntegratedProfilesHooksTest extends MediaWikiUnitTestCase {

	private const PNG_1X1 = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89" . "\x00\x00\x00\rIDATx\xdac\xfc\xcf\xc0P\x0f\x00\x04\x85\x01\x80\x84\xa9\x8c!\x00\x00\x00\x00IEND\xaeB`\x82";

	private string $png_path;

	protected function setUp(): void {
		parent::setUp();
		if ( !interface_exists( IntegratedProfilesVerifyImageUploadHook::class ) ) {
			$this->markTestSkipped( 'IntegratedProfiles is not installed' );
		}

		$this->png_path = tempnam( sys_get_temp_dir(), 'us-ip-' );
		file_put_contents( $this->png_path, self::PNG_1X1 );
	}

	protected function tearDown(): void {
		if ( isset( $this->png_path ) && is_file( $this->png_path ) ) {
			unlink( $this->png_path );
		}
		parent::tearDown();
	}

	/**
	 * @param float|null $score Null when the sidecar must not be called
	 * @param array<string, mixed> $config
	 * @param array<int, mixed> &$calls Arguments of each sidecar call
	 */
	private function new_hooks( ?float $score, array $config = [], array &$calls = [] ): IntegratedProfilesHooks {
		$sidecar = $this->createMock( SidecarClient::class );
		if ( $score === null ) {
			$sidecar->expects( $this->never() )->method( 'check' );
		} else {
			$sidecar->method( 'check' )->willReturnCallback( static function ( ...$args ) use ( $score, &$calls ) {
				$calls[] = $args;

				return new SidecarResponse( [ 'nsfw' => [ 'status' => 'ok', 'score' => $score, 'frame' => 0 ] ] );
			} );
		}

		$options = new ServiceOptions( UploadScreener::CONSTRUCTOR_OPTIONS, new HashConfig( $config + [
			'UploadSafetyAlwaysReject' => false,
			'UploadSafetyDebug' => false,
			'UploadSafetyFailClosed' => true,
			'UploadSafetyChecks' => [ 'nsfw' => [ 'threshold' => 0.5 ] ],
			'UploadSafetyAutoScreen' => [ 'upload' => true, 'avatar' => true, 'banner' => true ],
		] ) );

		return new IntegratedProfilesHooks(
			new UploadScreener( $options, $sidecar, [ new NsfwEvaluator() ], new NullLogger() )
		);
	}

	public function test_clean_avatar_is_allowed_and_sent_with_surface(): void {
		$calls = [];
		$error = null;
		$ret = $this->new_hooks( 0.1, [], $calls )->onIntegratedProfilesVerifyImageUpload(
			'avatar', $this->png_path, 'image/png', 42, new UserIdentityValue( 7, 'Kid' ), $error
		);

		$this->assertNotFalse( $ret );
		$this->assertNull( $error );
		$this->assertSame( [ $this->png_path, [ 'nsfw' ], 'image/png', 'avatar' ], $calls[0] );
	}

	public function test_flagged_banner_is_rejected(): void {
		$error = null;
		$ret = $this->new_hooks( 0.9 )->onIntegratedProfilesVerifyImageUpload(
			'banner', $this->png_path, 'image/png', 42, null, $error
		);

		$this->assertFalse( $ret );
		$this->assertSame( [ UploadScreener::MSG_REJECTED ], $error );
	}

	public function test_surface_can_be_left_to_another_caller(): void {
		$error = null;
		$ret = $this->new_hooks( null, [ 'UploadSafetyAutoScreen' => [ 'avatar' => false ] ] )
			->onIntegratedProfilesVerifyImageUpload( 'avatar', $this->png_path, 'image/png', 42, null, $error );

		$this->assertNotFalse( $ret );
		$this->assertNull( $error );
	}

	public function test_unlisted_surface_is_screened(): void {
		$error = null;
		$ret = $this->new_hooks( 0.9, [ 'UploadSafetyAutoScreen' => [] ] )
			->onIntegratedProfilesVerifyImageUpload( 'background', $this->png_path, 'image/png', 42, null, $error );

		$this->assertFalse( $ret );
	}

}
