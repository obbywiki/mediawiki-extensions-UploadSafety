<?php

namespace MediaWiki\Extension\UploadSafety\Check;

/**
 * `nudity_regions` check: rejects when a reported region's label has a threshold and its score is at or above it.
 *
 * Parameters: `thresholds` maps region labels to thresholds (default: the `*_exposed` labels at 0.3). Setting it replaces the default entirely, so labels left out are ignored.
 * With `suspect`, regions of those labels scoring from there up to their threshold are doubtful (CheckVerdict::Suspect).
 */
class NudityRegionsEvaluator implements CheckEvaluator {

	public const DEFAULT_THRESHOLDS = [
		'genitalia_exposed' => 0.3,
		'breast_exposed' => 0.3,
		'buttocks_exposed' => 0.3,
		'anus_exposed' => 0.3,
	];

	public function name(): string {
		return 'nudity_regions';
	}

	public function evaluate( array $signal, array $params ): CheckResult {
		$thresholds = self::thresholds( $params );
		if ( $thresholds === null ) {
			return CheckResult::unavailable( 'reason=invalid-config field=thresholds' );
		}

		$suspect = $params['suspect'] ?? null;
		if ( $suspect !== null && !is_numeric( $suspect ) ) {
			return CheckResult::unavailable( 'reason=invalid-config field=suspect' );
		}
		$suspect = $suspect !== null ? (float)$suspect : null;

		$regions = self::regions( $signal['regions'] ?? null );
		if ( $regions === null ) {
			return CheckResult::unavailable( 'reason=invalid-signal field=regions' );
		}

		// regions under the sidecar's min_score are never reported, so a lower threshold could not be honoured
		$min_score = $signal['min_score'] ?? null;
		$lowest = $thresholds === [] ? null : min( [ ...array_values( $thresholds ), ...( $suspect !== null ? [ $suspect ] : [] ) ] );
		if ( ( is_int( $min_score ) || is_float( $min_score ) ) && $lowest !== null && $lowest < $min_score ) {
			return CheckResult::unavailable( 'reason=threshold-below-min-score min_score=' . $min_score );
		}

		// the configured region closest to (or furthest over) its threshold
		$top = null;
		$verdict = CheckVerdict::Pass;
		foreach ( $regions as $region ) {
			$threshold = $thresholds[$region['label']] ?? null;
			if ( $threshold === null ) {
				continue;
			}
			if ( $top === null || $region['score'] - $threshold > $top['score'] - $top['threshold'] ) {
				$top = $region + [ 'threshold' => $threshold ];
			}

			if ( $region['score'] >= $threshold ) {
				$verdict = CheckVerdict::Reject;
			} elseif ( $suspect !== null && $region['score'] >= $suspect && $verdict === CheckVerdict::Pass ) {
				$verdict = CheckVerdict::Suspect;
			}
		}

		$scores = array_map( static fn ( array $region ) => [ 'label' => $region['label'], 'score' => $region['score'] ], $regions );
		if ( $top === null ) {
			return new CheckResult( CheckVerdict::Pass, '', null, $scores, 'configured-regions=none' );
		}

		$detail = 'threshold=' . $top['threshold'] . ( $suspect !== null ? ' suspect=' . $suspect : '' );
		if ( $top['frame'] !== null ) {
			$detail .= ' frame=' . $top['frame'];
		}
		if ( $top['box'] !== null ) {
			$detail .= ' box=' . implode( ',', $top['box'] );
		}

		return new CheckResult(
			$verdict,
			$top['label'],
			$top['score'],
			$scores,
			$detail
		);
	}

	/**
	 * @return array<string, float>|null Label => threshold, or null when the configuration is invalid
	 */
	private static function thresholds( array $params ): ?array {
		if ( !array_key_exists( 'thresholds', $params ) ) {
			return self::DEFAULT_THRESHOLDS;
		}
		if ( !is_array( $params['thresholds'] ) ) {
			return null;
		}

		$thresholds = [];
		foreach ( $params['thresholds'] as $label => $threshold ) {
			if ( !is_string( $label ) || !is_numeric( $threshold ) ) {
				return null;
			}
			$thresholds[$label] = (float)$threshold;
		}

		return $thresholds;
	}

	/**
	 * @return list<array{label: string, score: float, frame: int|null, box: list<int>|null}>|null Null when the signal is malformed
	 */
	private static function regions( mixed $regions ): ?array {
		if ( !is_array( $regions ) || !array_is_list( $regions ) ) {
			return null;
		}

		$result = [];
		foreach ( $regions as $region ) {
			if ( !is_array( $region ) || !is_string( $region['label'] ?? null ) ) {
				return null;
			}

			$score = $region['score'] ?? null;
			if ( !is_int( $score ) && !is_float( $score ) ) {
				return null;
			}

			$score = (float)$score;
			if ( !is_finite( $score ) || $score < 0.0 || $score > 1.0 ) {
				return null;
			}

			$box = $region['box'] ?? null;
			$result[] = [
				'label' => $region['label'],
				'score' => $score,
				'frame' => is_int( $region['frame'] ?? null ) ? $region['frame'] : null,
				'box' => is_array( $box ) && count( $box ) === 4 && array_is_list( $box ) && array_filter( $box, 'is_int' ) === $box ? $box : null,
			];
		}

		return $result;
	}

}
