'use strict';

var ParsoidExtApi = module.parent.require('./extapi.js').versionCheck('^0.9.0');
var PipelineUtils = ParsoidExtApi.PipelineUtils;
var DU = ParsoidExtApi.DOMUtils;
var Util = ParsoidExtApi.Util;

/**
 * See tests/parser/parserTestsParserHook.php in core.
 */

var myLittleHelper = function(env, extToken, argDict, html, cb) {
	var tsr = extToken.dataAttribs.tsr;

	if (!extToken.dataAttribs.tagWidths[1]) {
		argDict.body = undefined;  // Serialize to self-closing.
	}

	var addWrapperAttrs = function(firstNode) {
		firstNode.setAttribute('typeof', 'mw:Extension/' + argDict.name);
		DU.setDataMw(firstNode, argDict);
		DU.setDataParsoid(firstNode, {
			tsr: Util.clone(tsr),
			src: extToken.dataAttribs.src,
		});
	};

	var body = DU.ppToDOM(html);
	var tokens = PipelineUtils.buildDOMFragmentTokens(
		env, extToken, body, addWrapperAttrs,
		{ setDSR: true, isForeignContent: true }
	);

	cb({ tokens: tokens });
};

var dumpHook = function(manager, pipelineOpts, extToken, cb) {
	// All the interesting info is in data-mw.
	var html = '<pre />';
	var argDict = Util.getExtArgInfo(extToken).dict;
	myLittleHelper(manager.env, extToken, argDict, html, cb);
};

// Async processing means this isn't guaranteed to be in the right order.
// Plus, parserTests reuses the environment so state is bound to clash.
var staticTagHook = function(manager, pipelineOpts, extToken, cb) {
	var argDict = Util.getExtArgInfo(extToken).dict;
	var html;
	if (argDict.attrs.action === 'flush') {
		html = '<p>' + this.state.buf + '</p>';
		this.state.buf = '';  // Reset.
	} else {
		// FIXME: Choose a better DOM representation that doesn't mess with
		// newline constraints.
		html = '<span />';
		this.state.buf += argDict.body.extsrc;
	}
	myLittleHelper(manager.env, extToken, argDict, html, cb);
};

// Tag constructor
module.exports = function() {
	this.state = { buf: '' };  // Ughs
	this.config = {
		tags: [
			{ name: 'tag', tokenHandler: dumpHook },
			{ name: 'tåg', tokenHandler: dumpHook },
			{ name: 'statictag', tokenHandler: staticTagHook.bind(this) },
		],
	};
};
