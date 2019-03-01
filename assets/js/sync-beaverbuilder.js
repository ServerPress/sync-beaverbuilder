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
debug_out('starting...');
	var html = jQuery('#sync-beaverbuilder-ui').html();
debug_out('html=' + html);
//	jQuery('.fl-builder-templates-button').after(html);
	if (0 !== jQuery('.fl-builder--saving-indicator').length) {
		// v2.0.3.2+
		jQuery('.fl-builder--saving-indicator').after(html);
	} else {
		// v1.9-2.0
		jQuery('.fl-builder-add-content-button').after(html);
	}

	this.$push_button = jQuery('#sync-bb-push');

	jQuery('body').delegate('.fl-builder-settings-save', 'click', wpsitesync_beaverbuilder.disable_sync);

//	jQuery('.fl-builder-settings-save').on('click', this.disable_sync);
//	jQuery('.fl-builder-publish-button').on('click', this.enable_sync);
//	jQuery('.fl-builder-draft-button').on('click', this.enable_sync);
//	jQuery('.fl-builder-discard-button').on('click', this.enable_sync);

	this.inited = true;
//this.set_message('this is a test', true, true);

	// setup handlers to track Beaver Builder events and disable Push buttons when content changes
	jQuery('body').on('fl-builder.didAddColumn', function() { debug_out('added column'); wpsitesync_beaverbuilder.disable_sync();  });
	jQuery('body').on('fl-builder.didDeleteColumn', function() { debug_out('deleted column'); wpsitesync_beaverbuilder.disable_sync(); });
	jQuery('body').on('fl-builder.didDuplicateColumn', function() { debug_out('duplicated column'); wpsitesync_beaverbuilder.disable_sync(); });
	jQuery('body').on('fl-builder.didAddRow', function() { debug_out('added row'); wpsitesync_beaverbuilder.disable_sync(); });
	jQuery('body').on('fl-builder.didDeleteRow', function() { debug_out('deleted row'); wpsitesync_beaverbuilder.disable_sync(); });
	jQuery('body').on('fl-builder.didDuplicateRow', function() { debug_out('duplicated row'); wpsitesync_beaverbuilder.disable_sync(); });
	jQuery('body').on('fl-builder.didApplyTemplate', function() { debug_out('applied template'); wpsitesync_beaverbuilder.disable_sync(); });
	jQuery('body').on('fl-builder.didPublishLayout', function() { debug_out('publish layout'); wpsitesync_beaverbuilder.disable_sync(); });
	jQuery('body').on('fl-builder.triggerDone', function() { debug_out('done'); wpsitesync_beaverbuilder.disable_sync(); });
	jQuery('body').on('fl-builder.contentItemsChanged', function() { debug_out('content items changed'); wpsitesync_beaverbuilder.disable_sync(); });
	jQuery('body').on('fl-builder.didDeleteModule', function() { debug_out('deleted module'); wpsitesync_beaverbuilder.disable_sync(); });
	jQuery('body').on('fl-builder.didDuplicateModule', function() { debug_out('duplicated module'); wpsitesync_beaverbuilder.disable_sync(); });
	jQuery('body').on('fl-builder.didAddModule', function() { debug_out('added module'); wpsitesync_beaverbuilder.disable_sync(); });
	jQuery('body').on('fl-builder.didSaveNodeSettings', function() { debug_out('save node settings'); wpsitesync_beaverbuilder.disable_sync(); });

	jQuery('body').on('fl-builder.click', function() { debug_out('settings click'); wpsitesync_beaverbuilder.disable_sync(); });
	jQuery('body').on('fl-builder.change', function() { debug_out('settings change'); wpsitesync_beaverbuilder.disable_sync(); });
};

/**
 * Disables the Sync Push and Pull buttons after Content is edited
 */
WPSiteSyncContent_BeaverBuilder.prototype.disable_sync = function()
{
debug_out('disable_sync() - turning off the button');
	wpsitesync_beaverbuilder.content_dirty = true;
	jQuery('#sync-bb-push').addClass('sync-button-disable');
	jQuery('#sync-bb-pull').addClass('sync-button-disable');
};

/**
 * Enable the Sync Push and Pull buttons after Content changes are abandoned
 */
WPSiteSyncContent_BeaverBuilder.prototype.enable_sync = function()
{
debug_out('enable_sync() - turning on the button');
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
//debug_out('push() success response:', response);
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
				if ('undefined' !== typeof(response.error_message)) {
					var msg = '';
					if ('undefined' !== typeof(response.error_data))
						msg += ' - ' + response.error_data;
					wpsitesync_beaverbuilder.set_message(response.error_message + msg, false, true);
				} else if ('undefined' !== typeof(response.data.message))
//					jQuery('#sync-message').text(response.data.message);
					wpsitesync_beaverbuilder.set_message(response.data.message, false, true);
			}
		},
		error: function(response) {
//debug_out('push() failure response:', response);
			var msg = '';
			if ('undefined' !== typeof(response.error_message))
				wpsitesync_beaverbuilder.set_message('<span class="error">' + response.error_message + '</span>', false, true);
//			jQuery('#sync-content-anim').hide();
		}
	};

	// Allow other plugins to alter the ajax request
	jQuery(document).trigger('sync_api_call', [operation, push_xhr]);
//debug_out('push() calling jQuery.ajax');
	jQuery.ajax(push_xhr);
//debug_out('push() returned from ajax call');
};

/**
 * Sets the contents of the message <div>
 * @param {string} message The message to display
 * @param {boolean} anim true to enable display of the animation image; otherwise false.
 * @param {boolean} clear true to enable display of the dismiss icon; otherwise false.
 */
WPSiteSyncContent_BeaverBuilder.prototype.set_message = function(message, anim, clear)
{
debug_out('.set_message("' + message + '")');
	var pos = this.$push_button.offset();
debug_out(pos);
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
//debug_out('add_message() ' + msg);
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
debug_out('.push(' + post_id + ')');
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
debug_out('.pull(' + post_id + ')');
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
debug_out('.pull_disabled(' + post_id + ')');
	this.set_message(jQuery('#sync-message-pull-disabled').html(), false, true);
};

function debug_out(msg, val)
{
//return;
	if ('undefined' !== typeof(console.log)) {
		var fn = '';
//console.log('debug.caller');
//console.log(wpsitesync_beaverbuilder.debug.caller);
//console.log('this.caller');
//console.log(this.caller);
//console.log('callee');
//console.log(debug_out.caller.toString());
//console.log(arguments.callee.caller.name);
//console.log(arguments.callee.caller.name);
		if (null !== debug_out.caller)
			fn = debug_out.caller.name + '';
		if (0 !== fn.length)
			fn += '() ';
		if ('undefined' !== typeof(val)) {
			switch (typeof(val)) {
			case 'string':		msg += ' "' + val + '"';						break;
			case 'object':		msg += ' {' + JSON.stringify(val) + '}';		break;
			case 'number':		msg += ' #' + val;								break;
			case 'boolean':		msg += ' `' + (val ? 'true' : 'false') + '`';	break;
			}
			if (null === val)
				msg += ' `null`';
		}
		console.log('wpss for bb: ' + fn + msg);
	}
};

// create the instance of the Beaver Builder class
wpsitesync_beaverbuilder = new WPSiteSyncContent_BeaverBuilder();

// initialize the WPSiteSync operation on page load
jQuery(document).ready(function()
{
	wpsitesync_beaverbuilder.init();
});

// EOF
