<?php

namespace MediaWiki\Extension\UploadSafety\Sidecar;

/**
 * A `200` response from `POST /v1/check`.
 *
 * @see docs/sidecar-api.md
 */
class SidecarResponse {

	/**
	 * @param array<string, mixed> $checks Raw signal object per check name
	 * @param array<string, mixed> $image Decoded image description (format, width, height, frames)
	 */
	public function __construct(
		public readonly array $checks,
		public readonly array $image = [],
	) {
	}

	/**
	 * @param mixed $data Decoded JSON body
	 */
	public static function from_json( mixed $data ): ?self {
		if ( !is_array( $data ) || !isset( $data['checks'] ) || !is_array( $data['checks'] ) ) {
			return null;
		}

		if ( $data['checks'] !== [] && array_is_list( $data['checks'] ) ) {
			return null;
		}

		$image = isset( $data['image'] ) && is_array( $data['image'] ) ? $data['image'] : [];

		return new self( $data['checks'], $image );
	}

	/**
	 * Raw signal for one check, or null when the sidecar left it out.
	 *
	 * @return array<string, mixed>|null
	 */
	public function signal( string $check ): ?array {
		$signal = $this->checks[$check] ?? null;

		return is_array( $signal ) ? $signal : null;
	}

	/**
	 * Frame coverage as `analyzed/distinct/total`, or an empty string when not reported.
	 */
	public function frames_summary(): string {
		$frames = $this->image['frames'] ?? null;
		if ( !is_array( $frames ) ) {
			return '';
		}

		$values = [];
		foreach ( [ 'analyzed', 'distinct', 'total' ] as $key ) {
			$values[] = is_int( $frames[$key] ?? null ) ? (string)$frames[$key] : '?';
		}

		return implode( '/', $values );
	}

}
