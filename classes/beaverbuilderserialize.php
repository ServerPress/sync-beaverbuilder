<?php

class SyncBeaverBuilderSerialize
{
	public function fix_serialized_data($data)
	{
		$data = preg_replace('!s:(\d+):([\\\\]?"[\\\\]?"|[\\\\]?"((.*?)[^\\\\])[\\\\]?");!e', "'s:'.strlen(sync_bb_unescape_mysql('$3')).':\"'.sync_bb_unescape_quotes('$3').'\";'",
				$data);
		return $data;
	}
}

function sync_bb_unescape_mysql($data)
{
	return str_replace(array("\\\\", "\\0", "\\n", "\\r", "\Z",  "\'", '\"'),
					   array("\\",   "\0",  "\n",  "\r",  "\x1a", "'", '"'), 
					   $data);
}

function sync_bb_unescape_quotes($data)
{
	return str_replace('\"', '"', $data);
}

// EOF