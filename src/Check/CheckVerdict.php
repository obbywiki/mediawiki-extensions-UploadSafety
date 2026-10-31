<?php

namespace MediaWiki\Extension\UploadSafety\Check;

enum CheckVerdict: string {

	case Pass = 'pass';
	// doubtful: at or above the check's `suspect` level but under its threshold.
	// Rejects only together with other suspect checks ($wgUploadSafetySuspectsToReject), otherwise passes and is logged for review.
	case Suspect = 'suspect';
	case Reject = 'reject';
	// the check could not run (service down, bad response), UploadSafetyFailClosed decides what happens
	case Unavailable = 'unavailable';

}
