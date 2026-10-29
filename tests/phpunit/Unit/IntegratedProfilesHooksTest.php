<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\UploadSafety\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\IntegratedProfiles\Hook\IntegratedProfilesVerifyImageUploadHook;
use MediaWiki\Extension\UploadSafety\AutoScreening;
use MediaWiki\Extension\UploadSafety\Check\CheckResult;
use MediaWiki\Extension\UploadSafety\Check\CheckVerdict;
use MediaWiki\Extension\UploadSafety\IntegratedProfilesHooks;
use MediaWiki\Extension\UploadSafety\ScreeningResult;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarClient;
use MediaWiki\Extension\UploadSafety\UploadScreener;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;

/**
 * @group UploadSafety
 * @covers \MediaWiki\Extension\UploadSafety\IntegratedProfilesHooks
 */
class IntegratedProfilesHooksTest extends MediaWikiUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( !interface_exists( IntegratedProfilesVerifyImageUploadHook::class ) ) {
			$this->markTestSkipped( 'IntegratedProfiles is not installed' );
		}
	}

	/**
	 * @param ScreeningResult|null $result Null when screening must not happen
	 * @param array<string, mixed> $config
	 * @param array<int, mixed> &$calls Arguments of each AutoScreening::screen() call
	 */
	private function new_hooks( ?ScreeningResult $result, array $config = [], array &$calls = [] ): IntegratedProfilesHooks {
		$options = new ServiceOptions( UploadScreener::CONSTRUCTOR_OPTIONS, new HashConfig( $config + [
			'UploadSafetyAlwaysReject' => false,
			'UploadSafetyDebug' => false,
			'UploadSafetyFailClosed' => true,
			'UploadSafetyChecks' => [ 'nsfw' => [] ],
			'UploadSafetyAutoScreen' => [ 'upload' => true, 'avatar' => true, 'banner' => true ],
			'UploadSafetyMaxFrames' => 64,
		] ) );
		$screener = new UploadScreener( $options, $this->createNoOpMock( SidecarClient::class ), [], new NullLogger() );

		$auto = $this->createMock( AutoScreening::class );
		if ( $result === null ) {
			$auto->expects( $this->never() )->method( 'screen' );
		} else {
			$auto->method( 'screen' )->willReturnCallback( static function ( ...$args ) use ( $result, &$calls ) {
				$calls[] = $args;

				return $result;
			} );
		}

		return new IntegratedProfilesHooks( $screener, $auto );
	}

	private static function result( CheckVerdict $verdict ): ScreeningResult {
		$rejected = $verdict === CheckVerdict::Reject;

		return new ScreeningResult( $rejected, $rejected ? UploadScreener::MSG_REJECTED : '', [ 'nsfw' => new CheckResult( $verdict ) ] );
	}

	public function test_clean_avatar_is_allowed_and_screened_as_avatar(): void {
		$calls = [];
		$error = null;
		$ret = $this->new_hooks( self::result( CheckVerdict::Pass ), [], $calls )->onIntegratedProfilesVerifyImageUpload(
			'avatar', '/tmp/x.png', 'image/png', 42, new UserIdentityValue( 7, 'Some kid' ), $error
		);

		$this->assertNotFalse( $ret );
		$this->assertNull( $error );
		$this->assertSame( [ null, '/tmp/x.png', 'image/png', 'avatar:42:Some kid', 'avatar' ], array_slice( $calls[0], 0, 5 ) );
		$this->assertTrue( PageReferenceValue::localReference( NS_USER, 'Some_kid' )->isSamePageAs( $calls[0][5] ) );
	}

	public function test_flagged_banner_is_rejected(): void {
		$error = null;
		$ret = $this->new_hooks( self::result( CheckVerdict::Reject ) )->onIntegratedProfilesVerifyImageUpload(
			'banner', '/tmp/x.png', 'image/png', 42, null, $error
		);

		$this->assertFalse( $ret );
		$this->assertSame( [ UploadScreener::MSG_REJECTED ], $error );
	}

	public function test_surface_can_be_left_to_another_caller(): void {
		$error = null;
		$ret = $this->new_hooks( null, [ 'UploadSafetyAutoScreen' => [ 'avatar' => false ] ] )
			->onIntegratedProfilesVerifyImageUpload( 'avatar', '/tmp/x.png', 'image/png', 42, null, $error );

		$this->assertNotFalse( $ret );
		$this->assertNull( $error );
	}

	public function test_unlisted_surface_is_screened(): void {
		$error = null;
		$ret = $this->new_hooks( self::result( CheckVerdict::Reject ), [ 'UploadSafetyAutoScreen' => [] ] )
			->onIntegratedProfilesVerifyImageUpload( 'background', '/tmp/x.png', 'image/png', 42, null, $error );

		$this->assertFalse( $ret );
	}

}
