<?php

class SyncBeaverBuilderModel
{
	/**
	 * Searches postmeta for a '_fl_builder_template_id' meta data who's value matches the provided Template ID
	 * @param string $template_id The 13 character Template ID associated with the post ID
	 * @return int The post ID associated with the given Template ID
	 */
	public function template_id_to_post_id($template_id)
	{
		global $wpdb;
		$sql = "SELECT `post_id`
				FROM `{$wpdb->postmeta}`
				WHERE `meta_key`='_fl_builder_template_id' AND `meta_value`=%s
				LIMIT 1";
		$query = $wpdb->prepare($sql, $template_id);
		$res = $wpdb->get_col($query);
		if (0 !== count($res))
			return abs($res[0]);
		return FALSE;
	}

	/**
	 * Searches postmeta for a '_fl_builder_template_id' entry for the given post ID and returns the Template ID found
	 * @param int $post_id The post ID to obtain the Template ID from
	 * @return string The 13 character Template ID associated with the given post ID
	 */
	public function post_id_to_template_id($post_id)
	{
		$template_id = get_post_meta($post_id, '_fl_builder_template_id', TRUE);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post id=' . $post_id . ' refers to template id ' . $template_id);
		return $template_id;
	}
}

// EOF
