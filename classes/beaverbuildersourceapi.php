<?php

class SyncBeaverBuilderSourceAPI
{
	private $_image_refs = array();			// holds list of image references from the current content
	private $_post_refs = array();			// holds list of post references for Saved Rows and Saved Modules

	private $_push_controller = NULL;		// controller instance used to simulate Push operations

	/**
	 * Called from SyncApiRequest on the Source. Checks the API request and perform custom API actions
	 * @param array $args The arguments array sent to SyncApiRequest::api()
	 * @param string $action The API requested
	 * @param array $remote_args Array of arguments sent to SyncRequestApi::api()
	 * @return array The modified $args array, with any additional information added to it
	 */
	public function api_request_action($args, $action, $remote_args)
	{
SyncDebug::log(__METHOD__ . '() action=' . $action);

		if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_beaverbuilder', WPSiteSync_BeaverBuilder::PLUGIN_KEY, WPSiteSync_BeaverBuilder::PLUGIN_NAME))
			return $args;

		switch ($action) {
		case SyncBeaverBuilderApiRequest::API_PUSH_SETTINGS:
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' args=' . SyncDebug::arr_sanitize($args));

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
			$args[SyncBeaverBuilderApiRequest::BB_SETTINGS] = WPSiteSync_BeaverBuilder::PLUGIN_VERSION;
			break;

		case SyncBeaverBuilderApiRequest::API_PUSH_SETTINGS:
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' args=' . SyncDebug::arr_sanitize($args));
			break;

		case SyncBeaverBuilderApiRequest::API_IMAGE_REFS:
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' args=' . SyncDebug::arr_sanitize($args));
			break;

		case SyncBeaverBuilderApiRequest::API_PULL_IMAGE_REFS:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' args=' . SyncDebug::arr_sanitize($args));
			break;
		}

		// return the filter value
		return $args;
	}

	/**
	 * Callback for filtering the post data before it's sent to the Target. Here we check for image references within the meta data.
	 * @param array $data The data being Pushed to the Target machine
	 * @param SyncApiRequest $apirequest Instance of the API Request object
	 * @return array The modified data
	 */
	public function filter_push_content($data, $apirequest)
	{
SyncDebug::log(__METHOD__ . '()'); //  data=' . var_export($data, TRUE)); // . var_export($data, TRUE));
		// check to see if this is a Pull operation or a Push and connect the appropriate handler for after queue handling
//		$op = NULL;
//		if (NULL !== ($controller = SyncApiController::get_instance()))			// controller is non-NULL on Pull operations
//			$op = $controller->get_parent_action();
		$op = WPSiteSyncContent::get_instance()->get_parent_action();
SyncDebug::log(__METHOD__.'() parent operation: ' . var_export($op, TRUE));
		if ('pull' === $op)
			add_action('spectrom_sync_push_queue_complete', array($this, 'image_ref_pull_api'), 10, 1);
		else if (NULL === $op)
			// no parent operation means it's a Push
			add_action('spectrom_sync_push_queue_complete', array($this, 'image_ref_api'), 10, 1);

		// look for media references and call SyncApiRequest->send_media() to add media to the Push operation
		if (isset($data['post_meta'])) {
			$post_id = 0;
			if (isset($data['post_id']))   // present on Push operations
				$post_id = abs($data['post_id']);
			else if (isset($data['post_data']['ID']))   // present on Pull operations
				$post_id = abs($data['post_data']['ID']);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' post id=' . $post_id);
			$this->_post_id = $post_id;	// set this up for use in _send_media_instance()
			$this->_post_refs = array();
			$data[WPSiteSync_BeaverBuilder::DATA_IMAGE_REFS] = array(); // initialize the list of image references

			$regex_search = "/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?/";
			$attach_model = new SyncAttachModel();

			// set up some values to be used to identify site-specific image references vs. non-site images
			$site_url = site_url();
			$upload = wp_upload_dir();
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' upload info=' . var_export($upload, TRUE));
			$upload_url = $upload['baseurl'];
			// this sets the source domain- needed for SynApiRequest::send_media() to work
			$apirequest->set_source_domain(parse_url($site_url, PHP_URL_HOST));
			$this->_api_request = $apirequest;   // save this for the _send_media_instance() method

			foreach ($data['post_meta'] as $meta_key => $meta_value) {
				if ('_fl_builder_' === substr($meta_key, 0, 12)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found key: ' . $meta_key);
					$meta_data = serialize($meta_value);
					$meta_data = str_replace('"', ' " ', $meta_data);
					// look for any image references
					// TODO: look for other media: audio / video
					// check if there is a url in the text
					$urls = array();
					if (preg_match_all($regex_search, $meta_data, $urls)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found urls: ' . var_export($urls, TRUE));
						if (isset($urls[0]) && 0 !== count($urls[0])) {
							// look for only those URL references that match the current site's URL
							foreach ($urls[0] as $url) {
//								if ('http://' === substr($url, 0, 7) || 'https://' === substr($url, 0, 8)) {
								if ($site_url === substr($url, 0, strlen($site_url)) && FALSE !== strpos($url, $upload_url)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' syncing image: ' . $url);
									$attach_posts = $attach_model->search_by_guid($url, TRUE);
//SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' res=' . var_export($attach_posts, TRUE)); - has post passwords
									// ignore any images that are not found in the Image Library
									if (0 === count($attach_posts)) {
SyncDebug::log(' - no attachments found with this name, skipping');
										continue;
									}

									// find the attachment id
									$attach_id = 0;
									foreach ($attach_posts as $attach_post) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' checking guid "' . $attach_post->guid . '"');
SyncDebug::log(__METHOD__ . '() - ID #' . $attach_post->ID);
SyncDebug::log(__METHOD__ . '() - url="' . $url . '"');
SyncDebug::log(__METHOD__ . '() - attach guid="' . $attach_post->guid . '"');
SyncDebug::log(__METHOD__ . '() - orig guid="' . (isset($attach_post->orig_guid) ? $attach_post->orig_guid : 'NULL') . '"');
										if ($attach_post->guid === $url) {
											$attach_id = $attach_post->ID;
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found matching for id#' . $attach_id);
											break;
										} else if (isset($attach_post->orig_guid)) { // && $url === $attach_post->orig_guid) {
											// set the URL to what was found by the search_by_guid() extended search
											$attach_id = $attach_post->ID;
											$url = $attach_post->guid;
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' resetting #' . $attach_id . ' url to ' . $url);
										}
									}
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' attach id=' . $attach_id);
									// TODO: ensure images found via extended search are not causing duplicate uploads
									$apirequest->send_media($url, $post_id, 0, $attach_id);
								}
							} // foreach
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' done processing images');
						} // isset($urls[0])
					} // if preg_match_all
				} // if ('_fl_builder_

				// if it's a data or draft - look for references to attachments and other posts
				if ('_fl_builder_data' === $meta_key || '_fl_builder_draft' === $meta_key) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' looking for resource references in meta data');
					$meta_data = unserialize($meta_value[0]);
