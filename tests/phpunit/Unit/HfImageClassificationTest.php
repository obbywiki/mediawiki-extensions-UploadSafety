<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\UploadSafety\Tests\Unit;

use MediaWiki\Extension\UploadSafety\Check\CheckVerdict;
use MediaWiki\Extension\UploadSafety\Check\HfImageClassification;
use MediaWikiUnitTestCase;

/**
 * @group UploadSafety
 * @covers \MediaWiki\Extension\UploadSafety\Check\HfImageClassification
 * @covers \MediaWiki\Extension\UploadSafety\Check\CheckResult
 */
class HfImageClassificationTest extends MediaWikiUnitTestCase {

	public function test_request_payload_encodes_image_and_asks_for_both_labels(): void {
		$this->assertSame(
			[ 'inputs' => base64_encode( 'bytes' ), 'parameters' => [ 'top_k' => 2 ] ],
			HfImageClassification::request_payload( 'bytes' )
		);
	}

	public static function provide_responses(): iterable {
		$list = [ [ 'label' => 'normal', 'score' => 0.3 ], [ 'label' => 'nsfw', 'score' => 0.7 ] ];

		yield 'nsfw over threshold' => [ $list, 0.5, CheckVerdict::Reject, 'normal=0.3,nsfw=0.7' ];
		yield 'nsfw at threshold' => [ $list, 0.7, CheckVerdict::Reject, 'normal=0.3,nsfw=0.7' ];
		yield 'nsfw under threshold' => [ $list, 0.8, CheckVerdict::Pass, 'normal=0.3,nsfw=0.7' ];
		yield 'batched (nested list)' => [ [ $list ], 0.5, CheckVerdict::Reject, 'normal=0.3,nsfw=0.7' ];
		yield 'single entry object' => [ [ 'label' => 'nsfw', 'score' => 0.9 ], 0.5, CheckVerdict::Reject, 'nsfw=0.9' ];
		yield 'labels normalized' => [ [ [ 'label' => ' NSFW ', 'score' => '0.2' ] ], 0.5, CheckVerdict::Pass, 'nsfw=0.2' ];
		yield 'normal only' => [ [ [ 'label' => 'normal', 'score' => 0.99 ] ], 0.5, CheckVerdict::Pass, 'normal=0.99' ];
	}

	/**
	 * @dataProvider provide_responses
	 */
	public function test_from_response( mixed $data, float $threshold, CheckVerdict $verdict, string $scores ): void {
		$result = HfImageClassification::from_response( $data, $threshold );

		$this->assertNotNull( $result );
		$this->assertSame( $verdict, $result->verdict );
		$this->assertStringContainsString( 'scores=' . $scores, $result->debug_summary() );
	}

	public static function provide_invalid_responses(): iterable {
		yield 'not an array' => [ 'error' ];
		yield 'null' => [ null ];
		yield 'empty list' => [ [] ];
		yield 'error object' => [ [ 'error' => 'model loading' ] ];
		yield 'entry without score' => [ [ [ 'label' => 'nsfw' ] ] ];
		yield 'non-numeric score' => [ [ [ 'label' => 'nsfw', 'score' => 'high' ] ] ];
		yield 'unknown labels' => [ [ [ 'label' => 'cat', 'score' => 0.9 ] ] ];
	}

	/**
	 * @dataProvider provide_invalid_responses
	 */
	public function test_from_response_rejects_invalid_payloads( mixed $data ): void {
		$this->assertNull( HfImageClassification::from_response( $data, 0.5 ) );
	}

}
