<?php

namespace MediaWiki\Extension\UploadSafety\Check;

/**
 * Hugging Face image-classification task payload and response. HANDLING IS SUBJECT TO CHANGE.
 *
 * @see https://huggingface.co/docs/inference-providers/main/tasks/image-classification
 * @see https://github.com/huggingface/huggingface.js/blob/main/packages/tasks/src/tasks/image-classification/spec/input.json
 * @see https://github.com/huggingface/huggingface.js/blob/main/packages/tasks/src/tasks/image-classification/spec/output.json
 */
class HfImageClassification {

	/**
	 * JSON body for POST /v1/classify (and any HF image-classification endpoint).
	 * `inputs` is a base64 image. `top_k` is 2 so the classifier (Falconsai by default) returns both `normal` and `nsfw`.
	 *
	 * @return array{inputs: string, parameters: array{top_k: int}}
	 */
	public static function request_payload( string $image_bytes ): array {
		return [
			'inputs' => base64_encode( $image_bytes ),
			'parameters' => [ 'top_k' => 2 ]
		];
	}

	/**
	 * @param mixed $data Decoded JSON from the classifier
	 * @return ?CheckResult Null when the payload is not a valid HF classification output
	 */
	public static function from_response( mixed $data, float $threshold ): ?CheckResult {
		$scores = self::parse_scores( $data );
		if ( $scores === null ) {
			return null;
		}

		$nsfw_score = null;
		$top_label = '';
		$top_score = -INF;

		foreach ( $scores as $entry ) {
			if ( $entry['score'] > $top_score ) {
				$top_score = $entry['score'];
				$top_label = $entry['label'];
			}
			if ( $entry['label'] === 'nsfw' ) {
				$nsfw_score = $entry['score'];
			}
		}

		if ( $nsfw_score !== null ) {
			$rejected = $nsfw_score >= $threshold;
		} elseif ( $top_label === 'nsfw' ) {
			$rejected = true;
		} elseif ( $top_label === 'normal' ) {
			$rejected = false;
		} else {
			return null;
		}

		return new CheckResult(
			$rejected ? CheckVerdict::Reject : CheckVerdict::Pass,
			$top_label,
			is_finite( $top_score ) ? $top_score : null,
			$scores
		);
	}

	/**
	 * @return ?list<array{label: string, score: float}>
	 */
	private static function parse_scores( mixed $data ): ?array {
		if ( !is_array( $data ) ) {
			return null;
		}

		if ( self::is_score_entry( $data ) ) {
			$data = [ $data ];
		} elseif (
			$data !== []
			&& array_is_list( $data )
			&& is_array( $data[0] )
			&& !self::is_score_entry( $data[0] )
		) {
			$data = $data[0];
		}

		if ( $data === [] || !array_is_list( $data ) ) {
			return null;
		}

		$scores = [];
		foreach ( $data as $entry ) {
			if ( !self::is_score_entry( $entry ) ) {
				return null;
			}
			$scores[] = [
				'label' => strtolower( trim( (string)$entry['label'] ) ),
				'score' => (float)$entry['score'],
			];
		}

		return $scores;
	}

	private static function is_score_entry( mixed $entry ): bool {
		return is_array( $entry ) && array_key_exists( 'label', $entry ) && array_key_exists( 'score', $entry ) && is_string( $entry['label'] ) && is_numeric( $entry['score'] );
	}

}
