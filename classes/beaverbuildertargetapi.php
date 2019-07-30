<?php

class SyncBeaverBuilderTargetAPI extends SyncInput
{
	private $_target_post_id = FALSE;			// post ID being updated on Target
	private $_push_data;						// data being Pushed via the current request

	private $_source_site_key = NULL;

	private $_sync_model = NULL;				// instance of SyncModel used for ID fixups
	private $_source_urls = NULL;				// Source site's URLs - used for URL fixups
	private $_target_urls = NULL;				// Target site's URLs - used for URL fixups
	private $_images = array();					// list of images keyed by Source ID

	const RESULT_PRESENT = 700;

	/**
	 * Handles fixup of data on the Target after SyncApiController has finished processing Content.
	 * @param int $target_post_id The post ID being created/updated via API call
	 * @param array $post_data Post data sent via API call
	 * @param SyncApiResponse $response Response instance
	 */
	public function handle_push($target_post_id, $post_data, $response)
	{
SyncDebug::log(__METHOD__ . "({$target_post_id})");
#		return;

		// list of object properties that refer to image ids
//			$properties = array('hero_image', 'hero_subtitle_image', 'hero_video', 'about_image_field');
		// setup search and replace array for domain fixups
		$controller = SyncApiController::get_instance();
		$controller->get_fixup_domains($this->_source_urls, $this->_target_urls);

		$input = new SyncInput();
		$this->_sync_model();

		// process data found in the ['bb_image_refs'] element. create entries in spectrom_sync as needed
		// do this *before* post_meta processing so we have target_post_ids for image references and can fixup IDs for Media Entries
		$image_refs = $input->post_raw(WPSiteSync_BeaverBuilder::DATA_IMAGE_REFS, array());
		if (!empty($image_refs))
			$this->_process_image_references($image_refs, $target_post_id);

		// check POST contents to make sure we have something to work with
		$post_meta = $input->post_raw('post_meta', array());
		foreach ($post_meta as $meta_key => $meta_value) {
			if ('_fl_builder_' === substr($meta_key, 0, 12)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found BeaverBuilder meta: ' . $meta_key . '=' . var_export($meta_value, TRUE));
				if (is_array($meta_value)) {
					// only bother with serialization fixup if it's an array
					$meta_data = $meta_value[0];

					// unslash
					$meta_data = stripslashes($meta_data);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' stripped: ' . var_export($meta_data, TRUE));
/* 						if (('_fl_builder_data' === $meta_key || '_fl_builder_draft' === $meta_key) &&
						's:' === substr($meta_data, 0, 2)) {			// this is double serialized #15
//							$meta_data = unserialize($meta_data);
						$pos = strpos($meta_data, '"');
						if (FALSE !== $pos) {
							$meta_data = substr($meta_data, $pos + 1);
							$meta_data = substr($meta_data, 0, strlen($meta_data) - 1);
						} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sub-serialized string not found');
						}
					} */
					$meta_ = maybe_unserialize($meta_data);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' ** meta data before key "' . $meta_key . '": [' . var_export($meta_, TRUE) . ']');

					// fixup domains using SyncSerialize->parse_data() and fixup_url_references() callback
					if (is_serialized($meta_data)) {
						// if it's serialized data, use the SyncSerialize->parse_data() to fix domain references
						$ser = new SyncSerialize();
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' fixing domains: ' . implode(',', $this->_source_urls) . ' -> ' . $this->_target_urls[0]);
						$meta_data = $ser->parse_data($meta_data, array($this, 'fixup_url_references'));
					} else {
						// if it's a string, use a simple str_ireplace()
						$meta_data = str_ireplace($this->_source_urls, $this->_target_urls, $meta_data);
					}

SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' data ready for insertion: ' . var_export($meta_data, TRUE));
					// convert to an object. this is done so that serialized objects are saved correctly via update_post_meta()
					$meta_object = maybe_unserialize($meta_data);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' unserialized data "' . $meta_key . '" = ' . var_export($meta_object, TRUE));

					// scan meta object for items referencing image objects
					if ('_fl_builder_data' === $meta_key || '_fl_builder_draft' === $meta_key) {
						$meta_object = unserialize($meta_data);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' searching elements: ' . var_export($meta_object, TRUE));
						foreach ($meta_object as $obj_key => &$object) {
							if (isset($object->settings) && is_object($object->settings)) {
								// this instance has a $settings property. look through this to find urls and media IDs to update
//									$class_vars = get_class_vars($object->settings);
								$obj_vars = get_object_vars($object->settings);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' obj_vars: ' . var_export($obj_vars, TRUE));
								if (NULL !== $obj_vars) {
									$class_vars = array_keys($obj_vars);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' class_vars: ' . var_export($class_vars, TRUE));
									foreach ($class_vars as $var) {
										// typical properties include: 'hero_image', 'hero_subtitle_image',
										// 'hero_video', 'animation' 'id', 'about_image_field' id fixup needs to occur
										if ('_src' === substr($var, -4)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' object has a _src property');
											// found a property with a '_src' ending
											$prop = substr($var, 0, (strlen($var) - 4));
											if (isset($object->settings->$prop)) {
												// there's a '{prop}' property and a '{prop}_src' property
												$source_image_id = abs($object->settings->$prop);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' source image id=' . $source_image_id);
												$sync_data = $this->_sync_model->get_sync_data($source_image_id, $controller->source_site_key);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' get_sync_data(' . $source_image_id . ', "' . $controller->source_site_key . '")=' . var_export($sync_data, TRUE));
												if (NULL !== $sync_data) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' fixing attachment id "' . $prop . '" source=' . $source_image_id . ' target=' . $sync_data->target_content_id);
													if (is_int($object->settings->$prop))
														$object->settings->$prop = abs($sync_data->target_content_id);
													else
														$object->settings->$prop = strval(abs($sync_data->target_content_id));
												}
											}
										} // == '_src'
									} // foreach

									// handle slideshow photo modules
									if (isset($obj_vars['type']) && 'slideshow' === $obj_vars['type']) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found "slideshow" module');
										$photos = $obj_vars['photos'];
									}

									// handle audio modules #48
									if (isset($obj_vars['type']) && 'audio' === $obj_vars['type']) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found "audio" module data=' . var_export($obj_vars['data'], TRUE));
										// update post ID of image reference
										$source_id = abs($obj_vars['data']->id);
