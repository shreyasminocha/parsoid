<?php

namespace Parsoid\Lib\PHPUtils;

/**
* This file contains Parsoid-independent PHP helper functions.
* Over time, more functions can be migrated out of various other files here.
* @module
*/

class PHPUtil {

	/**
	 * Return accurate system time
	 * @return {time in seconds since Jan 1 1970 GMT accurate to the microsecond}
	 */
	public static function getStartHRTime() {
		$startHrTime = microtime(true);
		return $startHrTime;
	}

	/**
	 * Return millisecond accurate system time differential
	 * @param {previousTime}
	 * @return {# milliseconds}
	 */
	public static function getHRTimeDifferential($previousTime) {
		$diff = (microtime(true) - $previousTime) * 1000;
		return $diff;
	}

}

?>
