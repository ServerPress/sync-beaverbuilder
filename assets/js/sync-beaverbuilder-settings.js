/*
 * @copyright Copyright (C) 2015-2019 WPSiteSync.com. - All Rights Reserved.
 * @license GNU General Public License, version 2 (http://www.gnu.org/licenses/gpl-2.0.html)
 * @author WPSiteSync.com <hello@WPSiteSync.com>
 * @url https://wpsitesync.com/downloads/wpsitesync-beaver-builder/
 * The PHP code portions are distributed under the GPL license. If not otherwise stated, all images, manuals, cascading style sheets, and included JavaScript *are NOT GPL, and are released under the SpectrOMtech Proprietary Use License v1.0
 * More info at https://WPSiteSync.com/downloads/
 */

console.log('sync-beaverbuilder-settings.js');

function WPSiteSyncContent_BeaverBuilderSettings()
{
	this.inited = false;			// set to true after initialization
	this.$content = null;
	this.disabled = false;			// set to true when Push/Pull buttons are disabled
}

/**
 * Initialize behaviors for the Beaver Builder Settings page
 */
WPSiteSyncContent_BeaverBuilderSettings.prototype.init = function()
{
console.log('bb-init()');
	var html = jQuery('#sync-beaverbuilder-settings-ui').html();
console.log('html=' + html);
//	jQuery('.fl-builder-templates-button').after(html);
	jQuery('.fl-settings-heading').append(html);

	jQuery('form input').on('change', wpsitesynccontent.beaverbuilder.disable_buttons);
	jQuery('form select').on('change', wpsitesynccontent.beaverbuilder.disable_buttons);
	this.inited = true;
};

/**
 * Disables the WPSiteSync Push and Pull buttons when changes are made to settings
 */
WPSiteSyncContent_BeaverBuilderSettings.prototype.disable_buttons = function()
{
console.log('.disable_buttons() disabled=' + (this.disabled ? 'true' : 'false'));
	if (!this.disabled) {
		jQuery('.sync-button').attr('disabled', 'disabled');
//		jQuery('#sync-save-msg').show().css('display', 'block');
		wpsitesynccontent.set_message(jQuery('#sync-message-save-settings').text(), false, true);
		this.disabled = true;
		jQuery('form input').removeAttr('disabled');
	}
};

/**
 * Callback function for the Push Settings button
 */
WPSiteSyncContent_BeaverBuilderSettings.prototype.push_settings = function()
{
console.log('.push_settings()');
	wpsitesynccontent.api('pushbeaverbuildersettings', 0, jQuery('#sync-message-pushing-settings').text(), jQuery('#sync-message-push-success').text());
};

/**
 * Callback function for the Pull Settings button
 */
WPSiteSyncContent_BeaverBuilderSettings.prototype.pull_settings = function()
{
console.log('.pull_settings()');
	wpsitesynccontent.set_api_callback(this.pull_callback);
	wpsitesynccontent.api('pullbeaverbuildersettings', 0, jQuery('#sync-message-pull-settings').text(), jQuery('#sync-message-pull-success').text());
};

/**
 * Callback function used after successfully handling the Pull action. Reloads the page.
 */
WPSiteSyncContent_BeaverBuilderSettings.prototype.pull_callback = function()
{
console.log('.pull_callback');
	location.reload();
};

/**
 * Callback function for Pull button when Pull is disabled
 */
WPSiteSyncContent_BeaverBuilderSettings.prototype.pull_disabled = function()
{
console.log('.pull_disabled()');
	wpsitesynccontent.set_message(jQuery('#sync-message-pull-disabled').text());
};

// instantiate the Beaver Builder Settings instance
wpsitesynccontent.beaverbuilder = new WPSiteSyncContent_BeaverBuilderSettings();

// initialize the WPSiteSync operation on page load
jQuery(document).ready(function()
{
	wpsitesynccontent.beaverbuilder.init();
});

// EOF
