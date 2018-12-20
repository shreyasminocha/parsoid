<?php

namespace Parsoid\Lib\Wt2Html\PP\Processors;

require_once __DIR__."/../../../config/WikitextConstants.php";
require_once __DIR__."/../../../utils/DU.php";
require_once __DIR__."/../../../utils/Utils.php";

use Parsoid\Lib\Config\WikitextConstants;
use Parsoid\Lib\Utils\DU;
use Parsoid\Lib\Utils\Util;

function lastItem($a) {
	return $a[count($a) - 1];
}

function getSrc($env, $s, $e) {
	// FIXME
	// return $env->page->src->substring($s, $e);
	return null; // Force a crash
}

function portingFIXME($section) {
	// FIXME: This is a temporary port-related hack while we
	// don't yet have all DOMDataUtils ported over to ensure
	// that new nodes have the empty data-parsoid just like the JS version.
	$section->setAttribute('data-parsoid', '{}');
}

function createNewSection($state, $rootNode, $sectionStack, $tplInfo, $currSection, $node, $newLevel, $pseudoSection) {
	/* Structure for regular (editable or not) sections
	 *   <section data-mw-section-id="..">
	 *     <h*>..</h*>
	 *     ..
	 *   </section>
	 *
	 * Lead sections and pseudo-sections won't have <h*> or <div> tags
	 */
	$section = [
		"level" => $newLevel,
		// useful during debugging, unrelated to the data-mw-section-id
		"debug_id" => $state["count"]++,
		"container" => $state["doc"]->createElement('section')
	];

	// console.log("NEW section @ " + $node->nodeName + "; level: "
	// 	+ $newLevel + "; pseudo: " + $pseudoSection + "; id: "+ section.debug_id);

	/* Step 1. Get section stack to the right nesting level
	 * 1a. Pop stack till we have a higher-level section.
	 */
	$stack = $sectionStack;
	while (count($stack) > 0 && $newLevel <= lastItem($stack)["level"]) {
		array_pop($stack);
	}

	/* 1b. Push current section onto stack if it is a higher-level section */
	if ($currSection && $newLevel > $currSection["level"]) {
		$stack[] = $currSection;
	}

	/* Step 2: Add new section where it belongs: a parent section OR body */
	$parentSection = count($stack) > 0 ? lastItem($stack) : null;
	if ($parentSection) {
		$parentSection["container"]->appendChild($section["container"]);
	} else {
		$rootNode->insertBefore($section["container"], $node);
	}

	/* Step 3: Add <h*> to the <section> */
	$section["container"]->appendChild($node);

	/* Step 4: Assign data-mw-section-id attribute
	 *
	 * CX wants <section> tags with a distinguishing attribute so that
	 * it can differentiate between its internal use of <section> tags
	 * with what Parsoid adds. So, we will add a data-mw-section-id
	 * attribute always.
	 *
	 * data-mw-section-id = 0 for the lead section
	 * data-mw-section-id = -1 for non-editable sections
	 *     Note that templated content cannot be edited directly.
	 * data-mw-section-id = -2 for pseudo sections
	 * data-mw-section-id > 0 for everything else and this number
	 *     matches PHP parser / Mediawiki's notion of that section.
	 *
	 * The code here handles uneditable sections because of templating.
	 */
	if ($pseudoSection) {
		$section["container"]->setAttribute('data-mw-section-id', -2);
	} else if ($state["inTemplate"]) {
		$section["container"]->setAttribute('data-mw-section-id', -1);
	} else {
		$section["container"]->setAttribute('data-mw-section-id', $state["sectionNumber"]);
	}

	portingFIXME($section["container"]);

	/* Ensure that template continuity is not broken if the section
	 * tags aren't stripped by a client */
	if ($tplInfo && $node !== $tplInfo["first"]) {
		$section["container"]->setAttribute('about', $tplInfo["about"]);
	}

	return $section;
}

