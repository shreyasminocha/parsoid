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

	public static function isEmptyLineMetaToken( $token ) {
		return $token->getType() === "SelfclosingTagTk" &&
			$token->name === "meta" &&
			$token->getAttribute("typeof") === "mw:EmptyLine";
	}

	public function isSolTransparentLinkTag( $token ) {
		$tc = $token->getType();
		return ($tc === $pd->SelfclosingTagTk || $tc === $pd->TagTk || $tc === $pd->EndTagTk) &&
			$token->name === 'link' &&
			$this->solTransparentLinkRegexp->test($token->getAttribute('rel'));
	}

	/**
	 * This should come close to matching
	 * {@link DOMUtils.emitsSolTransparentSingleLineWT},
	 * without the single line caveat.
	 */
	public function isSolTransparent( $env, $token ) {
		$tc = $token->getType();
		if ($tc === "String") {
			return preg_match('/^\s*$/', $token);
			} else if ($this->isSolTransparentLinkTag($token)) {
			return true;
		} else if ($tc === $pd->CommentTk) {
			return true;
		} else if ($this->isBehaviorSwitch($env, $token)) {
			return true;
		} else if ($tc !== $pd->SelfclosingTagTk || $token->name !== 'meta') {
			return false;
		} else {  // only metas left
			return $token->dataAttribs->stx !== 'html';
		}
	}

}
