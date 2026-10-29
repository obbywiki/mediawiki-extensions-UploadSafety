<?php

namespace MediaWiki\Extension\UploadSafety;

use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Page\PageReference;
use MediaWiki\User\UserIdentity;

/**
 * Writes the restricted `uploadsafety` log (visible with the `uploadsafety-log` right).
 * Entries never hold the image itself, only its SHA-1.
 */
class RejectionLog {

	public const TYPE = 'uploadsafety';

	/**
	 * An upload was rejected by a check.
	 *
	 * @param UserIdentity $performer Uploader
	 * @param PageReference $target `File:` page for uploads, profile owner's user page for avatars and banners
	 * @param string $surface upload, avatar, banner, ...
	 * @param ScreeningResult $result
	 * @param string $sha1 Hex SHA-1 of the uploaded file
	 * @param bool $repeat Whether the same file was already rejected recently
	 */
	public function reject( UserIdentity $performer, PageReference $target, string $surface, ScreeningResult $result, string $sha1, bool $repeat ): void {
		[ $check, $check_result ] = $result->rejecting_check() ?? [ '', null ];
		$score = $check_result?->score;

		$this->insert( 'reject', $performer, $target, [
			'4::surface' => $surface,
			'5::check' => $check,
			'6::score' => $score === null ? '' : rtrim( rtrim( sprintf( '%.4f', $score ), '0' ), '.' ),
			'7::sha1' => $sha1,
			'repeat' => $repeat,
		] );
	}

	/**
	 * The uploader just reached a rejection rate limit; further uploads are refused until it resets.
	 *
	 * @param UserIdentity $performer Uploader
	 * @param PageReference $target Uploader's user page
	 * @param string $surface Surface of the upload that reached the limit
	 * @param string $limit Rate limit action that was reached
	 */
	public function limited( UserIdentity $performer, PageReference $target, string $surface, string $limit ): void {
		$this->insert( 'limited', $performer, $target, [
			'4::surface' => $surface,
			'5::limit' => $limit,
		] );
	}

	/**
	 * @param string $action
	 * @param UserIdentity $performer
	 * @param PageReference $target
	 * @param array<string, mixed> $params
	 */
	private function insert( string $action, UserIdentity $performer, PageReference $target, array $params ): void {
		$entry = new ManualLogEntry( self::TYPE, $action );
		$entry->setPerformer( $performer );
		$entry->setTarget( $target );
		$entry->setParameters( $params );
		// restricted log, so not published to RecentChanges
		$entry->insert();
	}

}
