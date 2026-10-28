<?php

namespace MediaWiki\Extension\UploadSafety\Check;

enum CheckVerdict: string {

	case Pass = 'pass';
	case Reject = 'reject';
	// the check could not run (service down, bad response), UploadSafetyFailClosed decides what happens
	case Unavailable = 'unavailable';

}
