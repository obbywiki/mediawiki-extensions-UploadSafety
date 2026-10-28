<?php

namespace MediaWiki\Extension\UploadSafety\Check;

/**
 * A single screening step run against an uploaded image (e.g. an NSFW classifier, OCR, hash matching).
 * UploadScreener runs every configured check and decides the outcome from their results.
 */
interface ImageCheck {

	/**
	 * Stable identifier used in logs and debug output.
	 */
	public function name(): string;

	/**
	 * @param string $file_path Readable raster image
	 * @param string $mime Detected MIME type
	 */
	public function check( string $file_path, string $mime ): CheckResult;

}
