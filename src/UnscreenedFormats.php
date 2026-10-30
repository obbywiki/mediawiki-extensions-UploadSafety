<?php

namespace MediaWiki\Extension\UploadSafety;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Permissions\Authority;

/**
 * Decides who may upload files in formats the checks cannot screen (SVG, PDF, video, ...).
 *
 * Unscreened formats are refused by default unless the uploader has the `uploadsafety-upload-unscreened` user right, because a format that is let through untouched is a gap through screening (e.g. a photo could be embedded in an SVG).
 */
class UnscreenedFormats {

	public const CONSTRUCTOR_OPTIONS = [ 'UploadSafetyBlockUnscreened', 'UploadSafetyUnscreenedAllowed' ];

	public const RIGHT = 'uploadsafety-upload-unscreened';
	public const MSG_RESTRICTED = 'uploadsafety-upload-unscreened-restricted';

	public function __construct(
		private readonly ServiceOptions $options,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * Returns true when $performer may upload a file of type $mime.
	 */
	public function allows( string $mime, Authority $performer ): bool {
		if ( ImageTypes::is_screenable_mime( $mime ) || !$this->options->get( 'UploadSafetyBlockUnscreened' ) ) {
			return true;
		}

		return $this->is_allowed_for_everyone( $mime ) || $performer->isAllowed( self::RIGHT );
	}

	private function is_allowed_for_everyone( string $mime ): bool {
		$mime = ImageTypes::normalize_mime( $mime );
		if ( $mime === '' ) {
			return false;
		}

		foreach ( (array)$this->options->get( 'UploadSafetyUnscreenedAllowed' ) as $allowed ) {
			$allowed = ImageTypes::normalize_mime( (string)$allowed );
			if ( $allowed === $mime || ( str_ends_with( $allowed, '/*' ) && str_starts_with( $mime, substr( $allowed, 0, -1 ) ) ) ) {
				return true;
			}
		}

		return false;
	}

}
