/*
 * @copyright Copyright (C) 2015-2016 SpectrOMtech.com. - All Rights Reserved.
 * @license GNU General Public License, version 2 (http://www.gnu.org/licenses/gpl-2.0.html)
 * @author SpectrOMtech.com <hello@SpectrOMtech.com>
 * @url https://www.SpectrOMtech.com/products/
 * The PHP code portions are distributed under the GPL license. If not otherwise stated, all images, manuals, cascading style sheets, and included JavaScript *are NOT GPL, and are released under the SpectrOMtech Proprietary Use License v1.0
 * More info at https://SpectrOMtech.com/products/
 */

console.log('sync-beaverbuilder-settings.js');

function WPSiteSyncContent_BeaverBuilderSettings()
{
	this.inited = false;
	this.$content = null;
	this.disable = false;
}

/**
 * Init
 */
WPSiteSyncContent_BeaverBuilderSettings.prototype.init = function()
{
console.log('bb-init()');
	var html = jQuery('#sync-beaverbuilder-settings-ui').html();
console.log('html=' + html);
//	jQuery('.fl-builder-templates-button').after(html);
	jQuery('.fl-settings-heading').append(html);

	this.inited = true;
};

WPSiteSyncContent_BeaverBuilderSettings.prototype.push_settings = function()
{
console.log('.push_settings()');
	wpsitesynccontent.api('pushbeaverbuildersettings', 0, jQuery('#sync-message-pushing-settings').text(), jQuery('#sync-message-push-success').text());
};

WPSiteSyncContent_BeaverBuilderSettings.prototype.pull_settings = function()
{
console.log('.pull_settings()');
	wpsitesynccontent.api('pullbeaverbuldersettings', 0, jQuery('#sync-message-pull-settings').text(), jQuery('#sync-message-pull-success').text());
};

WPSiteSyncContent_BeaverBuilderSettings.prototype.pull_disabled = function()
{
console.log('.pull_disabled()');
	wpsitesynccontent.set_message(jQuery('#sync-message-pull-disabled').text());
};

wpsitesynccontent.beaverbuilder = new WPSiteSyncContent_BeaverBuilderSettings();

// initialize the WPSiteSync operation on page load
jQuery(document).ready(function()
{
	wpsitesynccontent.beaverbuilder.init();
});

// EOF
