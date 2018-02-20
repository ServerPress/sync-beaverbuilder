<?php
/*
Plugin Name: WPSiteSync for Beaver Builder
Plugin URI: http://wpsitesync.com
Description: Allow Beaver Builder Content and Templates to be Synced to the Target site
Author: WPSiteSync
Author URI: http://wpsitesync.com
Version: 1.0 Beta
Text Domain: wpsitesync-beaverbuilder

The PHP code portions are distributed under the GPL license. If not otherwise stated, all
images, manuals, cascading stylesheets and included JavaScript are NOT GPL.
 */

if (!class_exists('WPSiteSync_BeaverBuilder')) {
	/*
	 * @package WPSiteSync_BeaverBuilder
	 * @author Dave Jesch
	 */
	class WPSiteSync_BeaverBuilder
	{
		private static $_instance = NULL;

		const PLUGIN_NAME = 'WPSiteSync for Beaver Builder';
		const PLUGIN_VERSION = '1.0';
		const PLUGIN_KEY = '940382e68ffadbfd801c7caa41226012';
		const REQUIRED_VERSION = '1.3.3';		 // minimum version of WPSiteSync required for this add-on to initialize

		const DATA_IMAGE_REFS = 'bb_image_refs';	// TODO: remove

		private $_post_id = 0;			// Post ID of data being Pushed to Target
		private $_api_request = NULL;		  // API Request instance used in pre-processing serialized data
		private $_source_urls = NULL;		  // Source site's URL. Used for URL fixups
		private $_target_urls = NULL;		  // Target site's URL. Used for URL fixups
		private $_image_refs = array();

		private function __construct()
		{
			add_action('spectrom_sync_init', array($this, 'init'));
			if (is_admin())
				add_action('wp_loaded', array($this, 'wp_loaded'));
		}

		/*
		 * retrieve singleton class instance
		 * @return instance reference to plugin
		 */
		public static function get_instance()
		{
			if (NULL === self::$_instance)
				self::$_instance = new self();
			return self::$_instance;
		}

		/**
		 * Callback for the 'spectrom_sync_init' action. Used to initialize this plugin knowing the WPSiteSync exists
		 */
		public function init()
		{
			add_filter('spectrom_sync_active_extensions', array($this, 'filter_active_extensions'), 10, 2);

			if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_beaverbuilder', self::PLUGIN_KEY, self::PLUGIN_NAME)) {
SyncDebug::log(__METHOD__ . '() no license');
				return;
			}

			// check for minimum WPSiteSync version
			if (is_admin() && version_compare(WPSiteSyncContent::PLUGIN_VERSION, self::REQUIRED_VERSION) < 0 && current_user_can('activate_plugins')) {
				add_action('admin_notices', array($this, 'notice_minimum_version'));
				return;
			}
			// initialize admin class
			if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
				$this->_load_class('beaverbuilderadmin');
				SyncBeaverBuilderAdmin::get_instance();
			}

			add_filter('spectrom_sync_allowed_post_types', array($this, 'allow_custom_post_types'));
			// use the 'spectrom_sync_api_request' filter to add any necessary taxonomy information
//			add_filter('spectrom_sync_api_request', array($this, 'add_bb_data'), 10, 3);
//			add_filter('spectrom_sync_tax_list', array($this, 'filter_taxonomies'), 10, 1);
			// load scripts and content on front end requests
			if (isset($_GET['fl_builder']) && SyncOptions::is_auth()) {
				add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
				add_action('wp_footer', array($this, 'output_html_content'));
			}

			// hooks for adjusting Push content
			add_filter('spectrom_sync_api_push_content', array($this, 'filter_push_content'), 10, 2); // TODO: use this or 'spectrom_sync_api_request'
			add_action('spectrom_sync_ajax_operation', array($this, 'ajax_operation'), 10, 3);
			add_action('spectrom_sync_push_content', array($this, 'handle_push'), 10, 3);
			add_filter('spectrom_sync_upload_media_allowed_mime_type', array($this, 'filter_allowed_mime_types'), 10, 2);

			// hooks for adding settings push and image reference APIs
			$this->_load_class('beaverbuilderapirequest', TRUE);
			$api = new SyncBeaverBuilderApiRequest();
			add_filter('spectrom_sync_api_request_action', array($api, 'api_request'), 20, 3); // called by SyncApiRequest
			add_filter('spectrom_sync_api', array($api, 'api_controller_request'), 10, 3); // called by SyncApiController
			add_action('spectrom_sync_api_request_response', array($api, 'api_response'), 10, 3); // called by SyncApiRequest->api()

			add_filter('spectrom_sync_error_code_to_text', array($api, 'filter_error_codes'), 10, 2);
			add_filter('spectrom_sync_notice_code_to_text', array($api, 'filter_notice_codes'), 10, 2);
		}

		/**
		 * Callback for the 'wp_loaded' action. Used to display admin notice if WPSiteSync for Content is not activated
		 */
		public function wp_loaded()
		{
			if (!class_exists('WPSiteSyncContent', FALSE) && current_user_can('activate_plugins')) {
				if (is_admin())
					add_action('admin_notices', array($this, 'notice_requires_wpss'));
			}
		}

		/**
		 * Display admin notice to install/activate WPSiteSync for Content
		 */
		public function notice_requires_wpss()
		{
			$this->_show_notice(sprintf(__('WPSiteSync for Beaver Builder requires the main <em>WPSiteSync for Content</em> plugin to be installed and activated. Please <a href="%1$s">click here</a> to install or <a href="%2$s">click here</a> to activate.', 'wpsitesync-beaverbuilder'), admin_url('plugin-install.php?tab=search&s=wpsitesync'), admin_url('plugins.php')), 'notice-warning');
		}

		/**
		 * Display admin notice to upgrade WPSiteSync for Content plugin
		 */
		public function notice_minimum_version()
		{
			$this->_show_notice(sprintf(__('WPSiteSync for Beaver Builder requires version %1$s or greater of <em>WPSiteSync for Content</em> to be installed. Please <a href="2%s">click here</a> to update.', 'wpsitesync-beaverbuilder'), self::REQUIRED_VERSION, admin_url('plugins.php')), 'notice-warning');
		}

		/**
		 * Helper method to display notices
		 * @param string $msg Message to display within notice
		 * @param string $class The CSS class used on the <div> wrapping the notice
		 * @param boolean $dismissable TRUE if message is to be dismissable; otherwise FALSE.
		 */
		private function _show_notice($msg, $class = 'notice-success', $dismissable = FALSE)
		{
			echo '<div class="notice ', $class, ' ', ($dismissable ? 'is-dismissible' : ''), '">';
			echo '<p>', $msg, '</p>';
			echo '</div>';
		}

		/**
		 * Handles fixup of data on the Target after SyncApiController has finished processing Content.
		 * @param int $target_post_id The post ID being created/updated via API call
		 * @param array $post_data Post data sent via API call
		 * @param SyncApiResponse $response Response instance
		 */
		public function handle_push($target_post_id, $post_data, $response)
		{
			// TODO: refactor into a SyncBeaverBuilderPushProcess class
SyncDebug::log(__METHOD__ . "({$target_post_id})");
			return;

			// list of object properties that refer to image ids
//			$properties = array('hero_image', 'hero_subtitle_image', 'hero_video', 'about_image_field');
			// setup search and replace array for domain fixups
			$controller = SyncApiController::get_instance();
			$controller->get_fixup_domains($this->_source_urls, $this->_target_urls);

			$input = new SyncInput();

			// process data found in the ['bb_image_refs'] element. create entries in spectrom_sync as needed
			// do this *before* post_meta processing so we have target_post_ids for image references and can fixup IDs for Media Entries
			$image_refs = $input->post_raw(self::DATA_IMAGE_REFS, array());
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
							$sync_model = new SyncModel();
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
													$sync_data = $sync_model->get_sync_data($source_image_id, $controller->source_site_key, 'media');
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' get_sync_data(' . $source_image_id . ', "' . $controller->source_site_key . '", "media")=' . var_export($sync_data, TRUE));
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
									}
								} // isset($object->settings)

								// look for video references
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' looking for video references');
								if (!empty($object->settings->bg_video) && isset($object->settings->bg_video_data) &&
									!empty($object->settings->bg_video_data->id)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found video id: ' . $object->settings->bg_video_data->id);
									$source_image_id = abs($object->settings->bg_video_data->id);
									$sync_data = $sync_model->get_sync_data($source_image_id, $controller->source_site_key, 'media');
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
//			if (0 !== $image_id && !in_array($image_id, $data[self::DATA_IMAGE_REFS])) {
			if (0 !== $image_id && !isset($this->_image_refs[$image_id])) {
//				$data[self::DATA_IMAGE_REFS][$image_id] = $image_src;
				$this->_image_refs[$image_id] = $image_src;
SyncDebug::log(__METHOD__ . '():' . __LINE__ . " calling send_media('{$image_src}', {$this->_post_id}, 0, {$image_id})");
				$this->_api_request->send_media($image_src, $this->_post_id, 0, $image_id);
			}
		}

		/**
		 * Callback for SyncSerialize->parse_data() when parsing the serialized data. Change old Source domain to Target domain.
		 * @param SyncSerializeEntry $entry The data representing the current node processed by Serialization parser.
		 */
		public function fixup_url_references($entry)
		{
			$entry->content = str_ireplace($this->_source_urls, $this->_target_urls, $entry->content);
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

			$sync_model = new SyncModel();
			$attach_model = new SyncAttachModel();
			$controller = SyncApiController::get_instance();
			$site_key = $controller->source_site_key;
			$target_site_key = SyncOptions::get('site_key');

			$source = $controller->source;
			$target = site_url();

			foreach ($image_refs as $img_id => $img_src) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found media #' . $img_id . ' - ' . $img_src);
				$entry = $sync_model->get_sync_data($img_id, NULL, 'media');
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
						$sync_model->save_sync_data($data);
					}
				}
			}
		}

		/**
		 * Callback for handling AJAX operations
		 * @param boolean $handled TRUE if API call has been handled
		 * @param string $operation Name of API operation
		 * @param SyncApiResponse $response Response instance to adjust based on results of API call
		 * @return boolean TRUE if this method handled the AJAX operation; otherwise original value
		 */
		public function ajax_operation($handled, $operation, $response)
		{
			if ('pushbeaverbuildersettings' === $operation) {
SyncDebug::log(__METHOD__ . '() found action: ' . $operation);
				$api = new SyncApiRequest();
				$api_response = $api->api('pushbeaverbuildersettings');
				$response->copy($api_response);
				$response->success(TRUE);
				$handled = TRUE;
			}
			return $handled;
		}

		/**
		 * Callback for filtering the post data before it's sent to the Target. Here we check for image references within the meta data.
		 * @param array $data The data being Pushed to the Target machine
		 * @param SyncApiRequest $apirequest Instance of the API Request object
		 * @return array The modified data
		 */
		public function filter_push_content($data, $apirequest)
		{
			// TODO: this needs to be refactored into a SyncBeaverBuilderPushContent class
			add_action('spectrom_sync_push_queue_complete', array($this, 'image_ref_api'), 10, 1);

SyncDebug::log(__METHOD__ . '()'); //  data=' . var_export($data, TRUE)); // . var_export($data, TRUE));
			// look for media references and call SyncApiRequest->send_media() to add media to the Push operation
			if (isset($data['post_meta'])) {
				$post_id = 0;
				if (isset($data['post_id']))	  // present on Push operations
					$post_id = abs($data['post_id']);
				else if (isset($data['post_data']['ID']))   // present on Pull operations
					$post_id = abs($data['post_data']['ID']);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' post id=' . $post_id);
				$this->_post_id = $post_id;	   // set this up for use in _send_media_instance()
				$data[self::DATA_IMAGE_REFS] = array();	// initialize the list of image references

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
//									if ('http://' === substr($url, 0, 7) || 'https://' === substr($url, 0, 8)) {
									if ($site_url === substr($url, 0, strlen($site_url)) && FALSE !== strpos($url, $upload_url)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' syncing image: ' . $url);
										$attach_posts = $attach_model->search_by_guid($url, TRUE);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' res=' . var_export($attach_posts, TRUE));
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
								}
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' done processing images');
							}
						}
					}

					if ('_fl_builder_data' === $meta_key || '_fl_builder_draft' === $meta_key) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' looking for images references in meta data');
						$meta_data = unserialize($meta_value[0]);
