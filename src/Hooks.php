<?php

namespace MediaWiki\Extension\UploadSafety;

use MediaWiki\Api\Hook\APIAfterExecuteHook;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarInfo;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Html\Html;
use MediaWiki\Specials\Hook\SoftwareInfoHook;
use MediaWiki\Title\Title;
use MediaWiki\Upload\Hook\UploadCompleteHook;
use MediaWiki\Upload\Hook\UploadVerifyFileHook;

class Hooks implements UploadVerifyFileHook, UploadCompleteHook, BeforePageDisplayHook, APIAfterExecuteHook, SoftwareInfoHook {

	private const SESSION_KEY = 'uploadsafety-debug';

	public function __construct(
		private readonly UploadScreener $upload_screener,
		private readonly AutoScreening $auto_screening,
		private readonly SidecarInfo $sidecar_info,
		private readonly Config $config,
	) {
	}

	/** @inheritDoc */
	public function onUploadVerifyFile( $upload, $mime, &$error ) {
		$mime = (string)$mime;
		if ( !$this->upload_screener->auto_screens( 'upload' ) || !ImageTypes::is_screenable_mime( $mime ) ) {
			return;
		}

		$filename = (string)$upload->getDesiredDestName();
		$target = $filename !== '' ? Title::makeTitleSafe( NS_FILE, $filename ) : null;
		$result = $this->auto_screening->screen(
			null,
			$upload->getTempPath(),
			$mime,
			$filename,
			'upload',
			$target
		);
		if ( $result->rejected ) {
			$error = $this->upload_screener->rejection_error( $result );
		}
	}

	/** @inheritDoc */
	public function onUploadComplete( $uploadBase ) {
		$summary = $this->auto_screening->last_approved_debug_summary();
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

		$summary = $this->auto_screening->last_approved_debug_summary();
		if ( $summary === null ) {
			return;
		}

		$module->getResult()->addValue( null, 'uploadsafety', [ 'debug' => $summary, 'rejected' => false ] );
	}

	/** @inheritDoc */
	public function onSoftwareInfo( &$software ) {
		if ( !$this->config->get( 'UploadSafetyShowSidecarVersion' ) ) {
			return;
		}

		foreach ( $this->sidecar_info->software_entries() as $name => $version ) {
			$software[$name] = $version;
		}
	}

}
