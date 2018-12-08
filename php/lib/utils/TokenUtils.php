<?php

namespace Parsoid\Lib\Utils;

require_once __DIR__."/../config/WikitextConstants.php";

use Parsoid\Lib\Config\WikitextConstants;

class TokenUtils {
	const solTransparentLinkRegexp = '/(?:^|\s)mw:PageProp\/(?:Category|redirect|Language)(?=$|\s)/';

	/**
	 * Determine if a tag is block-level or not.
	 *
	 * `<video>` is removed from block tags, since it can be phrasing content.
	 * This is necessary for it to render inline.
	 */
	public static function isBlockTag($name) {
		return $name !== 'video' && isset(WikitextConstants::$HTML['HTML4BlockTags'][$name]);
	}
}
