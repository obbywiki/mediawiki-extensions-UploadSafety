<?php

namespace MediaWiki\Extension\UploadSafety\Check;

/**
 * `faces` check: rejects images with a real person's face big enough to identify them, e.g. a selfie as an avatar.
 *
 * Only faces covering at least `min_area` of the frame (default 0.05) count. Rejects when such a face scores at or above `threshold` (default 0.7);
 * with `suspect`, scores from there up to the threshold are doubtful (CheckVerdict::Suspect).
 */
class FacesEvaluator implements CheckEvaluator, RejectionMessage {

	private const DEFAULT_THRESHOLD = 0.7;
	private const DEFAULT_MIN_AREA = 0.05;

	public function name(): string {
		return 'faces';
	}

	public function rejection_message(): string {
		return 'uploadsafety-upload-rejected-face';
	}

	public function evaluate( array $signal, array $params ): CheckResult {
		$threshold = $params['threshold'] ?? self::DEFAULT_THRESHOLD;
		$suspect = $params['suspect'] ?? null;
		$min_area = $params['min_area'] ?? self::DEFAULT_MIN_AREA;
		if ( !is_numeric( $threshold ) || ( $suspect !== null && !is_numeric( $suspect ) ) || !is_numeric( $min_area ) ) {
			return CheckResult::unavailable( 'reason=invalid-config' );
		}
		$threshold = (float)$threshold;
		$suspect = $suspect !== null ? (float)$suspect : null;
		$min_area = (float)$min_area;

		$faces = self::faces( $signal['faces'] ?? null );
		if ( $faces === null ) {
			return CheckResult::unavailable( 'reason=invalid-signal field=faces' );
		}

		// faces under the sidecar's min_score are never reported, so a lower level could not be honoured
		$min_score = $signal['min_score'] ?? null;
		if ( ( is_int( $min_score ) || is_float( $min_score ) ) && min( $threshold, $suspect ?? $threshold ) < $min_score ) {
			return CheckResult::unavailable( 'reason=threshold-below-min-score min_score=' . $min_score );
		}

		$top = null;
		foreach ( $faces as $face ) {
			if ( $face['area'] >= $min_area && ( $top === null || $face['score'] > $top['score'] ) ) {
				$top = $face;
			}
		}

		$scores = array_map( static fn ( array $face ) => [ 'label' => 'face', 'score' => $face['score'] ], $faces );
		$detail = 'threshold=' . $threshold . ( $suspect !== null ? ' suspect=' . $suspect : '' ) . ' min_area=' . $min_area;
		if ( $top === null ) {
			return new CheckResult( CheckVerdict::Pass, '', null, $scores, $detail . ( $faces === [] ? ' faces=none' : ' faces=small' ) );
		}

		$verdict = CheckVerdict::Pass;
		if ( $top['score'] >= $threshold ) {
			$verdict = CheckVerdict::Reject;
		} elseif ( $suspect !== null && $top['score'] >= $suspect ) {
			$verdict = CheckVerdict::Suspect;
		}

		$detail .= ' area=' . round( $top['area'], 4 );
		if ( $top['frame'] !== null ) {
			$detail .= ' frame=' . $top['frame'];
		}
		if ( $top['box'] !== null ) {
			$detail .= ' box=' . implode( ',', $top['box'] );
		}

		return new CheckResult( $verdict, 'face', $top['score'], $scores, $detail );
	}

	/**
	 * @return list<array{score: float, area: float, frame: int|null, box: list<int>|null}>|null Null when the signal is malformed
	 */
	private static function faces( mixed $faces ): ?array {
		if ( !is_array( $faces ) || !array_is_list( $faces ) ) {
			return null;
		}

		$result = [];
		foreach ( $faces as $face ) {
			if ( !is_array( $face ) ) {
				return null;
			}

			$score = $face['score'] ?? null;
			$area = $face['area'] ?? null;
			if ( ( !is_int( $score ) && !is_float( $score ) ) || ( !is_int( $area ) && !is_float( $area ) ) ) {
				return null;
			}

			$score = (float)$score;
			$area = (float)$area;
			if ( !is_finite( $score ) || $score < 0.0 || $score > 1.0 || !is_finite( $area ) || $area < 0.0 || $area > 1.0 ) {
				return null;
			}

			$box = $face['box'] ?? null;
			$result[] = [
				'score' => $score,
				'area' => $area,
				'frame' => is_int( $face['frame'] ?? null ) ? $face['frame'] : null,
				'box' => is_array( $box ) && count( $box ) === 4 && array_is_list( $box ) && array_filter( $box, 'is_int' ) === $box ? $box : null,
			];
		}

		return $result;
	}

}