function wrapSectionsInDOM($state, $currSection, $rootNode) {
	$tplInfo = null;
	$sectionStack = [];
	$highestSectionLevel = 7;
	$node = $rootNode->firstChild;
	while ($node) {
		$next = $node->nextSibling;
		$addedNode = false;

		// Track entry into templated output
		if (!$state["inTemplate"] && DU::isFirstEncapsulationWrapperNode($node)) {
			$about = $node->getAttribute("about");
			$state["inTemplate"] = true;
			$tplInfo = [
				"first" => $node,
				"about" => $about,
				"last" => lastItem(DU::getAboutSiblings($node, $about))
			];
		}

		if (preg_match('/^h[1-6]$/', $node->nodeName)) {
			$level = (int)$node->nodeName[1];

			// HTML <h*> tags don't get section numbers!
			if (!DU::isLiteralHTMLNode($node)) {
				$state["sectionNumber"]++;
				if ($level < $highestSectionLevel) {
					$highestSectionLevel = $level;
				}
				$currSection = createNewSection($state, $rootNode, $sectionStack, $tplInfo, $currSection, $node, $level, false);
				$addedNode = true;
			}
		} else if (DU::isElt($node)) {
			// If we find a higher level nested section,
			// (a) Make current section non-editable
			// (b) There are 2 $options here.
			//     Best illustrated with an example
			//     Consider the wiktiext below.
			//        <div>
			//        =1=
			//        b
			//        </div>
			//        c
			//        =2=
			//     1. Create a new pseudo-section to wrap '$node'
			//        There will be a <section> around the <div> which includes 'c'.
			//     2. Don't create the pseudo-section by setting '$currSection = null'
			//        But, this can leave some content outside any top-level section.
			//        'c' will not be in any section.
			//     The code below implements strategy 1.
			$nestedHighestSectionLevel = wrapSectionsInDOM($state, null, $node);
			if ($currSection && $nestedHighestSectionLevel <= $currSection["level"]) {
				$currSection["container"]->setAttribute('data-mw-section-id', -1);
				$currSection = createNewSection($state, $rootNode, $sectionStack, $tplInfo, $currSection, $node, $nestedHighestSectionLevel, true);
				$addedNode = true;
			}
		}

		if ($currSection && !$addedNode) {
			$currSection["container"]->appendChild($node);
		}

		if ($tplInfo && $tplInfo["first"] === $node) {
			$tplInfo["firstSection"] = $currSection;
		}

		// Track exit from templated output
		if ($tplInfo && $tplInfo["last"] === $node) {
			// The opening $node and closing $node of the template
			// are in different sections! This might require resolution.
			if ($currSection !== $tplInfo["firstSection"]) {
				$tplInfo["lastSection"] = $currSection;
				$state["tplsAndExtsToExamine"][] = $tplInfo;
			}

			$tplInfo = null;
			$state["inTemplate"] = false;
		}

		$node = $next;
	}

	// The last section embedded in a non-body DOM element
	// should always be marked non-editable since it will have
	// the closing tag (ex: </div>) showing up in the source editor
	// which we cannot support in a visual editing $environment.
	if ($currSection && !DU::isBody($rootNode)) {
		$currSection["container"]->setAttribute('data-mw-section-id', -1);
	}

	return $highestSectionLevel;
}

function getDSR($tplInfo, $node, $start) {
	if ($node->nodeName !== 'SECTION') {
		$dsr = DU::getDataParsoid($node)["dsr"] || DU::getDataParsoid($tplInfo["first"])["dsr"];
		return $start ? $dsr[0] : $dsr[1];
	}

	$offset = 0;
	$c = $start ? $node->firstChild : $node->lastChild;
	while ($c) {
		if (!DU::isElt($c)) {
			$offset += strlen($c->textContent);
		} else {
			return getDSR($tplInfo, $c, $start) + ($start ? -$offset : $offset);
		}
		$c = $start ? $c->nextSibling : $c->previousSibling;
	}

	return -1;
}

