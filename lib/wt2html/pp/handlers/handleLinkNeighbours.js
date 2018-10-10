/** @module */

'use strict';

require('../../../../core-upgrade.js');

var DU = require('../../../utils/DOMUtils.js').DOMUtils;


var findAndHandleNeighbour; // forward declaration

/**
 * Function for fetching the link prefix based on a link node.
 *
 * The content will be reversed, so be ready for that.
 * @private
 */
function getLinkPrefix(env, node) {
	var baseAbout = null;
	var regex = env.conf.wiki.linkPrefixRegex;

	if (!regex) {
		return null;
	}

	if (node !== null && DU.hasParsoidAboutId(node)) {
		baseAbout = node.getAttribute('about');
	}

	node = node === null ? node : node.previousSibling;
	return findAndHandleNeighbour(env, false, regex, node, baseAbout);
}

/**
 * Function for fetching the link trail based on a link node.
 * @private
 */
function getLinkTrail(env, node) {
	var baseAbout = null;
	var regex = env.conf.wiki.linkTrailRegex;

	if (!regex) {
		return null;
	}

	if (node !== null && DU.hasParsoidAboutId(node)) {
		baseAbout = node.getAttribute('about');
	}

	node = node === null ? node : node.nextSibling;
	return findAndHandleNeighbour(env, true, regex, node, baseAbout);
}

/**
 * Abstraction of both link-prefix and link-trail searches.
 * @private
 */
findAndHandleNeighbour = function(env, goForward, regex, node, baseAbout) {
	var value;
	var nextNode = goForward ? 'nextSibling' : 'previousSibling';
	var innerNode = goForward ? 'firstChild' : 'lastChild';
	var getInnerNeighbour = goForward ? getLinkTrail : getLinkPrefix;
	var result = { content: [], src: '' };

	while (node !== null) {
		var nextSibling = node[nextNode];
		var document = node.ownerDocument;

		if (DU.isText(node)) {
			var matches = node.nodeValue.match(regex);
			value = { content: node, src: node.nodeValue };
			if (matches !== null) {
				value.src = matches[0];
				if (value.src === node.nodeValue) {
					// entire node matches linkprefix/trail
					value.content = node;
					DU.deleteNode(node);
				} else {
					// part of node matches linkprefix/trail
					value.content = document.createTextNode(matches[0]);
					node.parentNode.replaceChild(document.createTextNode(node.nodeValue.replace(regex, '')), node);
				}
			} else {
				value.content = null;
				break;
			}
		} else if (DU.hasParsoidAboutId(node) &&
				baseAbout !== '' && baseAbout !== null &&
				node.getAttribute('about') === baseAbout) {
			value = getInnerNeighbour(env, node[innerNode]);
		} else {
			break;
		}

		if (value.content !== null) {
			if (value.content instanceof Array) {
				result.content = result.content.concat(value.content);
			} else {
				result.content.push(value.content);
			}

			if (goForward) {
				result.src += value.src;
			} else {
				result.src = value.src + result.src;
			}

			if (value.src !== node.nodeValue) {
				break;
			}
		} else {
			break;
		}
		node = nextSibling;
	}

	return result;
};

/**
 * Workhorse function for bringing linktrails and link prefixes into link content.
 * NOTE that this function mutates the node's siblings on either side.
 */
function handleLinkNeighbours(node, env) {
	var rel = node.getAttribute('rel');
	if (!/^mw:WikiLink(\/Interwiki)?$/.test(rel)) {
		return true;
	}

	var dp = DU.getDataParsoid(node);
	var ix, dataMW;
	var prefix = getLinkPrefix(env, node);
	var trail = getLinkTrail(env, node);

	if (prefix && prefix.content) {
		for (ix = 0; ix < prefix.content.length; ix++) {
			node.insertBefore(prefix.content[ix], node.firstChild);
		}
		if (prefix.src.length > 0) {
			dp.prefix = prefix.src;
			if (/(?:^|\s)mw:Transclusion(?:\s|$)/.test(node.getAttribute('typeof'))) {
				// only necessary if we're the first
				dataMW = DU.getDataMw(node);
				if (dataMW.parts) { dataMW.parts.unshift(prefix.src); }
			}
			if (dp.dsr) {
				dp.dsr[0] -= prefix.src.length;
				dp.dsr[2] += prefix.src.length;
			}
		}
	}

	if (trail && trail.content && trail.content.length) {
		for (ix = 0; ix < trail.content.length; ix++) {
			node.appendChild(trail.content[ix]);
		}
		if (trail.src.length > 0) {
			dp.tail = trail.src;
			var about = node.getAttribute('about');
			if (DU.hasParsoidAboutId(node) &&
				DU.getAboutSiblings(node, about).length === 1
			) {
				// search back for the first wrapper but
				// only if we're the last. otherwise can assume
				// template encapsulation will handle it
				var wrapper = DU.findFirstEncapsulationWrapperNode(node);
				if (wrapper !== null &&
						/(?:^|\s)mw:Transclusion(?:\s|$)/.test(wrapper.getAttribute('typeof'))) {
					dataMW = DU.getDataMw(wrapper);
					if (dataMW.parts) { dataMW.parts.push(trail.src); }
				}
			}
			if (dp.dsr) {
				dp.dsr[1] += trail.src.length;
				dp.dsr[3] += trail.src.length;
			}
		}
		// indicate that the node's tail siblings have been consumed
		return node;
	} else {
		return true;
	}
}

if (typeof module === "object") {
	module.exports.handleLinkNeighbours = handleLinkNeighbours;
}
