<?php

namespace MediaWiki\Extension\UploadSafety;

/**
 * Decides which uploads are raster images the checks can decode. SVG and other `image/*` drawing formats are excluded,
 * and are handled by UnscreenedFormats instead.
 */
class ImageTypes {

	private const SCREENABLE_MIME_TYPES = [
		'image/avif',
		'image/bmp',
		'image/gif',
		'image/jpeg',
		'image/jpg',
		'image/pjpeg',
		'image/png',
		'image/tiff',
		'image/webp',
		'image/x-bmp',
		'image/x-ms-bmp',
		'image/x-png',
		'image/x-tiff',
	];

	private const SCREENABLE_IMAGE_TYPES = [
		IMAGETYPE_AVIF,
		IMAGETYPE_BMP,
		IMAGETYPE_GIF,
		IMAGETYPE_JPEG,
		IMAGETYPE_PNG,
		IMAGETYPE_TIFF_II,
		IMAGETYPE_TIFF_MM,
		IMAGETYPE_WEBP,
	];

	/**
	 * True when MediaWiki reports a raster MIME type the checks can decode, false if otherwise.
	 */
	public static function is_screenable_mime( string $mime ): bool {
		return in_array( self::normalize_mime( $mime ), self::SCREENABLE_MIME_TYPES, true );
	}

	/**
	 * True when the file magic bytes identify a raster format the checks can decode, false if otherwise.
	 */
	public static function is_raster_image( string $file_path ): bool {
		$image_type = self::detect_image_type( $file_path );

		return $image_type !== false && in_array( $image_type, self::SCREENABLE_IMAGE_TYPES, true );
	}

	/**
	 * Lowercase MIME type without parameters.
	 */
	public static function normalize_mime( string $mime ): string {
		$mime = strtolower( trim( $mime ) );
		$semicolon = strpos( $mime, ';' );
		if ( $semicolon !== false ) {
			$mime = trim( substr( $mime, 0, $semicolon ) );
		}

		return $mime;
	}

	/**
	 * @return int|false
	 */
	private static function detect_image_type( string $file_path ) {
		if ( function_exists( 'exif_imagetype' ) ) {
			$type = @exif_imagetype( $file_path );
			if ( $type !== false ) {
				return $type;
			}
		}

		$image_info = @getimagesize( $file_path );
		if ( $image_info === false ) {
			return false;
		}

		return $image_info[2] ?? false;
	}

}
