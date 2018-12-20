<?php

namespace Parsoid\Lib\Utils;

class Util {
	private static $WtTagsWithLimitedTSR;

	public static function init() { }

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

	public static function getType($token) {
		return gettype($token) == 'string' ? 'String' : $token->getType();
	}

	public static function lookup( $kvs, $key ) {
		$kv = self::lookupKV( $kvs, $key );
		return $kv === null ? null : $kv->v;
	}

	public static function isBehaviorSwitch( $env, $token ) {
		// FIXME: STUB!
		return false;
	}

	public static function isEmptyLineMetaToken( $token ) {
		$tt = Util::getType($token);
		return $tt === "SelfclosingTagTk" &&
			$token->name === "meta" &&
			$token->getAttribute("typeof") === "mw:EmptyLine";
	}

	private static $solTransparentLinkRegexp = "/(?:^|\s)mw:PageProp\/(?:Category|redirect|Language)(?=$|\s)/";

	public static function isSolTransparentLinkTag( $token ) {
		$tc = Util::getType($token);
		return ($tc === 'SelfclosingTagTk' || $tc === 'TagTk' || $tc === 'EndTagTk') &&
			$token->name === 'link' &&
			preg_match(self::$solTransparentLinkRegexp, $token->getAttribute('rel'));
	}

	/**
	 * This should come close to matching
	 * {@link DOMUtils.emitsSolTransparentSingleLineWT},
	 * without the single line caveat.
	 */
	public static function isSolTransparent( $env, $token ) {
		$tc = Util::getType($token);
		if ($tc === "String") {
			return preg_match('/^\s*$/', $token);
		} else if (Util::isSolTransparentLinkTag($token)) {
			return true;
		} else if ($tc === 'CommentTk') {
			return true;
		} else if (Util::isBehaviorSwitch($env, $token)) {
			return true;
		} else if ($tc !== 'SelfclosingTagTk' || $token->name !== 'meta') {
			return false;
		} else {  // only metas left
			return ! (isset($token->dataAttribs->stx) && $token->dataAttribs->stx === 'html');
		}
	}

	/**
	 * Determine whether the current token was an HTML tag in wikitext.
	 *
	 * @return {boolean}
	 */
	public static function isHTMLTag( $token ) {
		global $console;
		switch ( Util::getType($token) ) {
			case "String":
			case "NlTk":
			case "CommentTk":
			case "EOFTk":
				return false;
			case "TagTk":
			case "EndTagTk":
			case "SelfclosingTagTk":
				if (isset($token->dataAttribs->stx))
					return $token->dataAttribs->stx === 'html';
				else return false;
			default:
				$console->assert(false, 'Unhandled token type');
		}
	}

	public static function makeSet( $a ) {
		$set = [];
		foreach ( $a as $e ) {
			$set[$e] = true;
		}

		return $set;
	}

	public static function makeMap( $a ) {
		$map = [];
		foreach ( $a as $e ) {
			$map[$e[0]] = $e[1];
		}

		return $map;
	}
}

?>
