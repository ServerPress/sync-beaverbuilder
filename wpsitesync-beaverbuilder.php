<?php
/*
Plugin Name: WPSiteSync for Beaver Builder
Plugin URI: https://wpsitesync.com/downloads/wpsitesync-beaver-builder/
Description: Allow Beaver Builder Content and Templates to be Synced to the Target site
Author: WPSiteSync
Author URI: https://wpsitesync.com
Version: 1.2.1
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
		const PLUGIN_VERSION = '1.2.1';
		const PLUGIN_KEY = '940382e68ffadbfd801c7caa41226012';
		const REQUIRED_VERSION = '1.5.3';		 // minimum version of WPSiteSync required for this add-on to initialize

		const DATA_IMAGE_REFS = 'bb_image_refs';	// TODO: remove

		private $_post_id = 0;					// Post ID of data being Pushed to Target
		private $_api_request = NULL;			// API Request instance used in pre-processing serialized data
		private $_source_api = NULL;			// reference to SyncBeaverBuilderSourceAPI
		private $_target_api = NULL;			// reference to SyncBeaverBuilderTargetAPI

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
		 * Helper method to load class files when needed
		 * @param string $class Name of class file to load
		 */
		private function _load_class($class)
		{
			$file = dirname(__FILE__) . '/classes/' . $class . '.php';
			require_once($file);
		}

		/**
		 * Callback for the 'spectrom_sync_init' action. Used to initialize this plugin knowing the WPSiteSync exists
		 */
		public function init()
		{
SyncDebug::log(__METHOD__.'():' . __LINE__);
			add_filter('spectrom_sync_active_extensions', array($this, 'filter_active_extensions'), 10, 2);

			if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_beaverbuilder', self::PLUGIN_KEY, self::PLUGIN_NAME)) {
#SyncDebug::log(__METHOD__ . '() no license');
				return;
			}

			add_action('wp_loaded', array($this, 'wp_loaded'));
			add_filter('spectrom_sync_setting-strict', array($this, 'filter_setting_strict'));

			// check for minimum WPSiteSync version
			if (is_admin() && version_compare(WPSiteSyncContent::PLUGIN_VERSION, self::REQUIRED_VERSION) < 0 && current_user_can('activate_plugins')) {
				add_action('admin_notices', array($this, 'notice_minimum_version'));
				return;
			}

			add_action('spectrom_sync_api_init', array($this, 'api_init'));

			// initialize admin class
			if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || isset($_GET['fl_builder'])) {
				$this->_load_class('beaverbuilderadmin');
				SyncBeaverBuilderAdmin::get_instance();
				// if the Beaver Builder editor is active, output the WPSS for Beaver Builder content in the footer
				if (isset($_GET['fl_builder'])) {
					add_action('wp_footer', array(SyncBeaverBuilderAdmin::get_instance(), 'admin_print_scripts'));
					if (class_exists('WPSiteSync_Pull', FALSE)) {
						WPSiteSync_Pull::get_instance()->load_class('pulladmin');
						add_action('wp_enqueue_scripts', array(SyncPullAdmin::get_instance(), 'admin_enqueue_scripts'));
					}
				}
			}

			// hooks for adding UI to Beaver Builder pages
			add_filter('spectrom_sync_allowed_post_types', array($this, 'allow_custom_post_types'));
			// use the 'spectrom_sync_api_request' filter to add any necessary taxonomy information
//			add_filter('spectrom_sync_api_request', array($this, 'add_bb_data'), 10, 3);
			add_filter('spectrom_sync_tax_list', array($this, 'filter_taxonomies'), 10, 1);
			// load scripts and content on front end requests
			if (isset($_GET['fl_builder']) && SyncOptions::is_auth()) {
				add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
				add_action('wp_footer', array($this, 'output_html_content'));
			}
		}

		/**
		 * Callback for the 'wp_loaded' action. Used to display admin notice if WPSiteSync for Content is not activated
		 */
		public function wp_loaded()
		{
			$continue = TRUE;
			if (!class_exists('WPSiteSyncContent', FALSE) && current_user_can('activate_plugins'))
				add_action('admin_notices', array($this, 'notice_requires_wpss'));

			if (!class_exists('FLBuilderLoader', FALSE) && current_user_can('activate_plugins'))
				add_action('admin_notices', array($this, 'notice_requires_bb'));
		}

		/**
		 * Initialize hooks and filters for API handling
		 */
		public function api_init()
		{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking capabilities');
			if (!SyncOptions::has_cap()) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' user does not have capabilities');
