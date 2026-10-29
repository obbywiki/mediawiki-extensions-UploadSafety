<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\UploadSafety\Tests\Unit;

use MediaWiki\Extension\UploadSafety\SvgUploadHooks;
use MediaWiki\Upload\UploadBase;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use Wikimedia\Mime\MimeAnalyzer;

/**
 * @group UploadSafety
 * @covers \MediaWiki\Extension\UploadSafety\SvgUploadHooks
 */
class SvgUploadHooksTest extends MediaWikiUnitTestCase {

	private string $path;

	protected function setUp(): void {
		parent::setUp();
		$this->path = tempnam( sys_get_temp_dir(), 'us-svg-' );
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
	 * @param string $dest_name
	 * @param bool $allowed Whether the uploader has the right
	 * @param string|null $guessed MIME type the analyzer guesses, null when it must not be asked
	 * @return array{0: bool|null, 1: mixed} Hook return value and error
	 */
	private function run_hook( ?array $props, string $dest_name, bool $allowed, ?string $guessed = null ): array {
		$mime_analyzer = $this->createMock( MimeAnalyzer::class );
		if ( $guessed === null ) {
			$mime_analyzer->expects( $this->never() )->method( 'guessMimeType' );
		} else {
			$mime_analyzer->method( 'guessMimeType' )->willReturn( $guessed );
		}

		$upload = $this->createMock( UploadBase::class );
		$upload->method( 'getTempPath' )->willReturn( $this->path );
		$upload->method( 'getDesiredDestName' )->willReturn( $dest_name );

		$user = $this->createMock( User::class );
		$user->method( 'isAllowed' )->willReturnCallback( static fn ( string $right ) => $allowed && $right === SvgUploadHooks::RIGHT );

		$error = null;
		$ret = ( new SvgUploadHooks( $mime_analyzer ) )->onUploadVerifyUpload( $upload, $user, $props, '', false, $error );

		return [ $ret, $error ];
	}

	public function test_svg_is_refused_without_the_right(): void {
		[ $ret, $error ] = $this->run_hook( [ 'mime' => 'image/svg+xml' ], 'Icon.svg', false );

		$this->assertFalse( $ret );
		$this->assertSame( [ SvgUploadHooks::MSG_RESTRICTED ], $error );
	}

	public function test_svg_is_allowed_with_the_right(): void {
		[ $ret, $error ] = $this->run_hook( [ 'mime' => 'image/svg+xml' ], 'Icon.svg', true );

		$this->assertNotFalse( $ret );
		$this->assertNull( $error );
	}

	public function test_svg_mime_is_refused_whatever_the_name(): void {
		[ $ret ] = $this->run_hook( [ 'mime' => 'image/svg+xml' ], 'Icon.png', false );

		$this->assertFalse( $ret );
	}

	public function test_svg_extension_is_refused_whatever_the_mime(): void {
		[ $ret ] = $this->run_hook( [ 'mime' => 'image/png' ], 'Icon.SVGZ', false );

		$this->assertFalse( $ret );
	}

	public function test_mime_is_guessed_when_props_are_missing(): void {
		[ $ret ] = $this->run_hook( null, 'Icon', false, 'image/svg+xml' );

		$this->assertFalse( $ret );
	}

	public function test_raster_upload_is_unaffected(): void {
		[ $ret, $error ] = $this->run_hook( [ 'mime' => 'image/png' ], 'Photo.png', false );

		$this->assertNotFalse( $ret );
		$this->assertNull( $error );
	}

}
