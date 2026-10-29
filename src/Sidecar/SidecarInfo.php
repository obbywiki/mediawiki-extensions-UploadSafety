<?php

namespace MediaWiki\Extension\UploadSafety\Sidecar;

use Wikimedia\ObjectCache\BagOStuff;

/**
 * Cached `GET /v1/info`, used for Special:Version.
 * Only the service version and `runtime` are exposed; service and model names stay private, model names since they help with crafting adversarial images.
 */
class SidecarInfo {

	private const TTL = 300;
	private const FAILURE_TTL = 30;

	public function __construct(
		private readonly SidecarClient $client,
		private readonly BagOStuff $cache,
	) {
	}

	/**
	 * Entries for the SoftwareInfo hook (`[url Name]` or `Name` => version), or none when the sidecar is unreachable.
	 *
	 * @return array<string, string>
	 */
	public function software_entries(): array {
		$info = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'uploadsafety', 'sidecar-info', 1 ),
			self::TTL,
			function ( &$ttl ) {
				$info = $this->client->info();
				if ( $info === null ) {
					$ttl = self::FAILURE_TTL;

					return [];
				}

				return $info;
			}
		);

		if ( !is_array( $info ) ) {
			return [];
		}

		$entries = [];
		$software = [ $info['service'] ?? null ];
		if ( is_array( $info['runtime'] ?? null ) ) {
			array_push( $software, ...array_values( $info['runtime'] ) );
		}

		foreach ( $software as $index => $entry ) {
			if ( !is_array( $entry ) || !is_string( $entry['name'] ?? null ) || !is_string( $entry['version'] ?? null ) ) {
				continue;
			}

			// the service's own name is not shown, so a sidecar named after its checks cannot leak them onto the page
			$name = $index === 0 ? 'UploadSafety sidecar' : self::wikitext_safe( $entry['name'] );
			$url = $entry['url'] ?? null;
			$label = is_string( $url ) && preg_match( '#^https?://[^\s\[\]]+$#', $url ) ? '[' . $url . ' ' . $name . ']' : $name;

			$entries[$label] = self::wikitext_safe( $entry['version'] );
		}

		return $entries;
	}

	private static function wikitext_safe( string $value ): string {
		return trim( preg_replace( '/[^\w .+\-()]/u', '', $value ) ?? '' );
	}

}