#										$target_id = $this->_get_target_id($source_id = abs($obj_vars['data']->id));
#										if (0 === $target_id) {
#											$att_model = new SyncAttachModel();
#											$att_model->search_by_guid($guid, TRUE);
#										}
										$attach_model = new SyncAttachModel();
										$target_id = $attach_model->search($obj_vars['data']->filename);
										if (FALSE === $target_id)
											$target_id = 0;
										$parent_id = FALSE;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' image Source ID=' . $source_id . ' Target ID=' . $target_id);
										if (0 !== $target_id) {
											$target_attachment = get_post($target_id, OBJECT);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target attachment=' . var_export($target_attachment, TRUE));
											$parent_link = FALSE;
											if (!empty($target_attachment->post_parent)) {
												$parent_id = $target_attachment->post_parent;	// used in adjusting uploadedTo property below
												$parent_link = get_permalink($parent_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' parent id=' . $parent_id . ' link=' . $parent_link);
											}

											// now adjust the properties in the audio module's data
											$obj_vars['data']->id = $target_id;
											$obj_vars['data']->url = $target_attachment->guid;
											if (FALSE !== $parent_link)
												$obj_vars['data']->link = $parent_link;
											$obj_vars['data']->name = strval($target_id);
											$obj_vars['data']->editLink = str_replace(
												'?post=' . $source_id . '&',
												'?post=' . $target_id . '&',
												$obj_vars['data']->editLink); // 'http://domain.com/wp-admin/post.php?post=2371&action=edit'
											$obj_vars['data']->link = str_replace(
												'/attachment/' . $source_id . '/',
												'/attachment/' . $target_id . '/',
												$obj_vars['data']->link); // 'http://domain.com/2019/01/11/audio-in-classic-block/attachment/2371/'
										}

										// update post ID of the 'owner' of the image
										$target_id = $this->_get_target_id($source_id = abs($obj_vars['data']->uploadedTo));
										if (0 === $target_id && FALSE !== $parent_id)
											$target_id = $parent_id;
										if (0 === $target_id)					// if not found
											$target_id = $target_post_id;		// assume current post is the owner
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' image owner Source ID=' . $source_id . ' Target ID=' . $target_id);
										$obj_vars['data']->uploadedTo = $target_id;
										$obj_vars['data']->uploadedToLink = str_replace(
											'?post=' . $source_id . '&',
											'?post=' . $target_id . '&',
											$obj_vars['data']->uploadedToLink); // 'http://domain.com/wp-admin/post.php?post=2370&action=edit'
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' modified entry=' . var_export($obj_vars['data'], TRUE));
									}
								}
							} // isset($object->settings)

							// look for video references
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' looking for video references');
							if (!empty($object->settings->bg_video) && isset($object->settings->bg_video_data) &&
								!empty($object->settings->bg_video_data->id)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found video id: ' . $object->settings->bg_video_data->id);
								$source_image_id = abs($object->settings->bg_video_data->id);
								$sync_data = $this->_sync_model->get_sync_data($source_image_id, $controller->source_site_key);
								if (NULL !== $sync_data) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found target id: ' . $sync_data->target_content_id);
									$object->settings->bg_video = strval($sync_data->target_content_id);
									$object->settings->bg_video_data->id = abs($sync_data->target_content_id);
									$object->settings->bg_video_data->editLink = str_replace('?post=' . $source_image_id . '&', '?post=' . $sync_data->target_content_id . '&', $object->settings->bg_video_data->editLink);
								}
							}
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' done checking videos references');
						} // foreach()
					} // '_fl_builder_data' || '_fl_builder_draft' meta data

					// re-serialie this one
					// TODO: check this.
