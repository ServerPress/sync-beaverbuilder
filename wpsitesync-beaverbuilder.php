<?php
/*
Plugin Name: WPSiteSync for Beaver Builder
Plugin URI: http://wpsitesync.com
Description: Allow custom post types to be Synced to the Target site
Author: WPSiteSync
Author URI: http://wpsitesync.com
Version: 1.0
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
		const REQUIRED_VERSION = '1.3';									// minimum version of WPSiteSync required for this add-on to initialize

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
SyncDebug::log(__METHOD__.'() no license');
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
//				require_once (dirname(__FILE__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'beaverbuilderadmin.php');
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
			add_filter('spectrom_sync_api_push_content', array($this, 'filter_push_content'), 10, 2);	// TODO: use this or 'spectrom_sync_api_request'
			add_action('spectrom_sync_ajax_operation', array($this, 'ajax_operation'), 10, 3);
			add_action('spectrom_sync_push_content', array($this, 'handle_push'), 10, 3);

			// hooks for adding settings push api
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
				return;
			}
		}

		/**
		 * Display admin notice to install/activate WPSiteSync for Content
		 */
		public function notice_requires_wpss()
		{
			$this->_show_notice(sprintf(__('WPSiteSync for Beaver Builder requires the main <em>WPSiteSync for Content</em> plugin to be installed and activated. Please <a href="%1$s">click here</a> to install or <a href="%2$s">click here</a> to activate.', 'wpsitesync-beaverbuilder'),
				admin_url('plugin-install.php?tab=search&s=wpsitesync'),
				admin_url('plugins.php')), 'notice-warning');
		}

		/**
		 * Display admin notice to upgrade WPSiteSync for Content plugin
		 */
		public function notice_minimum_version()
		{
			$this->_show_notice(sprintf(__('WPSiteSync for Beaver Builder requires version %1$s or greater of <em>WPSiteSync for Content</em> to be installed. Please <a href="2%s">click here</a> to update.', 'wpsitesync-beaverbuilder'),
				self::REQUIRED_VERSION,
				admin_url('plugins.php')), 'notice-warning');
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
			echo	'<p>', $msg, '</p>';
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
SyncDebug::log(__METHOD__."({$target_post_id})");
			$input = new SyncInput();
			$post_meta = $input->post_raw('post_meta', array());
			foreach ($post_meta as $meta_key => $meta_value) {
				if ('_fl_builder_' === substr($meta_key, 0, 12)) {
SyncDebug::log(__METHOD__.'() found BeaverBuilder meta: ' . $meta_key . '=' . var_export($meta_value, TRUE));
					if (is_array($meta_value)) {
						// only bother with serialization fixup if it's an array
						$meta_data = $meta_value[0];

						// unslash
						$meta_data = stripslashes($meta_data);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' stripped: ' . var_export($meta_data, TRUE));

						// fixup domains
						$controller = SyncApiController::get_instance();
						$source_url = $controller->source;
						$target_url = site_url();
						$meta_data = str_replace($source_url, $target_url, $meta_data);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' fix domain from "' . $source_url . '" to "' . $target_url . '": ' . var_export($meta_data, TRUE));

						// fixup serialization
						$this->_load_class('beaverbuilderserialize');
						$ser = new SyncBeaverBuilderSerialize();
						$meta_data = $ser->fix_serialized_data($meta_data);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' fix serialization: ' . var_export($meta_data, TRUE));

						// convert to an object
						$meta_object = maybe_unserialize($meta_data);

						// write meta data
						update_post_meta($target_post_id, $meta_key, $meta_object);
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
SyncDebug::log(__METHOD__.'() found action: ' . $operation);
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
SyncDebug::log(__METHOD__.'()'); //  data=' . var_export($data, TRUE)); // . var_export($data, TRUE));
			// look for media references and call SyncApiRequest->send_media() to add media to the Push operation
			if (isset($data['post_meta'])) {
				$post_id = 0;
				if (isset($data['post_id']))						// present on Push operations
					$post_id = abs($data['post_id']);
				else if (isset($data['post_data']['ID']))			// present on Pull operations
					$post_id = abs($data['post_data']['ID']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post id=' . $post_id);
				$regex_search = "/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?/";
				$attach_model = new SyncAttachModel();

				// set up some values to be used to identify site-specific image references vs. non-site images
				$site_url = site_url();
				$upload = wp_upload_dir();
SyncDebug::log(__METHOD__.'() upload info=' . var_export($upload, TRUE));
				$upload_url = $upload['baseurl'];
				// this sets the source domain- needed for SynApiRequest::send_media() to work
				$apirequest->set_source_domain(parse_url($site_url, PHP_URL_HOST));

				foreach ($data['post_meta'] as $meta_key => $meta_value) {
					if ('_fl_builder_' === substr($meta_key, 0, 12)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found key: ' . $meta_key);
						$meta_data = serialize($meta_value);
						$meta_data = str_replace('"', ' " ', $meta_data);
						// look for any image references
						// TODO: look for other media: audio / video

						// check if there is a url in the text
						$urls = array();
						if (preg_match_all($regex_search, $meta_data, $urls)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found urls: ' . var_export($urls, TRUE));
							if (isset($urls[0]) && 0 !== count($urls[0])) {
								// look for only those URL references that match the current site's URL
								foreach ($urls[0] as $url) {
//									if ('http://' === substr($url, 0, 7) || 'https://' === substr($url, 0, 8)) {
									if ($site_url === substr($url, 0, strlen($site_url)) && FALSE !== strpos($url, $upload_url)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' syncing image: ' . $url);
										$attach_posts = $attach_model->search_by_guid($url);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' res=' . var_export($attach_posts, TRUE));
										// ignore any images that are not found in the Image Library
										if (0 === count($attach_posts)) {
SyncDebug::log(' - no attachments found with this name, skipping');
											continue;
										}

										// find the attachment id
										$attach_id = 0;
										foreach ($attach_posts as $attach_post) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking guid "' . $attach_post->guid . '"');
											if ($attach_post->guid === $url) {
												$attach_id = $attach_post->ID;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found matching for id#' . $attach_id);
												break;
											}
										}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' attach id=' . $attach_id);
										$apirequest->send_media($url, $post_id, 0, $attach_id);
									}
								}
							}
						}
					}
				}
			}

			return $data;
		}

		/**
		 * Callback for the 'wp_enqueue_scripts' action to add JS and CSS to the page when the Page Builder is active
		 */
		public function enqueue_scripts()
		{
			wp_register_script('sync-beaverbuilder', plugin_dir_url(__FILE__) . '/assets/js/sync-beaverbuilder.js',
				array('jquery'),
				self::PLUGIN_VERSION, TRUE);
			wp_enqueue_script('sync-beaverbuilder');

			wp_register_style('sync-beaverbuilder', plugin_dir_url(__FILE__) . '/assets/css/sync-beaverbuilder.css',
				array(),
				self::PLUGIN_VERSION);
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
			echo	'<span id="sync-content-anim" style="display:none"> <img src="', WPSiteSyncContent::get_asset('imgs/ajax-loader.gif'), '"> </span>';
			echo	'<span id="sync-message"></span>';
			echo	'<span id="sync-message-dismiss" style="display:none"><span class="dashicons dashicons-dismiss" onclick="wpsitesync_beaverbuilder.clear_message(); return false"></span></span>';
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
		 * @return array
		 */
		public function allow_custom_post_types($post_types)
		{
			if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_beaverbuilder', self::PLUGIN_KEY, self::PLUGIN_NAME))
				return $post_types;

			$post_types[] = 'fl-builder-template';

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
		public function add_bb_data($data, $action, $request_args)
		{
SyncDebug::log(__METHOD__.'() action=' . $action);
			if ('push' !== $action && 'pull' !== $action)
				return $data;
if (!isset($data['post_data']))
	SyncDebug::log(__METHOD__.'() no post_data element found in ' . var_export($data, TRUE));
else if (!isset($data['post_data']['post_type']))
	SyncDebug::log(__METHOD__.'() no post_type element found in ' . var_export($data['post_data'], TRUE));

			if (!in_array($data['post_data']['post_type'], array('post', 'page'))) {
				// TODO: collect CPT taxonomy data and add to array
			}
			// TODO: add custom taxonomy information
			return $data;
		}

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
