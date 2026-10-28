<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\UploadSafety\Check\NsfwClassifierCheck;
use MediaWiki\Extension\UploadSafety\UploadScreener;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'UploadSafety.NsfwClassifierCheck' => static function ( MediaWikiServices $services ): NsfwClassifierCheck {
		return new NsfwClassifierCheck(
			$services->getHttpRequestFactory(),
			new ServiceOptions(
				NsfwClassifierCheck::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			LoggerFactory::getInstance( 'UploadSafety' )
		);
	},

	'UploadSafety.UploadScreener' => static function ( MediaWikiServices $services ): UploadScreener {
		return new UploadScreener(
			new ServiceOptions(
				UploadScreener::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			[
				$services->get( 'UploadSafety.NsfwClassifierCheck' ),
			],
			LoggerFactory::getInstance( 'UploadSafety' )
		);
	},
];
