<?php

/**
 * These helpers pertain to HTML and data attributes of a node.
 * @module
 */

// Port based on git-commit: <423eb7f04eea94b69da1cefe7bf0b27385781371>
// Initial porting, partially complete
// Not tested, all code that is not ported has assert or PORT-FIXME

//const semver = require('semver');
//const { DOMUtils } = require('./DOMUtils.js');
//const { JSUtils } = require('./jsutils.js');

namespace Parsoid\Lib\Utils;

require_once __DIR__."/../config/WikitextConstants.php";
require_once __DIR__."/DOMUtils.php";
require_once __DIR__."/phputils.php";

use Parsoid\Lib\Config\WikitextConstants;
use Parsoid\Lib\Utils\DOMUtils;
use Parsoid\Lib\PHPUtils\PHPUtils;


class DOMDataUtils {
	// The following getters and setters load from the .dataobject store,
	// with the intention of eventually moving them off the nodes themselves.

	public static function getNodeData($node) {
		if (!$node->dataobject) {
			$node->dataobject = PHPUtils::object();
		}
		return $node->dataobject;
	}

	public static function getDataParsoid($node) {
		$data = self::getNodeData($node);
		if (!$data->parsoid) {
			$data->parsoid = PHPUtils::object();
		}
		if (!$data->parsoid->tmp) {
			$data->parsoid->tmp = PHPUtil::object();
		}
		return $data->parsoid;
	}

	public static function getDataMw($node) {
		$data = self::getNodeData($node);
		if (!$data->mw) {
			$data->mw = PHPUtils::object();
		}
		return $data->mw;
	}

	public static function validDataMw($node) {
		return !!$Object->keys(self::getDataMw($node))->length;
	}

	public static function setDataParsoid($node, $dpObj) {
		$data = self::getNodeData($node);
		$data->parsoid = $dpObj;
		return $data->parsoid;
	}

	public static function setDataMw($node, $dmObj) {
		$data = self::getNodeData($node);
		$data->mw = $dmObj;
		return $data->mw;
	}

	public static function setNodeData($node, $data) {
		$node->dataobject = $data;
	}

	/**
	 * Get an object from a JSON-encoded XML attribute on a node.
	 *
	 * @param {Node} node
	 * @param {string} name Name of the attribute
	 * @param {any} defaultVal What should be returned if we fail to find a valid JSON structure
	 */
	public static function getJSONAttribute($node, $name, $defaultVal) {
		if (!DOMUtils::isElt($node)) {
			return $defaultVal;
		}
		$attVal = $node->getAttribute($name);
		if (!$attVal) {
			return $defaultVal;
		}
		try {
			// return JSON->parse($attVal);
			return PHPUtils::json_decode($attVal);
		} catch ($e) {
			$console->warn('ERROR: Could not decode attribute-val ' . $attVal .
				' for ' . $name . ' on node ' . $node->outerHTML);
			return $defaultVal;
		}
	}

	/**
	 * Set an attribute on a node to a JSON-encoded object.
	 *
	 * @param {Node} node
	 * @param {string} name Name of the attribute.
	 * @param {Object} obj
	 */
	public static function setJSONAttribute($node, $name, $obj) {
		// node.setAttribute(name, JSON.stringify(obj));
		$node->setAttribute($name, PHPUtils::json_encode($obj));
	}

	// Similar to the method on tokens
	public static function setShadowInfo($node, $name, $val, $origVal) {
		if ($val === $origVal || $origVal === null) { return; }
		$dp = self::getDataParsoid($node);
		if (!$dp->a) { $dp->a = PHPUtils::object(); }
		if (!$dp->sa) { $dp->sa = PHPUtils::object(); }
		if (isset($origVal) &&
			// FIXME: This is a hack to not overwrite already shadowed info.
			// We should either fix the call site that depends on this
			// behaviour to do an explicit check, or double down on this
			// by porting it to the token method as well.
			!$dp->a->hasOwnProperty($name)) {
			$dp->sa[$name] = $origVal;
		}
		$dp->a[$name] = $val;
	}

