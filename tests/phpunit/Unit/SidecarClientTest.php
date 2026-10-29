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
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarClient;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarUnavailableException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;

/**
 * @group UploadSafety
 * @covers \MediaWiki\Extension\UploadSafety\Sidecar\SidecarClient
 * @covers \MediaWiki\Extension\UploadSafety\Sidecar\SidecarResponse
 */
class SidecarClientTest extends MediaWikiUnitTestCase {

	private string $path;

	/** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
	private array $history = [];

	protected function setUp(): void {
		parent::setUp();
		$this->path = tempnam( sys_get_temp_dir(), 'us-sidecar-' );
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
	private function new_client( array $queue, string $api_key = '' ): SidecarClient {
		$stack = HandlerStack::create( new MockHandler( $queue ) );
		$stack->push( Middleware::history( $this->history ) );

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'createGuzzleClient' )->willReturnCallback(
			static fn ( array $config ) => new Client( [ 'handler' => $stack ] + $config )
		);

		return new SidecarClient(
			$factory,
			new ServiceOptions( SidecarClient::CONSTRUCTOR_OPTIONS, new HashConfig( [
				'UploadSafetySidecarUrl' => 'http://sidecar:8000/',
				'UploadSafetySidecarApiKey' => $api_key,
				'UploadSafetyTimeout' => 5,
				'UploadSafetyMaxFrames' => 32,
			] ) ),
			new NullLogger()
		);
	}

	private static function json( mixed $data, int $status = 200 ): Response {
		return new Response( $status, [ 'Content-Type' => 'application/json' ], json_encode( $data ) );
	}

	public function test_check_sends_multipart_request(): void {
		$response = $this->new_client( [
			self::json( [
				'image' => [ 'format' => 'gif', 'frames' => [ 'total' => 9, 'distinct' => 3, 'analyzed' => 3 ] ],
				'checks' => [ 'nsfw' => [ 'status' => 'ok', 'score' => 0.2, 'frame' => 0 ] ],
			] ),
		], 'secret' )->check( $this->path, [ 'nsfw' ], 'image/gif', 'avatar' );

		$this->assertSame( [ 'status' => 'ok', 'score' => 0.2, 'frame' => 0 ], $response->signal( 'nsfw' ) );
		$this->assertNull( $response->signal( 'ocr' ) );
		$this->assertSame( '3/3/9', $response->frames_summary() );

		$request = $this->history[0]['request'];
		$this->assertSame( 'POST', $request->getMethod() );
		$this->assertSame( 'http://sidecar:8000/v1/check', (string)$request->getUri() );
		$this->assertSame( 'Bearer secret', $request->getHeaderLine( 'Authorization' ) );
		$this->assertStringStartsWith( 'multipart/form-data', $request->getHeaderLine( 'Content-Type' ) );

		$body = (string)$request->getBody();
		$this->assertStringContainsString( 'name="image"', $body );
		$this->assertStringContainsString( 'image-bytes', $body );
		$this->assertStringContainsString(
			'{"checks":["nsfw"],"max_frames":32,"mime":"image\/gif","surface":"avatar"}',
			$body
		);
	}

	public function test_check_without_api_key_sends_no_auth(): void {
		$this->new_client( [ self::json( [ 'checks' => [] ] ) ] )->check( $this->path, [ 'nsfw' ] );

		$this->assertFalse( $this->history[0]['request']->hasHeader( 'Authorization' ) );
	}

	public static function provide_failures(): iterable {
		yield 'http error with contract body' => [
			self::json( [ 'error' => [ 'code' => 'undecodable_image', 'message' => 'bad' ] ], 422 ),
			'reason=http-error status=422 code=undecodable_image',
		];
		yield 'http error without body' => [ new Response( 502, [], 'Bad Gateway' ), 'reason=http-error status=502' ];
		yield 'not json' => [ new Response( 200, [], 'ok' ), 'reason=unexpected-payload' ];
		yield 'checks missing' => [ self::json( [ 'image' => [] ] ), 'reason=unexpected-payload' ];
		yield 'checks is a list' => [ self::json( [ 'checks' => [ [ 'status' => 'ok' ] ] ] ), 'reason=unexpected-payload' ];
		yield 'connection refused' => [
			new ConnectException( 'refused', new Request( 'POST', 'http://sidecar:8000/v1/check' ) ),
			'reason=request-failed error=refused',
		];
	}

	/**
	 * @dataProvider provide_failures
	 */
	public function test_check_failures_throw( Response|\Throwable $response, string $detail ): void {
		try {
			$this->new_client( [ $response ] )->check( $this->path, [ 'nsfw' ] );
			$this->fail( 'Expected SidecarUnavailableException' );
		} catch ( SidecarUnavailableException $e ) {
			$this->assertStringStartsWith( $detail, $e->getMessage() );
		}
	}

	public function test_info(): void {
		$info = [ 'service' => [ 'name' => 'x', 'version' => '1' ], 'checks' => [] ];

		$this->assertSame( $info, $this->new_client( [ self::json( $info ) ] )->info() );
		$this->assertSame( 'http://sidecar:8000/v1/info', (string)$this->history[0]['request']->getUri() );

		$this->assertNull( $this->new_client( [ self::json( $info, 500 ) ] )->info() );
		$this->assertNull( $this->new_client( [ self::json( [ 1, 2 ] ) ] )->info() );
		$this->assertNull( $this->new_client( [
			new ConnectException( 'refused', new Request( 'GET', 'http://sidecar:8000/v1/info' ) ),
		] )->info() );
	}

}
