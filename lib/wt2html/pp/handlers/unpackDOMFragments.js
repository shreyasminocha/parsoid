/** @module */

'use strict';

var DU = require('../../../utils/DOMUtils.js').DOMUtils;
var Util = require('../../../utils/Util.js').Util;
var DOMTraverser = require('../../../utils/DOMTraverser.js').DOMTraverser;


function hasBadNesting(targetNode, fragment) {
	// SSS FIXME: This is not entirely correct. This is only
	// looking for nesting of identical tags. But, HTML tree building
	// has lot more restrictions on nesting. It seems the simplest way
	// to get all the rules right is to (serialize + reparse).

	function isNestableElement(nodeName) {
		// A-tags cannot ever be nested inside each other at any level.
		// This is the one scenario we definitely have to handle right now.
		// We need a generic robust solution for other nesting scenarios.
		return nodeName !== 'A';
	}

	return !isNestableElement(targetNode.nodeName) &&
		DU.treeHasElement(fragment, targetNode.nodeName);
}

function fixUpMisnestedTagDSR(targetNode, fragment, env) {
	// Currently, this only deals with A-tags
	if (targetNode.nodeName !== 'A') {
		return;
	}

	// Walk the fragment till you find an 'A' tag and
	// zero out DSR width for all tags from that point on.
	// This also requires adding span wrappers around
	// bare text from that point on.

	// QUICK FIX: Add wrappers unconditionally and strip unneeded ones
	// Since this scenario should be rare in practice, I am going to
	// go with this simple solution.
	DU.addSpanWrappers(fragment.childNodes);

	var resetDSR = false;
	var currOffset = 0;
	var dsrFixer = new DOMTraverser(env, true);
	var fixHandler = function(node) {
		if (DU.isElt(node)) {
			var dp = DU.getDataParsoid(node);
			if (node.nodeName === 'A') {
				resetDSR = true;
			}
			if (resetDSR) {
				if (dp.dsr && dp.dsr[0]) {
					currOffset = dp.dsr[1] = dp.dsr[0];
				} else {
					dp.dsr = [currOffset, currOffset];
				}
				dp.misnested = true;
			} else if (dp.tmp.wrapper) {
				// Unnecessary wrapper added above -- strip it.
				var next = node.firstChild || node.nextSibling;
				DU.migrateChildren(node, node.parentNode, node);
				DU.deleteNode(node);
				return next;
			}
		}
		return true;
	};
	dsrFixer.addHandler(null, fixHandler);
	dsrFixer.traverse(fragment.firstChild);
	fixHandler(fragment);
}

function addDeltaToDSR(node, delta) {
	// Add 'delta' to dsr[0] and dsr[1] for nodes in the subtree
	// node's dsr has already been updated
	var child = node.firstChild;
	while (child) {
		if (DU.isElt(child)) {
			var dp = DU.getDataParsoid(child);
			if (dp.dsr) {
				// SSS FIXME: We've exploited partial DSR information
				// in propagating DSR values across the DOM.  But, worth
				// revisiting at some point to see if we want to change this
				// so that either both or no value is present to eliminate these
				// kind of checks.
				//
				// Currently, it can happen that one or the other
				// value can be null.  So, we should try to udpate
				// the dsr value in such a scenario.
				if (typeof (dp.dsr[0]) === 'number') {
					dp.dsr[0] += delta;
				}
				if (typeof (dp.dsr[1]) === 'number') {
					dp.dsr[1] += delta;
				}
			}
			addDeltaToDSR(child, delta);
		}
		child = child.nextSibling;
	}
}

function fixAbouts(env, node, aboutIdMap) {
	var c = node.firstChild;
	while (c) {
		if (DU.isElt(c)) {
			var cAbout = c.getAttribute("about");
			if (cAbout) {
				// Update about
				var newAbout = aboutIdMap.get(cAbout);
				if (!newAbout) {
					newAbout = env.newAboutId();
					aboutIdMap.set(cAbout, newAbout);
				}
				c.setAttribute("about", newAbout);
			}

			fixAbouts(env, c, aboutIdMap);
		}

		c = c.nextSibling;
	}
}

function makeChildrenEncapWrappers(node, about) {
	DU.addSpanWrappers(node.childNodes);

	var c = node.firstChild;
	while (c) {
		// FIXME: This unconditionally sets about on children
		// This is currently safe since all of them are nested
		// inside a transclusion, but do we need future-proofing?
		c.setAttribute("about", about);
		c = c.nextSibling;
	}
}

