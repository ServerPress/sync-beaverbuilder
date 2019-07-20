<?php

class SyncBeaverBuilderApiRequest extends SyncInput
{
	private static $_instance = NULL;

	const BB_SETTINGS = 'bb-settings';

	const API_PUSH_SETTINGS = 'pushbeaverbuildersettings';			// push settings API call
	const API_PULL_SETTINGS = 'pullbeaverbuildersettings';			// pull settings API call
	const API_IMAGE_REFS = 'beaverbuilderimagerefs';				// image reference API call

	const ERROR_SETTINGS_DATA_NOT_FOUND = 700;

	const NOTICE_ = 700;

	/**
	 * Filters the errors list, adding SyncBeaverBuilder specific code-to-string values
	 * @param string $message The error string message to be returned
	 * @param int $code The error code being evaluated
	 * @return string The modified $message string, with Beaver Builder specific errors added to it
	 */
	public function filter_error_codes($message, $code, $data)
	{
		switch ($code) {
		case self::ERROR_SETTINGS_DATA_NOT_FOUND:	$message = __('No settings data contained in API request.', 'wpsitesync-beaverbuilder'); break;
		}
		return $message;
	}

	/**
	 * Filters the notices list, adding SyncBeaverBuilder specific code-to-string values
	 * @param string $message The notice string message to be returned
	 * @param int $code The notice code being evaluated
	 * @return string The modified $message string, with Beaver Builder specific notices added to it
	 */
	public function filter_notice_codes($message, $code)
	{
		switch ($code) {
		case self::NOTICE_:
			$message = __('notice code', 'wpsitesync-beaverbuilder');
			break;
		}
		return $message;
	}
}

// EOF
