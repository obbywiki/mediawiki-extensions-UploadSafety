<?php

namespace MediaWiki\Extension\UploadSafety\Check;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * POSTs the image to a Hugging Face image-classification endpoint (Falconsai/nsfw_image_detection by default).
 */
class NsfwClassifierCheck implements ImageCheck {

	public const CONSTRUCTOR_OPTIONS = [
		'UploadSafetyNsfwClassifierUrl',
		'UploadSafetyNsfwClassifierApiKey',
		'UploadSafetyNsfwThreshold',
		'UploadSafetyTimeout',
	];

	public function __construct(
		private readonly HttpRequestFactory $http_request_factory,
		private readonly ServiceOptions $options,
		private readonly LoggerInterface $logger,
	) {
		$this->options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function name(): string {
		return 'nsfw-classifier';
	}

	public function check( string $file_path, string $mime ): CheckResult {
		$image_bytes = file_get_contents( $file_path );
		if ( $image_bytes === false || $image_bytes === '' ) {
			return CheckResult::unavailable( 'reason=empty-file' );
		}

		$url = $this->options->get( 'UploadSafetyNsfwClassifierUrl' );
		$timeout = (int)$this->options->get( 'UploadSafetyTimeout' );
		$threshold = (float)$this->options->get( 'UploadSafetyNsfwThreshold' );
		$api_key = (string)$this->options->get( 'UploadSafetyNsfwClassifierApiKey' );

		$headers = [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ];

		if ( $api_key !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		try {
			$client = $this->http_request_factory->createGuzzleClient( [
				'timeout' => $timeout,
				'connect_timeout' => min( 5, $timeout ),
			] );
			$response = $client->post( $url, [
				'headers' => $headers,
				'json' => HfImageClassification::request_payload( $image_bytes ),
			] );
			$body = (string)$response->getBody();
			$data = json_decode( $body, true );
			$result = HfImageClassification::from_response( $data, $threshold );

			if ( $result === null ) {
				$this->logger->warning( 'Classifier returned an unexpected payload' );

				return CheckResult::unavailable(
					'reason=unexpected-payload status=' . $response->getStatusCode()
					. ' body=' . self::truncate( $body )
				);
			}

			return $result->with_detail( 'threshold=' . $threshold );
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Classifier request failed: {message}', [ 'message' => $e->getMessage() ] );

			return CheckResult::unavailable(
				'reason=request-failed error=' . self::truncate( $e->getMessage() )
			);
		}
	}

	private static function truncate( string $value ): string {
		$value = str_replace( [ "\r", "\n" ], ' ', $value );
		if ( strlen( $value ) <= 300 ) {
			return $value;
		}

		return substr( $value, 0, 300 ) . '...';
	}

}