//				return;
			}

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' initializing api handlers');

			// hooks for adjusting Push content
			add_filter('spectrom_sync_api_push_content', array($this, 'filter_push_content'), 10, 2); // TODO: use this or 'spectrom_sync_api_request'
			add_action('spectrom_sync_ajax_operation', array($this, 'ajax_operation'), 10, 3);
			add_action('spectrom_sync_push_content', array($this, 'handle_push'), 10, 3);
			add_filter('spectrom_sync_upload_media_allowed_mime_type', array($this, 'filter_allowed_mime_types'), 10, 2);

			// filter for blocking images within the bb-plugin directory from being Pushed
			add_filter('spectrom_sync_send_media_attachment', array($this, 'filter_send_media_attachment'), 10, 3);
//			add_action('spectrom_sync_pull_complete', array($this, 'pull_complete'));

			// hooks for adding settings push and image reference APIs
			add_filter('spectrom_sync_api_request_action', array($this, 'api_request_action'), 20, 3); // called by SyncApiRequest
			add_filter('spectrom_sync_api', array($this, 'api_controller_request'), 10, 3); // called by SyncApiController
			add_action('spectrom_sync_api_request_response', array($this, 'api_request_response'), 10, 3); // called by SyncApiRequest->api()

			add_filter('spectrom_sync_error_code_to_text', array($this, 'filter_error_codes'), 10, 3);
			add_filter('spectrom_sync_notice_code_to_text', array($this, 'filter_notice_codes'), 10, 2);
		}

		/**
		 * Display admin notice to install/activate Beaver Builder
		 */
		public function notice_requires_bb()
		{
			$this->_show_notice(
				sprintf(__('WPSiteSync for Beaver Builder requires the Beaver Builder plugin to be installed and activated. Please <a href="%1$s">click here</a> to activate.', 'wpsitesync-beaverbuilder'),
					admin_url('plugins.php')),
				'notice-warning');
		}

		/**
		 * Display admin notice to install/activate WPSiteSync for Content
		 */
		public function notice_requires_wpss()
		{
			$this->_show_notice(
				sprintf(__('WPSiteSync for Beaver Builder requires the main <em>WPSiteSync for Content</em> plugin to be installed and activated. Please <a href="%1$s">click here</a> to install or <a href="%2$s">click here</a> to activate.', 'wpsitesync-beaverbuilder'),
					admin_url('plugin-install.php?tab=search&s=wpsitesync'), admin_url('plugins.php')),
				'notice-warning');
		}

		/**
		 * Display admin notice to upgrade WPSiteSync for Content plugin
		 */
		public function notice_minimum_version()
		{
			$this->_show_notice(
				sprintf(__('WPSiteSync for Beaver Builder requires version %1$s or greater of <em>WPSiteSync for Content</em> to be installed. Please <a href="2%s">click here</a> to update.', 'wpsitesync-beaverbuilder'),
					self::REQUIRED_VERSION, admin_url('plugins.php')),
				'notice-warning');
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
		 * Filters settings field data for the strict mode configuration setting
		 * @param array $args The arguments to be sent to rendering method
		 * @return array Modified arguments, with message about Beaver Builder included
		 */
		public function filter_setting_strict($args)
		{
			if (empty($args['description']))
				$args['description'] = '';
			$args['description'] .= (!empty($args['description']) ? '<br/>' : '' ) .
				__('With WPSiteSync for Beaver Builder installed, version checking for Beaver Builder is also performed when Pushing Beaver Builder Content.', 'wpsitesync-beaverbuilder');
			return $args;
		}

		/**
		 * Filter the sending of images in the bb-plugin directory
		 * @param boolean $send Value to be filtered
		 * @param string $url The full path to the URL being sent to the Target
		 * @param int $attach_id The ID of the attachment
		 * @return boolean TRUE (default) for sending the image; otherwise FALSE to block sending the image
		 */
		public function filter_send_media_attachment($send, $url, $attach_id)
		{
			// if the image path contains wp-content and bb-plugin, block sending of this image
			if (FALSE !== strpos($url, 'wp-content') && FALSE !== strpos($url, 'plugins') && FALSE !== strpos($url, 'bb-plugin'))
				$send = FALSE;
			return $send;
		}

		/**
		 * Callback used when processing of Pull request is complete
		 */
		public function pull_complete()
		{
SyncDebug::log(__METHOD__.'():'.__LINE__ . ' post=' . var_export($_POST, TRUE));
			if (isset($_POST['id_refs'])) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' id_refs=' . var_export($_POST['id_refs'], TRUE));
				$input = new SyncInput();
				$post_id = $input->post_int('post_id');
				$id_refs = $input->post_raw('id_refs', array());

				$api_data = array(
					'parent_action' => 'pull',
					'post_id' => $post_id,
					'id_refs' => $id_refs,
				);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' calling api("push_complete") with ' . var_export($api_data, TRUE));
				$this->_api_request->api('push_complete', $api_data);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' done with api("push_complete")');
			}