//						$meta_data = unserialize($meta_data);
SyncDebug::log('=' . var_export($meta_value[0], TRUE));
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' meta value=' . var_export($meta_data, TRUE));
					foreach ($meta_data as $key => $object) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' key=' . $key);
						// search for '_src' suffixed properties
						if (isset($object->settings) && is_object($object->settings)) {
if (isset($object->settings->type))
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' object type="' . $object->settings->type . '"');
							$obj_vars = get_object_vars($object->settings);
							if (NULL !== $obj_vars) {
								$class_vars = array_keys($obj_vars);
								foreach ($class_vars as $var) {
									// typical properties include: 'hero_image', 'hero_subtitle_image',
									// 'hero_video', 'animation' 'id', 'about_image_field' id fixup needs to occur
									if ('_src' === substr($var, -4)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found property: ' . $var);
										// found a property with a '_src' ending
										$prop = substr($var, 0, (strlen($var) - 4));
										if (isset($object->settings->$prop)) {
											// there's a '{prop}' property and a '{prop}_src' property
											$img_id = abs($object->settings->$prop);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' media id: ' . $img_id);
											if (0 !== $img_id)
												$this->_send_media_instance($img_id, $object->settings->$var, $data);
										}
									}
								}

								// examine specific node types:
								if (isset($obj_vars['type']) && !empty($obj_vars['type'])) {
									// there's a ['type'] entry within the object check for modules that need adjustments
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found a module type of "' . $obj_vars['type'] . '" - processing');
									switch ($obj_vars['type']) {
									case 'slideshow':							// handle slideshow photo references #22
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found "slideshow" module: ' . var_export($obj_vars['photo_data'], TRUE));
										$photos = $obj_vars['photos'];
										foreach ($photos as $photo_img) {
											$img_id = abs($photo_img);
											$img_src = wp_get_attachment_image_src($img_id, 'full');
											if (FALSE !== $img_src) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' sending media #' . $img_id . ': ' . var_export($img_src, TRUE));
												$this->_send_media_instance($img_id, $img_src[0], $data);
											}
										}
										break;

									case 'testimonials':						// handle testimonial references #41
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found testimonial object');
//SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' obj=' . var_export($obj_vars, TRUE));
										// look for image references in the testimonials
										foreach ($obj_vars['testimonials'] as $testi) {
											$content = $testi->testimonial;
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' content=' . $content);
											$apirequest->parse_media($post_id, $content);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' completed parse_media() call');
										}
										break;

									case 'video':								// handle video references #31
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found a video object');
//SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' obj=' . var_export($obj_vars, TRUE));
										if (isset($obj_vars['video_type']) && 'media_library' === $obj_vars['video_type']) {
											$video_id = abs($obj_vars['video']);
											$video_src = $obj_vars['data']->url;
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' video src=' . var_export($video_src, TRUE));
											if (FALSE !== $video_src && !empty($video_src)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' sending video #' . $video_id . ': ' . var_export($video_src, TRUE));
												$this->_send_media_instance($video_id, $video_src[0], $data);
											}
else SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' data=' . var_export($data, TRUE));
										}
										break;
									}
								}

								// give add-ons a chance to look up any custom references
								do_action('spectrom_sync_beaverbuilder_serialized_data_reference', $object, $post_id, $this->_api_request);
							} else {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' obj_vars=NULL');
							}
						} // isset($object->settings)

						// check for specific node types
						if (isset($object->type) && !empty($object->type)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found a node of type "' . $object->type . '" - checking');
							$ref_post_id = 0;
							$ref_post_args = array();
							switch ($object->type) {
							case 'row':									// handle row references #50
							case 'module':								// handle module references #50
							case 'column':								// handle column references #53
								$bb_model = new SyncBeaverBuilderModel();
								$template_id = FALSE;
								if (isset($object->template_id))
									$template_id = $object->template_id;
								if (FALSE !== $template_id) {
									$ref_post_id = $bb_model->template_id_to_post_id($template_id);
								}
								$ref_post_args = array();
								break;
							}
							if (0 !== $ref_post_id && !in_array($ref_post_id, $this->_post_refs)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' got a post ID of ' . $ref_post_id . ' from template id "' . $template_id . '"');
								// add referenced post to work queue so it'll get sent to the Target
								$apirequest->add_queue('push', array('post_id' => $ref_post_id));
								$this->_post_refs[] = $ref_post_id;
							} else {
if (0 !== $ref_post_id)
	SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ref post #' . $ref_post_id . ' has already been added to the queue');
							}
						}

						// look for video references
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' looking for video references');
						if (!empty($object->settings->bg_video) && isset($object->settings->bg_video_data) &&
							!empty($object->settings->bg_video_data->id)) {
							$img_id = abs($object->settings->bg_video_data->id);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' media id: ' . $img_id);
							$this->_send_media_instance($img_id, $object->settings->bg_video_data->url, $data);
						}
						// TODO: look for any additional references
					} // foreach ($meta_data)
				} // if ('_fl_builder_data'
			} // foreach
		} // isset($data['post_meta'])

		return $data;
	}

	/**
	 * Handles the request on the Source after API Requests are made and the response is ready to be interpreted. Called by SyncApiRequest->api().
	 * @param string $action The API name, i.e. 'push' or 'pull'
	 * @param array $remote_args The arguments sent to SyncApiRequest::api()
	 * @param SyncApiResponse $response The response object after the API requesst has been made
	 */
	public function api_request_response($action, $remote_args, $response)
	{
SyncDebug::log(__METHOD__ . "('{$action}')");

		switch ($action) {
		case SyncBeaverBuilderApiRequest::API_PUSH_SETTINGS:
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' response from push settings API request: ' . var_export($response, TRUE));

			$api_response = NULL;

			if (isset($response->response)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' decoding response: ' . var_export($response->response, TRUE));
				$api_response = $response->response;
			} else {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' no reponse->response element');
			}

SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' api response body=' . var_export($api_response, TRUE));

			if (0 === $response->get_error_code()) {
				$response->success(TRUE);
			}
			// TODO: add check for result code, display error if not present
			break;

		case SyncBeaverBuilderApiRequest::API_PULL_SETTINGS:
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' response from pull settings API request: ' . var_export($response, TRUE));
			$api_response = NULL;

			if (isset($response->response)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' decoding response: ' . var_export($response->response, TRUE));
				$api_response = $response->response;
			} else {
SyncDebug::log(__METHOD__ . '():' . __LINE__ > ' no response->response element');
			}

SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' api response body=' . var_export($api_response, TRUE));

			if (NULL !== $api_response) {
				$save_post = $_POST;			// save this to restore after simulated SyncApiController call

				// convert the pull data into an array
				$pull_data = json_decode(json_encode($api_response->data->pull_data), TRUE); // $response->response->data->pull_data;
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - pull data=' . var_export($pull_data, TRUE));
				$site_key = $api_response->data->site_key; // $pull_data->site_key;
				$target_url = SyncOptions::get('target');
				$pull_data['site_key'] = $site_key;
				$pull_data['pull'] = TRUE;

				$_POST['push_data'] = $pull_data['push_data'];
				$_POST[SyncBeaverBuilderApiRequest::BB_SETTINGS] = WPSiteSync_BeaverBuilder::PLUGIN_VERSION;
				$_POST['action'] = SyncBeaverBuilderApiRequest::API_PUSH_SETTINGS;

				$args = array(
					'action' => SyncBeaverBuilderApiRequest::API_PUSH_SETTINGS,
					'parent_action' => SyncBeaverBuilderApiRequest::API_PULL_SETTINGS,
					'site_key' => $site_key,
					'source' => $target_url,
					'response' => $response,
					'auth' => 0,
				);

SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' creating controller with: ' . var_export($args, TRUE));
				$this->_push_controller = SyncApiController::get_instance($args);
				$this->_push_controller->dispatch();
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - returned from controller');
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - response=' . var_export($response, TRUE));

				$_POST = $save_post;			// restore original $_POST data

//				if (0 === $response->get_error_code()) {
//					$response->success(TRUE);
//				}
			}
			break;

		case SyncBeaverBuilderApiRequest::API_IMAGE_REFS:
			break;

		case SyncBeaverBuilderApiRequest::API_PULL_IMAGE_REFS:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' response: ' . var_export($response, TRUE));
			break;

		// mimic the SyncBeaverBuilderApiRequest::API_IMAGE_REFS API call after Pull request has been processed
		case 'pull':
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' in response to a "pull" API request. args=' . var_export($remote_args, TRUE));
			$save_post = $_POST;									// save this to restore after simulated SyncApiController call

			$site_key = $api_response->data->site_key;
			$target_url = SyncOptions::get('target');

			$_POST['action'] = SyncBeaverBuilderApiRequest::API_IMAGE_REFS;

			// TODO: check returned data for references to Saved Row/Column/Module that has not yet been Sync'd

			// get the data that was added to the response array
			if (isset($remote_args['bb_image_refs'])) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' generating image ref API handling');
				$args = array(
					'action' => SyncBeaverBuilderApiRequest::API_IMAGE_REFS,
					'parent_action' => 'pull',
					'site_key' => $site_key,
					'source' => $target_url,
					'response' => $response,
					'auth' => 0,
					'post_id' => 1,
					'bb_image_refs' => $remote_args['bb_image_refs'],	// added by image_ref_pull_api() $this->_image_refs
				);

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' creating Controller with ' . var_export($args, TRUE));
				$this->_push_controller = SyncApiController::get_instance($args);
				$this->_push_controller->dispatch();
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - returned from controller');
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - response=' . var_export($response, TRUE));

				$_POST = $save_post;			// restore original $_POST data
			}
			break;
		}
	}

	/**
	 * Callback for 'spectrom_sync_push_queue_complete' action when Push queue is empty. Used to trigger Image Reference API call.
	 * @param SyncApiRequest $api_request Instance of request object
	 */
	public function image_ref_api($api_request)
	{
		$data = array(
			'post_id' => $this->_post_id,
			'bb_image_refs' => $this->_image_refs,
		);
		$api_request->api(SyncBeaverBuilderApiRequest::API_IMAGE_REFS, $data);
	}

	/**
	 * Callback for 'spectrom_sync_push_queue_complete' action when Pull is performed. Used to respond to Image Reference Pull API call.
	 * @param SyncApiRequest $api_request Instance of request object
	 */
	public function image_ref_pull_api($api_request)
	{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' adding image refs to response data: ' . var_export($this->_image_refs, TRUE));
		$response = $api_request->get_response();
		$response->set('bb_image_refs', $this->_image_refs);
	}

	/**
	 * Adds any references to media instances to the queue
	 * @param int $image_id ID of the attachment from the Media Library
	 * @param string $image_src URL reference to the attachment in the Media Library
	 * @param array $data The post data being filtered for the Push operation
	 */
	// TODO: remove $data parameter
	private function _send_media_instance($image_id, $image_src, &$data)
	{
SyncDebug::log(__METHOD__ . "({$image_id}, '{$image_src}', ...)");
		$image_id = abs($image_id);
//		if (0 !== $image_id && !in_array($image_id, $data[WPSiteSync_BeaverBuilder::DATA_IMAGE_REFS])) {
		if (0 !== $image_id && !isset($this->_image_refs[$image_id])) {
//			$data[WPSiteSync_BeaverBuilder::DATA_IMAGE_REFS][$image_id] = $image_src;
			$this->_image_refs[$image_id] = $image_src;
SyncDebug::log(__METHOD__ . '():' . __LINE__ . " calling send_media('{$image_src}', {$this->_post_id}, 0, {$image_id})");
			$this->_api_request->send_media($image_src, $this->_post_id, 0, $image_id);
		}
	}
}

// EOF