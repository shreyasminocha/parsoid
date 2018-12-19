<?php

namespace Parsoid\Lib\Wt2Html\PP\Processors;

require_once __DIR__."/../../../config/WikitextConstants.php";
require_once __DIR__."/../../../utils/DU.php";

use Parsoid\Lib\Config\WikitextConstants;
use Parsoid\Lib\Utils\DU;

function acceptableInconsistency($opts, $node, $cs, $s) {
	/**
	 * 1. For wikitext URL links, suppress cs-s diff warnings because
	 *    the diffs can come about because of various reasons since the
	 *    canonicalized/decoded href will become the a-link text whose width
	 *    will not match the tsr width of source wikitext
	 *
	 *    (a) urls with encoded chars (ex: 'http://example.com/?foo&#61;bar')
	 *    (b) non-canonical spaces (ex: 'RFC  123' instead of 'RFC 123')
	 *
	 * 2. We currently dont have source offsets for attributes.
	 *    So, we get a lot of spurious complaints about cs/s mismatch
	 *    when DSR computation hit the <body> tag on this attribute.
	 *    opts.attrExpansion tell us when we are processing an attribute
	 *    and let us suppress the mismatch warning on the <body> tag.
	 *
	 * 3. Other scenarios .. to be added
	 */
	if ($node->nodeName === 'a' && (DU::usesURLLinkSyntax($node, null) || DU::usesMagicLinkSyntax($node, null))) {
		return true;
	} else if (isset($opts->attrExpansion) && DU::isBody($node)) {
		return true;
	} else {
		return false;
	}
}

function tsrSpansTagDOM($n, $parsoidData) {
	// - tags known to have tag-specific tsr
	// - html tags with 'stx' set
	$name = $n->nodeName;
	return !(
		isset(DU::$WtTagsWithLimitedTSR[$name]) ||
		DU::hasLiteralHTMLMarker($parsoidData)
	);
}

function computeListEltWidth($li) {
	if (!$li->previousSibling && $li->firstChild) {
		if (DU::isList($li->firstChild)) {
			// Special case!!
			// First child of a list that is on a chain
			// of nested lists doesn't get a width->
			return 0;
		}
	}

	// count nest listing depth and assign
	// that to the opening tag width->
	$depth = 0;
	while ($li->nodeName === 'li' || $li->nodeName === 'dd') {
		$depth++;
		$li = $li->parentNode->parentNode;
	}

	return $depth;
}

function computeATagWidth($node, $dp) {
	/* -------------------------------------------------------------
	 * Tag widths are computed as per this logic here:
	 *
	 * 1. [[Foo|bar]] <-- piped mw:WikiLink
	 *     -> start-tag: "[[Foo|"
	 *     -> content  : "bar"
	 *     -> end-tag  : "]]"
	 *
	 * 2. [[Foo]] <-- non-piped mw:WikiLink
	 *     -> start-tag: "[["
	 *     -> content  : "Foo"
	 *     -> end-tag  : "]]"
	 *
	 * 3. [[{{echo|Foo}}|Foo]] <-- tpl-attr mw:WikiLink
	 *    Dont bother setting tag widths since dp['sa']["href"] will be
	 *    the expanded target and won't correspond to original source.
	 *    We dont always have access to the meta-tag that has the source.
	 *
	 * 4. [http://wp.org foo] <-- mw:ExtLink
	 *     -> start-tag: "[http://wp.org "
	 *     -> content  : "foo"
	 *     -> end-tag  : "]"
	 * -------------------------------------------------------------- */
	if (!$dp) {
		return null;
	} else {
		if (DU::usesWikiLinkSyntax($node, $dp) && !DU::hasExpandedAttrsType($node)) {
			if ($dp['stx'] === "piped") {
				$href = isset($dp['sa']) ? $dp['sa']['href'] : null;
				if ($href) {
					return [mb_strlen($href) + 3, 2];
				} else {
					return null;
				}
			} else {
				return [2, 2];
			}
		} else if (isset($dp['tsr']) && DU::usesExtLinkSyntax($node, $dp)) {
			return [$dp['targetOff'] - $dp['tsr'][0], 1];
		} else if (DU::usesURLLinkSyntax($node, $dp) || DU::usesMagicLinkSyntax($node, $dp)) {
			return [0, 0];
		} else {
			return null;
		}
	}
}

