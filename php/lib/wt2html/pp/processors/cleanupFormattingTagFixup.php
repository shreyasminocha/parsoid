<?php

namespace Parsoid\Lib\Wt2Html\PP\Processors;

require_once __DIR__."/../../../utils/DU.php";

use Parsoid\Lib\Utils\DU;

function cleanupFormattingTagFixup( $node, $env ) {
	$node = $node->firstChild;
	while ( $node !== null ) {
		if ( DU::isGeneratedFigure( $node ) ) {
			// Find path of formatting elements.
			// NOTE: <a> is a formatting elts as well and should be explicitly skipped
			$fpath = [];
			$c = $node->firstChild;
			while ( DU::isFormattingElt( $c ) && $c->nodeName !== 'a' && !$c->nextSibling ) {
				$fpath[] = $c;
				$c = $c->firstChild;
			}

			// Make sure that that we stopped at an A-tag and the last child is a caption
			$fpathHead = empty($fpath) ? null : $fpath[ 0 ];
			$fpathTail = empty($fpath) ? null : $fpath[ count( $fpath ) - 1 ];
			if ( $fpathHead && $fpathTail->firstChild->nodeName === 'a' ) {
				$anchor = $fpathTail->firstChild;
				$maybeCaption = $fpathTail->lastChild;

				// Fix up DOM appropriately
				$fig = $node;
				DU::migrateChildren( $fpathTail, $fig );
				if ($maybeCaption->$nodeName === 'figcaption') {
					DU::migrateChildren($maybeCaption, $fpathTail);
					$maybeCaption->appendChild($fpathHead);

					// For the formatting elements, if both the start and end tags
					// are auto-inserted, DSR algo will automatically ignore the tag.
					//
					// Otherwise, we need to clear out the TSR for DSR accuracy.
					// For simpler logic and code readability reasons, we are
					// unconditionally clearing out TSR for the formatting path that
					// got displaced from its original location so that DSR computation
					// can "recover properly" despite the extra wikitext chars
					// that interfere with it.
					foreach ( $fpath as $n ) {
						DU::getDataParsoid( $n )->tsr = null;
					};
				} else if ( $maybeCaption === $anchor ) {
					// NOTE: Probably don't need 'VIDEO' here since they aren't linked.
					#assert(['IMG', 'VIDEO']->includes($maybeCaption->firstChild->nodeName),
					#	'Expected first child of linked image to be an <img> tag->');
					// Delete the formatting elements since bolding/<small>-ing an image
					// is useless and doesn't make sense.
					while ( !empty( $fpath ) ) {
						DU::deleteNode( array_pop( $fpath ) );
					}
				}
			}
		} else if (DU::isElt( $node )) {
			cleanupFormattingTagFixup( $node, $env );
		}
		$node = $node->nextSibling;
	}
}
