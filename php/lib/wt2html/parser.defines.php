<?php

namespace Parsoid\Lib\Wt2html;

require_once (__DIR__.'/../utils/Utils.php');

use Parsoid\Lib\Utils\Util;

use ArrayObject;

/**
 * @class
 *
 * Key-value pair.
 */
class KV {
	/**
	 * @param {any} k
	 * @param {any} v
	 * @param {Array} srcOffsets The source offsets.
	 */
	public function __construct($k, $v, $srcOffsets = null) {
		/** Key. */
		$this->k = $k;
		/** Value. */
		$this->v = $v;
		if ($srcOffsets) {
			/** The source offsets. */
			$this->srcOffsets = $srcOffsets;
		}
	}
}

/**
 * Catch-all class for all token types.
 * @abstract
 * @class
 */
class Token {
	public function getType() {
		return $this->type;
	}

	/**
	 * Generic set attribute method.
	 *
	 * @param {string} name
	 * @param {any} value
	 */
	public function addAttribute($name, $value) {
		$this->attribs[] = new KV($name, $value, null);
	}

	/**
	 * Generic set attribute method with support for change detection.
	 * Set a value and preserve the original wikitext that produced it.
	 *
	 * @param {string} name
	 * @param {any} value
	 * @param {any} origValue
	 */
	public function addNormalizedAttribute($name, $value, $origValue) {
		$this->addAttribute($name, $value);
		$this->setShadowInfo($name, $value, $origValue);
	}

	/**
	 * Generic attribute accessor.
	 *
	 * @param {string} name
	 * @return {any}
	 */
	public function getAttribute($name) {
		// requireUtil();
		return Util::lookup($this->attribs, $name);
	}

	/**
	 * Set an unshadowed attribute.
	 *
	 * @param {string} name
	 * @param {any} value
	 */
	public function setAttribute($name, $value) {
		// requireUtil();
		// First look for the attribute and change the last match if found.
		for ($i = sizeof($this->attribs) - 1; $i >= 0; $i--) {
			$kv = $this->attribs[$i];
			$k = $kv->k;
			if (gettype($k) == "string" && $k->toLowerCase() === $name) {
				$kv->v = $value;
				$this->attribs[$i] = $kv;
				return;
			}
		}
		// Nothing found, just add the attribute
		$this->addAttribute($name, $value);
	}

	/**
	 * Store the original value of an attribute in a token's dataAttribs.
	 *
	 * @param {string} name
	 * @param {any} value
	 * @param {any} origValue
	 */
	public function setShadowInfo($name, $value, $origValue) {
		// Don't shadow if value is the same or the orig is null
		if ($value !== $origValue && $origValue !== null) {
			if (!$this->dataAttribs->a) {
				$this->dataAttribs->a = [];
            }
			$this->dataAttribs->a[$name] = $value;
			if (!$this->dataAttribs->sa) {
				$this->dataAttribs->sa = [];
            }
			if ($origValue !== $undefined) {
				$this->dataAttribs->sa[$name] = $origValue;
			}
		}
	}

	/**
	 * Attribute info accessor for the wikitext serializer. Performs change
	 * detection and uses unnormalized attribute values if set. Expects the
	 * context to be set to a token.
	 *
	 * @param {string} name
	 * @return {Object} Information about the shadow info attached to this attribute.
	 * @return {any} return.value
	 * @return {boolean} return.modified Whether the attribute was changed between parsing and now.
	 * @return {boolean} return.fromsrc Whether we needed to get the source of the attribute to round-trip it.
	 */
	public function getAttributeShadowInfo($name) {
		// requireUtil();
		$curVal = $Util->lookup($this->attribs, $name);

		// Not the case, continue regular round-trip information.
		if ($this->dataAttribs->a === undefined ||
			$this->dataAttribs->a[name] === undefined) {
			return [
				"value"=>curVal,
                // Mark as modified if a new element
                "modified"=>$Object->keys($this->dataAttribs)->length === 0,
                "fromsrc"=>false
            ];
        } else if ($this->dataAttribs->a[$name] !== $curVal) {
			return [
				"value"=>$curVal,
                "modified"=>true,
                "fromsrc"=>false
            ];
        } else if ($this->dataAttribs->sa === undefined ||
			$this->dataAttribs->sa[$name] === undefined) {
			return [
				"value"=>$curVal,
                "modified"=>false,
                "fromsrc"=>false
            ];
        } else {
			return [
				"value"=>$this->dataAttribs->sa[$name],
                "modified"=>false,
                "fromsrc"=>true
            ];
        }
	}