function computeTagWidths($widths, $node, $dp) {
	if (isset($dp['tagWidths'])) {
		return $dp['tagWidths'];
	}

	$stWidth = $widths[0];
	$etWidth = is_numeric( $widths[1] ) ? $widths[1] : null;

	if (DU::hasLiteralHTMLMarker($dp)) {
		if (isset($dp['selfClose'])) {
			$etWidth = 0;
		}
	} else {
		$nodeName = $node->nodeName;
		// 'tr' tags not in the original source have zero width
		if ($nodeName === 'tr' && !isset($dp['startTagSrc'])) {
			$stWidth = 0;
			$etWidth = 0;
		} else {
			$wtTagWidth = isset(WikitextConstants::$WtTagWidths[$nodeName]) ? WikitextConstants::$WtTagWidths[$nodeName] : null;
			if ($stWidth === null) {
				// we didn't have a tsr to tell us how wide this tag was.
				if ($nodeName === 'a') {
					$wtTagWidth = computeATagWidth($node, $dp);
					$stWidth = $wtTagWidth ? $wtTagWidth[0] : null;
				} else if ($nodeName === 'li' || $nodeName === 'dd') {
					$stWidth = computeListEltWidth($node);
				} else if ($wtTagWidth) {
					$stWidth = $wtTagWidth[0];
				}
			}

			if ($etWidth === null && $wtTagWidth) {
				$etWidth = $wtTagWidth[1];
			}
		}
	}

	return [$stWidth, $etWidth];
}

function trace() {
	$args = func_get_args();
	$env = array_shift($args);
	$env->log("trace/dsr", function() use ($args) {
		$buf = '';
		foreach ($args as $arg) {
			$buf .= (gettype($arg) === 'string' ? $arg : json_encode($arg));
		}
		return $buf;
	});
}

/* ------------------------------------------------------------------------
 * TSR = "Tag Source Range".  Start and end offsets giving the location
 * where the tag showed up in the original source.
 *
 * DSR = "DOM Source Range".  [0] and [1] are open and end,
 * [2] and [3] are widths of the container tag.
 *
 * TSR is set by the tokenizer. In most cases, it only applies to the
 * specific tag (opening or closing).  However, for self-closing
 * tags that the tokenizer generates, the TSR values applies to the entire
 * DOM subtree (opening tag + content + closing tag).
 *
 * Ex: So [[foo]] will get tokenized to a SelfClosingTagTk(...) with a TSR
 * value of [0,7].  The DSR algorithm will then use that info and assign
 * the a-tag rooted at the <a href='...'>foo</a> DOM subtree a DSR value of
 * [0,7,2,2], where 2 and 2 refer to the opening and closing tag widths.
 * ------------------------------------------------------------------------ */

/* ---------------------------------------------------------------------------
 * node  -- node to process
 * [s,e) -- if defined, start/end position of wikitext source that generated
 *          node's subtree
 * --------------------------------------------------------------------------- */