/**
 * DOMTraverser handler that unpacks DOM fragments which were injected in the
 * token pipeline.
 * @param {Node} node
 * @param {MWParserEnvironment} env
 */
function unpackDOMFragments(node, env) {
	if (!DU.isElt(node)) { return true; }

	var typeOf = node.getAttribute('typeof');

	// sealed fragments shouldn't make it past this point
	if (!/(?:^|\s)mw:DOMFragment(?=$|\s)/.test(typeOf)) { return true; }

	var about = node.getAttribute('about');
	var dp = DU.getDataParsoid(node);

	// Replace this node and possibly a sibling with node.dp.html
	var fragmentParent = node.parentNode;
	var dummyNode = node.ownerDocument.createElement(fragmentParent.nodeName);

	console.assert(/^mwf/.test(dp.html));

	var nodes = env.fragmentMap.get(dp.html);
	var n0dp = DU.getDataParsoid(nodes[0]);

	if (n0dp.tmp && n0dp.tmp.isHtmlExt) {
		// FIXME: This is a silly workaround for foundationwiki which has the
		// "html" extension tag which lets through arbitrary content and
		// often does so in a way that doesn't consider that we'd like to
		// encapsulate it.  For example, it closes the tag in the middle
		// of style tag content to insert a template and then closes the style
		// tag in another "html" extension tag.  The balance proposal isn't
		// its friend.
		//
		// This works because importNode does attribute error checking, whereas
		// parsing does not.  A better fix would be to use one ownerDocument
		// for the entire parse, so no adoption is needed.  See T179082
		var html = nodes.map(n => DU.toXML(n)).join('');
		DU.ppToDOM(html, { node: dummyNode });
	} else {
		nodes.forEach(function(n) {
			var imp = dummyNode.ownerDocument.importNode(n, true);
			dummyNode.appendChild(imp);
		});
		DU.visitDOM(dummyNode, DU.loadDataAttribs);
	}

	var contentNode = dummyNode.firstChild;

	if (/(?:^|\s)mw:Transclusion(?=$|\s)/.test(typeOf)) {
		// Transfer typeof, data-mw, and param info
		DU.setDataMw(contentNode, Util.clone(DU.getDataMw(node)));
		DU.addTypeOf(contentNode, "mw:Transclusion");
		DU.getDataParsoid(contentNode).pi = dp.pi;
	}

	// Update DSR:
	//
	// - Only update DSR for content that came from varnish/cache.
	// - For new DOM fragments from this pipeline,
	//   previously-computed DSR is valid.
	// - EXCEPTION: fostered content from tables get their DSR reset
	//   to zero-width.
	//
	// There is currently no DSR for DOMFragments nested inside
	// transclusion / extension content (extension inside template
	// content etc).
	// TODO: Make sure that is the only reason for not having a DSR here.
	var dsr = dp.dsr;
	if (dsr && (dp.tmp.setDSR || dp.fostered)) {
		var type = contentNode.getAttribute('typeof');
		var cnDP = DU.getDataParsoid(contentNode);
		if (/(?:^|\s)mw:(Transclusion|Extension)(?=$|\s)/.test(type)) {
			cnDP.dsr = [dsr[0], dsr[1]];
		} else { // non-transcluded images
			cnDP.dsr = [dsr[0], dsr[1], 2, 2];
			// Reused image -- update dsr by tsrDelta on all
			// descendents of 'firstChild' which is the <figure> tag
			var tsrDelta = dp.tmp.tsrDelta;
			if (tsrDelta) {
				addDeltaToDSR(contentNode, tsrDelta);
			}
		}
	}

	var n;

	if (dp.tmp.isForeignContent) {
		// Foreign Content = Transclusion and Extension content
		//
		// Set about-id always to ensure the unwrapped node
		// is recognized as encapsulated content as well.
		n = dummyNode.firstChild;
		while (n) {
			if (DU.isElt(n)) {
				n.setAttribute("about", about);
			}
			n = n.nextSibling;
		}
	} else {
		// Replace old about-id with new about-id that is
		// unique to the global page environment object.
		//
		// <figure>s are reused from cache. Note that figure captions
		// can contain multiple independent transclusions. Each one
		// of those individual transclusions should get a new unique
		// about id. Hence a need for an aboutIdMap and the need to
		// walk the entire tree.

		fixAbouts(env, dummyNode, new Map());

		// Discard unnecessary span wrappers
		n = dummyNode.firstChild;
		while (n) {
			var next = n.nextSibling;

			// Preserve wrappers that have an about id
			if (DU.isElt(n) && !n.getAttribute('about')) {
				if (DU.getDataParsoid(n).tmp.wrapper) {
					DU.migrateChildren(n, n.parentNode, n);
					DU.deleteNode(n);
				}
			}

			n = next;
		}
	}

	var nextNode = node.nextSibling;

	if (hasBadNesting(fragmentParent, dummyNode)) {
		/* -----------------------------------------------------------------------
		 * If fragmentParent is an A element and the fragment contains another
		 * A element, we have an invalid nesting of A elements and needs fixing up
		 *
		 * doc1: ... fragmentParent -> [... dummyNode=mw:DOMFragment, ...] ...
		 *
		 * 1. Change doc1:fragmentParent -> [... "#unique-hash-code", ...] by replacing
		 *    node with the "#unique-hash-code" text string
		 *
		 * 2. str = parentHTML.replace(#unique-hash-code, dummyHTML)
		 *    We now have a HTML string with the bad nesting. We will now use the HTML5
		 *    parser to parse this HTML string and give us the fixed up DOM
		 *
		 * 3. ParseHTML(str) to get
		 *    doc2: [BODY -> [[fragmentParent -> [...], nested-A-tag-from-dummyNode, ...]]]
		 *
		 * 4. Replace doc1:fragmentParent with doc2:body.childNodes
		 * ----------------------------------------------------------------------- */
		var timestamp = (Date.now()).toString();
		fragmentParent.replaceChild(node.ownerDocument.createTextNode(timestamp), node);

		// If fragmentParent has an about, it presumably is nested inside a template
		// Post fixup, its children will surface to the encapsulation wrapper level.
		// So, we have to fix them up so they dont break the encapsulation.
		//
		// Ex: {{echo|[http://foo.com This is [[bad]], very bad]}}
		//
		// In this example, the <a> corresponding to Foo is fragmentParent and has an about.
		// dummyNode is the DOM corresponding to "This is [[bad]], very bad". Post-fixup
		// "[[bad]], very bad" are at encapsulation level and need about ids.
		about = fragmentParent.getAttribute("about");
		if (about !== null) {
			makeChildrenEncapWrappers(dummyNode, about);
		}

		// Set zero-dsr width on all elements that will get split
		// in dummyNode's tree to prevent selser-based corruption
		// on edits to a page that contains badly nested tags.
		fixUpMisnestedTagDSR(fragmentParent, dummyNode, env);

		var dummyHTML = DU.ppToXML(dummyNode, {
			innerXML: true,
			// We just added some span wrappers and we need to keep
			// that tmp info so the unnecessary ones get stripped.
			// Should be fine since tmp was stripped before packing.
			keepTmp: true,
		});
		var parentHTML = DU.ppToXML(fragmentParent);

		var p = fragmentParent.previousSibling;

		// We rely on HTML5 parser to fixup the bad nesting (see big comment above)
		var newDoc = DU.parseHTML(parentHTML.replace(timestamp, dummyHTML));
		DU.migrateChildrenBetweenDocs(newDoc.body, fragmentParent.parentNode, fragmentParent);

		if (!p) {
			p = fragmentParent.parentNode.firstChild;
		} else {
			p = p.nextSibling;
		}

		while (p !== fragmentParent) {
			DU.visitDOM(p, DU.loadDataAttribs);
			p = p.nextSibling;
		}

		// Set nextNode to the previous-sibling of former fragmentParent (which will get deleted)
		// This will ensure that all nodes will get handled
		nextNode = fragmentParent.previousSibling;

		// fragmentParent itself is useless now
		DU.deleteNode(fragmentParent);
	} else {
		// Move the content nodes over and delete the placeholder node
		DU.migrateChildren(dummyNode, fragmentParent, node);
		DU.deleteNode(node);
	}

	return nextNode;
}

if (typeof module === "object") {
	module.exports.unpackDOMFragments = unpackDOMFragments;
}
