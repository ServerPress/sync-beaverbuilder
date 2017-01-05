/*
 * @copyright Copyright (C) 2015-2016 SpectrOMtech.com. - All Rights Reserved.
 * @license GNU General Public License, version 2 (http://www.gnu.org/licenses/gpl-2.0.html)
 * @author SpectrOMtech.com <hello@SpectrOMtech.com>
 * @url https://www.SpectrOMtech.com/products/
 * The PHP code portions are distributed under the GPL license. If not otherwise stated, all images, manuals, cascading style sheets, and included JavaScript *are NOT GPL, and are released under the SpectrOMtech Proprietary Use License v1.0
 * More info at https://SpectrOMtech.com/products/
 */

console.log('sync-beaverbuilder.js');

function WPSiteSyncContent_BeaverBuilder()
{
	this.inited = false;
	this.$content = null;
	this.$push_button = null;
	this.disable = false;
	this.success_msg = '';
	this.content_dirty = false;
}

/**
 * Init
 */
WPSiteSyncContent_BeaverBuilder.prototype.init = function()
{
console.log('bb-init()');
	var html = jQuery('#sync-beaverbuilder-ui').html();
console.log('html=' + html);
//	jQuery('.fl-builder-templates-button').after(html);
	jQuery('.fl-builder-add-content-button').after(html);

	this.$push_button = jQuery('#sync-bb-push');

	jQuery('body').delegate('.fl-builder-settings-save', 'click', WPSiteSyncContent_BeaverBuilder.disable_sync);

//	jQuery('.fl-builder-settings-save').on('click', this.disable_sync);
//	jQuery('.fl-builder-publish-button').on('click', this.enable_sync);
//	jQuery('.fl-builder-draft-button').on('click', this.enable_sync);
//	jQuery('.fl-builder-discard-button').on('click', this.enable_sync);

	this.inited = true;
//this.set_message('this is a test', true, true);
};

/**
 * Disables the Sync Push and Pull buttons after Content is edited
 */
WPSiteSyncContent_BeaverBuilder.disable_sync = function()
{
console.log('disable_sync() - turning off the button');
	WPSiteSyncContent_BeaverBuilder.content_dirty = true;
	jQuery('#sync-bb-push').addClass('sync-button-disable');
	jQuery('#sync-bb-pull').addClass('sync-button-disable');
};

/**
 * Enable the Sync Push and Pull buttons after Content changes are abandoned
 */
WPSiteSyncContent_BeaverBuilder.enable_sync = function()
{
console.log('disable_sync() - turning on the button');
	WPSiteSyncContent_BeaverBuilder.content_dirty = false;
	jQuery('#sync-bb-push').removeClass('sync-button-disable');
	jQuery('#sync-bb-pull').removeClass('sync-button-disable');
};

/**
 * Common method to perform API operations
 * @param {int} post_id The post ID being sync'd
 * @param {string} operation The API operation name
 */
WPSiteSyncContent_BeaverBuilder.prototype.api = function(post_id, operation)
{
	this.post_id = post_id;
	var data = {
		action: 'spectrom_sync',
		operation: operation,
		post_id: post_id,
		_sync_nonce: jQuery('#_sync_nonce').html()
	};

	var push_xhr = {
		type: 'post',
		async: true,
		data: data,
		url: ajaxurl,
		success: function(response) {
//console.log('push() success response:');
//console.log(response);
			wpsitesync_beaverbuilder.clear_message();
			if (response.success) {
//				jQuery('#sync-message').text(jQuery('#sync-success-msg').text());
				wpsitesync_beaverbuilder.set_message(jQuery(wpsitesync_beaverbuilder.success_msg).text(), false, true);
				if ('undefined' !== typeof(response.notice_codes) && response.notice_codes.length > 0) {
					for (var idx = 0; idx < response.notice_codes.length; idx++) {
						wpsitesync_beaverbuilder.add_message(response.notices[idx]);
					}
				}
			} else {
				if ('undefined' !== typeof(response.error_message))
					wpsitesync_beaverbuilder.set_message(response.error_message, false, true);
				else if ('undefined' !== typeof(response.data.message))
//					jQuery('#sync-message').text(response.data.message);
					wpsitesync_beaverbuilder.set_message(response.data.message, false, true);
			}
		},
		error: function(response) {
//console.log('push() failure response:');
//console.log(response);
			var msg = '';
			if ('undefined' !== typeof(response.error_message))
				wpsitesync_beaverbuilder.set_message('<span class="error">' + response.error_message + '</span>', false, true);
//			jQuery('#sync-content-anim').hide();
		}
	};

	// Allow other plugins to alter the ajax request
	jQuery(document).trigger('sync_api_call', [operation, push_xhr]);
//console.log('push() calling jQuery.ajax');
	jQuery.ajax(push_xhr);
//console.log('push() returned from ajax call');
};