//						$meta_data = unserialize($meta_data);
SyncDebug::log('=' . var_export($meta_value[0], TRUE));
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' meta value=' . var_export($meta_data, TRUE));
						foreach ($meta_data as $key => $object) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' key=' . $key);
							// search for '_src' suffixed properties
							if (isset($object->settings) && is_object($object->settings)) {
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
												$this->_send_media_instance($img_id, $object->settings->$var, $data);
											}
										}
									}
								}
							} // isset($object->settings)
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
					}
				}
			}

			return $data;
		}

		/**
		 * Callback for 'spectrom_sync_push_queue_complete' action when Push queue is empty. Used to trigger Image Reference API call.
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
		 * Callback for filtering the allowed extensions. Needed to allow video types set with Advanced Settings.
		 * File types allowed are: avi, flv, wmv, mp4 and mov
		 * @param boolean $allow TRUE to allow the type; otherwise FALSE
		 * @param array $type Array of File Type information returned from wp_check_filetype()
		 */
		public function filter_allowed_mime_types($allowed, $type)
		{
			if (in_array($type['ext'], array('avi', 'flv', 'wmv', 'mp4', 'mov')))
				$allowed = TRUE;
			return $allowed;
		}

		/**
		 * Callback for the 'wp_enqueue_scripts' action to add JS and CSS to the page when the Page Builder is active
		 */
		public function enqueue_scripts()
		{
			wp_register_script('sync-beaverbuilder', plugin_dir_url(__FILE__) . '/assets/js/sync-beaverbuilder.js', array('jquery'), self::PLUGIN_VERSION, TRUE);
			wp_enqueue_script('sync-beaverbuilder');

			wp_register_style('sync-beaverbuilder', plugin_dir_url(__FILE__) . '/assets/css/sync-beaverbuilder.css', array(), self::PLUGIN_VERSION);
			wp_enqueue_style('sync-beaverbuilder');
		}

		/**
		 * Outputs the HTML content for the WPSiteSync Beaver Builder UI
		 */
		public function output_html_content()
		{
			global $post;

			echo '<div id="sync-beaverbuilder-ui" style="display:none">';
			echo '<span id="sync-separator" class="fl-builder-button"></span>';

			if (class_exists('WPSiteSync_Pull', FALSE)) {
				$class = 'fl-builder-button-primary';
				$js_function = 'pull';
			} else {
				$class = 'fl-builder-button';
				$js_function = 'pull_disabled';
			}
			echo '<span id="sync-bb-pull" class="fl-builder-button ', $class, '" onclick="wpsitesync_beaverbuilder.', $js_function, '(', $post->ID, ');return false">';
			echo '<span class="sync-button-icon sync-button-icon-rotate dashicons dashicons-migrate"></span> ';
			echo __('Pull from Target', 'wpsitesync-beaverbuilder'), '</span>';

			echo '<span id="sync-bb-push" class="fl-builder-button fl-builder-button-primary" onclick="wpsitesync_beaverbuilder.push(', $post->ID, ');return false">';
			echo '<span class="sync-button-icon dashicons dashicons-migrate"></span> ';
			echo __('Push to Target', 'wpsitesync-beaverbuilder'), '</span>';

			echo '<img id="sync-logo" src="', WPSiteSyncContent::get_asset('/imgs/wpsitesync-logo-blue.png'), '" width="80" height="30" alt="WPSiteSync logo" title="WPSiteSync for Content">';

//			echo '<button id="sync-bb-push" class="fl-builder-button fl-builder-button-primary">', __('Push', 'wpsitesync-beaverbuilder'), '</button>';
//			echo '<button id="sync-bb-pull" class="fl-builder-button ', $class, '">', __('Pull', 'wpsitesync-beaverbuilder'), '</button>';
			echo '<div id="sync-beaverbuilder-msg-container">';
			echo '<div id="sync-beaverbuilder-msg" style="display:none">';
			echo '<span id="sync-content-anim" style="display:none"> <img src="', WPSiteSyncContent::get_asset('imgs/ajax-loader.gif'), '"> </span>';
			echo '<span id="sync-message"></span>';
			echo '<span id="sync-message-dismiss" style="display:none"><span class="dashicons dashicons-dismiss" onclick="wpsitesync_beaverbuilder.clear_message(); return false"></span></span>';
			echo '</div>';
			echo '</div>';

			echo '<div style="display:none">';
			// translatable messages
			echo '<span id="sync-msg-save-first">', __('Please save content before Pushing to Target.', 'wpsitesync-beaverbuilder'), '</span>';
			echo '<span id="sync-msg-starting-push">', __('Pushing Content to Target site...', 'wpsitesync-beaverbuilder'), '</span>';
			echo '<span id="sync-msg-success">', __('Content successfully Pushed to Target site.', 'wpsitesync-beaverbuilder'), '</span>';
			echo '<span id="sync-msg-starting-pull">', __('Pulling Content from Target site...', 'wpsitesync-beaverbuilder'), '</span>';
			echo '<span id="sync-msg-pull-success">', __('Content successfully Pulled from Target site.', 'wpsitesync-beaverbuilder'), '</span>';
			echo '<span id="sync-msg-pull-disabled">', __('Please install and activate the WPSiteSync for Pull add-on to have Pull capability.', 'wpsitesync-beaverbuilder'), '</span>';
			echo '<span id="_sync_nonce">', wp_create_nonce('sync'), '</span>';
			echo '</div>';

			echo '</div>'; // #sync-beaverbuilder-ui
		}

		/**
		 * Adds all custom post types to the list of `spectrom_sync_allowed_post_types`
		 * @param  array $post_types The post types to allow
		 * @return array The allowed post types, with the bb types added
		 */
		public function allow_custom_post_types($post_types)
		{
			if (WPSiteSyncContent::get_instance()->get_license()->check_license('sync_beaverbuilder', self::PLUGIN_KEY, self::PLUGIN_NAME)) {
				$post_types[] = 'fl-builder-template';	// bb templates
				$post_types[] = 'fl-theme-layout';	 // bb themes #14
			}

			return $post_types;
		}

		/**
		 * Adds all known taxonomies to the list of available taxonomies for Syncing
		 * @param array $tax Array of taxonomy information to filter
		 * @return array The taxonomy list, with all taxonomies added to it
		 */
		// TODO: may not be needed
		public function filter_taxonomies($tax)
		{
			$all_tax = get_taxonomies(array(), 'objects');
			$tax = array_merge($tax, $all_tax);
			return $tax;
		}

		/**
		 * Adds custom taxonomy information to the data array collected for the current post
		 * @param array $data The array of data that will be sent to the Target
		 * @param string $action The API action, i.e. 'auth', 'post', etc.
		 * @param string $request_args The arguments being sent to wp_remote_post()
		 * @return array The modified data with Beaver Builder specific information added
		 */
		// TODO: not needed
