<?php

namespace MediaWiki\Extension\UploadSafety;

use MediaWiki\Upload\Hook\UploadVerifyUploadHook;
use Wikimedia\Mime\MimeAnalyzer;

/**
 * Restricts SVG uploads to users with the `uploadsafety-upload-svg` right (sysops by default).
 * SVGs are not screened, and core allows them to embed raster images, so any image could otherwise be wrapped in one to skip screening.
 * Runs on UploadVerifyUpload, which every upload passes through right before it is stored (including stashed and chunked uploads).
 */
class SvgUploadHooks implements UploadVerifyUploadHook {

	public const RIGHT = 'uploadsafety-upload-svg';
	public const MSG_RESTRICTED = 'uploadsafety-upload-svg-restricted';

	private const SVG_MIME = 'image/svg+xml';
	private const SVG_EXTENSIONS = [ 'svg', 'svgz' ];

	public function __construct(
		private readonly MimeAnalyzer $mime_analyzer,
	) {
	}

	/** @inheritDoc */
	public function onUploadVerifyUpload( $upload, $user, $props, $comment, $pageText, &$error ) {
		if ( $user->isAllowed( self::RIGHT ) || !$this->is_svg( $upload->getTempPath(), $props, (string)$upload->getDesiredDestName() ) ) {
			return;
		}

		$error = [ self::MSG_RESTRICTED ];

		return false;
	}

	/**
	 * @param string|null $path Uploaded file
	 * @param array|null $props File properties from MWFileProps, when the upload has them
	 * @param string $dest_name Destination file name
	 */
	private function is_svg( ?string $path, ?array $props, string $dest_name ): bool {
		$extension = strtolower( pathinfo( $dest_name, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, self::SVG_EXTENSIONS, true ) ) {
			return true;
		}

		$mime = $props['mime'] ?? null;
		if ( !is_string( $mime ) && $path !== null && $path !== '' && is_readable( $path ) ) {
			$mime = $this->mime_analyzer->guessMimeType( $path, false );
		}

		return is_string( $mime ) && strtolower( $mime ) === self::SVG_MIME;
	}

}
