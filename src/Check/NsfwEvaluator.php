<?php

namespace MediaWiki\Extension\UploadSafety\Check;

/**
 * `nsfw` check: rejects when the worst frame's score is at or above `threshold` (default 0.5).
 * With `suspect`, scores from there up to the threshold are doubtful (CheckVerdict::Suspect).
 */
class NsfwEvaluator implements CheckEvaluator {

	private const DEFAULT_THRESHOLD = 0.5;

	public function name(): string {
		return 'nsfw';
	}

	public function evaluate( array $signal, array $params ): CheckResult {
		$score = $signal['score'] ?? null;
		if ( !is_int( $score ) && !is_float( $score ) ) {
			return CheckResult::unavailable( 'reason=invalid-signal field=score' );
		}

		$score = (float)$score;
		if ( !is_finite( $score ) || $score < 0.0 || $score > 1.0 ) {
			return CheckResult::unavailable( 'reason=invalid-signal field=score' );
		}

		$threshold = is_numeric( $params['threshold'] ?? null ) ? (float)$params['threshold'] : self::DEFAULT_THRESHOLD;
		$suspect = is_numeric( $params['suspect'] ?? null ) ? (float)$params['suspect'] : null;

		$verdict = CheckVerdict::Pass;
		if ( $score >= $threshold ) {
			$verdict = CheckVerdict::Reject;
		} elseif ( $suspect !== null && $score >= $suspect ) {
			$verdict = CheckVerdict::Suspect;
		}

		$detail = 'threshold=' . $threshold . ( $suspect !== null ? ' suspect=' . $suspect : '' );
		if ( is_int( $signal['frame'] ?? null ) ) {
			$detail .= ' frame=' . $signal['frame'];
		}

		return new CheckResult(
			$verdict,
			'nsfw',
			$score,
			self::labels( $signal['labels'] ?? null ),
			$detail
		);
	}

	/**
	 * @return list<array{label: string, score: float}>
	 */
	private static function labels( mixed $labels ): array {
		if ( !is_array( $labels ) ) {
			return [];
		}

		$result = [];
		foreach ( $labels as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['label'] ?? null ) && is_numeric( $entry['score'] ?? null ) ) {
				$result[] = [ 'label' => $entry['label'], 'score' => (float)$entry['score'] ];
			}
		}

		return $result;
	}

}
