<?php

namespace MediaWiki\Extension\UploadSafety;

use MediaWiki\Context\RequestContext;
use MediaWiki\Page\PageReference;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Permissions\RateLimiter;
use MediaWiki\User\User;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Automatic screening (core uploads, IntegratedProfiles images): UploadScreener plus the side effects a wiki wants when it screens on its own, i.e. rate limits, a verdict cache and the rejection log.
 * Other callers (e.g. a moderation queue) can use UploadScreener directly and decide those for themselves instead.
 */
class AutoScreening {

	// every image sent to the sidecar (so a single account cannot tie it up)
	public const LIMIT_SCREEN = 'uploadsafety-screen';
	// rejections over a short window, generous enough for one page's worth of false positives
	public const LIMIT_REJECT = 'uploadsafety-reject';
	// rejections over a day, to slow down probing for the threshold
	public const LIMIT_REJECT_DAILY = 'uploadsafety-reject-daily';

	public const MSG_SCREEN_LIMITED = 'uploadsafety-upload-screen-limited';
	public const MSG_REJECT_LIMITED = 'uploadsafety-upload-reject-limited';

	private const VERDICT_TTL = 86400;

	private ?ScreeningResult $last_result = null;

	public function __construct(
		private readonly UploadScreener $screener,
		private readonly RateLimiter $rate_limiter,
		private readonly WANObjectCache $cache,
		private readonly RejectionLog $log,
	) {
	}

	/**
	 * @param User|null $performer Uploader, the current request's user when null
	 * @param string $file_path Uploaded file
	 * @param string $mime MediaWiki-detected MIME type
	 * @param string $filename Destination name, for logs
	 * @param string $surface upload, avatar, banner, ...
	 * @param PageReference|null $target Log target, the uploader's user page when null
	 */
	public function screen( ?User $performer, string $file_path, string $mime, string $filename, string $surface, ?PageReference $target = null ): ScreeningResult {
		$performer ??= RequestContext::getMain()->getUser();
		$this->last_result = $this->decide( $performer, $file_path, $mime, $filename, $surface, $target );

		return $this->last_result;
	}

	/**
	 * Screening detail for an allowed image in the current request, or null when debug is off.
	 */
	public function last_approved_debug_summary(): ?string {
		$result = $this->last_result;
		if ( !$this->screener->debug_enabled() || $result === null || $result->rejected || $result->skipped() ) {
			return null;
		}

		return 'rejected=0 ' . $result->debug_summary();
	}

	private function decide( User $performer, string $file_path, string $mime, string $filename, string $surface, ?PageReference $target ): ScreeningResult {
		$subject = $performer->toRateLimitSubject();
		$user_page = PageReferenceValue::localReference( NS_USER, strtr( $performer->getName(), ' ', '_' ) );

		// refuse before doing anything once someone has been rejected too often
		if (
			$this->rate_limiter->limit( $subject, self::LIMIT_REJECT, 0 ) ||
			$this->rate_limiter->limit( $subject, self::LIMIT_REJECT_DAILY, 0 )
		) {
			return new ScreeningResult( true, self::MSG_REJECT_LIMITED, [], 'limited=reject' );
		}

		$sha1 = is_readable( $file_path ) ? sha1_file( $file_path ) : false;
		$cache_key = is_string( $sha1 ) ? $this->cache_key( $sha1 ) : null;

		$cached = $cache_key !== null ? ScreeningResult::from_array( $this->cache->get( $cache_key ) ) : null;
		if ( $cached !== null ) {
			$result = $cached->with_detail( trim( 'cached=1 ' . $cached->detail ) );
			$this->screener->log_result( $result, $file_path, $mime, $filename, $surface );
			if ( $result->rejecting_check() !== null ) {
				// the same file again teaches the uploader nothing, so it is logged but not counted
				$this->log->reject( $performer, $target ?? $user_page, $surface, $result, (string)$sha1, true );
			}

			return $result;
		}

		if ( $this->rate_limiter->limit( $subject, self::LIMIT_SCREEN ) ) {
			return new ScreeningResult( true, self::MSG_SCREEN_LIMITED, [], 'limited=screen' );
		}

		$result = $this->screener->screen( $file_path, $mime, $filename, $surface );

		if ( $cache_key !== null && $result->cacheable() ) {
			$this->cache->set( $cache_key, $result->to_array(), self::VERDICT_TTL );
		}

		if ( $result->rejecting_check() !== null && is_string( $sha1 ) ) {
			$this->log->reject( $performer, $target ?? $user_page, $surface, $result, $sha1, false );
			$this->count_rejection( $performer, $user_page, $surface );
		}

		return $result;
	}

	private function count_rejection( User $performer, PageReference $user_page, string $surface ): void {
		$subject = $performer->toRateLimitSubject();
		foreach ( [ self::LIMIT_REJECT, self::LIMIT_REJECT_DAILY ] as $limit ) {
			$was_limited = $this->rate_limiter->limit( $subject, $limit, 0 );
			$this->rate_limiter->limit( $subject, $limit );

			// log once, when this rejection is the one that reaches the limit
			if ( !$was_limited && $this->rate_limiter->limit( $subject, $limit, 0 ) ) {
				$this->log->limited( $performer, $user_page, $surface, $limit );
			}
		}
	}

	private function cache_key( string $sha1 ): string {
		// verdicts depend on the checks and their parameters, so changing them invalidates the cache
		$fingerprint = md5( json_encode( [ $this->screener->enabled_checks(), $this->screener->max_frames() ] ) );

		return $this->cache->makeKey( 'uploadsafety', 'verdict', $sha1, $fingerprint );
	}

}
