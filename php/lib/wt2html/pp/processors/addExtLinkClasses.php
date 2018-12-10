<?php
namespace Parsoid\Lib\Wt2Html\PP\Processors;

require_once __DIR__."/../../../utils/DU.php";

use Parsoid\Lib\Utils\DU;

/**
 * Adds a new attribute name and value immediately after an
 * attribute specified in afterName. If afterName is not found
 * the new attribute is appended to the end of the list.
 */
function insertAfter($node, $afterName, $newName, $newVal) {
	// ensure existing attribute of $newName doesn't interfere
	// with desired positioning
	$node->removeAttribute($newName);
	$attributes = $node->attributes;
	// attempt to find the $afterName
	$where = 0;
	for (; $where < count($attributes); $where++) {
		if ($attributes[$where]->name === $afterName) {
			break;
		}
	}
	// if we found the $afterName key, then removing them from the DOM
	for ($i = $where + 1; $i < count($attributes); $i++) {
		$node->removeAttribute($attributes[$i]->name);
	}
	// add the new attribute
	$node->setAttribute($newName, $newVal);

	// add back all stored attributes that were temporarily removed
	for ($i = $where + 1; $i < count($attributes); $i++) {
		$node->setAttribute($attributes[$i]->name, $attributes[$i]->value);
	}
}

/**
 * Add class info to ExtLink information.
 * Currently positions the class immediately after the rel attribute
 * to keep tests stable.
 */
function addExtLinkClasses($env, $document) {
	$extLinks = $document->body->querySelectorAll('a[rel~="mw:ExtLink"]');
	foreach ($extLinks as $a) {
		$classInfoText = 'external autonumber';
		if ($a->firstChild) {
			$classInfoText = 'external text';
			// The "external free" class is reserved for links which
			// are syntactically unbracketed; see commit
			// 65fcb7a94528ea56d461b3c7b9cb4d4fe4e99211 in core.
			if (DU::usesURLLinkSyntax($a)) {
				$classInfoText = 'external free';
			} else if (DU::usesMagicLinkSyntax($a)) {
				// PHP uses specific suffixes for RFC/PMID/ISBN (the last of
				// which is an internal link, not an mw:ExtLink), but we'll
				// keep it simple since magic links are deprecated.
				$classInfoText = 'external mw-magiclink';
			}
		}

		insertAfter($a, 'rel', 'class', $classInfoText);
	});
}