	/**
	 * Completely remove all attributes with this name.
	 *
	 * @param {string} name
	 */
	public function removeAttribute($name) {
		$out = [];
		$attribs = $this->attribs;
		for ($i = 0, $l = $attribs->length; $i < $l; $i++) {
			$kv = attribs[$i];
			if ($kv->k->toLowerCase() !== $name) {
				$out->push($kv);
            }
        }
        $this->attribs = $out;
    }

	/**
	 * Add a space-separated property value.
	 *
	 * @param {string} name
	 * @param {any} value The value to add to the attribute.
	 */
	public function addSpaceSeparatedAttribute($name, $value) {
		// requireUtil();
		$curVal = $Util->lookupKV($this->attribs, $name);
		// vals;
		if ($curVal !== null) {
			$vals = peg_split("/[\s]+/", $curVal->v);  // was:  vals = curVal->v->split(/\s+/);
			for ($i = 0, $l = $vals->length; $i < $l; $i++) {
				if ($vals[$i] === $value) {
					// value is already included, nothing to do.
					return;
				}
			}
			// Value was not yet included in the existing attribute, just add
			// it separated with a space
			$this->setAttribute($curVal->k, $curVal->v + ' ' + $value);
		} else {
			// the attribute did not exist at all, just add it
			$this->addAttribute($name, $value);
		}
	}

	/**
	 * Get the wikitext source of a token.
	 *
	 * @param {MWParserEnvironment} env
	 * @return {string}
	 */
	public function getWTSource($env) {
		$tsr = $this->dataAttribs->tsr;
		$console->assert(is_array($tsr), 'Expected token to have tsr info.');
		return substring($env->page->src, $tsr[0], $tsr[1]);
	}
}

/**
 * HTML tag token.
 * @class
 * @extends ~Token
 */
class TagTk extends Token {
	/**
	 * @param {string} name
	 * @param {KV[]} attribs
	 * @param {Object} dataAttribs Data-parsoid object.
	 */
	public function __construct($name, $attribs = [], $dataAttribs = []) {
		$this->type = "TagTk";
		/** @type {string} */
		$this->name = $name;
		/** @type {KV[]} */
		$this->attribs = $attribs;
		/** @type {Object} */
		$this->dataAttribs = $dataAttribs;
    }
}

/**
 * HTML end tag token.
 * @class
 * @extends ~Token
 */
class EndTagTk extends Token {

	/*
	* @param {string} name
	* @param {KV[]} attribs
	* @param {Object} dataAttribs
	*/
	public function __construct($name, $attribs = [], $dataAttribs = []) {
		$this->type = "EndTagTk";
			/** @type {string} */
		$this->name = $name;
			/** @type {KV[]} */
		$this->attribs = $attribs;
			/** @type {Object} */
		$this->dataAttribs = $dataAttribs;
	}
}

/**
 * HTML tag token for a self-closing tag (like a br or hr).
 * @class
 * @extends ~Token
 */
class SelfclosingTagTk extends Token {
	/**
	 * @param {string} name
	 * @param {KV[]} attribs
	 * @param {Object} dataAttribs
	 */
	public function __construct($name, $attribs = [], $dataAttribs = []) {
		$this->type = "SelfclosingTagTk";
			/** @type {string} */
		$this->name = $name;
			/** @type {KV[]} */
		$this->attribs = $attribs;
			/** @type {Object} */
		$this->dataAttribs = $dataAttribs;
	}
}

