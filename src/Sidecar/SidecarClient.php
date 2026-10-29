<?php

namespace MediaWiki\Extension\UploadSafety\Sidecar;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP client for the sidecar API.
 *
 * @see docs/sidecar-api.md
 */
class SidecarClient {

	public const CONSTRUCTOR_OPTIONS = [
		'UploadSafetySidecarUrl',
		'UploadSafetySidecarApiKey',
		'UploadSafetyTimeout',
		'UploadSafetyMaxFrames',
	];

	public function __construct(
		private readonly HttpRequestFactory $http_request_factory,
		private readonly ServiceOptions $options,
		private readonly LoggerInterface $logger,
	) {
		$this->options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * `POST /v1/check`.
	 *
	 * @param string $file_path Readable image
	 * @param list<string> $checks Check names to run
	 * @param string $mime MediaWiki-detected MIME type
	 * @param string $surface Where the image came from (upload, avatar, banner, ...)
	 * @throws SidecarUnavailableException
	 */
	public function check( string $file_path, array $checks, string $mime = '', string $surface = '' ): SidecarResponse {
		try {
			// the stream owns the handle and closes it when released
			$image = Utils::streamFor( Utils::tryFopen( $file_path, 'rb' ) );
		} catch ( Throwable ) {
			throw new SidecarUnavailableException( 'reason=unreadable-file' );
		}

		$request = [
			'checks' => array_values( $checks ),
			'max_frames' => max( 1, (int)$this->options->get( 'UploadSafetyMaxFrames' ) ),
		];
		if ( $mime !== '' ) {
			$request['mime'] = $mime;
		}
		if ( $surface !== '' ) {
			$request['surface'] = $surface;
		}

		try {
			$response = $this->client()->post( $this->url( '/v1/check' ), [
				'headers' => $this->headers(),
				'multipart' => [
					[ 'name' => 'image', 'contents' => $image, 'filename' => 'image' ],
					[
						'name' => 'request',
						'contents' => json_encode( $request, JSON_THROW_ON_ERROR ),
						'headers' => [ 'Content-Type' => 'application/json' ],
					],
				],
			] );
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Sidecar request failed: {message}', [ 'message' => $e->getMessage() ] );

			throw new SidecarUnavailableException( 'reason=request-failed error=' . self::truncate( $e->getMessage() ) );
		}

		$body = (string)$response->getBody();
		if ( $response->getStatusCode() !== 200 ) {
			$detail = 'reason=http-error status=' . $response->getStatusCode() . self::error_code( $body );
			$this->logger->warning( 'Sidecar returned an error: {detail}', [ 'detail' => $detail ] );

			throw new SidecarUnavailableException( $detail );
		}

		$result = SidecarResponse::from_json( json_decode( $body, true ) );
		if ( $result === null ) {
			$this->logger->warning( 'Sidecar returned an unexpected payload' );

			throw new SidecarUnavailableException( 'reason=unexpected-payload body=' . self::truncate( $body ) );
		}

		return $result;
	}

	/**
	 * `GET /v1/info`, or null when the sidecar is unreachable or the response is not an object.
	 *
	 * @return array<string, mixed>|null
	 */
	public function info( int $timeout = 2 ): ?array {
		try {
			$response = $this->client( $timeout )->get( $this->url( '/v1/info' ), [ 'headers' => $this->headers() ] );
		} catch ( Throwable $e ) {
			$this->logger->info( 'Sidecar info request failed: {message}', [ 'message' => $e->getMessage() ] );

			return null;
		}

		$data = self::decode_object( $response );

		return $response->getStatusCode() === 200 ? $data : null;
	}

	private function client( ?int $timeout = null ): Client {
		$timeout ??= (int)$this->options->get( 'UploadSafetyTimeout' );

		return $this->http_request_factory->createGuzzleClient( [
			'timeout' => $timeout,
			'connect_timeout' => min( 5, $timeout ),
			'http_errors' => false,
		] );
	}

	private function url( string $path ): string {
		return rtrim( (string)$this->options->get( 'UploadSafetySidecarUrl' ), '/' ) . $path;
	}

	/**
	 * @return array<string, string>
	 */
	private function headers(): array {
		$headers = [ 'Accept' => 'application/json' ];

		$api_key = (string)$this->options->get( 'UploadSafetySidecarApiKey' );
		if ( $api_key !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		return $headers;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function decode_object( ResponseInterface $response ): ?array {
		$data = json_decode( (string)$response->getBody(), true );

		return is_array( $data ) && ( $data === [] || !array_is_list( $data ) ) ? $data : null;
	}

	/**
	 * ` code=<code>` from a contract error body, or an empty string.
	 */
	private static function error_code( string $body ): string {
		$data = json_decode( $body, true );
		$code = is_array( $data ) && is_array( $data['error'] ?? null ) ? ( $data['error']['code'] ?? null ) : null;

		return is_string( $code ) && $code !== '' ? ' code=' . self::truncate( $code ) : '';
	}

	private static function truncate( string $value ): string {
		$value = str_replace( [ "\r", "\n" ], ' ', $value );
		if ( strlen( $value ) <= 300 ) {
			return $value;
		}

		return substr( $value, 0, 300 ) . '...';
	}

}
