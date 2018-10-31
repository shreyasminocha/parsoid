/**
 * This file contains parsing pipeline related utilities.
 *
 * @module
 */

'use strict';

require('../../core-upgrade.js');

var JSUtils = require('./jsutils.js').JSUtils;
var Promise = require('./promise.js');
var pd = require('../wt2html/parser.defines.js');
var DU = require('./DOMUtils.js').DOMUtils;
var Util = require('./Util.js').Util;
var TokenUtils = require('./TokenUtils.js').TokenUtils;

/**
 * @namespace
 */
var PipelineUtils = {
	/**
	 * Creates a dom-fragment-token for processing 'content' (an array of tokens)
	 * in its own subpipeline all the way to DOM. These tokens will be processed
	 * by their own handler (DOMFragmentBuilder) in the last stage of the async
	 * pipeline.
	 *
	 * srcOffsets should always be provided to process top-level page content in a
	 * subpipeline. Without it, DSR computation and template wrapping cannot be done
	 * in the subpipeline. While unpackDOMFragment can do this on unwrapping, that can
	 * be a bit fragile and makes dom-fragments a leaky abstraction by leaking subpipeline
	 * processing into the top-level pipeline.
	 *
	 * @param {Token[]} content
	 *   The array of tokens to process.
	 * @param {number[]} srcOffsets
	 *   Wikitext source offsets (start/end) of these tokens.
	 * @param {Object} [opts]
	 *   Parsing options.
	 * @param {Token} opts.contextTok
	 *   The token that generated the content.
	 * @param {boolean} opts.inlineContext
	 *   Is this DOM fragment used in an inline context?
	 * @param {boolean} opts.inPHPBlock
	 *   Is this DOM fragment used inside a "PHP Block"
	 *   FIXME: This primarily exists for backward compatibility
	 *   reasons and is likely to eventually go away.
	 */
	getDOMFragmentToken: function(content, srcOffsets, opts) {
		if (!opts) {
			opts = {};
		}

		return new pd.SelfclosingTagTk('mw:dom-fragment-token', [
			new pd.KV('contextTok', opts.token),
			new pd.KV('content', content),
			new pd.KV('inlineContext',  opts.inlineContext || false),
			new pd.KV('inPHPBLock',  opts.inPHPBLock || false),
			new pd.KV('srcOffsets', srcOffsets),
		]);
	},

	/**
	 * Processes content (wikitext, array of tokens, whatever) in its own pipeline
	 * based on options.
	 *
	 * @param {Object} env
	 *    The environment/context for the expansion.
	 *
	 * @param {Object} frame
	 *    The parent frame within which the expansion is taking place.
	 *    This param is mostly defunct now that we are not doing native
	 *    expansion anymore.
	 *
	 * @param {Object} content
	 *    This could be wikitext or single token or an array of tokens.
	 *    How this content is processed depends on what kind of pipeline
	 *    is constructed specified by opts.
	 *
	 * @param {Object} opts
	 *    Processing options that specify pipeline-type, opts, and callbacks.
	 */
	processContentInPipeline: function(env, frame, content, opts) {
		// Build a pipeline
		var pipeline = env.pipelineFactory.getPipeline(
			opts.pipelineType,
			opts.pipelineOpts
		);

		// Set frame if necessary
		if (opts.tplArgs) {
			pipeline.setFrame(frame, opts.tplArgs.name, opts.tplArgs.attribs);
		} else {
			pipeline.setFrame(frame, null, []);
		}

		// Set source offsets for this pipeline's content
		if (opts.srcOffsets) {
			pipeline.setSourceOffsets(opts.srcOffsets[0], opts.srcOffsets[1]);
		}

		// Set up provided callbacks
		if (opts.chunkCB) {
			pipeline.addListener('chunk', opts.chunkCB);
		}
		if (opts.endCB) {
			pipeline.addListener('end', opts.endCB);
		}
		if (opts.documentCB) {
			pipeline.addListener('document', opts.documentCB);
		}

		// Off the starting block ... ready, set, go!
		pipeline.process(content);
	},

	/**
	 * A promise returning wrapper around processContentInPipeline that
	 * resolves with the docuemnt.
	 * @return {Promise<Document>}
	 */
	promiseToProcessContent: function(env, frame, content, opts, cb) {
		cb = JSUtils.mkPromised(cb);
		PipelineUtils.processContentInPipeline(env, frame, content, {
			pipelineType: opts.pipelineType,
			pipelineOpts: opts.pipelineOpts,
			srcOffsets: opts ? opts.srcOffsets : undefined,
			// processContentInPipeline has no error callback :(
			documentCB: function(dom) { cb(null, dom); },
		});
		return cb.promise;
	},

	/**
	 * Expands values all the way to DOM and passes them back to a callback.
	 *
	 * FIXME: More of the users of `PipelineUtils.promiseToProcessContent` and
	 * `PipelineUtils.processContentInPipeline` could be converted to use this method
	 * if we could find a good way to abstract the different use cases.
	 *
	 * @param {Object} env
	 *    The environment/context for the expansion.
	 *
	 * @param {Object} frame
	 *    The parent frame within which the expansion is taking place.
	 *    This param is mostly defunct now that we are not doing native
	 *    expansion anymore.
	 *
	 * @param {Object[]} vals
	 *    The array of values to process.
	 *    Each value of this array is expected to be an object with a "html" property.
	 *    The html property is expanded to DOM only if it is an array (of tokens).
	 *    Non-arrays are passed back unexpanded.
	 *
	 * @param {boolean} expandTemplates
	 *    Should any templates encountered here be expanded
	 *    (usually false for nested templates since they are never directly editable).
	 *
	 * @param {boolean} inTemplate
	 *    Unexpanded templates can occur in the content of extension tags.
	 *
	 * @param {Function} [finalCB]
	 *    The (optional) callback to pass the expanded values into.
	 *
	 * @return {Promise}
	 *    A promise that will be resolved with the expanded values.
	 */
	expandValuesToDOM: function(env, frame, vals, expandTemplates, inTemplate, finalCB) {
		return Promise.all(vals.map(Promise.async(function *(v) {
			if (Array.isArray(v.html)) {
				// Set up pipeline options
				var opts = {
					pipelineType: 'tokens/x-mediawiki/expanded',
					pipelineOpts: {
						attrExpansion: true,
						inlineContext: true,
						expandTemplates: expandTemplates,
						inTemplate: inTemplate,
					},
				};
				var content = v.html.concat([new pd.EOFTk()]);
				try {
					var dom = yield PipelineUtils.promiseToProcessContent(
						env, frame, content, opts
					);
					// Since we aren't at the top level, data attrs
					// were not applied in cleanup.  However, tmp
					// was stripped.
					v.html = DU.ppToXML(dom.body, { innerXML: true });
				} catch (err) {
					env.log('error', 'Expanding values to DOM', err);
				}
			}
			return v;
		}))).nodify(finalCB);
	},

	/**
	 * @param {Token[]} tokBuf This is where the tokens get stored.
	 */
	convertDOMtoTokens: function(tokBuf, node) {
		function domAttrsToTagAttrs(attrs) {
			var out = [];
			for (var j = 0, m = attrs.length; j < m; j++) {
				var a = attrs.item(j);
				out.push(new pd.KV(a.name, a.value));
			}
			return { attrs: out, dataAttrs: DU.getDataParsoid(node) };
		}

		switch (node.nodeType) {
			case node.ELEMENT_NODE:
				var nodeName = node.nodeName.toLowerCase();
				var attrInfo = domAttrsToTagAttrs(node.attributes);

				if (Util.isVoidElement(nodeName)) {
					tokBuf.push(new pd.SelfclosingTagTk(nodeName, attrInfo.attrs, attrInfo.dataAttrs));
				} else {
					tokBuf.push(new pd.TagTk(nodeName, attrInfo.attrs, attrInfo.dataAttrs));
					for (var child = node.firstChild; child; child = child.nextSibling) {
						tokBuf = PipelineUtils.convertDOMtoTokens(tokBuf, child);
					}
					var endTag = new pd.EndTagTk(nodeName);
					// Keep stx parity
					if (DU.isLiteralHTMLNode(node)) {
						endTag.dataAttribs = { 'stx': 'html' };
					}
					tokBuf.push(endTag);
				}
				break;

			case node.TEXT_NODE:
				tokBuf = tokBuf.concat(TokenUtils.newlinesToNlTks(node.nodeValue));
				break;

			case node.COMMENT_NODE:
				tokBuf.push(new pd.CommentTk(node.nodeValue));
				break;

			default:
				console.warn("Unhandled node type: " + node.outerHTML);
				break;
		}
		return tokBuf;
	},

	/**
	 * Get tokens representing a DOM forest (from transclusions, extensions,
	 * whatever that were generated as part of a separate processing pipeline)
	 * in the token stream. These tokens will tunnel the subtree through the
	 * token processing while preserving token stream semantics as if
	 * the DOM had been converted to tokens.
	 *
	 * @param {Node[]} nodes List of DOM nodes that need to be tunneled through.
	 * @param {Object} opts The pipeline opts that generated the DOM.
	 * @return {Array} List of token representatives.
	 */
	getWrapperTokens: function(nodes, opts) {
		var node = nodes[0];
		console.assert(DU.isElt(node),
			'Non-element nodes are expected to be span wrapped.');

		// Do we represent this with inline or block elements?
		// This is to ensure that we get p-wrapping correct.
		//
		// * If all content is inline, we use inline-elements to represent this
		//   so that this content gets swallowed into the P tag that wraps
		//   adjacent inline content.
		//
		// * If any part of this is a block content, we treat extension content
		//   independent of surrounding content and don't want inline content
		//   here to be swallowed into a P tag that wraps adjacent inline content.
		//
		// This behavior ensures that we and clients can "drop-in" extension content
		// into the DOM without messing with fixing up paragraph tags of surrounding
		// content. It could potentially introduce minor rendering differences when
		// compared to PHP parser output, but we'll swallow it for now.
		var wrapperType = 'INLINE';
		if (opts.inlineContext || opts.inPHPBlock) {
			// If the DOM fragment is being processed in the context where P wrapping
			// has been suppressed, we represent the DOM fragment with inline-tokens.
			//
			// FIXME(SSS): Looks like we have some "impedance mismatch" here. But, this
			// is correct in scenarios where link-content or image-captions are being
			// processed in a sub-pipeline and we don't want a <div> in the link-caption
			// to cause the <a>..</a> to get split apart.
		} else if (opts.unwrapFragment === false) {
			// Sealed fragments aren't amenable to inspection, since the
			// ultimate content is unknown.  For example, refs shuttle content
			// through treebuilding that ends up in the references list.
			//
			// FIXME(arlolra): Do we need a mechanism to specify content
			// categories?
		} else {
			for (var i = 0; i < nodes.length; i++) {
				if (DU.isBlockNode(nodes[i]) || DU.hasBlockElementDescendant(nodes[i])) {
					wrapperType = 'BLOCK';
					break;
				}
			}
		}

		var wrapperName;
		if (wrapperType === 'BLOCK' && !DU.isBlockNode(node)) {
			wrapperName = 'DIV';
		} else if (node.nodeName === 'A') {
			// Do not use 'A' as a wrapper node because it could
			// end up getting nested inside another 'A' and the DOM
			// structure can change where the wrapper tokens are no
			// longer siblings.
			// Ex: "[http://foo.com Bad nesting [[Here]]].
			wrapperName = 'SPAN';
		} else if (['STYLE', 'SCRIPT'].includes(node.nodeName) && nodes.length > 1) {
			// <style>/<script> tags are not fostered, so if we're wrapping
			// more than a single node, they aren't a good representation for
			// the content.  It can lead to fosterable content being inserted
			// in a fosterable position after treebuilding is done, which isn't
			// roundtrippable.
			wrapperName = 'SPAN';
		} else {
			wrapperName = node.nodeName;
		}

		var workNode;
		if (node.hasChildNodes() || wrapperName !== node.nodeName) {
			// Create a copy of the node without children
			workNode = node.ownerDocument.createElement(wrapperName);
			// copy over attributes
			for (var j = 0; j < node.attributes.length; j++) {
				var attribute = node.attributes.item(j);
				if (attribute.name !== 'typeof') {
					workNode.setAttribute(attribute.name, attribute.value);
				}
			}
			// dataAttribs are not copied over so that we don't inject
			// broken tsr or dsr values. This also lets these tokens pass
			// through the sanitizer as stx.html is not set.
		} else {
			workNode = node;
		}

		var tokens = [];
		PipelineUtils.convertDOMtoTokens(tokens, workNode);

		// Remove the typeof attribute from the first token. It will be
		// replaced with mw:DOMFragment.
		tokens[0].removeAttribute('typeof');

		return tokens;
	},

	/**
	 * Generates wrapper tokens for a HTML expansion -- the wrapper
	 * tokens are placeholders that adequately represent semantics
	 * of the HTML DOM for the purposes of additional token transformations
	 * that will be applied to them.
	 *
	 * @param {MWParserEnvironment} env
	 *    The active environment/context.
	 *
	 * @param {Token} token
	 *    The token that generated the DOM.
	 *
	 * @param {Object} expansion
	 * @param {string} expansion.html
	 *    HTML of the expansion.
	 * @param {Node[]} expansion.nodes
	 *    Outermost nodes of the HTML.
	 *
	 * @param {Object} [opts]
	 * @param {string} opts.aboutId
	 *    The about-id to set on the generated tokens.
	 * @param {boolean} opts.noAboutId
	 *    If true, an about-id will not be added to the tokens
	 *    if an aboutId is not provided.
	 *    For example: `<figure>`
	 * @param {Object} opts.tsr
	 *    The TSR to set on the generated tokens. This TSR is
	 *    used to compute DSR on the placeholder tokens.
	 *    The computed DSR is transferred over to the unpacked DOM
	 *    if setDSR is true (see below).
	 * @param {boolean} opts.setDSR
	 *    When the DOM fragment is unpacked, this option governs
	 *    whether the DSR from the placeholder node is transferred
	 *    over to the unpacked DOM or not.
	 *    For example: Cite, reused transclusions.
	 * @param {boolean} opts.isForeignContent
	 *    Does the DOM come from outside the main page? This governs
	 *    how the encapsulation ids are assigned to the unpacked DOM.
	 *    For example: transclusions, extensions -- all siblings get the same
	 *    about id. This is not true for `<figure>` HTML.
	 */
	encapsulateExpansionHTML: function(env, token, expansion, opts) {
		opts = opts || {};

		// Get placeholder tokens to get our subdom through the token processing
		// stages. These will be finally unwrapped on the DOM.
		var toks = PipelineUtils.getWrapperTokens(expansion.nodes, opts);
		var firstWrapperToken = toks[0];

		// Add the DOMFragment type so that we get unwrapped later.
		firstWrapperToken.setAttribute('typeof', 'mw:DOMFragment' + (opts.unwrapFragment === false ? '/sealed/' + opts.wrapperName : ''));

		// Assign the HTML fragment to the data-parsoid.html on the first wrapper token.
		firstWrapperToken.dataAttribs.html = expansion.html;

		// Set foreign content flag.
		if (opts.isForeignContent) {
			if (!firstWrapperToken.dataAttribs.tmp) {
				firstWrapperToken.dataAttribs.tmp = {};
			}
			firstWrapperToken.dataAttribs.tmp.isForeignContent = true;
		}

		// Pass through setDSR flag
		if (opts.setDSR) {
			if (!firstWrapperToken.dataAttribs.tmp) {
				firstWrapperToken.dataAttribs.tmp = {};
			}
			firstWrapperToken.dataAttribs.tmp.setDSR = opts.setDSR;
		}

		// Add about to all wrapper tokens, if necessary.
		var about = opts.aboutId;
		if (!about && !opts.noAboutId) {
			about = env.newAboutId();
		}
		if (about) {
			toks.forEach(function(tok) {
				tok.setAttribute('about', about);
			});
		}

		// Transfer the tsr.
		// The first token gets the full width, the following tokens zero width.
		var tokenTsr = opts.tsr || (token.dataAttribs ? token.dataAttribs.tsr : null);
		if (tokenTsr) {
			firstWrapperToken.dataAttribs.tsr = tokenTsr;
			firstWrapperToken.dataAttribs.tagWidths = token.dataAttribs ? token.dataAttribs.tagWidths : null;
			var endTsr = [tokenTsr[1], tokenTsr[1]];
			for (var i = 1; i < toks.length; i++) {
				toks[i].dataAttribs.tsr = endTsr;
			}
		}

		return toks;
	},

	/**
	 * Convert a HTML5 DOM into a mw:DOMFragment and generate appropriate
	 * tokens to insert into the token stream for further processing.
	 *
	 * The DOMPostProcessor will unpack the fragment and insert the HTML
	 * back into the DOM.
	 *
	 * @param {MWParserEnvironment} env
	 *    The active environment/context.
	 *
	 * @param {Token} token
	 *    The token that generated the DOM.
	 *
	 * @param {Node} body
	 *    The DOM that the token expanded to.
	 *
	 * @param {Function} addAttrsCB
	 *    Callback that adds additional attributes to the generated tokens.
	 *
	 * @param {Object} opts
	 *    Options to be passed onto the encapsulation code
	 *    See encapsulateExpansionHTML's doc. for more info about these options.
	 */
	buildDOMFragmentTokens: function(env, token, body, addAttrsCB, opts) {
		console.assert(DU.isBody(body), 'DOMFragment expected body node.');

		var nodes;
		if (body.hasChildNodes()) {
			nodes = body.childNodes;
		} else {
			// RT extensions expanding to nothing.
			nodes = [body.ownerDocument.createElement('link')];
		}

		// Wrap bare text nodes into spans
		nodes = DU.addSpanWrappers(nodes);

		if (addAttrsCB) {
			addAttrsCB(nodes[0]);
		}

		// Get placeholder tokens to get our subdom through the token processing
		// stages. These will be finally unwrapped on the DOM.
		var expansion = PipelineUtils.makeExpansion(env, nodes);
		return PipelineUtils.encapsulateExpansionHTML(env, token, expansion, opts);
	},

	makeExpansion: function(env, nodes) {
		nodes.forEach(function(n) {
			// The nodes have been through post-processing and,
			// therefore, had their tmp data stripped.  However,
			// we just added tmp info in the span wrapping above,
			// so keep it; it's necessary and safe.
			DU.visitDOM(n, DU.storeDataAttribs, { keepTmp: true });
		});
		return { nodes: nodes, html: env.setFragment(nodes) };
	},

	/**
	 * Extract transclusion and extension expansions from a DOM, and return
	 * them in a structure like this:
	 * ```
	 *     {
	 *         transclusions: {
	 *             'key1': {
	 *                  html: 'html1',
	 *                  nodes: [<node1>, <node2>]
	 *             }
	 *         },
	 *         extensions: {
	 *             'key2': {
	 *                  html: 'html2',
	 *                  nodes: [<node1>, <node2>]
	 *             }
	 *         },
	 *         files: {
	 *             'key3': {
	 *                  html: 'html3',
	 *                  nodes: [<node1>, <node2>]
	 *             }
	 *         }
	 *     }
	 * ```
	 */
	extractExpansions: function(env, body) {
		var expansions = {
			transclusions: {},
			extensions: {},
			media: {},
		};
		function doExtractExpansions(node) {
			var nodes, expAccum;
			while (node) {
				if (DU.isElt(node)) {
					var typeOf = node.getAttribute('typeof');
					var about = node.getAttribute('about');
					if ((/(?:^|\s)(?:mw:(?:Transclusion(?=$|\s)|Extension\/))/.test(typeOf) && about) ||
							/(?:^|\s)(?:mw:(?:Image|Video|Audio)(?:(?=$|\s)|\/))/.test(typeOf)) {
						var dp = DU.getDataParsoid(node);
						nodes = DU.getAboutSiblings(node, about);

						var key;
						if (/(?:^|\s)mw:Transclusion(?=$|\s)/.test(typeOf)) {
							expAccum = expansions.transclusions;
							key = dp.src;
						} else if (/(?:^|\s)mw:Extension\//.test(typeOf)) {
							expAccum = expansions.extensions;
							key = dp.src;
						} else {
							expAccum = expansions.media;
							// XXX gwicke: use proper key that is not
							// source-based? This also needs to work for
							// transclusion output.
							key = null;
						}

						if (key) {
							expAccum[key] = PipelineUtils.makeExpansion(env, nodes);
						}

						node = JSUtils.lastItem(nodes);
					} else {
						doExtractExpansions(node.firstChild);
					}
				}
				node = node.nextSibling;
			}
		}
		// Kick off the extraction
		doExtractExpansions(body.firstChild);
		return expansions;
	},

};

if (typeof module === "object") {
	module.exports.PipelineUtils = PipelineUtils;
}
