<?php

namespace Parsoid\Lib\Utils;

class Util {
	private static $WtTagsWithLimitedTSR;

	public static function init() {
	}

	public static function lookupKV( $kvs, $key ) {
		if (!isset($kvs)) {
			return null;
		}
		for ($i = 0, $l = sizeof($kvs); $i < $l; $i++) {
			$kv = $kvs[$i];
			#var_dump($kv);
			if (gettype($kv->k) == "string" && trim($kv->k) === $key) {
				// found, return it.
				return $kv;
			}
		}
		// nothing found!
		return null;
	}

	public static function lookup( $kvs, $key ) {
		$kv = self::lookupKV( $kvs, $key );
		return $kv === null ? null : $kv->v;
	}
}
