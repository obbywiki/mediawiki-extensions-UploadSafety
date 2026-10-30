<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\UploadSafety\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\UploadSafety\UnscreenedFormats;
use MediaWiki\Extension\UploadSafety\UnscreenedUploadHooks;
use MediaWiki\Upload\UploadBase;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use Wikimedia\Mime\MimeAnalyzer;

/**
 * @group UploadSafety
 * @covers \MediaWiki\Extension\UploadSafety\UnscreenedUploadHooks
 * @covers \MediaWiki\Extension\UploadSafety\UnscreenedFormats
 */
class UnscreenedUploadHooksTest extends MediaWikiUnitTestCase {

	private string $path;

	protected function setUp(): void {
		parent::setUp();
		$this->path = tempnam( sys_get_temp_dir(), 'us-unscreened-' );
		file_put_contents( $this->path, '<svg xmlns="http://www.w3.org/2000/svg"/>' );
	}

	protected function tearDown(): void {
		if ( is_file( $this->path ) ) {
			unlink( $this->path );
		}
		parent::tearDown();
	}

	/**
	 * @param array|null $props
	 * @param bool $has_right Whether the uploader has uploadsafety-upload-unscreened
	 * @param array<string, mixed> $config
	 * @param string|null $guessed MIME type the analyzer guesses, null when it must not be asked
	 * @return array{0: bool|null, 1: mixed} Hook return value and error
	 */
	private function run_hook( ?array $props, bool $has_right = false, array $config = [], ?string $guessed = null ): array {
		$mime_analyzer = $this->createMock( MimeAnalyzer::class );
		if ( $guessed === null ) {
			$mime_analyzer->expects( $this->never() )->method( 'guessMimeType' );
		} else {
			$mime_analyzer->method( 'guessMimeType' )->willReturn( $guessed );
		}

		$formats = new UnscreenedFormats( new ServiceOptions( UnscreenedFormats::CONSTRUCTOR_OPTIONS, new HashConfig( $config + [
			'UploadSafetyBlockUnscreened' => true,
			'UploadSafetyUnscreenedAllowed' => [],
		] ) ) );

		$upload = $this->createMock( UploadBase::class );
		$upload->method( 'getTempPath' )->willReturn( $this->path );

		$user = $this->createMock( User::class );
		$user->method( 'isAllowed' )->willReturnCallback( static fn ( string $right ) => $has_right && $right === UnscreenedFormats::RIGHT );

		$error = null;
		$ret = ( new UnscreenedUploadHooks( $formats, $mime_analyzer ) )->onUploadVerifyUpload( $upload, $user, $props, '', false, $error );

		return [ $ret, $error ];
	}

	public static function provide_unscreened(): array {
		return [
			'svg' => [ 'image/svg+xml' ],
			'pdf' => [ 'application/pdf' ],
			'video' => [ 'video/webm' ],
			'heic' => [ 'image/heic' ],
		];
	}

	/**
	 * @dataProvider provide_unscreened
	 */
	public function test_unscreened_format_is_refused_without_the_right( string $mime ): void {
		[ $ret, $error ] = $this->run_hook( [ 'mime' => $mime ] );

		$this->assertFalse( $ret );
		$this->assertSame( [ UnscreenedFormats::MSG_RESTRICTED ], $error );
	}

	public function test_unscreened_format_is_allowed_with_the_right(): void {
		[ $ret, $error ] = $this->run_hook( [ 'mime' => 'image/svg+xml' ], true );

		$this->assertNotFalse( $ret );
		$this->assertNull( $error );
	}

	public function test_screenable_format_is_left_to_screening(): void {
		[ $ret, $error ] = $this->run_hook( [ 'mime' => 'image/avif' ] );

		$this->assertNotFalse( $ret );
		$this->assertNull( $error );
	}

	public function test_blocking_can_be_turned_off(): void {
		[ $ret ] = $this->run_hook( [ 'mime' => 'image/svg+xml' ], false, [ 'UploadSafetyBlockUnscreened' => false ] );

		$this->assertNotFalse( $ret );
	}

	public function test_allowed_formats_are_open_to_everyone(): void {
		$config = [ 'UploadSafetyUnscreenedAllowed' => [ 'audio/*', 'application/pdf' ] ];

		$this->assertNotFalse( $this->run_hook( [ 'mime' => 'audio/ogg' ], false, $config )[0] );
		$this->assertNotFalse( $this->run_hook( [ 'mime' => 'Application/PDF' ], false, $config )[0] );
		$this->assertFalse( $this->run_hook( [ 'mime' => 'image/svg+xml' ], false, $config )[0] );
		$this->assertFalse( $this->run_hook( [ 'mime' => 'audiox/ogg' ], false, $config )[0] );
	}

	public function test_mime_is_guessed_when_props_are_missing(): void {
		[ $ret ] = $this->run_hook( null, false, [], 'image/svg+xml' );

		$this->assertFalse( $ret );
	}

	public function test_unknown_mime_is_refused(): void {
		[ $ret ] = $this->run_hook( [ 'mime' => 'unknown/unknown' ] );

		$this->assertFalse( $ret );
	}

}
