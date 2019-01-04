<?php
/**
* DOM utilities for querying the DOM. This is largely independent of Parsoid
* although some Parsoid details (diff markers, TokenUtils, inline content version)
* have snuck in. Trying to prevent that is probably not worth the effort yet
* at this stage of refactoring.
*/

namespace Parsoid\Lib\Utils;

require_once __DIR__."/../config/WikitextConstants.php";
require_once __DIR__."/phputils.php";
require_once __DIR__."/TokenUtils.php";

use Parsoid\Lib\PHPUtils\PHPUtil;
use Parsoid\Lib\Config\WikitextConstants;
use Parsoid\Lib\Utils\TokenUtils;

class DU
{
	const TPL_META_TYPE_REGEXP = '/(?:^|\s)(mw:(?:Transclusion|Param)(?:\/End)?)(?=$|\s)/';
	const FIRST_ENCAP_REGEXP = '/(?:^|\s)(mw:(?:Transclusion|Param|LanguageVariant|Extension(\/[^\s]+)))(?=$|\s)/';

	// For an explanation of what TSR is, see dom.computeDSR.js
	//
	// TSR info on all these tags are only valid for the opening tag.
	// (closing tags dont have attrs since tree-builder strips them
	//  and adds meta-tags tracking the corresponding TSR)
	//
	// On other tags, a, hr, br, meta-marker tags, the tsr spans
	// the entire DOM, not just the tag.
	//
	// This code is not in mediawiki.wikitext.constants.js because this
	// information is Parsoid-implementation-specific.
	private static $WtTagsWithLimitedTSR;

	public static function init() {
		self::$WtTagsWithLimitedTSR = array(
			"b"  =>      true,
			"i"  =>      true,
			"h1" =>      true,
			"h2" =>      true,
			"h3" =>      true,
			"h4" =>      true,
			"h5" =>      true,
			"ul" =>      true,
			"ol" =>      true,
			"dl" =>      true,
			"li" =>      true,
			"dt" =>      true,
			"dd" =>      true,
			"table" =>   true,
			"caption" => true,
			"tr" =>      true,
			"td" =>      true,
			"th" =>      true,
			"hr" =>      true, // void element
			"br" =>      true, // void element
			"pre" =>     true,
		);
	}

	/**
	 * Parse HTML, return the tree.
	 *
	 * @param {string} html
	 * @return {Node}
	 */
	public static function parseHTML($html) {
/*		if (!html.match(/^<(?:!doctype|html|body)/i)) {
			// Make sure that we parse fragments in the body. Otherwise comments,
			// link and meta tags end up outside the html element or in the head
			// element.
			html = '<body>' + html;
		}
		return domino.createDocument(html); */
	}

	/**
	 * This is a simplified version of the DOMTraverser.
	 * Consider using that before making this more complex.
	 *
	 * FIXME: Move to DOMTraverser OR create a new class?
	 */
	public static function visitDOM($node, $handler, ...$args) {
/*		handler(node, ...args);
		node = node.firstChild;
		while (node) {
			this.visitDOM(node, handler, ...args);
			node = node.nextSibling;
		} */
	}

	/**
	 * Move 'from'.childNodes to 'to' adding them before 'beforeNode'
	 * If 'beforeNode' is null, the nodes are appended at the end.
	 */
	public static function migrateChildren($from, $to, $beforeNode = null) {
		while ( $from->firstChild ) {
			$to->insertBefore($from->firstChild, $beforeNode);
		}
	}

	/**
	 * Move 'from'.childNodes to 'to' adding them before 'beforeNode'
	 * 'from' and 'to' belong to different documents.
	 *
	 * If 'beforeNode' is null, the nodes are appended at the end.
	 */
	public static function migrateChildrenBetweenDocs($from, $to, $beforeNode) {
/*		if (beforeNode === undefined) {
			beforeNode = null;
		}
		var n = from.firstChild;
		var destDoc = to.ownerDocument;
		while (n) {
			to.insertBefore(destDoc.importNode(n, true), beforeNode);
			n = n.nextSibling;
		} */
	}

/**
	 * Check whether this is a DOM element node.
	 * @see http://dom.spec.whatwg.org/#dom-node-nodetype
	 * @param {Node} node
	 */
	public static function isElt($node) {
		return $node && $node->nodeType === 1;
	}

