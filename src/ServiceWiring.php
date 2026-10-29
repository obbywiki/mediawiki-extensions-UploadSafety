<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\UploadSafety\Check\NsfwEvaluator;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarClient;
use MediaWiki\Extension\UploadSafety\Sidecar\SidecarInfo;
use MediaWiki\Extension\UploadSafety\UploadScreener;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'UploadSafety.SidecarClient' => static function ( MediaWikiServices $services ): SidecarClient {
		return new SidecarClient(
			$services->getHttpRequestFactory(),
			new ServiceOptions(
				SidecarClient::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			LoggerFactory::getInstance( 'UploadSafety' )
		);
	},

	'UploadSafety.SidecarInfo' => static function ( MediaWikiServices $services ): SidecarInfo {
		return new SidecarInfo(
			$services->get( 'UploadSafety.SidecarClient' ),
			$services->getLocalServerObjectCache()
		);
	},

	'UploadSafety.UploadScreener' => static function ( MediaWikiServices $services ): UploadScreener {
		return new UploadScreener(
			new ServiceOptions(
				UploadScreener::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->get( 'UploadSafety.SidecarClient' ),
			[
				new NsfwEvaluator(),
			],
			LoggerFactory::getInstance( 'UploadSafety' )
		);
	},
];
