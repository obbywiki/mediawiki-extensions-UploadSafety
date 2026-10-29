<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\UploadSafety\Tests\Unit;

use MediaWiki\Extension\UploadSafety\Check\CheckVerdict;
use MediaWiki\Extension\UploadSafety\Check\NsfwEvaluator;
use MediaWikiUnitTestCase;

/**
 * @group UploadSafety
 * @covers \MediaWiki\Extension\UploadSafety\Check\NsfwEvaluator
 * @covers \MediaWiki\Extension\UploadSafety\Check\CheckResult
 */
class NsfwEvaluatorTest extends MediaWikiUnitTestCase {

	public static function provide_signals(): iterable {
		yield 'over threshold' => [ [ 'score' => 0.7 ], [ 'threshold' => 0.5 ], CheckVerdict::Reject ];
		yield 'at threshold' => [ [ 'score' => 0.5 ], [ 'threshold' => 0.5 ], CheckVerdict::Reject ];
		yield 'under threshold' => [ [ 'score' => 0.49 ], [ 'threshold' => 0.5 ], CheckVerdict::Pass ];
		yield 'default threshold' => [ [ 'score' => 0.5 ], [], CheckVerdict::Reject ];
		yield 'integer score' => [ [ 'score' => 0 ], [], CheckVerdict::Pass ];
		yield 'missing score' => [ [], [], CheckVerdict::Unavailable ];
		yield 'string score' => [ [ 'score' => '0.9' ], [], CheckVerdict::Unavailable ];
		yield 'score above 1' => [ [ 'score' => 1.5 ], [], CheckVerdict::Unavailable ];
		yield 'negative score' => [ [ 'score' => -0.1 ], [], CheckVerdict::Unavailable ];
	}

	/**
	 * @dataProvider provide_signals
	 */
	public function test_evaluate( array $signal, array $params, CheckVerdict $verdict ): void {
		$this->assertSame( $verdict, ( new NsfwEvaluator() )->evaluate( [ 'status' => 'ok' ] + $signal, $params )->verdict );
	}

	public function test_debug_summary_includes_frame_and_valid_labels(): void {
		$result = ( new NsfwEvaluator() )->evaluate( [
			'status' => 'ok',
			'score' => 0.75,
			'frame' => 12,
			'labels' => [ [ 'label' => 'nsfw', 'score' => 0.75 ], [ 'label' => 'normal', 'score' => 0.25 ], 'junk' ],
		], [ 'threshold' => 0.8 ] );

		$this->assertSame( 'threshold=0.8 frame=12 label=nsfw score=0.75 scores=nsfw=0.75,normal=0.25', $result->debug_summary() );
	}

}