/* 		public function add_bb_data($data, $action, $request_args)
		{
SyncDebug::log(__METHOD__.'() action=' . $action);
			if ('push' !== $action && 'pull' !== $action)
				return $data;
if (!isset($data['post_data']))
	SyncDebug::log(__METHOD__.'() no post_data element found in ' . var_export($data, TRUE));
else if (!isset($data['post_data']['post_type']))
	SyncDebug::log(__METHOD__.'() no post_type element found in ' . var_export($data['post_data'], TRUE));

		  // TODO: do we need to check for allowed post types or just post/page?
		  if (!in_array($data['post_data']['post_type'], array('post', 'page'))) {
			  // TODO: collect CPT taxonomy data and add to array
		  }
		  // TODO: add custom taxonomy information
		  return $data;
		} */

		/**
		 * Add the Beaver Builder add-on to the list of known WPSiteSync extensions
		 * @param array $extensions The list to add to
		 * @param boolean $set
		 * @return array The list of extensions, with the WPSiteSync for Beaver Builder add-on included
		 */
		public function filter_active_extensions($extensions, $set = FALSE)
		{
//SyncDebug::log(__METHOD__.'()');
			if ($set || WPSiteSyncContent::get_instance()->get_license()->check_license('sync_beaverbuilder', self::PLUGIN_KEY, self::PLUGIN_NAME))
				$extensions['sync_beaverbuilder'] = array(
					'name' => self::PLUGIN_NAME,
					'version' => self::PLUGIN_VERSION,
					'file' => __FILE__,
				);
			return $extensions;
		}

		/**
		 * Helper method to load class files when needed
		 * @param string $class Name of class file to load
		 */
		private function _load_class($class)
		{
			$file = dirname(__FILE__) . '/classes/' . $class . '.php';
			require_once($file);
		}
	}
}

// Initialize the extension
WPSiteSync_BeaverBuilder::get_instance();

// EOF
