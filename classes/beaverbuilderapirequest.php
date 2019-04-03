<?php

class SyncBeaverBuilderApiRequest extends SyncInput
{
	private static $_instance = NULL;
	private $_push_data;

	const BB_SETTINGS = 'bb-settings';

	const API_PUSH_SETTINGS = 'pushbeaverbuildersettings';			// push settings API call
	const API_PULL_SETTINGS = 'pullbeaverbuildersettings';			// pull settings API call
	const API_IMAGE_REFS = 'beaverbuilderimagerefs';				// image reference API call

	const ERROR_SETTINGS_DATA_NOT_FOUND = 700;

	const NOTICE_ = 700;

	const RESULT_PRESENT = 700;

	private $_source_site_key = NULL;
	private $_sync_model = NULL;
//	private $_source_urls = NULL;
//	private $_target_urls = NULL;
	private $_images = NULL;

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

	/**
	 * Called from SyncApiRequest on the Source. Checks the API request and perform custom API actions
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

		switch ($action) {
		case self::API_PUSH_SETTINGS:
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' args=' . var_export($args, TRUE));

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

SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' push_data=' . var_export($push_data, TRUE));

			$args['push_data'] = $push_data;
			$args[self::BB_SETTINGS] = WPSiteSync_BeaverBuilder::PLUGIN_VERSION;
			break;

		case self::API_PUSH_SETTINGS:
SyncDebug::log(__METHOD__ . '():' . __LINE__. ' args=' . var_export($args, TRUE));
			break;

		case self::API_IMAGE_REFS:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' args=' . var_export($args, TRUE));
			break;
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

		switch ($action) {
		case self::API_PUSH_SETTINGS:
		case self::API_PULL_SETTINGS:
		case self::API_IMAGE_REFS:
			// only check licensing if it's a BB API call
			if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_beaverbuilder',
				WPSiteSync_BeaverBuilder::PLUGIN_KEY, WPSiteSync_BeaverBuilder::PLUGIN_NAME))
				return TRUE;
			break;
		default:
			// not a BB API call, return whatever was passed in
			return $return;
		}

		// tell API caller that we're here
		$response->result_code(self::RESULT_PRESENT);

		switch ($action) {
		case self::API_PUSH_SETTINGS:
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' post=' . var_export($_POST, TRUE));
			if (FALSE === $this->post_raw(self::BB_SETTINGS, FALSE)) {
				$response->error_code(self::ERROR_SETTINGS_DATA_NOT_FOUND);
				return TRUE;
			}

			$this->_push_data = $this->post_raw('push_data', array());
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
			$return = TRUE;
			break;

		case self::API_PULL_SETTINGS:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' handling pull settings API');
			$return = TRUE;
			break;

		case self::API_IMAGE_REFS:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' handling image refs API');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post data=' . var_export($_POST, TRUE));
			$return = $this->_fix_serialized_data($response);
			break;
		}

		return $return;
	}

	/**
	 * Handles the 'beaverbuilderimageregs' API being processed on the Target from SyncApiController
	 * @param SyncApiResponse $response The response instance
	 * @return boolean $response TRUE when successfully handling a API request action; otherwise FALSE
	 */
	private function _fix_serialized_data(SyncApiResponse $response)
	{
SyncDebug::log(__METHOD__.'():' . __LINE__);
		// setup search and replace array for domain fixups
		$controller = SyncApiController::get_instance();
//		$controller->get_fixup_domains($this->_source_urls, $this->_target_urls);
		$this->_source_site_key = $controller->source_site_key;

		// construct a list of known image Source IDs and their Target IDs from the API parameters
		$this->_build_image_list();
		$sync_model = new SyncModel();

		// set up Source and Target ID values
		$source_post_id = $this->post_int('post_id');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source post id=' . $source_post_id);
		$target_post_id = $this->_get_target_id($source_post_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target post id=' . $target_post_id);

		// search for all Post Meta data and correct and Image IDs and URLs found

		// read all BB specific meta data
		global $wpdb;
		$sql = "SELECT *
				FROM `{$wpdb->postmeta}`
				WHERE `post_id` = %d AND `meta_key` LIKE '_fl_builder_%' ";
		$res = $wpdb->get_results($wpdb->prepare($sql, $target_post_id));

		if (NULL === $res) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' could not read postmeta for post ID ' . $target_post_id);
			// TODO: return error code via $response
			return FALSE;
		}

