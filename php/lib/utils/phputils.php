<?php

namespace Parsoid\Lib\PHPUtils;

/**
* This file contains Parsoid-independent PHP helper functions.
* Over time, more functions can be migrated out of various other files here.
* @module
*/

class PHPUtil {

	/**
	 * Convert a counter to a Base64 encoded string.
	 * Padding is stripped. \,+ are replaced with _,- respectively.
	 * Warning: Max integer is 2^31 - 1 for bitwise operations.
	 */
/*	counterToBase64: function(n) {
		// eslint-disable no-bitwise
		var arr = [];
		do {
			arr.unshift(n & 0xff);
			n >>= 8;
		} while (n > 0);
		return (Buffer.from(arr))
		.toString("base64")
		.replace(/=/g, "")
		.replace(/\//g, "_")
		.replace(/\+/g, "-");
			// eslint-enable no-bitwise
		}, */
	public static function counterToBase64($n) {
		$arr = [];
		do {
			push_array($arr, ($n & 0xff));
			$n >>= 8;
		} while ($n > 0);
		return rtrim(strtr(base64_encode($arr), '+/', '-_'), '=');
	}

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

	public static function json_encode($o) {
		return json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	public static function json_decode($o) {
		return json_decode($o);
	}

}