	public static function addAttributes($elt, $attrs) {
/*		Object.keys(attrs).forEach(function(k) {
			if (attrs[k] !== null && attrs[k] !== undefined) {
				elt.setAttribute(k, attrs[k]);
			}
		}); */
		foreach($attrs as $key => $key_value) {
			if ($key !== null && isset($key_value) {
				$elt->setAttribute($key, $key_value);
			}
		}
	}

	// Similar to the method on tokens
	public static function addNormalizedAttribute($node, $name, $val, $origVal) {
		$node->setAttribute($name, $val);
		self::setShadowInfo($node, $name, $val, $origVal);
	}

	/**
	 * Test if a node matches a given typeof.
	 */
	public static function hasTypeOf($node, $type) {
		if (!$node->getAttribute) {
			return false;
		}
		$typeOfs = $node->getAttribute('typeof');
		if (!$typeOfs) {
			return false;
		}
		// return typeOfs->split(' ').indexOf(type) !== -1;
		$types = explode(' ', $typeOfs);
		return (array_search($type, $types) !== FALSE);
	}

	/**
	 * Add a type to the typeof attribute. This method works for both tokens
	 * and DOM nodes as it only relies on getAttribute and setAttribute, which
	 * are defined for both.
	 */
	public static function addTypeOf($node, $type) {
		$typeOf = $node->getAttribute('typeof');
		if ($typeOf) {
		//  types = typeOf.split(' ');
			$types = explode(' ', $typeOf);
		//  if (types.indexOf(type) === -1) {
			if (array_search($type, $types) !== FALSE) {
				// not in type set yet, so add it.
			//	$types.push($type);
				array_push($types, $type);
			}
			$node->setAttribute('typeof', join(' ', $types));
		} else {
			$node->setAttribute('typeof', $type);
		}
	}

	/**
	 * Remove a type from the typeof attribute. This method works on both
	 * tokens and DOM nodes as it only relies on
	 * getAttribute/setAttribute/removeAttribute.
	 */
	public static function removeTypeOf($node, $type) {
		$typeOf = $node->getAttribute('typeof');
	//	function notType(t) {
	//		return t !== type;
	//	}
		$notType = function($t) use($type) {
			return $t !== $type;
		};
		if ($typeOf) {
		//	types = typeOf.split(' ').filter(notType);
			$types = array_filter(explode(' ', $typeOf), "notType");

			if ($types->length) {
				$node->setAttribute('typeof', join(' ', $types));
			} else {
				$node->removeAttribute('typeof');
			}
		}
	}

	/**
	 * Removes the `data-*` attribute from a node, and migrates the data to the
	 * document's JSON store. Generates a unique id with the following format:
	 * ```
	 * mw<base64-encoded counter>
	 * ```
	 * but attempts to keep user defined ids.
	 */
	public static function storeInPageBundle($node, $env, $data) {
		$uid = $node->getAttribute('id');
		$document = $node->ownerDocument;
		$pb = self::getDataParsoid($document)->pagebundle;
		$docDp = $pb->parsoid;
		$origId = $uid || null;
		if ($docDp->ids->hasOwnProperty($uid)) {
			$uid = null;
			// FIXME: Protect mw ids while tokenizing to avoid false positives.
			$env->log('info', 'Wikitext for this page has duplicate ids: ' . $origId);
		}
		if (!$uid) {
			do {
				$docDp->counter += 1;
				$uid = 'mw' . PHPUtils::counterToBase64($docDp->counter);
			} while ($document->getElementById($uid));
			self::addNormalizedAttribute($node, 'id', $uid, $origId);
		}
		$docDp->ids[$uid] = $data->parsoid;
		if ($data->hasOwnProperty('mw')) {
			$pb->mw->ids[$uid] = $data->mw;
		}
	}

	/**
	 * @param {Document} doc
	 * @param {Object} obj
	 */
	public static function injectPageBundle($doc, $obj) {
		// $pb = JSON->stringify($obj);
		$pb = PHPUtils::json_encode($obj);
		$script = $doc->createElement('script');
		self::addAttributes($script, array(
			'id'=>'mw-pagebundle',
			'type'=>'application/x-mw-pagebundle'
		));
		$script->appendChild($doc->createTextNode($pb));
		$doc->head->appendChild($script);
	}

	/**
	 * @param {Document} doc
	 * @return {Object|null}
	 */
	public static function extractPageBundle(doc) {
		$pb = null;
		$dpScriptElt = $doc->getElementById('mw-pagebundle');
		if ($dpScriptElt) {
			$dpScriptElt->parentNode->removeChild($dpScriptElt);
		//	pb = JSON.parse(dpScriptElt.text);
			$pb = PHPUtils::json_decode($dpScriptElt->text);
		}
		return $pb;
	}

	/**
	 * Applies the `data-*` attributes JSON structure to the document.
	 * Leaves `id` attributes behind -- they are used by citation
	 * code to extract `<ref>` body from the DOM.
	 */
	public static function applyPageBundle($doc, $pb) {
		$console->assert(false, "Not yet ported");
/*		DOMUtils::visitDOM($doc->body, ($node) => {
			if (DOMUtils::isElt($node)) {
				$id = $node->getAttribute('id');
				if ($pb->parsoid->ids->hasOwnProperty($id)) {
					self::setJSONAttribute($node, 'data-parsoid', $pb->parsoid->ids[$id]);
				}
				if ($pb->mw && $pb->mw->ids->hasOwnProperty($id)) {
				// Only apply if it isn't already set.  This means earlier
				// applications of the pagebundle have higher precedence,
				// inline data being the highest.
					if ($node->getAttribute('data-mw') === null) {
						self::setJSONAttribute($node, 'data-mw', $pb->mw->ids[$id]);
					}
				}
			}
		}); */
	}

	public static function visitAndLoadDataAttribs($node, $markNew) {
	//	DOMUtils.visitDOM(node, (...args) => this.loadDataAttribs(...args), markNew);
//	PORT-FIXME	the passing of functin loadDataAttribs in PHP may not be correct
		DOMUtils::visitDOM($node, 'DOMDataUtils::loadDataAttribs', $markNew);
	}

	// These are intended be used on a document after post-processing, so that
	// the underlying .dataobject is transparently applied (in the store case)
	// and reloaded (in the load case), rather than worrying about keeping
	// the attributes up-to-date throughout that phase.  For the most part,
	// using this.ppTo* should be sufficient and using these directly should be
	// avoided.

	public static function loadDataAttribs($node, $markNew) {
		if (!DOMUtils::isElt($node)) {
			return;
		}
		$dp = self::getJSONAttribute($node, 'data-parsoid', PHPUtils::object());
		if ($markNew) {
			if (!$dp->tmp) { $dp->tmp = object(); }
			$dp->tmp->isNew = ($node->getAttribute('data-parsoid') === null);
		}
		self::setDataParsoid($node, $dp);
		$node->removeAttribute('data-parsoid');
		self::setDataMw($node, self::getJSONAttribute($node, 'data-mw', undefined));
		$node->removeAttribute('data-mw');
	}

	public static function visitAndStoreDataAttribs($node, $options) {
		$console->assert(false, "Not yet ported");
	//	DOMUtils::visitDOM($node, (...$args) => self::storeDataAttribs(...$args), $options);
	}

	/**
	 * @param {Node} node
	 * @param {Object} [options]
	 */
	public static function storeDataAttribs($node, $options) {
		$console->assert(false, "Not yet fully ported");
		if (!DOMUtils::isElt($node)) { return; }
		$options = $options || PHPUtils::object();
		$console->assert(!($options->discardDataParsoid && $options->keepTmp));  // Just a sanity check
		$dp = self::getDataParsoid($node);
		// Don't modify `options`, they're reused.
		$discardDataParsoid = $options->discardDataParsoid;
		if ($dp->tmp->isNew) {
			// Only necessary to support the cite extension's getById,
			// that's already been loaded once.
			//
			// This is basically a hack to ensure that DOMUtils.isNewElt
			// continues to work since we effectively rely on the absence
			// of data-parsoid to identify new elements. But, loadDataAttribs
			// creates an empty {} if one doesn't exist. So, this hack
			// ensures that a loadDataAttribs + storeDataAttribs pair don't
			// dirty the node by introducing an empty data-parsoid attribute
			// where one didn't exist before.
			//
			// Ideally, we'll find a better solution for this edge case later.
			$discardDataParsoid = true;
		}
		$data = null;
		if (!$discardDataParsoid) {
			// WARNING: keeping tmp might be a bad idea.  It can have DOM
			// nodes, which aren't going to serialize well.  You better know
			// of what you do.
		//	if (!$options->keepTmp) { $dp->tmp = undefined; }
			if (!$options->keepTmp) { $dp->tmp = null; }
			if ($options->storeInPageBundle) {
				$data = $data || PHPUtils::object();
				$data->parsoid = $dp;
			} else {
				self::setJSONAttribute($node, 'data-parsoid', $dp);
			}
		}
		// Strip invalid data-mw attributes
		if (self::validDataMw($node)) {
			if ($options->storeInPageBundle && $options->env &&
				// The pagebundle didn't have data-mw before 999.x
// PORT-FIXME - semver equivalent code required
				$semver->satisfies($options->env->outputContentVersion, '^999.0.0')) {
				$data = $data || PHPUtils::object();
				$data->mw = self::getDataMw($node);
			} else {
				self::setJSONAttribute($node, 'data-mw', self::getDataMw($node));
			}
		}
		// Store pagebundle
		if ($data !== null) {
			self::storeInPageBundle($node, $options->env, $data);
		}
	}
}
