<?php

class SyncBeaverBuilderApiRequest
{
	private static $_instance = NULL;
	private $_push_data;

	const BB_SETTINGS = 'bb-settings';

	const ERROR_SETTINGS_DATA_NOT_FOUND = 700;

	const NOTICE_ = 700;

	const RESULT_PRESENT = 700;

	/**
	 * Retrieve singleton class instance
	 * @since 1.0.0
	 * @return SyncBeaverBuilderApiRequest instance reference API Request
	 */
	public static function get_instance()
	{
		if (NULL === self::$_instance)
			self::$_instance = new self();
		return self::$_instance;
	}

	/**
	 * Filters the errors list, adding SyncBeaverBuilder specific code-to-string values
	 * @param string $message The error string message to be returned
	 * @param int $code The error code being evaluated
	 * @return string The modified $message string, with Pull specific errors added to it
	 */
	public function filter_error_codes($message, $code)
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
	 * @return string The modified $message string, with Pull specific notices added to it
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

	/**
	 * Checks the API request if the action is to pull/push the Beaver Builder settings
	 * @param array $args The arguments array sent to SyncApiRequest::api()
	 * @param string $action The API requested
	 * @param array $remote_args Array of arguments sent to SyncRequestApi::api()
	 * @return array The modified $args array, with any additional information added to it
	 */
	public function api_request($args, $action, $remote_args)
	{
SyncDebug::log(__METHOD__ . '() action=' . $action);

		if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_beaverbuilder', WPSiteSync_BeaverBuilder::PLUGIN_KEY, WPSiteSync_BeaverBuilder::PLUGIN_NAME))
			return $args;

		if ('pushbeaverbuildersettings' === $action) {
SyncDebug::log(__METHOD__ . '() args=' . var_export($args, TRUE));

			$push_data = array();
			// these values are not to be pushed: version number and license email
			$settings_exclude = array(
				'_fl_builder_version',
				'fl_themes_subscription_email',
			);

			// read all options for Beaver Builder
			global $wpdb;
			$sql = "SELECT *
					FROM `{$wpdb->options}`
					WHERE `option_name` LIKE '_fl_builder%' OR `option_name` LIKE 'fl-builder%'";
			$res = $wpdb->get_results($sql, OBJECT);
			foreach ($res as $row) {
				if (!in_array($row->option_name, $settings_exclude)) {
					$push_data['settings'][$row->option_name] = $row->option_value;
					$push_data['autoload'][$row->option_name] = $row->autoload;
				}
			}

SyncDebug::log(__METHOD__ . '() push_data=' . var_export($push_data, TRUE));

			$args['push_data'] = $push_data;
			$args[self::BB_SETTINGS] = WPSiteSync_BeaverBuilder::PLUGIN_VERSION;
		} else if ('pullbeaverbuildersettings' === $action) {
SyncDebug::log(__METHOD__ . '() args=' . var_export($args, TRUE));
		}

		// return the filter value
		return $args;
	}

	/**
	 * Handles the requests being processed on the Target from SyncApiController
	 * @param boolean $return filter value
	 * @param string $action The API request action
	 * @param SyncApiResponse $response The response instance
	 * @return boolean $response TRUE when handling a API request action; otherwise FALSE
	 */
	public function api_controller_request($return, $action, SyncApiResponse $response)
	{
SyncDebug::log(__METHOD__ . "() handling '{$action}' action");

		if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_beaverbuilder', WPSiteSync_BeaverBuilder::PLUGIN_KEY, WPSiteSync_BeaverBuilder::PLUGIN_NAME))
			return TRUE;

		// tell API caller that we're here
		$response->result_code(self::RESULT_PRESENT);

		if ('pushbeaverbuildersettings' === $action) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' post=' . var_export($_POST, TRUE));
			$input = new SyncInput();
			if (FALSE === $input->post_raw(self::BB_SETTINGS, FALSE)) {
				$response->error_code(self::ERROR_SETTINGS_DATA_NOT_FOUND);
				return TRUE;
			}

			$this->_push_data = $input->post_raw('push_data', array());
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found push_data information: ' . var_export($this->_push_data, TRUE));

