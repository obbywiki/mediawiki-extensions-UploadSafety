<?php

namespace MediaWiki\Extension\UploadSafety;

use MediaWiki\Upload\Hook\UploadVerifyUploadHook;
use Wikimedia\Mime\MimeAnalyzer;

/**
 * Refuses core uploads in formats the checks cannot screen, see UnscreenedFormats.
 *
 * Runs on UploadVerifyUpload, which every upload passes through right before it is stored (including stashed and chunked uploads).
 */
class UnscreenedUploadHooks implements UploadVerifyUploadHook {

	public function __construct(
		private readonly UnscreenedFormats $unscreened_formats,
		private readonly MimeAnalyzer $mime_analyzer,
	) {
	}

	/** @inheritDoc */
	public function onUploadVerifyUpload( $upload, $user, $props, $comment, $pageText, &$error ) {
		if ( $this->unscreened_formats->allows( $this->mime( $upload->getTempPath(), $props ), $user ) ) {
			return;
		}

		$error = [ UnscreenedFormats::MSG_RESTRICTED ];

		return false;
	}

	/**
	 * Detected MIME type, or '' when it cannot be determined (which counts as unscreened).
	 *
	 * @param string|null $path Uploaded file
	 * @param array|null $props File properties from MWFileProps, when the upload has them
	 */
	private function mime( ?string $path, ?array $props ): string {
		$mime = $props['mime'] ?? null;
		if ( !is_string( $mime ) && $path !== null && $path !== '' && is_readable( $path ) ) {
			$mime = $this->mime_analyzer->guessMimeType( $path, false );
		}

		return is_string( $mime ) ? $mime : '';
	}

}
