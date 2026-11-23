<?php

namespace MediaWiki\Extension\UploadSafety\Check;

/**
 * Implemented by evaluators whose rejections deserve their own message, because the generic one would leave the uploader guessing (e.g. a photo of a real person).
 * Only used when every check that rejected an upload has the same message.
 */
interface RejectionMessage {

	/**
	 * Message key shown when this check rejects an upload. A `-debug` variant taking the screening detail as $1 must exist too.
	 */
	public function rejection_message(): string;

}