		// process each postmeta entry
		foreach ($res as $meta_entry) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' meta entry=' . var_export($meta_entry, TRUE));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' processing postmeta entry for "' . $meta_entry->meta_key . '" ' . strlen($meta_entry->meta_value) . ' bytes');
			$meta_data = $meta_entry->meta_value;

			// fixup domains using SyncSerialize->parse_data() and fixup_url_references() callback
/* moved to SyncApiController->push()
			if (is_serialized($meta_data)) {
				// if it's serialized data, use the SyncSerialize->parse_data() to fix domain references
				$ser = new SyncSerialize();
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' fixing domains: ' . implode(',', $this->_source_urls) . ' -> ' . $this->_target_urls[0]);
				$meta_data = $ser->parse_data($meta_data, array($this, 'fixup_url_references'));
			} else {
				// if it's a string, use a simple str_ireplace()
				$meta_data = str_ireplace($this->_source_urls, $this->_target_urls, $meta_data);
			} */

//SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' data ready for work: ' . var_export($meta_data, TRUE));
			// convert to an object so we can work with it
$meta_object = maybe_unserialize($meta_data);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' unserialized data "' . $meta_entry->meta_key . '" = ' . var_export($meta_object, TRUE));

			// scan meta object for items referencing image objects
			if ('_fl_builder_data' === $meta_entry->meta_key || '_fl_builder_draft' === $meta_entry->meta_key) {
				$meta_object = unserialize($meta_data);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' searching elements: ' . var_export($meta_object, TRUE));
				if (FALSE === $meta_object)
					continue;

				foreach ($meta_object as $obj_key => &$object) {
					if (isset($object->settings) && is_object($object->settings)) {
						// this instance has a $settings property. look through this to find urls and media IDs to update
						$obj_vars = get_object_vars($object->settings);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' obj_vars: ' . var_export($obj_vars, TRUE));
						if (NULL !== $obj_vars) {
							$class_vars = array_keys($obj_vars);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' class_vars: ' . var_export($class_vars, TRUE));
							foreach ($class_vars as $var) {
								// typical properties include: 'hero_image', 'hero_subtitle_image',
								// 'hero_video', 'animation' 'id', 'about_image_field' id fixup needs to occur
								if ('_src' === substr($var, -4)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' object has a _src property: ' . $var);
									// found a property with a '_src' ending
									$prop = substr($var, 0, (strlen($var) - 4));
									if (isset($object->settings->$prop)) {
										// there's a '{prop}' property and a '{prop}_src' property
										$source_image_id = abs($object->settings->$prop);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' source image id=' . $source_image_id);
										if (0 !== $source_image_id) {
/*											$sync_data = $sync_model->get_sync_data($source_image_id, $controller->source_site_key, 'media');
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' get_sync_data(' . $source_image_id . ', "' . $controller->source_site_key . '", "media")=' . var_export($sync_data, TRUE));
											if (NULL !== $sync_data) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' fixing attachment id "' . $prop . '" source=' . $source_image_id . ' target=' . $sync_data->target_content_id);
												if (is_int($object->settings->$prop))
													$object->settings->$prop = abs($sync_data->target_content_id);
												else
													$object->settings->$prop = strval(abs($sync_data->target_content_id));
											} */
											$target_image_id = $this->_get_target_id($source_image_id);
											// TODO: if source === target, don't do an update
											if (0 !== $target_image_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' fixing attachment id "' . $prop . '" source=' . $source_image_id . ' target=' . $target_image_id);
												if (is_int($object->settings->$prop))
													$object->settings->$prop = $target_image_id;
												else
													$object->settings->$prop = strval($target_image_id);
											}
										} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' skipping empty _src property');
										}
									}
								} // == '_src'
							} // foreach

							// handle slideshow photo references #22
							if (isset($obj_vars['type']) && 'slideshow' === $obj_vars['type']) {
								$photos = $obj_vars['photos'];
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found "slideshow" module: ' . var_export($photos, TRUE));
								$fixed_photos = array();
								foreach ($photos as $photo_img) {
									$source_image_id = abs($photo_img);								// source site's image id
									$target_image_id = $this->_get_target_id($source_image_id);		// target site's image id
									$fixed_photos[] = $target_image_id;								// add photo's id to the fixed photo list
								}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' fixed photo list: ' . var_export($fixed_photos, TRUE));
								$object->settings->photos = $fixed_photos;							// update photo information with fixed photo list
							}

							// handle video reference #31
							if (isset($obj_vars['type']) && 'video' === $obj_vars['type']) {
								$video = abs($obj_vars['video']);
								$source_video_id = abs($obj_vars['video']);							// source site's media id
								$target_video_id = $this->_get_target_id($source_video_id);			// target site's media id
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' video object before:' . var_export($object->settings, TRUE));
								$object->settings->video = $target_video_id;
								$object->settings->data->id = $target_video_id;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' video object after:' . var_export($object->settings, TRUE));
							}

							// handle gallery photo references #21 #32
							if (isset($obj_vars['type']) && 'gallery' === $obj_vars['type']) {
								$photos = $obj_vars['photo_data'];
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found "gallery" module: ' . var_export($photos, TRUE));
								$fixed_photos = array();
								$photo_ids = array();
								foreach ($photos as $photo_img => $photo_data) {
									$source_image_id = abs($photo_data->id);						// source site's image id
									$target_image_id = $this->_get_target_id($source_image_id);		// target site's image id
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source img id=' . $source_image_id . ' = target img id=' . $target_image_id);
									$photo_data->id = $target_image_id;								// update the data structure
									$fixed_photos[$target_image_id] = $photo_data;					// add data to fixed photo list
									$photo_ids[] = $target_image_id;								// update new photos[] array image id
								}
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' fixed gallery list: ' . var_export($fixed_photos, TRUE));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' starting gallery object: ' . var_export($object, TRUE));
								$object->settings->photo_data = $fixed_photos;						// update gallery information with fixed photo list
								$object->settings->photos = $photo_ids;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' fixed gallery object: ' . var_export($object, TRUE));
							} // 'gallery'
						}

						// look for video references
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' looking for video references');
						if (!empty($object->settings->bg_video) && isset($object->settings->bg_video_data) &&
							!empty($object->settings->bg_video_data->id)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found video id: ' . $object->settings->bg_video_data->id);
							$source_image_id = abs($object->settings->bg_video_data->id);
							$target_image_id = $this->_get_target_id($source_image_id);
#							$sync_data = $sync_model->get_sync_data($source_image_id, $controller->source_site_key, 'media');
#							if (NULL !== $sync_data) {
							if (0 !== $target_image_id) {
#SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found target id: ' . $sync_data->target_content_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found target id: ' . $target_image_id);
#								$object->settings->bg_video = strval($sync_data->target_content_id);
								$object->settings->bg_video = strval($target_image_id);				// needs to be a string
#								$object->settings->bg_video_data->id = abs($sync_data->target_content_id);
								$object->settings->bg_video_data->id = $target_image_id;
#								$object->settings->bg_video_data->editLink = str_replace('?post=' . $source_image_id . '&', '?post=' . $sync_data->target_content_id . '&', $object->settings->bg_video_data->editLink);
								$object->settings->bg_video_data->editLink = str_replace('?post=' . $source_image_id . '&', '?post=' . $target_image_id . '&', $object->settings->bg_video_data->editLink);
							}
						}
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' done checking video references');

						// give add-ons a chance to update custom module data
						do_action('spectrom_sync_beaverbuilder_serialized_data_update', $object, $source_post_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' completed work on object: ' . var_export($object, TRUE));
					} // isset($object->settings)

					// TODO: any more id references?

				} // foreach()

				// reserialize in preparation for database update
				$new_meta_data = serialize($meta_object);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' writing meta data: ' . var_export($new_meta_data, TRUE));
			} // '_fl_builder_data' || '_fl_builder_draft' meta data

			if ($new_meta_data !== $meta_data) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' data has been updated, write to db');
				// write the data back to the database
				$sql = "UPDATE `{$wpdb->postmeta}`
						SET `meta_value`=%s
						WHERE `meta_id`=%d ";
				$wpdb->query($wpdb->prepare($sql, $new_meta_data, $meta_entry->meta_id));
			}
		}
		
		return TRUE;
	}

	/**
	 * Callback for SyncSerialize->parse_data() when parsing the serialized data. Change old Source domain to Target domain.
	 * @param SyncSerializeEntry $entry The data representing the current node processed by Serialization parser.
	 */