/**
 * Sets the contents of the message <div>
 * @param {string} message The message to display
 * @param {boolean} anim true to enable display of the animation image; otherwise false.
 * @param {boolean} clear true to enable display of the dismiss icon; otherwise false.
 */
WPSiteSyncContent_BeaverBuilder.prototype.set_message = function(message, anim, clear)
{
console.log('.set_message("' + message + '")');
	var pos = this.$push_button.offset();
console.log(pos);
	jQuery('#sync-beaverbuilder-msg').css('left', (pos.left - 10) + 'px').css('top', (Math.min(pos.top, 7) + 30) + 'px');

	jQuery('#sync-message').html(message);
	if ('undefined' !== typeof(anim) && anim)
		jQuery('#sync-content-anim').show();
	else
		jQuery('#sync-content-anim').hide();
	if ('undefined' !== typeof(clear) && clear)
		jQuery('#sync-message-dismiss').show();
	else
		jQuery('#sync-message-dismiss').hide();
	jQuery('#sync-beaverbuilder-msg').show();
};

/**
 * Adds some message content to the current success/failure message in the Sync metabox
 * @param {string} msg The message to append
 */
WPSiteSyncContent_BeaverBuilder.prototype.add_message = function(msg)
{
//console.log('add_message() ' + msg);
	jQuery('#sync-beaverbuilder-msg').append('<br/>' + msg);
};

/**
 * Clears and hides the message <div>
 */
WPSiteSyncContent_BeaverBuilder.prototype.clear_message = function()
{
	jQuery('#sync-beaverbuilder-msg').hide();
};

/**
 * Perform Content Push operation
 * @param {int} post_id The post ID being Pushed
 */
WPSiteSyncContent_BeaverBuilder.prototype.push = function(post_id)
{
console.log('.push(' + post_id + ')');
	if (WPSiteSyncContent_BeaverBuilder.content_dirty) {
		this.set_message(jQuery('#sync-msg-save-first').html(), false, true);
		return;
	}
	this.success_msg = '#sync-msg-success';
	this.set_message(jQuery('#sync-msg-starting-push').html(), true);
	this.api(post_id, 'push');
};

/**
 * Perform Content Pull operation
 * @param {int} post_id The post ID being Pulled
 */
WPSiteSyncContent_BeaverBuilder.prototype.pull = function(post_id)
{
console.log('.pull(' + post_id + ')');
	if (WPSiteSyncContent_BeaverBuilder.content_dirty) {
		this.set_message(jQuery('#sync-msg-save-first').html(), false, true);
		return;
	}
	this.success_msg = '#sync-msg-pull-success';
	this.set_message(jQuery('#sync-msg-starting-pull').html(), true);
	this.api(post_id, 'pull');
};

/**
 * The disabled pull operation, displays message about WPSiteSync for Pull
 * @param {int} post_id The post ID being Pulled
 */
WPSiteSyncContent_BeaverBuilder.prototype.pull_disabled = function(post_id)
{
console.log('.pull_disabled(' + post_id + ')');
	this.set_message(jQuery('#sync-message-pull-disabled').html(), false, true);
};

// create the instance of the Beaver Builder class
// TODO: add this to the main WPSS object
wpsitesync_beaverbuilder = new WPSiteSyncContent_BeaverBuilder();

// initialize the WPSiteSync operation on page load
jQuery(document).ready(function()
{
	wpsitesync_beaverbuilder.init();
});

// EOF
