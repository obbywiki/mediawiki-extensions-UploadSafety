<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\UploadSafety\Tests\Unit;

use MediaWiki\Extension\UploadSafety\Sidecar\SidecarClient;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarInfo;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @group UploadSafety
 * @covers \MediaWiki\Extension\UploadSafety\Sidecar\SidecarInfo
 */
class SidecarInfoTest extends MediaWikiUnitTestCase {

	public function test_software_entries_expose_service_and_runtime_only(): void {
		$client = $this->createMock( SidecarClient::class );
		$client->expects( $this->once() )->method( 'info' )->willReturn( [
			'service' => [ 'name' => 'nsfw-detector', 'version' => '0.2.0', 'url' => 'https://example.org/sidecar' ],
			'runtime' => [
				[ 'name' => 'Python', 'version' => '3.12.7', 'url' => 'https://www.python.org/' ],
				[ 'name' => 'Bad]]', 'version' => '1<script>' ],
				[ 'name' => 'NoVersion' ],
			],
			'checks' => [ 'nsfw' => [ 'model' => 'secret-model' ] ],
		] );

		$info = new SidecarInfo( $client, new HashBagOStuff() );
		$expected = [
			'[https://example.org/sidecar UploadSafety sidecar]' => '0.2.0',
			'[https://www.python.org/ Python]' => '3.12.7',
			'Bad' => '1script',
		];

		$this->assertSame( $expected, $info->software_entries() );
		$this->assertSame( $expected, $info->software_entries(), 'cached' );
		$this->assertStringNotContainsString( 'secret-model', json_encode( $expected ) );
		$this->assertStringNotContainsString( 'nsfw', json_encode( $expected ) );
	}

	public function test_unreachable_sidecar_has_no_entries(): void {
		$client = $this->createMock( SidecarClient::class );
		$client->method( 'info' )->willReturn( null );

		$this->assertSame( [], ( new SidecarInfo( $client, new HashBagOStuff() ) )->software_entries() );
	}

}
