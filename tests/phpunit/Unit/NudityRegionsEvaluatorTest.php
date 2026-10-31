<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\UploadSafety\Tests\Unit;

use MediaWiki\Extension\UploadSafety\Check\CheckVerdict;
use MediaWiki\Extension\UploadSafety\Check\NudityRegionsEvaluator;
use MediaWikiUnitTestCase;

/**
 * @group UploadSafety
 * @covers \MediaWiki\Extension\UploadSafety\Check\NudityRegionsEvaluator
 * @covers \MediaWiki\Extension\UploadSafety\Check\CheckResult
 */
class NudityRegionsEvaluatorTest extends MediaWikiUnitTestCase {

	private static function region( string $label, float|int $score, int $frame = 0 ): array {
		return [ 'label' => $label, 'score' => $score, 'frame' => $frame, 'box' => [ 1, 2, 30, 40 ] ];
	}

	public static function provide_signals(): iterable {
		yield 'no regions' => [ [ 'regions' => [] ], [], CheckVerdict::Pass ];
		yield 'exposed over default threshold' => [ [ 'regions' => [ self::region( 'breast_exposed', 0.31 ) ] ], [], CheckVerdict::Reject ];
		yield 'exposed at default threshold' => [ [ 'regions' => [ self::region( 'anus_exposed', 0.3 ) ] ], [], CheckVerdict::Reject ];
		yield 'exposed under default threshold' => [ [ 'regions' => [ self::region( 'genitalia_exposed', 0.29 ) ] ], [], CheckVerdict::Pass ];
		yield 'covered is ignored by default' => [ [ 'regions' => [ self::region( 'breast_covered', 0.99 ) ] ], [], CheckVerdict::Pass ];
		yield 'unknown label is ignored' => [ [ 'regions' => [ self::region( 'face', 1 ) ] ], [], CheckVerdict::Pass ];
		yield 'any configured region rejects' => [
			[ 'regions' => [ self::region( 'breast_covered', 0.9 ), self::region( 'buttocks_exposed', 0.1 ) ] ],
			[ 'thresholds' => [ 'breast_covered' => 0.8, 'buttocks_exposed' => 0.5 ] ],
			CheckVerdict::Reject,
		];
		yield 'thresholds replace the defaults' => [
			[ 'regions' => [ self::region( 'genitalia_exposed', 0.9 ) ] ],
			[ 'thresholds' => [ 'breast_covered' => 0.5 ] ],
			CheckVerdict::Pass,
		];
		yield 'regions missing' => [ [], [], CheckVerdict::Unavailable ];
		yield 'regions not a list' => [ [ 'regions' => [ 'a' => self::region( 'breast_exposed', 0.9 ) ] ], [], CheckVerdict::Unavailable ];
		yield 'region without label' => [ [ 'regions' => [ [ 'score' => 0.9 ] ] ], [], CheckVerdict::Unavailable ];
		yield 'region with string score' => [ [ 'regions' => [ [ 'label' => 'breast_exposed', 'score' => '0.9' ] ] ], [], CheckVerdict::Unavailable ];
		yield 'region score above 1' => [ [ 'regions' => [ self::region( 'breast_exposed', 1.5 ) ] ], [], CheckVerdict::Unavailable ];
		yield 'region that is not an object' => [ [ 'regions' => [ 'junk' ] ], [], CheckVerdict::Unavailable ];
		yield 'invalid thresholds' => [ [ 'regions' => [] ], [ 'thresholds' => [ 'breast_exposed' => 'high' ] ], CheckVerdict::Unavailable ];
		yield 'thresholds not an array' => [ [ 'regions' => [] ], [ 'thresholds' => 0.3 ], CheckVerdict::Unavailable ];
		yield 'threshold below the sidecar min_score' => [ [ 'min_score' => 0.2, 'regions' => [] ], [ 'thresholds' => [ 'breast_exposed' => 0.1 ] ], CheckVerdict::Unavailable ];
		yield 'threshold at the sidecar min_score' => [ [ 'min_score' => 0.2, 'regions' => [] ], [ 'thresholds' => [ 'breast_exposed' => 0.2 ] ], CheckVerdict::Pass ];
	}

	/**
	 * @dataProvider provide_signals
	 */
	public function test_evaluate( array $signal, array $params, CheckVerdict $verdict ): void {
		$this->assertSame( $verdict, ( new NudityRegionsEvaluator() )->evaluate( [ 'status' => 'ok' ] + $signal, $params )->verdict );
	}

	public function test_reports_the_region_furthest_over_its_threshold(): void {
		$result = ( new NudityRegionsEvaluator() )->evaluate( [
			'status' => 'ok',
			'regions' => [ self::region( 'breast_covered', 0.95, 2 ), self::region( 'genitalia_exposed', 0.6, 7 ) ],
		], [ 'thresholds' => [ 'breast_covered' => 0.9, 'genitalia_exposed' => 0.3 ] ] );

		$this->assertSame( CheckVerdict::Reject, $result->verdict );
		$this->assertSame( 'genitalia_exposed', $result->label );
		$this->assertSame( 0.6, $result->score );
		$this->assertSame(
			'threshold=0.3 frame=7 box=1,2,30,40 label=genitalia_exposed score=0.6 scores=breast_covered=0.95,genitalia_exposed=0.6',
			$result->debug_summary()
		);
	}

	public function test_pass_without_configured_regions_has_no_score(): void {
		$result = ( new NudityRegionsEvaluator() )->evaluate( [
			'status' => 'ok',
			'regions' => [ self::region( 'breast_covered', 0.5 ) ],
		], [] );

		$this->assertSame( CheckVerdict::Pass, $result->verdict );
		$this->assertNull( $result->score );
		$this->assertSame( 'configured-regions=none scores=breast_covered=0.5', $result->debug_summary() );
	}

}