function computeNodeDSR($env, $node, $s, $e, $dsrCorrection, $opts) {

	if ($e === null && !$node->hasChildNodes()) {
		$e = $s;
	}

	trace($env, "BEG: ", $node->nodeName, " with [s, e]=", [$s, $e]);

	$savedEndTagWidth = null;
	$ce = $e;
	// Initialize $cs to $ce to handle the zero-children case properly
	// if this $node has no child content, then the start and end for
	// the child dom are indeed identical.  Alternatively, we could
	// explicitly code this check before everything and bypass this.
	$cs = $ce;
	$rtTestMode = false; #$env->conf->parsoid->rtTestMode;

	$child = $node->lastChild;
	while ($child !== null) {
		$prevChild = $child->previousSibling;
		$isMarkerTag = false;
		$origCE = $ce;
		$cType = $child->nodeType;
		$endTagInfo = null;
		$fosteredNode = false;
		$cs = null;

		// In edit mode, StrippedTag marker tags will be removed and wont
		// be around to miss in the filling gap.  So, absorb its width into
		// the DSR of its previous sibling.  Currently, this fix is only for
		// B and I tags where the fix is clear-cut and obvious.
		if (!$rtTestMode) {
			$next = $child->nextSibling;
			if ($next && DU::isElt($next)) {
				$ndp = DU::getDataParsoid($next);
				if (isset($ndp['src']) &&
					preg_match('/(?:^|\s)mw:Placeholder\/StrippedTag(?=$|\s)/', $next->getAttribute("typeof"))) {
					if (isset(WikitextConstants::$WTQuoteTags[$ndp['name']]) &&
						isset(WikitextConstants::$WTQuoteTags[$child->nodeName])) {
						$correction = mb_strlen($ndp['src']);
						$ce += $correction;
						$dsrCorrection = $correction;
						#if (Util::isValidDSR($ndp['dsr']))
						if (DU::isValidDSR($ndp['dsr'])) {
							// Record original DSR for the meta tag
							// since it will now get corrected to zero width
							// since child acquires its width->
							if (!$ndp['tmp']) {
								$ndp['tmp'] = [];
							}
							$ndp['tmp']['origDSR'] = [$ndp['dsr'][0], $ndp['dsr'][1], null, null];
						}
					}
				}
			}
		}

		$env->log("trace/dsr", function() use ($child, $cs, $ce) {
			// slow, for debugging only
			$i = 0;
			foreach ($child->parentNode->childNodes as $x) {
				if ($x === $child) {
					break;
				}
				$i++;
			}
			return "     CHILD: <" . $child->parentNode->nodeName . ":" . $i .
				">=" .
				(DU::isElt($child) ? '' : (DU::isText($child) ? '#' : '!')) .
				(DU::isElt($child) ?
					($child->nodeName === 'meta' ? $child->ownerDocument->saveHTML($child) : $child->nodeName) :
					json_encode($child->data)) .  " with " . json_encode([$cs, $ce]);
		});

		if ($cType === 3) {
			if ($ce !== null) {
				// This code is replicated below. Keep both in sync.
				// $cs = $ce - mb_strlen($child->data) - DU::indentPreDSRCorrection($child);
				$cs = $ce - mb_strlen($child->textContent) - DU::indentPreDSRCorrection($child);
			}
		} else if ($cType === 8) {
			if ($ce !== null) {
				// decode html entities & reencode as wikitext to find length
				$cs = $ce - DU::decodedCommentLength($child);
			}
		} else if ($cType === 1) {
			$cTypeOf = $child->getAttribute("typeof");
			$dp = DU::getDataParsoid($child);
			$tsr = isset($dp['tsr']) ? $dp['tsr'] : null;
			$oldCE = $tsr ? $tsr[1] : null;
			$propagateRight = false;
			$stWidth = null;
			$etWidth = null;

			$fosteredNode = isset($dp['fostered']) ? $dp['fostered'] : false;

			// In edit-mode, we are making dsr corrections to account for
			// stripped tags (end tags usually)->  When stripping happens,
			// in most common use cases, a corresponding end tag is added
			// back elsewhere in the DOM->
			//
			// So, when an autoInsertedEnd tag is encountered and a matching
			// dsr-correction is found, make a 1-time correction in the
			// other direction->
			//
			// Currently, this fix is only for
			// B and I tags where the fix is clear-cut and obvious->
			if (!$rtTestMode && $ce !== null && isset($dp['autoInsertedEnd']) && DU::isQuoteElt($child)) {
				$correction = 3 + mb_strlen($child->nodeName);
				if ($correction === $dsrCorrection) {
					$ce -= $correction;
					$dsrCorrection = 0;
				}
			}

			if ($child->nodeName === "meta") {
				// Unless they have been foster-parented,
				// meta marker tags have valid tsr info->
				if ($cTypeOf === "mw:EndTag" || $cTypeOf === "mw:TSRMarker") {
					if ($cTypeOf === "mw:EndTag") {
						// FIXME: This seems like a different function that is
						// tacked onto DSR computation, but there is no clean place
						// to do this one-off thing without doing yet another pass
						// over the DOM -- maybe we need a 'do-misc-things-pass'.
						//
						// Update table-end syntax using info from the meta tag
						$prev = $child->previousSibling;
						if ($prev && $prev->nodeName === "table") {
							$prevDP = DU::getDataParsoid($prev);
							if (!DU::hasLiteralHTMLMarker($prevDP)) {
								if (isset($dp['endTagSrc'])) {
									$prevDP->endTagSrc = $dp['endTagSrc'];
								}
							}
						}
					}

					$isMarkerTag = true;
					// TSR info will be absent if the tsr-marker came
					// from a template since template tokens have all
					// their tsr info-> stripped->
					if ($tsr) {
						$endTagInfo = array(
							'width' => $tsr[1] - $tsr[0],
							'nodeName' => $child->getAttribute("data-etag"),
						);
						$cs = $tsr[1];
						$ce = $tsr[1];
						$propagateRight = true;
					}
				} else if ($tsr) {
					if (DU::isTplMetaType($cTypeOf)) {
						// If this is a meta-marker tag (for templates, extensions),
						// we have a new valid '$cs'.  This marker also effectively resets tsr
						// back to the top-level wikitext source range from nested template
						// source range.
						$cs = $tsr[0];
						$ce = $tsr[1];
						$propagateRight = true;
					} else {
						// All other meta-tags: <includeonly>, <noinclude>, etc.
						$cs = $tsr[0];
						$ce = $tsr[1];
					}
				} else if (preg_match('/^mw:Placeholder(\/\w*)?$/', $cTypeOf) && $ce !== null && $dp['src']) {
					$cs = $ce - mb_strlen($dp['src']);
				} else {
					$property = $child->getAttribute("property");
					if ($property && preg_match('/mw:objectAttr/', $property)) {
						$cs = $ce;
					}
				}
				if (isset($dp['tagWidths'])) {
					$stWidth = $dp['tagWidths'][0];
					$etWidth = $dp['tagWidths'][1];
					$dp['tagWidths'] = null;
				}
			} else if ($cTypeOf === "mw:Entity" && $ce !== null && $dp['src']) {
				$cs = $ce - mb_strlen($dp['src']);
			} else {
				if (preg_match('/^mw:Placeholder(\/\w*)?$/', $cTypeOf) && $ce !== null && $dp['src']) {
					$cs = $ce - mb_strlen($dp['src']);
				} else {
					// Non-meta tags
					if ($tsr && !isset($dp['autoInsertedStart'])) {
						$cs = $tsr[0];
						if (tsrSpansTagDOM($child, $dp)) {
							if (!$ce || $tsr[1] > $ce) {
								$ce = $tsr[1];
								$propagateRight = true;
							}
						} else {
							$stWidth = $tsr[1] - $tsr[0];
						}

						trace($env, "     TSR: ", $tsr, "; cs: ", $cs, "; ce: ", $ce);
					} else if ($s && $child->previousSibling === null) {
						$cs = $s;
					}
				}

				// Compute width of opening/closing tags for this dom $node
				$tagWidths = computeTagWidths([$stWidth, $savedEndTagWidth], $child, $dp);
				$stWidth = $tagWidths[0];
				$etWidth = $tagWidths[1];

				if (isset($dp['autoInsertedStart'])) {
					$stWidth = 0;
				}
				if (isset($dp['autoInsertedEnd'])) {
					$etWidth = 0;
				}

				$ccs = $cs !== null && $stWidth !== null ? $cs + $stWidth : null;
				$cce = $ce !== null && $etWidth !== null ? $ce - $etWidth : null;

				/* -----------------------------------------------------------------
				 * Process DOM rooted at '$child'.
				 *
				 * NOTE: You might wonder why we are not checking for the zero-$children
				 * case. It is strictly not necessary and you can set newDsr directly.
				 *
				 * But, you have 2 options: [$ccs, $ccs] or [$cce, $cce]. Setting it to
				 * [$cce, $cce] would be consistent with the RTL approach. We should
				 * then compare $ccs and $cce and verify that they are identical.
				 *
				 * But, if we handled the zero-child case like the other scenarios,
				 * we don't have to worry about the above decisions and checks.
				 * ----------------------------------------------------------------- */

				if (DU::isDOMFragmentWrapper($child)) {
					// Eliminate artificial $cs/s mismatch warnings since this is
					// just a wrapper token with the right DSR but without any
					// nested subtree that could account for the DSR span.
					$newDsr = [$ccs, $cce];
				} else if ($child->nodeName === 'a'
					&& DU::usesWikiLinkSyntax($child, $dp)
					&& $dp['stx'] !== "piped") {
					/* -------------------------------------------------------------
					 * This check here eliminates artificial DSR mismatches on content
					 * text of the A-$node because of entity expansion, etc.
					 *
					 * Ex: [[7%25 solution]] will be rendered as:
					 *    <a href=....>7% solution</a>
					 * If we descend into the text for the a-$node, we'll have a 2-char
					 * DSR mismatch which will trigger artificial error warnings.
					 *
					 * In the non-piped link scenario, all dsr info is already present
					 * in the link target and so we get nothing new by processing
					 * content.
					 * ------------------------------------------------------------- */
					$newDsr = [$ccs, $cce];
				} else {
					$env->log("trace/dsr", function() use ($cs, $ce, $stWidth, $etWidth, $ccs, $cce) {
						return "     before-recursing:" .
							"[cs,ce]=" . json_encode([$cs, $ce]) .
							"; [sw,ew]=" . json_encode([$stWidth, $etWidth]) .
							"; subtree-[cs,ce]=" . json_encode([$ccs,$cce]);
					});

					trace($env, "<recursion>");
					$newDsr = computeNodeDSR($env, $child, $ccs, $cce, $dsrCorrection, $opts);
					trace($env, "</recursion>");
				}

				// $cs = min($child-dom-tree dsr[0] - tag-width, current dsr[0])
				if ($stWidth !== null && $newDsr[0] !== null) {
					$newCs = $newDsr[0] - $stWidth;
					if ($cs === null || (!$tsr && $newCs < $cs)) {
						$cs = $newCs;
					}
				}

				// $ce = max($child-dom-tree dsr[1] + tag-width, current dsr[1])
				if ($etWidth !== null && $newDsr[1] !== null) {
					$newCe = $newDsr[1] + $etWidth;
					if ($newCe > $ce) {
						$ce = $newCe;
					}
				}
			}

			if ($cs !== null || $ce !== null) {
				if ($ce < 0) {
					if (!$fosteredNode) {
						$env->log("warn/dsr/negative", "Negative DSR for $node: " . $node->nodeName . "; resetting to zero");
					}
					$ce = 0;
				}

				// Fostered $nodes get a zero-dsr width range.
				if ($fosteredNode) {
					// Reset to 0, if necessary.
					// This is critical to avoid duplication of fostered content in selser mode.
					if ($origCE < 0) {
						$origCE = 0;
					}
					$dp['dsr'] = [$origCE, $origCE];
				} else {
					$dp['dsr'] = [$cs, $ce, $stWidth, $etWidth];
				}

				DU::setDataParsoid( $child, $dp );
				#print "NODE ($child->nodeName) DSR: " . $dp['dsr'][0] . ", " . $dp['dsr'][1] . "\n";
				$env->log("trace/dsr", function() use ($child, $cs, $ce, $cTypeOf) {
					$str = "     UPDATING " . $child->nodeName .
						" with " . json_encode([$cs, $ce]) . "; typeof: " . ($cTypeOf ? $cTypeOf : "null");
					// Set up 'dbsrc' so we can debug this
					// $dp['dbsrc'] = $env->page->src->substring($cs, $ce);
					return $str;
				});
			}

			// Propagate any required changes to the right
			// taking care not to cross-over into template content
			if ($ce !== null &&
				($propagateRight || $oldCE !== $ce || $e === null) &&
				!DU::isTplStartMarkerMeta($child)) {
				$sibling = $child->nextSibling;
				$newCE = $ce;
				while ($newCE !== null && $sibling && !DU::isTplStartMarkerMeta($sibling)) {
					$nType = $sibling->nodeType;
					if ($nType === 3) {
						#$newCE = $newCE + mb_strlen($sibling->data) + DU::indentPreDSRCorrection($sibling);
						$newCE = $newCE + mb_strlen($sibling->textContent) + DU::indentPreDSRCorrection($sibling);
					} else if ($nType === 8) {
						$newCE += DU::decodedCommentLength($sibling);
					} else if ($nType === 1) {
						$siblingDP = DU::getDataParsoid($sibling);

						if (!isset($siblingDP['dsr'])) {
							$siblingDP['dsr'] = [null, null];
						}

						if (isset($siblingDP['fostered']) ||
							($siblingDP['dsr'][0] !== null && $siblingDP['dsr'][0] === $newCE) ||
							($siblingDP['dsr'][0] !== null && isset($siblingDP->tsr) && $siblingDP['dsr'][0] < $newCE)) {
							// $sibling is fostered
							//   => nothing to propagate past it
							// $sibling's dsr[0] matches what we might propagate
							//   => nothing will change
							// $sibling's dsr value came from tsr and it is not outside expected range
							//   => stop propagation so you don't overwrite it
							break;
						}

						// Update and move right
						$env->log("trace/dsr", function() use ($ce, $newCE, $sibling, $siblingDP) {
							$str = "     CHANGING $ce->start of " . $sibling->nodeName .
								" from " . $siblingDP['dsr'][0] . " to " . $newCE;
							// debug info
							if ($siblingDP['dsr'][1]) {
								// $siblingDP['dbsrc'] = $env->page->src->substring($newCE, $siblingDP['dsr'][1]);
							}
							return $str;
						});

						$siblingDP['dsr'][0] = $newCE;
						// If we have a dsr[1] as well and since we updated
						// dsr[0], we have to ensure that the two values don't
						// introduce an inconsistency where dsr[0] > dsr[1].
						// Since we are in a LTR pass and are pushing updates
						// forward, we are resolving it by updating dsr[1] as
						// well. There could be scenarios where this would be
						// incorrect, but there is no universal fix here.
						if ($siblingDP['dsr'][1] !== null && $newCE > $siblingDP['dsr'][1]) {
							$siblingDP['dsr'][1] = $newCE;
						}
						$newCE = $siblingDP['dsr'][1];

					} else {
						break;
					}
					$sibling = $sibling->nextSibling;
				}

				// Propagate new end information
				if (!$sibling) {
					$e = $newCE;
				}
			}
		}

		// Dont change state if we processed a fostered $node
		if ($fosteredNode) {
			$ce = $origCE;
		} else {
			// $ce for next $child = $cs of current $child
			$ce = $cs;

			// Save end-tag width from marker meta tag
			if ($endTagInfo && $child->previousSibling &&
				$endTagInfo['nodeName'] === $child->previousSibling->nodeName) {
				$savedEndTagWidth = $endTagInfo['width'];
			} else {
				$savedEndTagWidth = null;
			}
		}

		// No use for this marker tag after this->
		// Looks like DSR computation assumes that
		// these meta tags will be removed->
		if ($isMarkerTag) {
			// Collapse text $nodes to prevent n^2 effect in the LTR propagation pass
			// Example: enwiki:Colonization?oldid=718468597
			$nextChild = $child->nextSibling;
			if (DU::isText($prevChild) && DU::isText($nextChild)) {
				$prevText = $prevChild->nodeValue;
				$nextText = $nextChild->nodeValue;

				// Process prevText in place
				if ($ce !== null) {
					// indentPreDSRCorrection is not required here since
					// we'll never come down this branch (mw:TSRMarker won't exist
					// in indent-pres, and mw:EndTag markers won't have a text $node
					// for its previous sibling), but, for sake of maintenance sanity,
					// replicating code from above.
					$cs = $ce - mb_strlen($prevText) - DU::indentPreDSRCorrection($prevChild);
					$ce = $cs;
				}

				// Update DOM
				$newNode = $node->ownerDocument->createTextNode($prevText . $nextText);
				$node->replaceChild($newNode, $prevChild);
				$node->removeChild($nextChild);
				$prevChild = $newNode->previousSibling;
			}
			$node->removeChild($child);
		}

		$child = $prevChild;
	}

	if ($cs === null) {
		$cs = $s;
	}

	// Detect errors
	if ($s !== null && $cs !== $s && !acceptableInconsistency($opts, $node, $cs, $s)) {
		print "DSR inconsistency: cs/s mismatch for $node->nodeName, s: $s, cs: $cs\n";
		$env->log("warn/dsr/inconsistent", "DSR inconsistency: cs/s mismatch for node:",
			$node->nodeName, "s:", $s, "; cs:", $cs);
		/*
		*/
	}

	trace($env, "END: ", $node->nodeName, ", returning: ", $cs, ", ", $e);

	return [$cs, $e];
}

