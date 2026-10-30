<?php

namespace MediaWiki\Extension\UploadSafety;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\IntegratedProfiles\Hook\IntegratedProfilesVerifyImageUploadHook;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\User\UserIdentity;

/**
 * Screens IntegratedProfiles avatar and banner uploads.
 * Registered as its own handler so this class (and the IntegratedProfiles interface) only loads when IntegratedProfiles runs the hook.
 */
class IntegratedProfilesHooks implements IntegratedProfilesVerifyImageUploadHook {

	public function __construct(
		private readonly UploadScreener $upload_screener,
		private readonly AutoScreening $auto_screening,
		private readonly UnscreenedFormats $unscreened_formats,
	) {
	}

	/** @inheritDoc */
	public function onIntegratedProfilesVerifyImageUpload( string $kind, string $path, string $mime, int $central_id, ?UserIdentity $user, &$error ) {
		if ( !ImageTypes::is_screenable_mime( $mime ) ) {
			if ( $this->unscreened_formats->allows( $mime, RequestContext::getMain()->getAuthority() ) ) {
				return;
			}
			$error = [ UnscreenedFormats::MSG_RESTRICTED ];

			return false;
		}

		if ( !$this->upload_screener->auto_screens( $kind ) ) {
			return;
		}

		$filename = $kind . ':' . $central_id . ( $user !== null ? ':' . $user->getName() : '' );
		$target = $user !== null ? PageReferenceValue::localReference( NS_USER, strtr( $user->getName(), ' ', '_' ) ) : null;
		$result = $this->auto_screening->screen( null, $path, $mime, $filename, $kind, $target );
		if ( $result->rejected ) {
			$error = $this->upload_screener->rejection_error( $result );

			return false;
		}
	}

}
