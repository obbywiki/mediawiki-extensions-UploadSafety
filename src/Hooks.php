<?php

namespace MediaWiki\Extension\UploadSafety;

use MediaWiki\Api\Hook\APIAfterExecuteHook;
use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Html\Html;
use MediaWiki\Upload\Hook\UploadCompleteHook;
use MediaWiki\Upload\Hook\UploadVerifyFileHook;

class Hooks implements UploadVerifyFileHook, UploadCompleteHook, BeforePageDisplayHook, APIAfterExecuteHook {

	private const SESSION_KEY = 'uploadsafety-debug';

	public function __construct(
		private readonly UploadScreener $upload_screener,
	) {
	}

	/** @inheritDoc */
	public function onUploadVerifyFile( $upload, $mime, &$error ) {
		$mime = (string)$mime;
		if ( !ImageTypes::is_screenable_mime( $mime ) ) {
			return;
		}

		$result = $this->upload_screener->screen( $upload->getTempPath(), $mime, (string)$upload->getDesiredDestName() );
		if ( $result->rejected ) {
			$error = $this->upload_screener->rejection_error( $result );
		}
	}

	/** @inheritDoc */
	public function onUploadComplete( $uploadBase ) {
		$summary = $this->upload_screener->last_approved_debug_summary();
		if ( $summary === null ) {
			return;
		}

		$session = RequestContext::getMain()->getRequest()->getSession();
		$session->persist();
		$session->set( self::SESSION_KEY, $summary );
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		$session = $out->getRequest()->getSession();
		$summary = $session->get( self::SESSION_KEY );
		if ( !is_string( $summary ) || $summary === '' ) {
			return;
		}

		$session->remove( self::SESSION_KEY );
		$out->prependHTML( Html::noticeBox(
			wfMessage( 'uploadsafety-debug-approved', $summary )->escaped(),
			'uploadsafety-debug'
		) );
	}

	/** @inheritDoc */
	public function onAPIAfterExecute( $module ) {
		if ( $module->getModuleName() !== 'upload' ) {
			return;
		}

		$summary = $this->upload_screener->last_approved_debug_summary();
		if ( $summary === null ) {
			return;
		}

		$module->getResult()->addValue( null, 'uploadsafety', [ 'debug' => $summary, 'rejected' => false ] );
	}

}
