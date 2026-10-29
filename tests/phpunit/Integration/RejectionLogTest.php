<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\UploadSafety\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\UploadSafety\Check\CheckResult;
use MediaWiki\Extension\UploadSafety\Check\CheckVerdict;
use MediaWiki\Extension\UploadSafety\RejectionLog;
use MediaWiki\Extension\UploadSafety\ScreeningResult;
use MediaWiki\Logging\DatabaseLogEntry;
use MediaWiki\Logging\LogEventsList;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWikiIntegrationTestCase;

/**
 * @group UploadSafety
 * @group Database
 * @covers \MediaWiki\Extension\UploadSafety\RejectionLog
 */
class RejectionLogTest extends MediaWikiIntegrationTestCase {

	private function last_entry( string $action ): DatabaseLogEntry {
		$row = $this->getDb()->newSelectQueryBuilder()
			->queryInfo( DatabaseLogEntry::getSelectQueryData() )
			->where( [ 'log_type' => RejectionLog::TYPE, 'log_action' => $action ] )
			->orderBy( 'log_id', 'DESC' )
			->caller( __METHOD__ )
			->fetchRow();
		$this->assertNotFalse( $row );

		return DatabaseLogEntry::newFromRow( $row );
	}

	private function render( DatabaseLogEntry $entry ): string {
		$formatter = $this->getServiceContainer()->getLogFormatterFactory()->newFromEntry( $entry );
		$context = new RequestContext();
		$context->setAuthority( new UltimateAuthority( $this->getTestSysop()->getUser() ) );
		$formatter->setContext( $context );

		return $formatter->getPlainActionText();
	}

	public function test_reject_entry(): void {
		$uploader = $this->getTestUser()->getUser();
		$result = new ScreeningResult( true, 'uploadsafety-upload-rejected', [
			'nsfw' => new CheckResult( CheckVerdict::Reject, 'nsfw', 0.93 ),
		] );

		( new RejectionLog() )->reject( $uploader, PageReferenceValue::localReference( NS_FILE, 'Bad.png' ), 'upload', $result, str_repeat( 'a', 40 ), false );

		$entry = $this->last_entry( 'reject' );
		$this->assertSame( $uploader->getName(), $entry->getPerformerIdentity()->getName() );
		$this->assertSame( 'Bad.png', $entry->getTarget()->getDBkey() );
		$this->assertSame( NS_FILE, $entry->getTarget()->getNamespace() );

		$params = $entry->getParameters();
		$this->assertSame( 'upload', $params['4::surface'] );
		$this->assertSame( 'nsfw', $params['5::check'] );
		$this->assertSame( '0.93', $params['6::score'] );
		$this->assertSame( str_repeat( 'a', 40 ), $params['7::sha1'] );
		$this->assertFalse( $params['repeat'] );

		$text = $this->render( $entry );
		$this->assertStringContainsString( 'nsfw check', $text );
		$this->assertStringContainsString( str_repeat( 'a', 40 ), $text );
	}

	public function test_limited_entry(): void {
		$uploader = $this->getTestUser()->getUser();
		( new RejectionLog() )->limited( $uploader, $uploader->getUserPage(), 'avatar', 'uploadsafety-reject' );

		$entry = $this->last_entry( 'limited' );
		$this->assertSame( NS_USER, $entry->getTarget()->getNamespace() );
		$this->assertStringContainsString( 'uploadsafety-reject limit', $this->render( $entry ) );
	}

	public function test_log_is_restricted_to_uploadsafety_log_right(): void {
		$this->assertSame( 'uploadsafety-log', $this->getConfVar( 'LogRestrictions' )[RejectionLog::TYPE] ?? null );

		$this->assertTrue( LogEventsList::userCanViewLogType( RejectionLog::TYPE, $this->getTestSysop()->getAuthority() ) );
		$this->assertFalse( LogEventsList::userCanViewLogType( RejectionLog::TYPE, $this->getTestUser()->getAuthority() ) );
	}

}