	/**
	 * Check whether this is a DOM text node.
	 * @see http://dom.spec.whatwg.org/#dom-node-nodetype
	 * @param {Node} node
	 */
	public static function isText($node) {
		return $node && $node->nodeType === 3;
	}

	/**
	 * Check whether this is a DOM comment node.
	 * @see http://dom.spec.whatwg.org/#dom-node-nodetype
	 * @param {Node} node
	 */
	public static function isComment($node) {
		return $node && $node->nodeType === 8;
	}

	/**
	 * Determine whether this is a block-level DOM element.
	 * @see TokenUtils.isBlockTag
	 * @param {Node} node
	 */
	public static function isBlockNode($node) {
		return $node && TokenUtils::isBlockTag($node->nodeName);
	}

	public static function isFormattingElt($node) {
		return $node && isset( WikitextConstants::$HTML['FormattingTags'][ $node->nodeName ] );
	}

	public static function isQuoteElt($node) {
		return $node && isset( WikitextConstants::$WTQuoteTags[$node->nodeName] );
	}

	public static function isBody($node) {
		return $node && $node->nodeName === 'body';
	}

	/**
	 * Test the number of children this node has without using
	 * `Node#childNodes.length`.  This walks the sibling list and so
	 * takes O(`nchildren`) time -- so `nchildren` is expected to be small
	 * (say: 0, 1, or 2).
	 *
	 * Skips all diff markers by default.
	 */
	public static function hasNChildren($node, $nchildren, $countDiffMarkers) {
/*		for (var child = node.firstChild; child; child = child.nextSibling) {
		if (!countDiffMarkers && this.isDiffMarker(child)) {
			continue;
		}
		if (nchildren <= 0) { return false; }
			nchildren -= 1;
		}
		return (nchildren === 0); */
		for ($child = $node->firstChild; $child; $child = $child->nextSibling) {
			if (!$countDiffMarkers && self::isDiffMarker($child)) {
				continue;
			}
			if ($nchildren <= 0) { return false; }
				$nchildren -= 1;
		}
		return ($nchildren === 0);
	}

	/**
	 * Build path from a node to its passed-in ancestor.
	 * Doesn't include the ancestor in the returned path.
	 *
	 * @param {Node} node
	 * @param {Node} ancestor Should be an ancestor of `node`.
	 * @return {Node[]}
	 */
	public static function pathToAncestor($node, $ancestor) {
		$path = [];
		while ($node && $node !== $ancestor) {
			$path[] = $node;
			$node = $node->parentNode;
		}
		return $path;
	}

	/**
	 * Build path from a node to the root of the document.
	 *
	 * @return {Node[]}
	 */
	public static function pathToRoot($node) {
		return self::pathToAncestor($node, null);
	}

	/**
	 * Build path from a node to its passed-in sibling.
	 *
	 * @param {Node} node
	 * @param {Node} sibling
	 * @param {boolean} left Whether to go backwards, i.e., use previousSibling instead of nextSibling.
	 * @return {Node[]} Will not include the passed-in sibling.
	 */
	public static function pathToSibling( $node, $sibling, $left) {
/*		var path = [];
		while (node && node !== sibling) {
			path.push(node);
			node = left ? node.previousSibling : node.nextSibling;
		}
		return path; */
		$path = [];
		while ($node && $node !== $sibling) {
			array_push($path, $node);
			$node = $left ? $node->previousSibling : $node->nextSibling;
		}
		return $path;
	}

	/**
	 * Check whether a node `n1` comes before another node `n2` in
	 * their parent's children list.
	 *
	 * @param {Node} n1 The node you expect to come first.
	 * @param {Node} n2 Expected later sibling.
	 */
	public static function inSiblingOrder($n1, $n2) {
/*		while (n1 && n1 !== n2) {
			n1 = n1.nextSibling;
		}
		return n1 !== null; */
		while ($n1 && $n1 !== $n2) {
			$n1 = $n1->nextSibling;
		}
		return !isnull($n1);
	}

	/**
	 * Check that a node 'n1' is an ancestor of another node 'n2' in
	 * the DOM. Returns true if n1 === n2.
	 *
	 * @param {Node} n1 The suspected ancestor.
	 * @param {Node} n2 The suspected descendant.
	 */
	public static function isAncestorOf($n1, $n2) {
/*		while (n2 && n2 !== n1) {
			n2 = n2.parentNode;
		}
		return n2 !== null; */
		while ($n2 && $n2 !== $n1) {
			$n2 = $n2->parentNode;
		}
		return !isnull($n2);
	}

