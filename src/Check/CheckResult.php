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
