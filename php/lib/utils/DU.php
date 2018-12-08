<?php

namespace Parsoid\Lib\Utils;

require_once __DIR__."/../config/WikitextConstants.php";
require_once __DIR__."/TokenUtils.php";

use Parsoid\Lib\Config\WikitextConstants;
use Parsoid\Lib\Utils\TokenUtils;

class DU {
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

	public static function isElt( $node ) {
		return $node && $node->nodeType === 1;
	}

	public static function isText( $node ) {
		return $node && $node->nodeType === 3;
	}

	public static function isComment( $node ) {
		return $node && $node->nodeType === 8;
	}

	public static function isBody( $node ) {
		return $node && $node->nodeName === 'body';
	}

	/**
	 * Is a node representing inter-element whitespace?
	 */
	public static function isIEW($node) {
		// ws-only
		return self::isText($node) && preg_match('/^\s*$/', $node->nodeValue);
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

	/**
	 * Check whether a meta's typeof indicates that it is a template expansion.
	 *
	 * @param {string} nType
	 */
	public static function isTplMetaType($nType) {
		return preg_match(self::TPL_META_TYPE_REGEXP, $nType);
	}

	public static function isBlockNode($node) {
		return $node && TokenUtils::isBlockTag($node->nodeName);
	}

	public static function getDataParsoid( $node ) {
		// fixme: inefficient!!
		// php dom impl doesn't provide the DOMUserData field => cannot cache this right now
		return json_decode($node->getAttribute('data-parsoid'), true);
	}

	public static function setDataParsoid( $node, $dp ) {
		$node->setAttribute( 'data-parsoid', json_encode( $dp ) );
	}

	public static function getDataMW( $node ) {
		// fixme: inefficient!!
		// php dom impl doesn't provide the DOMUserData field => cannot cache this right now
		return json_decode($node->getAttribute('data-mw'), true);
	}

	public static function setDataMW( $node, $dp ) {
		$node->setAttribute( 'data-mw', json_encode( $dp ) );
	}

	/**
	 * Check whether a node's data-parsoid object includes
	 * an indicator that the original wikitext was a literal
	 * HTML element (like table or p)
	 *
	 * @param {Object} dp
	 *   @param {string|undefined} [dp.stx]
	 */
	public static function hasLiteralHTMLMarker( $dp ) {
		return isset($dp['stx']) && $dp['stx'] === 'html';
	}

	/**
	 * Run a node through #hasLiteralHTMLMarker
	 */
	public static function isLiteralHTMLNode($node) {
		return ($node &&
			self::isElt($node) &&
			self::hasLiteralHTMLMarker(self::getDataParsoid($node)));
	}

	/**
	 * Check whether a node is a meta signifying the start of a template expansion.
	 */
	public static function isTplStartMarkerMeta($node) {
		if ($node->nodeName == "meta") {
			$t = $node->getAttribute("typeof");
			return self::isTplMetaType($t) && !preg_match('/\/End(?=$|\s)/', $t);
		} else {
			return false;
		}
	}

	/**
	 * Check whether a pre is caused by indentation in the original wikitext.
	 */
	public static function isIndentPre($node) {
		return $node->nodeName === "pre" && !self::isLiteralHTMLNode($node);
	}

	public static function isFormattingElt( $node ) {
		return $node && isset( WikitextConstants::$HTML['FormattingTags'][ $node->nodeName ] );
	}

	public static function isList( $n ) {
		return $n && isset( WikitextConstants::$ListTags[$n->nodeName] );
	}

	public static function isQuoteElt( $n ) {
		return $n && isset( WikitextConstants::$WTQuoteTags[$n->nodeName] );
	}

	public static function tsrSpansTagDOM($n, $parsoidData) {
		// - tags known to have tag-specific tsr
		// - html tags with 'stx' set
		// - span tags with 'mw:Nowiki' type
		$name = $n->nodeName;
		return !(
			isset(self::$WtTagsWithLimitedTSR[$name]) ||
			self::hasLiteralHTMLMarker($parsoidData) ||
			self::isNodeOfType($n, 'span', 'mw:Nowiki')
		);
	}

	public static function deleteNode( $node ) {
		$node->parentNode->removeChild( $node );
	}

	public static function migrateChildren( $from, $to, $beforeNode = null) {
		while ( $from->firstChild ) {
			$to->insertBefore( $from->firstChild, $beforeNode );
		}
	}

	public static function isGeneratedFigure( $n ) {
		return self::isElt( $n ) && preg_match( '/(^|\s)mw:(?:Image|Video|Audio)(\s|$|\/)/', $n->getAttribute("typeof") );
	}

	/**
	 * Check whether a typeof indicates that it signifies an
	 * expanded attribute.
	 */
	public static function hasExpandedAttrsType( $node ) {
		$nType = $node->getAttribute('typeof');
		return preg_match( '/(?:^|\s)mw:ExpandedAttrs(\/[^\s]+)*(?=$|\s)/', $nType );
	}

	/**
	 * Helper functions to detect when an A-node uses [[..]]/[..]/... style
	 * syntax (for wikilinks, ext links, url links). rel-type is not sufficient
	 * anymore since mw:ExtLink is used for all the three link syntaxes.
	 */
	public static function usesWikiLinkSyntax( $aNode, $dp ) {
		if ($dp === null) {
			$dp = self::getDataParsoid($aNode);
		}

		// SSS FIXME: This requires to be made more robust
		// for when dp['stx'] value is not present
		return $aNode->getAttribute("rel") === "mw:WikiLink" ||
			(isset($dp['stx']) && $dp['stx'] !== "url" && $dp['stx'] !== "magiclink");
	}

	public static function usesExtLinkSyntax( $aNode, $dp ) {
		if ($dp === null) {
			$dp = self::getDataParsoid($aNode);
		}

		// SSS FIXME: This requires to be made more robust
		// for when $dp['stx'] value is not present
		return $aNode->getAttribute("rel") === "mw:ExtLink" &&
			(!isset($dp['stx']) || ($dp['stx'] !== "url" && $dp['stx'] !== "magiclink"));
	}

	public static function usesURLLinkSyntax($aNode, $dp) {
		if ($dp === null) {
			$dp = self::getDataParsoid($aNode);
		}

		// SSS FIXME: This requires to be made more robust
		// for when $dp['stx'] value is not present
		return $aNode->getAttribute("rel") === "mw:ExtLink" &&
			isset($dp['stx']) && ($dp['stx'] === "url" || $dp['stx'] === "magiclink");
	}

	public static function usesMagicLinkSyntax($aNode, $dp) {
		if ($dp === null) {
			$dp = self::getDataParsoid($aNode);
		}

		return $aNode->getAttribute("rel") === "mw:ExtLink" &&
			isset($dp['stx']) && $dp['stx'] === "magiclink";
	}

	/**
	 * Find how much offset is necessary for the DSR of an
	 * indent-originated pre tag.
	 *
	 * @param {TextNode} textNode
	 * @return {number}
	 */
	public static function indentPreDSRCorrection($textNode) {
		// NOTE: This assumes a text-node and doesn't check that it is one.
		//
		// FIXME: Doesn't handle text nodes that are not direct children of the pre
		if (self::isIndentPre($textNode->parentNode)) {
			if ($textNode->parentNode->lastChild === $textNode) {
				// We dont want the trailing newline of the last child of the pre
				// to contribute a pre-correction since it doesn't add new content
				// in the pre-node after the text
				$numNLs = preg_match_all('/\n./', $textNode->nodeValue);
			} else {
				$numNLs = preg_match_all('/\n/', $textNode->nodeValue);
			}
			return $numNLs;
		} else {
			return 0;
		}
	}

/*
	// Map an HTML DOM-escaped comment to a wikitext-escaped comment.
	public static function decodeComment($comment) {
		// Undo HTML entity escaping to obtain "true value" of comment.
		$trueValue = Util.decodeEntities($comment);
		// ok, now encode this "true value" of the comment in such a way
		// that the string "-->" never shows up.  (See above.)
		return $trueValue
			.replace(/--(&(amp;)*gt;|>)/g, function(s) {
				return s === '-->' ? '--&gt;' : '--&amp;' + s.slice(3);
			});
	}
*/

	// Utility function: we often need to know the wikitext DSR length for
	// an HTML DOM comment value.
	public static function decodedCommentLength($node) {
		# assert(self::isComment($node));
		// Add 7 for the "<!--" and "-->" delimiters in wikitext.
		#return mb_strlen(self::decodeComment($node->data)) + 7;
		return mb_strlen($node->textContent) + 7;
	}

	public static function isDOMFragmentType($typeOf) {
		return preg_match('/(?:^|\s)mw:DOMFragment(\/sealed\/\w+)?(?=$|\s)/', $typeOf);
	}

	public static function isDOMFragmentWrapper($node) {
		if (!self::isElt($node)) {
			return false;
		}

		$about = $node->getAttribute("about");
		return $about && (
			self::isDOMFragmentType($node->getAttribute("typeof"))
		);
	}

	/**
	 * Is node the first wrapper element of encapsulated content?
	 */
	public static function isFirstEncapsulationWrapperNode($node) {
		return self::isElt($node) &&
			preg_match(self::FIRST_ENCAP_REGEXP, $node->getAttribute('typeof'));
	}

	public static function isFosterablePosition($n) {
		return $n && isset(WikitextConstants::$HTML['FosterablePosition'][$n->parentNode->nodeName]);
	}

	/**
	 * Gets all siblings that follow 'node' that have an 'about' as
	 * their about id.
	 *
	 * This is used to fetch transclusion/extension content by using
	 * the about-id as the key.  This works because
	 * transclusion/extension content is a forest of dom-trees formed
	 * by adjacent dom-nodes.  This is the contract that templace
	 * encapsulation, dom-reuse, and VE code all have to abide by.
	 *
	 * The only exception to this adjacency rule is IEW nodes in
	 * fosterable positions (in tables) which are not span-wrapped to
	 * prevent them from getting fostered out.
	 */
	public static function getAboutSiblings($node, $about) {
		$nodes = [ $node ];

		if (!$about) {
			return $nodes;
		}

		$node = $node->nextSibling;
		while ($node && (
			self::isElt($node) && $node->getAttribute('about') === $about ||
				self::isFosterablePosition($node) && !self::isElt($node) && self::isIEW($node)
		)) {
			$nodes[] = $node;
			$node = $node->nextSibling;
		}

		// Remove already consumed trailing IEW, if any
		while (count($nodes) > 0 && self::isIEW($nodes[count($nodes) - 1])) {
			array_pop($nodes);
		}

		return $nodes;
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

	// FIXME: Should be in Utils.php
	public static function isValidDSR( $dsr ) {
		return $dsr &&
			is_numeric( $dsr[0] ) && $dsr[0] >= 0 &&
			is_numeric( $dsr[1] ) && $dsr[1] >= 0;
	}

	public static function dumpDOM($node, $str) {
		/* nothing */
	}

	public static function isFallbackIdSpan($node) {
		return $node->nodeName === 'span' && $node->getAttribute('typeof') === 'mw:FallbackId';
	}

	public static function isSolTransparentLink($node) {
		return self::isElt($node) && $node->nodeName === 'link' &&
			preg_match(TokenUtils::solTransparentLinkRegexp, $node->getAttribute('rel'));
	}

	public static function isRenderingTransparentNode($node) {
		// FIXME: Can we change this entire thing to
		// self::isComment($node) ||
		// self::getDataParsoid($node).stx !== 'html' &&
		//   ($node->nodeName === 'META' || $node->nodeName === 'LINK')
		//
		$typeOf = self::isElt($node) && $node->getAttribute('typeof');
		return self::isComment($node) ||
			self::isSolTransparentLink($node) ||
			// Catch-all for everything else.
			($node->nodeName === 'meta' &&
				// (Start|End)Tag metas clone data-parsoid from the tokens
				// they're shadowing, which trips up on the stx check.
				// TODO: Maybe that data should be nested in a property?
				(preg_match('/(mw:StartTag)|(mw:EndTag)/', $typeOf) || !isset(self::getDataParsoid($node)["stx"]) || self::getDataParsoid($node)["stx"] !== 'html')) ||
			self::isFallbackIdSpan($node);
	}
}
