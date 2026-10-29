<?php

namespace MediaWiki\Extension\UploadSafety\Check;

class CheckResult {

	/**
	 * @param CheckVerdict $verdict
	 * @param string $label Top label reported by the check
	 * @param float|null $score Score of the top label
	 * @param array<array{label: string, score: float}> $scores
	 * @param string $detail Extra debug detail (threshold, failure reason, ...)
	 */
	public function __construct(
		public readonly CheckVerdict $verdict,
		public readonly string $label = '',
		public readonly ?float $score = null,
		public readonly array $scores = [],
		public readonly string $detail = '',
	) {
	}

	public static function unavailable( string $detail ): self {
		return new self( CheckVerdict::Unavailable, '', null, [], $detail );
	}

	/**
	 * @return array{verdict: string, label: string, score: float|null, scores: array<array{label: string, score: float}>, detail: string}
	 */
	public function to_array(): array {
		return [
			'verdict' => $this->verdict->value,
			'label' => $this->label,
			'score' => $this->score,
			'scores' => $this->scores,
			'detail' => $this->detail,
		];
	}

	/**
	 * Inverse of to_array(), or null when $data is not a valid result.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( !is_array( $data ) || !is_string( $data['verdict'] ?? null ) ) {
			return null;
		}

		$verdict = CheckVerdict::tryFrom( $data['verdict'] );
		if ( $verdict === null ) {
			return null;
		}

		return new self(
			$verdict,
			is_string( $data['label'] ?? null ) ? $data['label'] : '',
			is_float( $data['score'] ?? null ) || is_int( $data['score'] ?? null ) ? (float)$data['score'] : null,
			is_array( $data['scores'] ?? null ) ? $data['scores'] : [],
			is_string( $data['detail'] ?? null ) ? $data['detail'] : ''
		);
	}

	public function with_detail( string $detail ): self {
		return new self( $this->verdict, $this->label, $this->score, $this->scores, $detail );
	}

	public function debug_summary(): string {
		$parts = [];
		if ( $this->detail !== '' ) {
			$parts[] = $this->detail;
		}
		if ( $this->label !== '' ) {
			$parts[] = 'label=' . $this->label;
		}
		if ( $this->score !== null ) {
			$parts[] = 'score=' . self::format_score( $this->score );
		}
		if ( $this->scores !== [] ) {
			$score_parts = [];

			foreach ( $this->scores as $entry ) {
				$score_parts[] = $entry['label'] . '=' . self::format_score( $entry['score'] );
			}

			$parts[] = 'scores=' . implode( ',', $score_parts );
		}

		return implode( ' ', $parts );
	}

	private static function format_score( float $score ): string {
		return rtrim( rtrim( sprintf( '%.4f', $score ), '0' ), '.' );
	}

}