else SyncDebug::log(__METHOD__.'():' . __LINE__ . ' no [id_refs] element in POST content');
		}

		/**
		 * Helper method to retrieve an API instance
		 * @return SyncBeaverBuilderSourceAPI instance
		 */
		private function _get_source_api()
		{
			if (NULL === $this->_source_api) {
				$this->_load_class('beaverbuildersourceapi');
				$this->_source_api = new SyncBeaverBuilderSourceAPI();
			}
			if (!class_exists('SyncBeaverBuilderApiRequest', FALSE))
				$this->_load_class('beaverbuilderapirequest');
			if (!class_exists('SyncBeaverBuilderModel', FALSE))
				$this->_load_class('beaverbuildermodel');
			return $this->_source_api;
		}

		/**
		 * Helper method to retrieve an API instance
		 * @return SyncBeaverBuilderTargetAPI instance
		 */
		private function _get_target_api()
		{
			if (NULL === $this->_target_api) {
				$this->_load_class('beaverbuildertargetapi');
				$this->_target_api = new SyncBeaverBuilderTargetAPI();
			}
			if (!class_exists('SyncBeaverBuilderApiRequest', FALSE))
				$this->_load_class('beaverbuilderapirequest');
			if (!class_exists('SyncBeaverBuilderModel', FALSE))
				$this->_load_class('beaverbuildermodel');
			return $this->_target_api;
		}

		//
		// callbacks for API operations on Target
		//

		/**
		 * Handles fixup of data on the Target after SyncApiController has finished processing Content.
		 * @param int $target_post_id The post ID being created/updated via API call
		 * @param array $post_data Post data sent via API call
		 * @param SyncApiResponse $response Response instance
		 */
		public function handle_push($target_post_id, $post_data, $response)
		{
			$this->_get_target_api();
			$this->_target_api->handle_push($target_post_id, $post_data, $response);
		}

		/**
		 * Handles the requests being processed on the Target from SyncApiController. This handles API requests introduced by the Beaver Builder add-on.
		 * @param boolean $return filter value
		 * @param string $action The API request action
		 * @param SyncApiResponse $response The response instance
		 * @return boolean $response TRUE when handling a API request action; otherwise FALSE
		 */
		public function api_controller_request($return, $action, SyncApiResponse $response)
		{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' got a "' . $action . '" request from SyncApiController');
			$this->_get_target_api();
			return $this->_target_api->api_controller_request($return, $action, $response);
		}

		//
		// callbacks for API operations on Target
		//

		/**
		 * Callback for handling AJAX operations
		 * @param boolean $handled TRUE if API call has been handled
		 * @param string $operation Name of API operation
		 * @param SyncApiResponse $response Response instance to adjust based on results of API call
		 * @return boolean TRUE if this method handled the AJAX operation; otherwise original value
		 */
		public function ajax_operation($handled, $operation, $response)
		{
SyncDebug::log(__METHOD__ . '() found action: ' . $operation);
			if ('pushbeaverbuildersettings' === $operation) {
				$api = new SyncApiRequest();
				$api_response = $api->api('pushbeaverbuildersettings');
				$response->copy($api_response);
				if (!$api->get_response()->has_errors())
					$response->success(TRUE);
				$handled = TRUE;
			} else if ('pullbeaverbuildersettings' === $operation) {
				$api = new SyncApiRequest();
				$api_response = $api->api('pullbeaverbuildersettings');
				$response->copy($api_response);
				if (!$api_response->has_errors())
					$response->success(TRUE);
				$handled = TRUE;
			}

			return $handled;
		}

		/**
		 * Handles the request on the Source after API Requests are made and the response is ready to be interpreted. Called by SyncApiRequest->api().
		 * @param string $action The API name, i.e. 'push' or 'pull'
		 * @param array $remote_args The arguments sent to SyncApiRequest::api()
		 * @param SyncApiResponse $response The response object after the API requesst has been made
		 */
		public function api_request_response($action, $remote_args, $response)
		{
			$this->_get_source_api();
			$this->_source_api->api_request_response($action, $remote_args, $response);
		}

		/**
		 * Called from SyncApiRequest on the Source. Checks the API request and perform custom API actions
		 * @param array $args The arguments array sent to SyncApiRequest::api()
		 * @param string $action The API requested
		 * @param array $remote_args Array of arguments sent to SyncRequestApi::api()
		 * @return array The modified $args array, with any additional information added to it
		 */
		public function api_request_action($args, $action, $remote_args)
		{
			$this->_get_source_api();
			return $this->_source_api->api_request_action($args, $action, $remote_args);
		}

		/**
		 * Callback for filtering the post data before it's sent to the Target. Here we check for image references within the meta data.
		 * @param array $data The data being Pushed to the Target machine
		 * @param SyncApiRequest $apirequest Instance of the API Request object
		 * @return array The modified data
		 */
		public function filter_push_content($data, $apirequest)
		{
			$this->_get_source_api();
			return $this->_source_api->filter_push_content($data, $apirequest);
		}

		/**
		 * Callback for filtering the allowed extensions. Needed to allow video types set with Advanced Settings.
		 * File types allowed are: avi, flv, wmv, mp4 and mov
		 * @param boolean $allow TRUE to allow the type; otherwise FALSE
		 * @param array $type Array of File Type information returned from wp_check_filetype()
		 */
		public function filter_allowed_mime_types($allowed, $type)
		{
			$this->_get_target_api();
			return $this->_target_api->filter_allowed_mime_types($allowed, $type);
		}

		/**
		 * Callback for the 'wp_enqueue_scripts' action to add JS and CSS to the page when the Page Builder is active
		 */
		public function enqueue_scripts()
		{
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' registering "sync-beaverbuilder" script');
			wp_register_script('sync', WPSiteSyncContent::get_asset('js/sync.js'), array('jquery'), WPSiteSyncContent::PLUGIN_VERSION, TRUE);
			wp_register_script('sync-beaverbuilder', plugin_dir_url(__FILE__) . 'assets/js/sync-beaverbuilder.js', array('jquery'), self::PLUGIN_VERSION, TRUE);

			wp_register_style('sync-admin', WPSiteSyncContent::get_asset('css/sync-admin.css'), array(), WPSiteSyncContent::PLUGIN_VERSION, 'all');
			wp_register_style('sync-beaverbuilder', plugin_dir_url(__FILE__) . 'assets/css/sync-beaverbuilder.css', array(), self::PLUGIN_VERSION);

			if (isset($_GET['fl_builder'])) {
				if (class_exists('WPSiteSync_Pull', FALSE)) {
					wp_enqueue_script('sync');
					SyncPullAdmin::get_instance()->admin_enqueue_scripts('post.php');
				}
				// only need to enqueue these if the Beaver Builder editor is being loaded on the page
				wp_enqueue_script('sync-beaverbuilder');
				wp_enqueue_style('sync-beaverbuilder');
			}
		}

		/**
		 * Outputs the HTML content for the WPSiteSync Beaver Builder UI
		 */
		// TODO: move to SyncBeaverBuilderUI class
		public function output_html_content()
		{
			global $post;

			echo '<div id="sync-beaverbuilder-ui" style="display:none">';
			echo '<span id="sync-separator" class="fl-builder-button"></span>';

			// look up the Target post ID if it's available
			$target_post_id = 0;
			$model = new SyncModel();
			$sync_data = $model->get_sync_data($post->ID);
			if (NULL !== $sync_data)
				$target_post_id = abs($sync_data->target_content_id);
$target_post_id = 0;
echo '<!-- WPSiteSync_Pull class ', (class_exists('WPSiteSync_Pull', FALSE) ? 'exists' : 'does not exist'), ' -->', PHP_EOL;
			// check for existence and version of WPSS Pull
			if (class_exists('WPSiteSync_Pull', FALSE)) {
				$class = 'fl-builder-button-primary';
				$js_function = 'pull';
				if (version_compare(WPSiteSync_Pull::PLUGIN_VERSION, '2.1', '<=')) {
echo '<!-- Pull v2.1 -->', PHP_EOL;
					// it's <= v2.1. if there's no previous Push we can't do a pull. disable it
					if (0 === $target_post_id) {
						$class = 'fl-builder-button';
						$js_function = 'pull_disabled_push';
					}
				} else if (version_compare(WPSiteSync_Pull::PLUGIN_VERSION, '2.2', '>=')) {
echo '<!-- Pull v2.2+ -->', PHP_EOL;
					// it's >= v2.2. we can do a search so Pull without previous Push is allowed
					$class = 'fl-builder-button-primary';
					$js_function = 'pull';
				}
			} else {
				$class = 'fl-builder-button';
				$js_function = 'pull_disabled';
			}

			echo '<span id="sync-bb-pull" class="fl-builder-button ', $class, '" ',
				' onclick="wpsitesync_beaverbuilder.', $js_function, '(', $post->ID, ',', $target_post_id, ');return false">';
			echo '<span class="sync-button-icon sync-button-icon-rotate dashicons dashicons-migrate"></span> ';
			echo __('Pull from Target', 'wpsitesync-beaverbuilder'), '</span>';

			echo '<span id="sync-bb-push" class="fl-builder-button fl-builder-button-primary" onclick="wpsitesync_beaverbuilder.push(', $post->ID, ');return false">';
			echo '<span class="sync-button-icon dashicons dashicons-migrate"></span> ';
			echo __('Push to Target', 'wpsitesync-beaverbuilder'), '</span>';

			echo '<img id="sync-logo" src="', WPSiteSyncContent::get_asset('imgs/wpsitesync-logo-blue.png'), '" width="80" height="30" style="width:97px;height:35px" alt="WPSiteSync logo" title="WPSiteSync for Content" >';
			echo '<br/><span id="sync-target-info">', sprintf(__('Target site: <u>%1$s</u>', 'wpsitesync-beaverbuilder'), SyncOptions::get('host')), ' </span>';

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

			if (class_exists('WPSiteSync_Pull', FALSE) && version_compare(WPSiteSync_Pull::PLUGIN_VERSION, '2.2', '>=')) {
				// if the Pull add-on is active, use it to output the Search modal #24
				SyncPullAdmin::get_instance()->output_dialog_modal($post->ID, $post->post_type, 'post');
			}

			echo '</div>'; // #sync-beaverbuilder-ui
		}

		/**
		 * Adds all custom post types to the list of `spectrom_sync_allowed_post_types`
		 * @param array $post_types The post types to allow
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
		 * Adds all Beaver Builder taxonomies to the list of available taxonomies for Syncing
		 * @param array $tax Array of taxonomy information to filter
		 * @return array The taxonomy list, with all taxonomies added to it
		 */
		public function filter_taxonomies($tax)
		{
			$all_tax = get_taxonomies(array(), 'objects');
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' taxonomies: ' . var_export($all_tax, TRUE));
//fl-builder-template-category
//fl-builder-template-type
			// only add the taxonomies created by BB #20
			$bb_tax = array();
			foreach ($all_tax as $tax_name => $tax_info) {
				if ('fl-builder-' === substr($tax_name, 0, 11))
					$bb_tax[$tax_name] = $tax_info;
			}
			$tax = array_merge($tax, $bb_tax);
			return $tax;
		}

		/**
		 * Filters the errors list, adding SyncBeaverBuilder specific code-to-string values
		 * @param string $message The error string message to be returned
		 * @param int $code The error code being evaluated
		 * @return string The modified $message string, with Beaver Builder specific errors added to it
		 */
		public function filter_error_codes($message, $code, $data)
		{
			$this->_load_class('beaverbuilderapirequest', TRUE);
			$api = new SyncBeaverBuilderApiRequest();
			return $api->filter_error_codes($message, $code, $data);
		}

		/**
		 * Filters the notices list, adding SyncBeaverBuilder specific code-to-string values
		 * @param string $message The notice string message to be returned
		 * @param int $code The notice code being evaluated
		 * @return string The modified $message string, with Beaver Builder specific notices added to it
		 */
		public function filter_notice_codes($message, $code)
		{
			$this->_load_class('beaverbuilderapirequest', TRUE);
			$api = new SyncBeaverBuilderApiRequest();
			return $api->filter_notice_codes($message, $code);
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
	}
}

// Initialize the extension
WPSiteSync_BeaverBuilder::get_instance();

// EOF
