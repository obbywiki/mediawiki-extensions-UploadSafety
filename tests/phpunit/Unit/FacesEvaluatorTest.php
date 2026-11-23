<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\UploadSafety\Tests\Unit;

use MediaWiki\Extension\UploadSafety\Check\CheckVerdict;
use MediaWiki\Extension\UploadSafety\Check\FacesEvaluator;
use MediaWikiUnitTestCase;

/**
 * @group UploadSafety
 * @covers \MediaWiki\Extension\UploadSafety\Check\FacesEvaluator
 * @covers \MediaWiki\Extension\UploadSafety\Check\CheckResult
 */
class FacesEvaluatorTest extends MediaWikiUnitTestCase {

	private static function face( float|int $score, float|int $area, int $frame = 0 ): array {
		return [ 'score' => $score, 'area' => $area, 'frame' => $frame, 'box' => [ 10, 20, 100, 120 ] ];
	}

	public static function provide_signals(): iterable {
		yield 'no faces' => [ [ 'faces' => [] ], [], CheckVerdict::Pass ];
		yield 'selfie' => [ [ 'faces' => [ self::face( 0.93, 0.3 ) ] ], [], CheckVerdict::Reject ];
		yield 'at default threshold' => [ [ 'faces' => [ self::face( 0.7, 0.3 ) ] ], [], CheckVerdict::Reject ];
		yield 'under default threshold' => [ [ 'faces' => [ self::face( 0.69, 0.3 ) ] ], [], CheckVerdict::Pass ];
		yield 'small face in a scene' => [ [ 'faces' => [ self::face( 0.95, 0.01 ) ] ], [], CheckVerdict::Pass ];
		yield 'at default min_area' => [ [ 'faces' => [ self::face( 0.95, 0.05 ) ] ], [], CheckVerdict::Reject ];
		yield 'any large face counts' => [ [ 'faces' => [ self::face( 0.95, 0.01 ), self::face( 0.8, 0.2 ) ] ], [], CheckVerdict::Reject ];
		yield 'doubtful' => [ [ 'faces' => [ self::face( 0.62, 0.3 ) ] ], [ 'suspect' => 0.6 ], CheckVerdict::Suspect ];
		yield 'under suspect' => [ [ 'faces' => [ self::face( 0.59, 0.3 ) ] ], [ 'suspect' => 0.6 ], CheckVerdict::Pass ];
		yield 'custom threshold and area' => [ [ 'faces' => [ self::face( 0.8, 0.02 ) ] ], [ 'threshold' => 0.75, 'min_area' => 0.01 ], CheckVerdict::Reject ];
		yield 'integer values' => [ [ 'faces' => [ self::face( 1, 1 ) ] ], [], CheckVerdict::Reject ];
		yield 'faces missing' => [ [], [], CheckVerdict::Unavailable ];
		yield 'faces not a list' => [ [ 'faces' => [ 'a' => self::face( 0.9, 0.3 ) ] ], [], CheckVerdict::Unavailable ];
		yield 'face without area' => [ [ 'faces' => [ [ 'score' => 0.9 ] ] ], [], CheckVerdict::Unavailable ];
		yield 'face with string score' => [ [ 'faces' => [ [ 'score' => '0.9', 'area' => 0.3 ] ] ], [], CheckVerdict::Unavailable ];
		yield 'area above 1' => [ [ 'faces' => [ self::face( 0.9, 1.5 ) ] ], [], CheckVerdict::Unavailable ];
		yield 'face that is not an object' => [ [ 'faces' => [ 'junk' ] ], [], CheckVerdict::Unavailable ];
		yield 'invalid threshold' => [ [ 'faces' => [] ], [ 'threshold' => 'high' ], CheckVerdict::Unavailable ];
		yield 'suspect below the sidecar min_score' => [ [ 'min_score' => 0.5, 'faces' => [] ], [ 'suspect' => 0.4 ], CheckVerdict::Unavailable ];
		yield 'threshold below the sidecar min_score' => [ [ 'min_score' => 0.5, 'faces' => [] ], [ 'threshold' => 0.45 ], CheckVerdict::Unavailable ];
		yield 'levels above the sidecar min_score' => [ [ 'min_score' => 0.5, 'faces' => [] ], [ 'suspect' => 0.6 ], CheckVerdict::Pass ];
	}

	/**
	 * @dataProvider provide_signals
	 */
	public function test_evaluate( array $signal, array $params, CheckVerdict $verdict ): void {
		$this->assertSame( $verdict, ( new FacesEvaluator() )->evaluate( [ 'status' => 'ok' ] + $signal, $params )->verdict );
	}

	public function test_reports_the_strongest_large_face(): void {
		$result = ( new FacesEvaluator() )->evaluate( [
			'status' => 'ok',
			'faces' => [ self::face( 0.95, 0.01, 1 ), self::face( 0.8, 0.25, 4 ) ],
		], [ 'suspect' => 0.6 ] );

		$this->assertSame( 'face', $result->label );
		$this->assertSame( 0.8, $result->score );
		$this->assertSame(
			'threshold=0.7 suspect=0.6 min_area=0.05 area=0.25 frame=4 box=10,20,100,120 label=face score=0.8 scores=face=0.95,face=0.8',
			$result->debug_summary()
		);
	}

	public function test_small_faces_only_pass_without_a_score(): void {
		$result = ( new FacesEvaluator() )->evaluate( [ 'status' => 'ok', 'faces' => [ self::face( 0.95, 0.01 ) ] ], [] );

		$this->assertSame( CheckVerdict::Pass, $result->verdict );
		$this->assertNull( $result->score );
		$this->assertStringContainsString( 'faces=small', $result->debug_summary() );
	}

	public function test_has_its_own_rejection_message(): void {
		$this->assertSame( 'uploadsafety-upload-rejected-face', ( new FacesEvaluator() )->rejection_message() );
	}

}