function resolveTplExtSectionConflicts($state) {
	foreach ($state["tplsAndExtsToExamine"] as $tplInfo) {
		$s1 = $tplInfo["firstSection"] && $tplInfo["firstSection"]["container"]; // could be undefined
		$s2 = $tplInfo["lastSection"]["container"]; // guaranteed to be non-null

		// Find a common ancestor of s1 and s2 (could be s1)
		$s2Ancestors = Util::makeMap(DU::pathToRoot($s2));
		$s1Ancestors = [];
		if ($s1) {
			for ($ancestor = $s1; !$s2Ancestors.has($ancestor); $ancestor = $ancestor->parentNode) {
				$s1Ancestors[] = $ancestor;
			}
			// ancestor is now the common ancestor of s1 and s2
			$s1Ancestors[] = $ancestor;
			$i = $s2Ancestors[$ancestor];
		}

		if (!$s1 || $ancestor === $s1) {
			// Scenario 1: s1 is s2's ancestor OR s1 doesn't exist.
			// In either case, s2 only covers part of the transcluded content.
			// But, s2 could also include content that follows the transclusion.
			// If so, append the content of the section after the last $node
			// to data-mw.parts.
			if ($tplInfo["last"]->nextSibling) {
				$newTplEndOffset = getDSR($tplInfo, $s2, false); // will succeed because it traverses non-tpl content
				$tplDsr = DU::getDataParsoid($tplInfo["first"])["dsr"];
				$tplEndOffset = $tplDsr[1];
				$dmw = DU::getDataMw($tplInfo["first"]);
				if (preg_match('/mw:Transclusion/', $tplInfo["first"]->getAttribute('typeof'))) {
					if ($dmw["parts"]) { $dmw["parts"][] = getSrc($state["env"], $tplEndOffset, $newTplEndOffset); }
				} else { /* Extension */
					// https://phabricator.wikimedia.org/T184779
					$dmw["extSuffix"] = getSrc($state["env"], $tplEndOffset, $newTplEndOffset);
				}
				// Update DSR
				$tplDsr[1] = $newTplEndOffset;

				// Set about attributes on all children of s2 - add span wrappers if required
				for ($n = $tplInfo["last"]->nextSibling; $n; $n = $n->nextSibling) {
					if (DU::isElt($n)) {
						$n->setAttribute('about', $tplInfo["about"]);
						$span = null;
					} else {
						if (!$span) {
							$span = $state["doc"]->createElement('span');
							$span->setAttribute('about', $tplInfo["about"]);
							$n->parentNode->replaceChild($span, $n);
						}
						$span->appendChild($n);
						$n = $span; // to ensure n->nextSibling is correct
					}
				}
			}
		} else {
			// Scenario 2: s1 and s2 are in different subtrees
			// Find children of the common ancestor that are on the
			// path from s1 -> ancestor and s2 -> ancestor
			$newS1 = $s1Ancestors[count($s1Ancestors) - 2]; // length >= 2 since we know ancestors != s1
			$newS2 = $s2Ancestors->item($i - 1); // i >= 1 since we know s2 is not s1's ancestor
			$newAbout = $state["env"]->newAboutId(); // new about id for the new wrapping layer

			// Ensure that all children from newS1 and newS2 have about attrs set
			for ($n = $newS1; $n !== $newS2->nextSibling; $n = $n->nextSibling) {
				$n->setAttribute('about', $newAbout);
			}

			// Update transclusion info
			$dsr1 = getDSR($tplInfo, $newS1, true); // will succeed because it traverses non-tpl content
			$dsr2 = getDSR($tplInfo, $newS2, false); // will succeed because it traverses non-tpl content
			$tplDP = DU::getDataParsoid($tplInfo["first"]);
			$tplDsr = $tplDP["dsr"];
			$dmw = Util::clone(DU::getDataMw($tplInfo["first"]));
			if (preg_match('/mw:Transclusion/', $tplInfo["first"]->getAttribute('typeof'))) {
				if ($dmw["parts"]) {
					array_unshift($dmw["parts"], getSrc($state["env"], $dsr1, $tplDsr[0]));
					$dmw["parts"][] = getSrc($state["env"], $tplDsr[1], $dsr2);
				}
				DU::setDataMw($newS1, $dmw);
				$newS1->setAttribute('typeof', 'mw:Transclusion');
				// Copy the template's parts-information object
				// which has white-space information for formatting
				// the transclusion and eliminates dirty-diffs.
				DU::setDataParsoid($newS1, [ "pi" => $tplDP["pi"], "dsr" => [ $dsr1, $dsr2 ] ]);
			} else { /* extension */
				// https://phabricator.wikimedia.org/T184779
				$dmw["extPrefix"] = getSrc($state["env"], $dsr1, $tplDsr[0]);
				$dmw["extSuffix"] = getSrc($state["env"], $tplDsr[1], $dsr2);
				DU::setDataMw($newS1, $dmw);
				$newS1->setAttribute('typeof', $tplInfo["first"]->getAttribute('typeof'));
				DU::setDataParsoid($newS1, [ "dsr" => [ $dsr1, $dsr2 ] ]);
			}
		}
	}
}

/**
 */
function wrapSections($rootNode, $env, $options) {
	if (!$env->wrapSections) {
		return;
	}

	/*
	if ($env.conf.parsoid.dumpFlags && $env.conf.parsoid.dumpFlags.has("dom:pre-sections")) {
		DU::dumpDOM($rootNode, 'DOM: before section wrapping');
	}
	*/

	$doc = $rootNode->ownerDocument;
	$leadSection = [
		"container" => $doc->createElement('section'),
		"debug_id" => 0,
		// lowest possible level since we don't want
		// any nesting of h-tags in the lead section
		"level" => 6,
		"lead" => true
	];
	$leadSection["container"]->setAttribute('data-mw-section-id', 0);
	portingFIXME($leadSection["container"]);

	// Global $state
	$state = [
		"env" => $env,
		"count" => 1,
		"doc" => $doc,
		"rootNode:" => $rootNode,
		"sectionNumber" => 0,
		"inTemplate" => false,
		"tplsAndExtsToExamine" => []
	];
	wrapSectionsInDOM($state, $leadSection, $rootNode);

	// There will always be a lead section, even if sometimes it only
	// contains whitespace + comments.
	$rootNode->insertBefore($leadSection["container"], $rootNode->firstChild);

	// Resolve template conflicts after all sections have been added to the DOM
	resolveTplExtSectionConflicts($state);
}