/**
 * Computes DSR ranges for every node of a DOM tree->
 *
 * @param {Object} rootNode
 *     The root of the tree for which DSR has to be computed
 *
 * @param {Object} env
 *     The environment/context for the parse pipeline
 *
 * @param {Object} options
 *     Options governing DSR computation
 *     sourceOffsets: [start, end] source offset. If missing, this defaults to
 *                    [0, env->page->src->length]
 *     attrExpansion: Is this an attribute expansion pipeline?
 */
function computeDSR($rootNode, $env, $options = []) {
	$startOffset = isset($options['sourceOffsets']) ? $options['sourceOffsets'][0] : 0;
	$endOffset = isset($options['sourceOffsets']) ? $options['sourceOffsets'][1] : mb_strlen($env->page->src);

/*
	$psd = $env->conf->parsoid;
	if ($psd->dumpFlags && $psd->dumpFlags->has("dom:pre-dsr")) {
		DU::dumpDOM($rootNode, 'DOM: pre-DSR');
	}
*/

	$env->log("trace/dsr", "------- tracing DSR computation -------");

	// The actual computation buried in trace/debug stmts.
	$opts = [ 'attrExpansion' => $options['attrExpansion'] ];
	computeNodeDSR($env, $rootNode, $startOffset, $endOffset, 0, $opts);

	$dp = DU::getDataParsoid($rootNode);
	$dp['dsr'] = [$startOffset, $endOffset, 0, 0];
	#print "DSR: " . $dp['dsr'][0] . ", " . $dp['dsr'][1] . "\n";
	DU::setDataParsoid( $rootNode, $dp );

	$env->log("trace/dsr", "------- done tracing computation -------");

/*
	if ($psd->dumpFlags && $psd->dumpFlags->has("dom:post-dsr")) {
		DU::dumpDOM($rootNode, 'DOM: post-DSR');
	}
*/
}