			// check api parameters
			if (empty($this->_push_data) || empty($this->_push_data['settings'])) {
				$response->error_code(self::ERROR_SETTINGS_DATA_NOT_FOUND);
				return TRUE;            // return, signaling that the API request was processed
			}

SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' processing settings');
			$controller = SyncApiController::get_instance();
			$source_url = $controller->source;
			$target_url = site_url();

			// update settings based on API content
			foreach ($this->_push_data['settings'] as $setting_key => $setting_value) {
				// fixup data
				$setting_value = stripslashes($setting_value);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' key=' . $setting_key . ' data=' . var_export($setting_value, TRUE));
				if (FALSE !== strpos($setting_value, $source_url)) {
					$setting_value = str_replace($source_url, $target_url, $setting_value);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' changing url to ' . var_export($setting_value, TRUE));
				}
				$setting_value = maybe_unserialize($setting_value);

				// determine autoload state
				$auto = 'no';
				if (isset($this->_push_data['autoload'][$setting_key]))
					$auto = $this->_push_data['autoload'][$setting_key];

				if (FALSE === get_option($setting_key, FALSE)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' adding');
					// setting does not already exist - add it
					// add the option
					add_option($setting_key, $setting_value, NULL, $auto);
				} else {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' updating');
					// update the existing option
					update_option($setting_key, $setting_value, $auto);
				}
			}
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' completed processing');
			return TRUE;
		} else if ('pullbeaverbuildersettings' === $action) {
		}

		return $return;
	}

	/**
	 * Handles the request on the Source after API Requests are made and the response is ready to be interpreted
	 *
	 * @param string $action The API name, i.e. 'push' or 'pull'
	 * @param array $remote_args The arguments sent to SyncApiRequest::api()
	 * @param SyncApiResponse $response The response object after the API requesst has been made
	 */
	public function api_response($action, $remote_args, $response)
	{
SyncDebug::log(__METHOD__ . "('{$action}')");

		if ('pushbeaverbuildersettings' === $action) {
SyncDebug::log(__METHOD__ . '() response from API request: ' . var_export($response, TRUE));

			$api_response = NULL;

			if (isset($response->response)) {
SyncDebug::log(__METHOD__ . '() decoding response: ' . var_export($response->response, TRUE));
				$api_response = $response->response;
			} else {
SyncDebug::log(__METHOD__ . '() no reponse->response element');
			}

SyncDebug::log(__METHOD__ . '() api response body=' . var_export($api_response, TRUE));

			if (0 === $response->get_error_code()) {
				$response->success(TRUE);
			}
			// TODO: add check for result code, display error if not present
		} else if ('pullbeaverbuildersettings' === $action) {
SyncDebug::log(__METHOD__ . '() response from API request: ' . var_export($response, TRUE));

			$api_response = NULL;

			if (isset($response->response)) {
SyncDebug::log(__METHOD__ . '() decoding response: ' . var_export($response->response, TRUE));
				$api_response = $response->response;
			} else {
SyncDebug::log(__METHOD__ . '() no response->response element');
			}

SyncDebug::log(__METHOD__ . '() api response body=' . var_export($api_response, TRUE));

			if (NULL !== $api_response) {
				$save_post = $_POST;

				// convert the pull data into an array
				$pull_data = json_decode(json_encode($api_response->data->pull_data), TRUE); // $response->response->data->pull_data;
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - pull data=' . var_export($pull_data, TRUE));
				$site_key = $api_response->data->site_key; // $pull_data->site_key;
				$target_url = SyncOptions::get('target');
				$pull_data['site_key'] = $site_key;
				$pull_data['pull'] = TRUE;

				$_POST['push_data'] = $pull_data;
				$_POST[self::BB_SETTINGS] = WPSiteSync_BeaverBuilder::PLUGIN_VERSION;
				$_POST['action'] = 'pushbeaverbuildersettings';

				$args = array(
					'action' => 'pushbeaverbuildersettings',
					'parent_action' => 'pullbeaverbuildersettings',
					'site_key' => $site_key,
					'source' => $target_url,
					'response' => $response,
					'auth' => 0,
				);

SyncDebug::log(__METHOD__ . '() creating controller with: ' . var_export($args, TRUE));
				$this->_push_controller = new SyncApiController($args);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - returned from controller');
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - response=' . var_export($response, TRUE));

				$_POST = $save_post;

				if (0 === $response->get_error_code()) {
					$response->success(TRUE);
				}
			}
		}
	}
}

// EOF
