<?php

class SyncBeaverBuilderAdmin
{
	private static $_instance = NULL;

	private function __construct()
	{
		if (isset($_GET['page']) && 'fl-builder-settings' === $_GET['page']) {
			// only add the script/content on the BB settings page
			if (SyncOptions::is_auth()) {
				add_action('admin_print_scripts', array($this, 'admin_print_scripts'));
				add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
			}
		}
	}

	/**
	 * Returns singleton instance of the BeaverBuilder admin class
	 * @return SyncBeaverBuilderAdmin instance
	 */
	public static function get_instance()
	{
		if (NULL === self::$_instance)
			self::$_instance = new self();
		return self::$_instance;
	}

	/**
	 * Callback for the 'admin_enqueue_scripts' action. Used to add scripts and styles to the page
	 */
	public function admin_enqueue_scripts()
	{
		wp_register_script('sync-beaverbuilder-settings', plugin_dir_url(dirname(__FILE__)) . '/assets/js/sync-beaverbuilder-settings.js',
			array('jquery', 'sync'),
			WPSiteSync_BeaverBuilder::PLUGIN_VERSION, TRUE);
		wp_enqueue_script('sync-beaverbuilder-settings');

		wp_register_style('sync-beaverbuilder-admin', plugin_dir_url(dirname(__FILE__)) . 'assets/css/sync-beaverbuilder-admin.css',
			array('sync-admin'),
			WPSiteSync_BeaverBuilder::PLUGIN_VERSION);
		wp_enqueue_style('sync-beaverbuilder-admin');
	}

	/**
	 * Callback for the 'admin_print_scripts' action. Outputs DOM elements for the UI and translated strings.
	 */
	public function admin_print_scripts()
	{
		echo '<div id="sync-beaverbuilder-settings-ui" style="display:none">';

		echo '<div id="spectrom_sync">';

		echo '<img id="sync-logo" src="', WPSiteSyncContent::get_asset('imgs/wpsitesync-logo-blue.png'), '" width="80" height="30" alt="WPSiteSync logo" title="WPSiteSync for Content">';

		echo '<button id="sync-bb-push-settings" class="button button-primary sync-button" onclick="wpsitesynccontent.beaverbuilder.push_settings(); return false;">';
		echo '<span class="sync-button-icon dashicons dashicons-migrate"></span>';
		echo __('Push Settings to Target', 'wpsitesync-beaverbuilder');
		echo '</button>';

		if (class_exists('WPSiteSync_Pull', FALSE)) {
			$class = 'button-primary';
			$js_function = 'pull_settings';
		} else {
			$class = 'button';
			$js_function = 'pull_disabled';
		}
		echo '<button id="sync-bb-pull-settings" class="button ', $class, ' sync-button" onclick="wpsitesynccontent.beaverbuilder.', $js_function, '(); return false;">';
		echo '<span class="sync-button-icon sync-button-icon-rotate dashicons dashicons-migrate"></span>';
		echo __('Pull Settings from Target', 'wpsitesync-beaverbuilder');
		echo '</button>';
		echo '</div>';		// #spectrom_sync

		// messages to display within the UI
		echo '<div style="display:none">';
		echo	'<span id="sync-message-pushing-settings">', __('Pushing Beaver Builder Settings to Target...', 'wpsitesync-beaverbuilder'), '</span>';
		echo	'<span id="sync-message-push-success">', __('Settings successfully sent to Target.', 'wpsitesync-beaverbuilder'), '</span>';
		echo	'<span id="sync-message-pull-settings">', __('Pulling Settings from Target...', 'wpsitesync-beaverbuilder'), '</span>';
		echo	'<span id="sync-message-pull-success">', __('Settings successfully Pulled from Target site. Reloading page.', 'wpsitesync-beaverbuilder'), '</span>';
		echo	'<span id="sync-message-pull-disabled">', __('Please install and activate the WPSiteSync for Pull add-on to have Pull capability.', 'wpsitesync-beaverbuilder'), '</span>';
		echo	'<span id="sync-message-save-settings">', __('Please Save settings before Pushing or Pulling from Target.', 'wpsitesync-beaverbuilder'), '</span>';
		echo '</div>';
		echo '</div>';

		// the message container
		echo '<div id="sync-message-container" style="display:none">';
		echo '<span id="sync-content-anim" style="display:none"> <img src="', WPSiteSyncContent::get_asset('imgs/ajax-loader.gif'), '"></span>';
		echo '<span id="sync-message"></span>';
		echo '<span id="sync-message-dismiss" style="display:none"><span class="dashicons dashicons-dismiss" onclick="wpsitesynccontent.clear_message(); return false"></span></span>';
		echo '</div>';
	}
}

// EOF