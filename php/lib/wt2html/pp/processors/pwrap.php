<?php
namespace Parsoid\Lib\Wt2Html\PP\Processors;

require_once __DIR__."/../../../utils/DU.php";

use Parsoid\Lib\Utils\DU;

// This is equivalent to DU.emitsSolTransparentSingleLineWT except for the
// single line constraint.
function emitsSolTransparentWT($n) {
	return DU::isText($n) && preg_match('/^\s*$/', $n->nodeValue) || DU::isRenderingTransparentNode($n);
}

function isSplittableTag($n) {
	// Seems safe to split span, sub, sup, cite tags
	//
	// These are the only 4 tags that are in HTML5Depurate's
	// list of inline tags that are not self-closing and that
	// can embed tags inside them.
	//
	// However, if we want to mimic Parsoid and HTML5 spec
	// precisely, we should only use isFormattingElt(n)
	return DU::isFormattingElt($n);
}

// Flattens an array with other arrays for elements into
// an array without nested arrays
function flatten($a) {
	$ret = [];
	foreach ($a as $v) {
		$ret = array_merge($ret, $v);
	}
	return $ret;
}

function isBlockNode($node) {
	// T167349: Special case introduced by template styles
	return DU::isBlockNode($node) || $node->nodeName === 'style';
}

// Does the subtree $rooted at 'n' have a block tag in it?
function hasBlockTag($n) {
	$c = $n->firstChild;
	while ($c) {
		if (isBlockNode($n) || hasBlockTag($c)) {
			return true;
		}
		$c = $c->nextSibling;
	}
	return false;
}

// mergeRuns merges split subtrees that
// have identical pwrap properties
function mergeRuns($n, $a) {
	$curr = null;
	$ret = [];
	// This flag should be transferred to the rightmost
	// clone of this node in the loop below.
	$origAIEnd = DU::getDataParsoid($n)["autoInsertedEnd"];
	foreach ($a as $v) {
		if (!$curr) {
			$curr = [ "pwrap" => $v["pwrap"], "node" => $n ];
			array_push($ret, $curr);
		} else if ($curr["pwrap"] === null) {
			$curr["pwrap"] = $v["pwrap"];
		} else if ($curr["pwrap"] !== $v["pwrap"] && $v["pwrap"] !== null) {
			DU::getDataParsoid($curr["node"])["autoInsertedEnd"] = true;
			$curr = [ "pwrap" => $v["pwrap"], $node => $n->clone() ];
			DU::getDataParsoid($curr["node"])["autoInsertedStart"] = true;
			array_push($ret, $curr);
		}
		$curr["node"]->appendChild($v["node"]);
	}

	if ($curr) {
		DU::getDataParsoid($curr["node"])["autoInsertedEnd"] = $origAIEnd;
	}
	return $ret;
}

// split does the split operation described in the outline of
// the algorithm below.
function split($n) {
	if (emitsSolTransparentWT($n)) {
		// The null stuff here is mainly to support mw:EndTag metas getting in
		// the way of runs and causing unnecessary wrapping.
		return [ [ "pwrap" => null, "node" => $n ] ];
	} else if (DU::isText($n)) {
		return [ [ "pwrap" => true, "node" => $n ] ];
	} else if (!isSplittableTag($n) || count($n->childNodes) === 0) {
		// block tag OR non-splittable inline tag
		return [ [ "pwrap" => !isBlockNode($n) && !hasBlockTag($n), "node" => n ] ];
	} else {
		// splittable inline tag
		// split for each child and merge runs
		return mergeRuns($n, flatten(array_map("split", $n->childNodes)));
	}
}

// Wrap children of '$root' with paragraph tags while
// so that the final output has the following properties:
//
// 1. A paragraph will have at least one non-whitespace text
//    node or an non-block element node in its subtree.
//
// 2. Two paragraph nodes aren't siblings of each other.
//
// 3. If a child of $root is not a paragraph node, it is one of:
//    - a white-space only text node
//    - a comment node
//    - a block element
//    - a splittable inline element which has some block node
//      on *all* paths from it to all leaves in its subtree.
//    - a non-splittable inline element which has some block node
//      on *some* path from it to a leaf in its subtree.
//
//
// This output is generated with the following algorithm
//
// 1. Block nodes are skipped over
// 2. Non-splittable inline nodes that have a block tag
//    in its subtree are skipped over.
// 3. A splittable inline node, I, that has at least one block tag
//    in its subtree is split into multiple tree such that
//    * each new tree is $rooted in I
//    * the trees alternate between two kinds
//      (a) it has no block node inside
//          => pwrap is true
//      (b) all paths from I to its leaves have some block node inside
//          => pwrap is false
// 4. A paragraph tag is wrapped around adjacent runs of comment nodes,
//    text nodes, and an inline node that has no block node embedded inside.
//    This paragraph tag does not start with a white-space-only text node
//    or a comment node. The current algorithm does not ensure that it doesn't
//    end with one of those either, but that is a potential future enhancement.

function pWrap($root) {
	$p = null;
	$c = $root->firstChild;
	while ($c) {
		$next = $c->nextSibling;
		if (isBlockNode($c)) {
			$p = null;
		} else {
			$vs = split($c);
			foreach ($vs as $v) {
				$n = $v["node"];
				if ($v["pwrap"] === false) {
					$p = null;
					$root->insertBefore($n, $next);
				} else if (emitsSolTransparentWT($n)) {
					if ($p) {
						$p->appendChild($n);
					} else {
						$root->insertBefore($n, $next);
					}
				} else {
					if (!$p) {
						$p = $root->ownerDocument->createElement('p');
						$root->insertBefore($p, $next);
					}
					$p->appendChild($n);
				}
			}
		}
		$c = $next;
	}
}

// This function walks the DOM tree $rooted at '$root'
// and uses pWrap to add appropriate paragraph wrapper
// tags around children of nodes with tag name '$tagName'.
function pWrapInsideTag($root, $tagName) {
	$c = $root->firstChild;
	while ($c) {
		$next = $c->nextSibling;
		if ($c->nodeName === $tagName) {
			pWrap($c);
		} else if (DU::isElt($c)) {
			pWrapInsideTag($c, $tagName);
		}
		$c = $next;
	}
}

// Wrap children of <body> as well as children of
// <blockquote> found anywhere in the DOM tree.
function pWrapDOM($root, $env, $options) {
	pWrap($root);
	pWrapInsideTag($root, 'blockquote');
}
