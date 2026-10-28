<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\UploadSafety\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\UploadSafety\Check\CheckVerdict;
use MediaWiki\Extension\UploadSafety\Check\NsfwClassifierCheck;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;

/**
 * @group UploadSafety
 * @covers \MediaWiki\Extension\UploadSafety\Check\NsfwClassifierCheck
 */
class NsfwClassifierCheckTest extends MediaWikiUnitTestCase {

	private string $path;

	/** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
	private array $history = [];

	protected function setUp(): void {
		parent::setUp();
		$this->path = tempnam( sys_get_temp_dir(), 'us-nsfw-' );
		file_put_contents( $this->path, 'image-bytes' );
	}

	protected function tearDown(): void {
		if ( is_file( $this->path ) ) {
			unlink( $this->path );
		}
		parent::tearDown();
	}

	/**
	 * @param list<Response|\Throwable> $queue
	 */
	private function new_check( array $queue, string $api_key = '' ): NsfwClassifierCheck {
		$stack = HandlerStack::create( new MockHandler( $queue ) );
		$stack->push( Middleware::history( $this->history ) );

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'createGuzzleClient' )->willReturn( new Client( [ 'handler' => $stack ] ) );

		return new NsfwClassifierCheck(
			$factory,
			new ServiceOptions( NsfwClassifierCheck::CONSTRUCTOR_OPTIONS, new HashConfig( [
				'UploadSafetyNsfwClassifierUrl' => 'http://classifier/v1/classify',
				'UploadSafetyNsfwClassifierApiKey' => $api_key,
				'UploadSafetyNsfwThreshold' => 0.5,
				'UploadSafetyTimeout' => 5,
			] ) ),
			new NullLogger()
		);
	}

	private static function json( mixed $data, int $status = 200 ): Response {
		return new Response( $status, [ 'Content-Type' => 'application/json' ], json_encode( $data ) );
	}

	public function test_posts_image_and_rejects_nsfw(): void {
		$result = $this->new_check( [
			self::json( [ [ 'label' => 'nsfw', 'score' => 0.9 ], [ 'label' => 'normal', 'score' => 0.1 ] ] ),
		], 'secret' )->check( $this->path, 'image/png' );

		$this->assertSame( CheckVerdict::Reject, $result->verdict );
		$this->assertStringStartsWith( 'threshold=0.5', $result->debug_summary() );

		$request = $this->history[0]['request'];
		$this->assertSame( 'POST', $request->getMethod() );
		$this->assertSame( 'Bearer secret', $request->getHeaderLine( 'Authorization' ) );
		$this->assertSame( base64_encode( 'image-bytes' ), json_decode( (string)$request->getBody(), true )['inputs'] );
	}

	public function test_passes_normal_without_auth_header(): void {
		$result = $this->new_check( [
			self::json( [ [ 'label' => 'normal', 'score' => 0.95 ], [ 'label' => 'nsfw', 'score' => 0.05 ] ] ),
		] )->check( $this->path, 'image/png' );

		$this->assertSame( CheckVerdict::Pass, $result->verdict );
		$this->assertFalse( $this->history[0]['request']->hasHeader( 'Authorization' ) );
	}

	public function test_unexpected_payload_is_unavailable(): void {
		$result = $this->new_check( [ self::json( [ 'error' => 'loading' ] ) ] )->check( $this->path, 'image/png' );

		$this->assertSame( CheckVerdict::Unavailable, $result->verdict );
		$this->assertStringContainsString( 'reason=unexpected-payload status=200', $result->debug_summary() );
	}

	public function test_http_error_is_unavailable(): void {
		$result = $this->new_check( [ self::json( [ 'error' => 'down' ], 503 ) ] )->check( $this->path, 'image/png' );

		$this->assertSame( CheckVerdict::Unavailable, $result->verdict );
		$this->assertStringContainsString( 'reason=request-failed', $result->debug_summary() );
	}

	public function test_connection_failure_is_unavailable(): void {
		$result = $this->new_check( [
			new ConnectException( 'refused', new Request( 'POST', 'http://classifier/v1/classify' ) ),
		] )->check( $this->path, 'image/png' );

		$this->assertSame( CheckVerdict::Unavailable, $result->verdict );
		$this->assertStringContainsString( 'error=refused', $result->debug_summary() );
	}

	public function test_empty_file_is_unavailable_without_request(): void {
		file_put_contents( $this->path, '' );
		$result = $this->new_check( [] )->check( $this->path, 'image/png' );

		$this->assertSame( CheckVerdict::Unavailable, $result->verdict );
		$this->assertSame( [], $this->history );
	}

}