/*	public function fixup_url_references($entry)
	{
		$entry->content = str_ireplace($this->_source_urls, $this->_target_urls, $entry->content);
	} */

	/**
	 * Constructs a list of known image Source IDs and their Target IDs from the API parameters. Used in processing the serialized meta data.
	 */
	private function _build_image_list()
	{
		$images = $this->post_raw(WPSiteSync_BeaverBuilder::DATA_IMAGE_REFS, array());
		$this->_sync_model = new SyncModel();
		$this->_images = array();

		// look up each image and find it's Target ID
		foreach ($images as $source_id => $img_ref) {
			// use type 'post' because attachment post types are still stored in the wp_posts table
			$sync_data = $this->_sync_model->get_sync_data($source_id, $this->_source_site_key, 'post');
			if (NULL !== $sync_data) {
				$entry = new stdClass();
				$entry->img_ref = $img_ref;
				$entry->target_id = abs($sync_data->target_content_id);
				$this->_images[$source_id] = $entry;
			}
			// TODO: if an image can't be found, return an error before processing starts?
		}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' image data=' . var_export($this->_images, TRUE));
	}

	/**
	 * Looks up the Target Image ID from the Source's Image ID. Also works for Content Post ID.
	 * @param int $source_id The post ID of the Image on the Source.
	 * @return int The Target's post ID for the Image if found; otherwise 0;
	 */
	private function _get_target_id($source_id)
	{
		// check to see that we know the image
		if (isset($this->_images[$source_id]))
			return $this->_images[$source_id]->target_id;

		// not found, we can look it up via the SyncModel
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' looking up source image id ' . $source_id);
		$sync_data = $this->_sync_model->get_sync_data($source_id, $this->_source_site_key, 'post');
		if (NULL !== $sync_data) {
			$entry = new stdClass();
			// TODO: do we need the img_ref? Can do lookup post via image name
			$entry->img_ref = '';
			$entry->target_id = $sync_data->target_content_id;
			$this->_images[$source_id] = $entry;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found target id ' . $sync_data->target_content_id);
			return abs($sync_data->target_content_id);
		}

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' unable to find Target Post ID for Source ID ' . $source_id);
		return 0;
	}

	/**
	 * Handles the request on the Source after API Requests are made and the response is ready to be interpreted. Called by SyncApiRequest->api().
	 * @param string $action The API name, i.e. 'push' or 'pull'
	 * @param array $remote_args The arguments sent to SyncApiRequest::api()
	 * @param SyncApiResponse $response The response object after the API requesst has been made
	 */
	public function api_response($action, $remote_args, $response)
	{
SyncDebug::log(__METHOD__ . "('{$action}')");

		switch ($action) {
		case self::API_PUSH_SETTINGS:
SyncDebug::log(__METHOD__ . '() response from push settings API request: ' . var_export($response, TRUE));

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
			break;

		case self::API_PULL_SETTINGS:
SyncDebug::log(__METHOD__ . '() response from pull settings API request: ' . var_export($response, TRUE));

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
				$_POST['action'] = self::API_PUSH_SETTINGS;

				$args = array(
					'action' => self::API_PUSH_SETTINGS,
					'parent_action' => self::API_PULL_SETTINGS,
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
			break;

		case self::API_IMAGE_REFS:
			break;
		}
	}
}

// EOF