	/**
	 * Check whether `node` has an ancesor named `name`.
	 *
	 * @param {Node} node
	 * @param {string} name
	 */
	public static function hasAncestorOfName($node, $name) {
/*		while (node && node.nodeName !== name) {
			node = node.parentNode;
		}
		return node !== null; */
		while ($node && $node->nodeName !== $name) {
			$node = $node->parentNode;
		}
		return !isnull($node);
	}

	/**
	 * Determine whether the node matches the given nodeName and typeof
	 * attribute value.
	 *
	 * @param {Node} n
	 * @param {string} name Passed into #hasNodeName
	 * @param {string} type Expected value of "typeof" attribute
	 */
	public static function isNodeOfType($n, $name, $type) {
		return $n->nodeName === $name && $n->getAttribute("typeof") === $type;
	}

	public static function isFosterablePosition($n) {
		return $n && isset(WikitextConstants::$HTML['FosterablePosition'][$n->parentNode->nodeName]);
	}

	public static function isList($n) {
		return $n && isset( WikitextConstants::$ListTags[$n->nodeName] );
	}

	public static function isListItem($n) {
/*		return n && Consts.HTML.ListItemTags.has(n.nodeName); */
	}

	public static function isListOrListItem($n) {
/*		return this.isList(n) || this.isListItem(n); */
		return self::isList($n) || self::isListItem($n);
	}

	public static function isNestedInListItem($n) {
/*		var parentNode = n.parentNode;
		while (parentNode) {
			if (this.isListItem(parentNode)) {
				return true;
			}
				parentNode = parentNode.parentNode;
			}
		return false; */
		var $parentNode = $n->parentNode;
		while ($parentNode) {
			if (self::isListItem($parentNode)) {
				return true;
			}
				$parentNode = $parentNode->parentNode;
			}
		return false;
	}

	public static function isNestedListOrListItem($n) {
/*		return (this.isList(n) || this.isListItem(n)) && this.isNestedInListItem(n); */
	}

	/**
	 * Check a node to see whether it's a meta with some typeof.
	 *
	 * @param {Node} n
	 * @param {string} type Passed into {@link #isNodeOfType}.
	 */
	public static function isMarkerMeta($n, $type) {
/*		return this.isNodeOfType(n, "META", type); */
		return self::isNodeOfType($n, 'META', $type);
	}

	// FIXME: This would ideally belong in DiffUtils.js
	// but that would introduce circular dependencies.
	public static function isDiffMarker($node, $mark) {
/*		if (!node) { return false; }

		if (mark) {
			return this.isMarkerMeta(node, 'mw:DiffMarker/' + mark);
		} else {
			return node.nodeName === 'META' && /\bmw:DiffMarker\/\w*\b/.test(node.getAttribute('typeof'));
		} */
		if (!$node) { return false; }

		if ($mark) {
			return self::isMarkerMeta($node, 'mw:DiffMarker/' + $mark);
		} else {
// NTR - must convert regex expression to php style
//			return $node->nodeName === 'META' && /\bmw:DiffMarker\/\w*\b/.test($node->getAttribute('typeof'));
		}
	}