//						if ('_fl_builder_draft' === $meta_key)
//							$meta_object = serialize($meta_object);
					// write the updated meta data
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' ** meta data after key "' . $meta_key . '": [' . var_export($meta_object, TRUE) . ']');
					update_post_meta($target_post_id, $meta_key, $meta_object);
				}
			}
		}
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
		case SyncBeaverBuilderApiRequest::API_PUSH_SETTINGS:
		case SyncBeaverBuilderApiRequest::API_PULL_SETTINGS:
		case SyncBeaverBuilderApiRequest::API_IMAGE_REFS:
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
		case SyncBeaverBuilderApiRequest::API_PUSH_SETTINGS:
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' post=' . var_export($_POST, TRUE));
			if (FALSE === $this->post_raw(SyncBeaverBuilderApiRequest::BB_SETTINGS, FALSE)) {
				$response->error_code(SyncBeaverBuilderApiRequest::ERROR_SETTINGS_DATA_NOT_FOUND);
				return TRUE;
			}

			$this->_push_data = $this->post_raw('push_data', array());
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found push_data information: ' . var_export($this->_push_data, TRUE));

			// check api parameters
			if (empty($this->_push_data) || empty($this->_push_data['settings'])) {
				$response->error_code(SyncBeaverBuilderApiRequest::ERROR_SETTINGS_DATA_NOT_FOUND);
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

		case SyncBeaverBuilderApiRequest::API_PULL_SETTINGS:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' handling pull settings API');
			if (!class_exists('SyncBeaverBuilderSourceApi', FALSE))
				require_once(__DIR__ . '/beaverbuildersourceapi.php');
			$source_api = new SyncBeaverBuilderSourceAPI();
			$api_data = array();
			$api_data = $source_api->api_request_action($api_data, SyncBeaverBuilderApiRequest::API_PUSH_SETTINGS, array());
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' api data=' . var_export($api_data, TRUE));
			$response->set('pull_data', $api_data);
			$return = TRUE;
			break;

		case SyncBeaverBuilderApiRequest::API_IMAGE_REFS:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' handling image refs API');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post data=' . var_export($_POST, TRUE));
			$return = $this->_fix_serialized_data($response);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' clearing cache for post ' . var_export($this->_target_post_id, TRUE));
			// add call to break cache. _fix_serialized_data() will set target post id to FALSE if/when Saved Rows/Modules are in use
			// to allow flushing all cached items in those cases. #51
			FLBuilderModel::delete_all_asset_cache($this->_target_post_id);
			break;
		}

		return $return;
	}

	/**
	 * Returns a single instance of the SyncModel class
	 * @return SyncModel instance
	 */
	private function _sync_model()
	{
		if (NULL === $this->_sync_model)
			$this->_sync_model = new SyncModel();
		if (NULL === $this->_source_site_key) {
			$controller = SyncApiController::get_instance();
			$this->_source_site_key = $controller->source_site_key;
		}
//		$controller->get_fixup_domains($this->_source_urls, $this->_target_urls);
		return $this->_sync_model;
	}

	/**
	 * Handles the 'beaverbuilderimagerefs' API being processed on the Target from SyncApiController
	 * @param SyncApiResponse $response The response instance
	 * @return boolean $response TRUE when successfully handling a API request action; otherwise FALSE
	 */
	private function _fix_serialized_data(SyncApiResponse $response)
	{
SyncDebug::log(__METHOD__.'():' . __LINE__);
		// construct a list of known image Source IDs and their Target IDs from the API parameters
		$this->_build_image_list();

if (NULL === $this->_source_site_key) SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: source site key not set');

		// set up Source and Target ID values
		$source_post_id = $this->post_int('post_id');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source post id=' . $source_post_id);
		$target_post_id = $this->_get_target_id($source_post_id);
		$this->_target_post_id = $target_post_id;
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
/*											$sync_data = $this->_sync_model->get_sync_data($source_image_id, $controller->source_site_key');
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' get_sync_data(' . $source_image_id . ', "' . $controller->source_site_key . '")=' . var_export($sync_data, TRUE));
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

							// TODO: handle Saved Rows and Saved Modules #50
							// TODO: if using Saved Rows or Saved Modules, set $this->_target_post_id = FALSE
							//		 to indicate to delete_all_asset_cache() to clear all cache data
						}

						// look for video references
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' looking for video references');
						if (!empty($object->settings->bg_video) && isset($object->settings->bg_video_data) &&
							!empty($object->settings->bg_video_data->id)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found video id: ' . $object->settings->bg_video_data->id);
							$source_image_id = abs($object->settings->bg_video_data->id);
							$target_image_id = $this->_get_target_id($source_image_id);
#							$sync_data = $this->_sync_model->get_sync_data($source_image_id, $controller->source_site_key);
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
	 * Constructs a list of known image Source IDs and their Target IDs from the API parameters. Used in processing the serialized meta data.
	 */
	private function _build_image_list()
	{
		$images = $this->post_raw(WPSiteSync_BeaverBuilder::DATA_IMAGE_REFS, array());
		$this->_sync_model();
		$this->_images = array();

		// look up each image and find it's Target ID
		foreach ($images as $source_id => $img_ref) {
			// use type 'post' because attachment post types are still stored in the wp_posts table
			$sync_data = $this->_sync_model->get_sync_data($source_id, $this->_source_site_key);
			if (NULL !== $sync_data) {
				$entry = new stdClass();
				$entry->img_ref = $img_ref;
				$entry->target_id = abs($sync_data->target_content_id);
				$this->_images[$source_id] = $entry;
			}
			// TODO: if an image can't be found, return an error before processing starts
		}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' image data=' . var_export($this->_images, TRUE));
	}

	/**
	 * Processes Image references found in the ['bb_image_refs'] parameter in the API call on the Target
	 * This will create entries in the spectrom_sync table and Media Library as needed.
	 * @param array $image_refs Array of image ID references contained in Push request
	 * @param int $target_post_id The parent post ID for images
	 */
	private function _process_image_references($image_refs, $target_post_id)
	{
		// we need to handle these here so that when the next 'upload_media' API call occurs that
		// references these images comes in, we already have the entry in spectrom_sync and we can make
		// the appropriate Source ID to Target ID changes when processing meta data within handle_push().

		$this->_sync_model();
		$attach_model = new SyncAttachModel();
		$controller = SyncApiController::get_instance();
		$site_key = $controller->source_site_key;
		$target_site_key = SyncOptions::get('site_key');

		$source = $controller->source;
		$target = site_url();

		foreach ($image_refs as $img_id => $img_src) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found media #' . $img_id . ' - ' . $img_src);
			$entry = $this->_sync_model->get_sync_data($img_id);
			if (NULL === $entry) {
				// create the attachment entry in wp_posts
				$guid = str_replace($source, $target, $img_src);
				$target_image_id = $attach_model->create_from_guid($guid, $target_post_id);

				if (0 !== $target_image_id) {
					// no record exists, create one
					$data = array(
						'site_key' => $site_key,
						'source_content_id' => $img_id,
						'target_content_id' => $target_image_id,
						'target_site_key' => $target_site_key,
						'content_type' => 'post',
					);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' saving sync entry: ' . var_export($data, TRUE));
					$this->_sync_model->save_sync_data($data);
				}
			}
		}
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
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source site key: ' . $this->_source_site_key);
		$sync_data = $this->_sync_model->get_sync_data($source_id, $this->_source_site_key);
		if (NULL !== $sync_data) {
			$entry = new stdClass();
			// TODO: do we need the img_ref? Can do lookup post via image name
			$entry->img_ref = '';
			$entry->target_id = $sync_data->target_content_id;
			$this->_images[$source_id] = $entry;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found target id ' . $sync_data->target_content_id);
			return abs($sync_data->target_content_id);
		}

		// TODO: use SyncAttachModel

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' unable to find Target Post ID for Source ID ' . $source_id);
		return 0;
	}

	/**
	 * Callback for filtering the allowed extensions. Needed to allow video types set with Advanced Settings.
	 * File types allowed are: avi, flv, wmv, mp4 and mov
	 * @param boolean $allow TRUE to allow the type; otherwise FALSE
	 * @param array $type Array of File Type information returned from wp_check_filetype()
	 */
	public function filter_allowed_mime_types($allowed, $type)
	{
		// TODO: check license

		// add audio types #7
		if (in_array($type['ext'], array('ai', 'avi', 'flv', 'midi', 'wmv', 'mp4', 'mp3', 'mov', 'wav', 'wma')))
			$allowed = TRUE;
		return $allowed;
	}

	/**
	 * Callback for SyncSerialize->parse_data() when parsing the serialized data. Change old Source domain to Target domain.
	 * @param SyncSerializeEntry $entry The data representing the current node processed by Serialization parser.
	 */
	public function fixup_url_references($entry)
	{
		$entry->content = str_ireplace($this->_source_urls, $this->_target_urls, $entry->content);
	}
}

// EOF
