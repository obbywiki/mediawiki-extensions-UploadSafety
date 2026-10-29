<?php

namespace MediaWiki\Extension\UploadSafety;

use MediaWiki\Extension\IntegratedProfiles\Hook\IntegratedProfilesVerifyImageUploadHook;
use MediaWiki\User\UserIdentity;

/**
 * Screens IntegratedProfiles avatar and banner uploads.
 * Registered as its own handler so this class (and the IntegratedProfiles interface) only loads when IntegratedProfiles runs the hook.
 */
class IntegratedProfilesHooks implements IntegratedProfilesVerifyImageUploadHook {

	public function __construct(
		private readonly UploadScreener $upload_screener,
	) {
	}

	/** @inheritDoc */
	public function onIntegratedProfilesVerifyImageUpload( string $kind, string $path, string $mime, int $central_id, ?UserIdentity $user, &$error ) {
		if ( !$this->upload_screener->auto_screens( $kind ) || !ImageTypes::is_screenable_mime( $mime ) ) {
			return;
		}

		$filename = $kind . ':' . $central_id . ( $user !== null ? ':' . $user->getName() : '' );
		$result = $this->upload_screener->screen( $path, $mime, $filename, $kind );
		if ( $result->rejected ) {
			$error = $this->upload_screener->rejection_error( $result );

			return false;
		}
	}

}