	/**
	 * Check whether a node has any children that are elements.
	 */
	public static function hasElementChild($node) {
/*		for (var child = node.firstChild; child; child = child.nextSibling) {
			if (this.isElt(child)) {
				return true;
			}
		}
		return false; */
		for ($child = $node->firstChild; $child; $child = $child->nextSibling) {
			if (self::isElt($child)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if a node has a block-level element descendant.
	 */
	public static function hasBlockElementDescendant($node) {
/*		for (var child = node.firstChild; child; child = child.nextSibling) {
			if (this.isElt(child) &&
				// Is a block-level node
			(this.isBlockNode(child) ||
				// or has a block-level child or grandchild or..
			this.hasBlockElementDescendant(child))) {
				return true;
			}
		}
		return false; */
		for ($child = $node->firstChild; $child; $child = $child->nextSibling) {
			if (self::isElt($child) &&
				// Is a block-level node
			(self::isBlockNode($child) ||
				// or has a block-level child or grandchild or..
			self::hasBlockElementDescendant($child))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is a node representing inter-element whitespace?
	 */
	public static function isIEW($node) {
		// ws-only
		return self::isText($node) && preg_match('/^\s*$/', $node->nodeValue);
	}

	public static function isDocumentFragment($node) {
/*		return node && node.nodeType === 11; */
		return $node && $node->nodeType === 11;
	}

	public static function atTheTop($node) {
/*		return this.isDocumentFragment(node) || this.isBody(node); */
		return self::isDocumentFragment($node) || self::isBody($node);
	}

	public static function isContentNode($node) {
/*		return !this.isComment(node) &&
		!this.isIEW(node) &&
		!this.isDiffMarker(node); */
		return !self::isComment($node) &&
			!self::isIEW($node) &&
			!self::isDiffMarker($node);
	}

	/**
	 * Get the first child element or non-IEW text node, ignoring
	 * whitespace-only text nodes, comments, and deleted nodes.
	 */
	public static function firstNonSepChild($node) {
/*		var child = node.firstChild;
		while (child && !this.isContentNode(child)) {
			child = child.nextSibling;
		}
		return child; */
		$child = $node->firstChild;
		while ($child && !self::isContentNode($child)) {
			$child = $child->nextSibling;
		}
		return $child;
	}

	/**
	 * Get the last child element or non-IEW text node, ignoring
	 * whitespace-only text nodes, comments, and deleted nodes.
	 */
	public static function lastNonSepChild($node) {
/*		var child = node.lastChild;
		while (child && !this.isContentNode(child)) {
			child = child.previousSibling;
		}
		return child; */
		$child = $node->lastChild;
		while ($child && !self::isContentNode($child)) {
			$child = $child->previousSibling;
		}
		return $child;
	}

	public static function previousNonSepSibling($node) {
/*		var prev = node.previousSibling;
		while (prev && !this.isContentNode(prev)) {
			prev = prev.previousSibling;
		}
		return prev; */
		$prev = $node->previousSibling;
		while ($prev && !self::isContentNode($prev)) {
			$prev = $prev->previousSibling;
		}
		return $prev;
	}

	public static function nextNonSepSibling($node) {
/*		var next = node.nextSibling;
		while (next && !this.isContentNode(next)) {
			next = next.nextSibling;
		}
		return next; */
		$next = $node->nextSibling;
		while ($next && !self::isContentNode($next)) {
			$next = $next->nextSibling;
		}
		return $next;
	}

	public static function numNonDeletedChildNodes($node) {
/*		var n = 0;
		var child = node.firstChild;
		while (child) {
			if (!this.isDiffMarker(child)) { // FIXME: This is ignoring both inserted/deleted
				n++;
			}
			child = child.nextSibling;
		}
		return n; */
		$num = 0;
		$child = $node->firstChild;
		while ($child) {
			if (!self::isDiffMarker($child)) { // FIXME: This is ignoring both inserted/deleted
				$num++;
			}
			$child = $child->nextSibling;
		}
		return $num;
	}

	/**
	 * Get the first non-deleted child of node.
	 */
	public static function firstNonDeletedChild($node) {
/*		var child = node.firstChild;
		while (child && this.isDiffMarker(child)) { // FIXME: This is ignoring both inserted/deleted
			child = child.nextSibling;
		}
		return child; */
		$child = $node->firstChild;
		while ($child && self::isDiffMarker($child)) { // FIXME: This is ignoring both inserted/deleted
			$child = $child->nextSibling;
		}
		return $child;
	}

	/**
	 * Get the last non-deleted child of node.
	 */
	public static function lastNonDeletedChild($node) {
/*		var child = node.lastChild;
		while (child && this.isDiffMarker(child)) { // FIXME: This is ignoring both inserted/deleted
			child = child.previousSibling;
		}
		return child; */
		$child = $node->lastChild;
		while ($child && self::isDiffMarker($child)) { // FIXME: This is ignoring both inserted/deleted
			$child = $child->previousSibling;
		}
		return $child;
	}

	/**
	 * Get the next non deleted sibling.
	 */
	public static function nextNonDeletedSibling($node) {
/*		node = node.nextSibling;
		while (node && this.isDiffMarker(node)) { // FIXME: This is ignoring both inserted/deleted
			node = node.nextSibling;
		}
		return node; */
		$node = $node->nextSibling;
		while ($node && self::isDiffMarker($node)) { // FIXME: This is ignoring both inserted/deleted
			$node = $node->nextSibling;
		}
		return $node;
	}

	/**
	 * Get the previous non deleted sibling.
	 */
	public static function previousNonDeletedSibling($node) {
/*		node = node.previousSibling;
		while (node && this.isDiffMarker(node)) { // FIXME: This is ignoring both inserted/deleted
			node = node.previousSibling;
		}
		return node; */
		$node = $node->previousSibling;
		while ($node && self::isDiffMarker($node)) { // FIXME: This is ignoring both inserted/deleted
			$node = $node->previousSibling;
		}
		return $node;
	}

	/**
	 * Are all children of this node text or comment nodes?
	 */
	public static function allChildrenAreTextOrComments($node) {
/*		var child = node.firstChild;
		while (child) {
			if (!this.isDiffMarker(child)
				&& !this.isText(child)
				&& !this.isComment(child)) {
				return false;
			}
			child = child.nextSibling;
		}
		return true; */
		$child = $node->firstChild;
		while ($child) {
			if (!self::isDiffMarker($child)
				&& !self::isText($child)
				&& !self::isComment($child)) {
				return false;
			}
			$child = $child->nextSibling;
		}
		return true;
	}

	/**
	 * Are all children of this node text nodes?
	 */
	public static function allChildrenAreText($node) {
/*		var child = node.firstChild;
		while (child) {
			if (!this.isDiffMarker(child) && !this.isText(child)) {
				return false;
			}
			child = child.nextSibling;
		}
		return true; */
		$child = $node->firstChild;
		while ($child) {
			if (!self::isDiffMarker($child) && !self::isText($child)) {
				return false;
			}
			$child = $child->nextSibling;
		}
		return true;
	}

	/**
	 * Does `node` contain nothing or just non-newline whitespace?
	 * `strict` adds the condition that all whitespace is forbidden.
	 */
	public static function nodeEssentiallyEmpty($node, $strict) {
/*		var n = node.firstChild;
		while (n) {
			if (this.isElt(n) && !this.isDiffMarker(n)) {
				return false;
			} else if (this.isText(n) &&
				(strict || !/^[ \t]*$/.test(n.nodeValue))) {
				return false;
			} else if (this.isComment(n)) {
				return false;
			}
			n = n.nextSibling;
		}
		return true; */
		$n = $node->firstChild;
		while ($n) {
			if (self::isElt($n) && !self::isDiffMarker($n)) {
				return false;
			} else if (self::isText($n) &&
// NTR - must convert regex expression to php style
//				(strict || !/^[ \t]*$/.test($n->nodeValue))) {
			{ // NTR - remove this brace when line above is restored
				return false;
			} else if (self::isComment($n)) {
				return false;
			}
			$n = $n->nextSibling;
		}
		return true;
	}

	/**
	 * Check if the dom-subtree rooted at node has an element with tag name 'tagName'
	 * The root node is not checked.
	 */
	public static function treeHasElement($node, $tagName) {
/*		node = node.firstChild;
		while (node) {
			if (this.isElt(node)) {
				if (node.nodeName === tagName || this.treeHasElement(node, tagName)) {
					return true;
				}
			}
			node = node.nextSibling;
		}
		return false; */
		$node = $node->firstChild;
		while ($node) {
			if (self::isElt($node)) {
				if ($node->nodeName === tagName || self::treeHasElement($node, $tagName)) {
					return true;
				}
			}
			$node = $node->nextSibling;
		}
		return false;
	}

	/**
	 * Is node a table tag (table, tbody, td, tr, etc.)?
	 * @param {Node} node
	 * @return {boolean}
	 */
	public static function isTableTag($node) {
/*		return Consts.HTML.TableTags.has(node.nodeName); */
	}

	/**
	 * Returns a media element nested in `node`
	 *
	 * @param {Node} node
	 * @return {Node|null}
	 */
	public static function selectMediaElt($node) {
/*		return node.querySelector('img, video, audio'); */
	}

	/**
	 * Extract http-equiv headers from the HTML, including content-language and
	 * vary headers, if present
	 *
	 * @param {Document} doc
	 * @return {Object}
	 */
	public static function findHttpEquivHeaders($doc) {
/*		return Array.from(doc.querySelectorAll('meta[http-equiv][content]'))
			.reduce((r,el) => {
			r[el.getAttribute('http-equiv').toLowerCase()] =
				el.getAttribute('content');
			return r;
		}, {}); */
	}

	/**
	 * @param {Document} doc
	 * @return {string|null}
	 */
	public static function extractInlinedContentVersion($doc) {
/*		var el = doc.querySelector('meta[property="mw:html:version"]');
		return el ? el.getAttribute('content') : null; */
	}

}