/**
 * Newline token.
 * @class
 * @extends ~Token
 */
class NlTk extends Token {
	/**
	 * @param {Array} tsr The TSR of the newline(s).
	 */
	public function __construct($tsr) {
		$this->name = "";
		$this->type = "NlTk";
		if (isset($tsr)) {
			/** @type {Object} */
			$this->dataAttribs = ["tsr"=>$tsr];
		}
	}
}

/**
 * @class
 * @extends ~Token
 */
class CommentTk extends Token {
	/**
	 * @param {string} value
	 * @param {Object} dataAttribs data-parsoid object.
	 */
	public function __construct($value, $dataAttribs) {
		$this->name = "";
		$this->type = "CommentTk";
		/** @type {string} */
		$this->value = $value;
		// won't survive in the DOM, but still useful for token serialization
		if (isset($dataAttribs)) {
			/** @type {Object} */
			$this->dataAttribs = $dataAttribs;
		}
	}
}

	/* -------------------- EOFTk -------------------- */
class EOFTk extends Token {
	public function __construct() {
		$this->name = "";
		$this->type = "EOFTk";
	}
}


/* -------------------- Params -------------------- */
/**
 * A parameter object wrapper, essentially an array of key/value pairs with a
 * few extra methods.
 *
 * @class
 * @extends Array
 */
class Params extends ArrayObject {
	public function __construct($params){
		super($params->length);
      for ($i = 0; $i < $params->length; $i++) {
			$this[$i] = $params[$i];
		}
		$this->argDict = null;
		$this->namedArgsDict = null;
	}

	public function dict() {
		// requireUtil();
		if ($this->argDict === null) {
			$res = [];
			for ($i = 0, $l = $this->length; $i < $l; $i++) {
				$kv = $this[$i];
				$key = $Util->tokensToString($kv->k)->trim();
				$res[$key] = $kv->v;
			}
			$this->argDict = $res;
		}
		return $this->argDict;
	}

	public function named() {
		// requireUtil();
		if ($this->namedArgsDict === null) {
			$n = 1;
			$out = [];
			$namedArgs = [];

			for ($vi = 0, $l = $this->length; $i < $l; $i++) {
				// FIXME: Also check for whitespace-only named args!
				$k = this[$i]->k;
				$v = this[$i]->v;
				if (gettype($k) == "string") {
					$k = $k->trim();
				}
				if (!$k->length &&
					// Check for blank named parameters
					$this[$i]->srcOffsets[1] === $this[$i]->srcOffsets[2]) {
					$out[$n->toString()] = $v;
					$n++;
				} else if (gettype($k) == "string") {
					$namedArgs[$k] = true;
					$out[$k] = $v;
				} else {
					$k = $Util->tokensToString($k)->trim();
					$namedArgs[$k] = true;
					$out[$k] = $v;
				}
			}
			$this->namedArgsDict = ["namedArgs"=>$namedArgs, "dict"=>$out];
		}

		return $this->namedArgsDict;
	}

	/**
	 * Expand a slice of the parameters using the supplied get options.
	 * @return {Promise}
	 */
/* STB not sure how to port this due to promises and async stuff
	public function getSlice($options, $start, $end) {
		// requireUtil();
		$args = $this->slice($start, $end);
		return $Promise->all($args->map($Promise->async(function *($kv){ // eslint-disable-line require-yield
			$k = $kv->k;
			$v = $kv->v;
			if ($Array->isArray($v) && $v->length === 1 && gettype($v[0]) == "string") {
				// remove String from Array
				$kv = new KV($k, $v[0], $kv->srcOffsets);
			} else if (gettype($v) == "string") {
				$kv = new KV($k, $Util->tokensToString($v), $kv->srcOffsets);
			}
			return $kv;
		})));
	} */
}

?>
