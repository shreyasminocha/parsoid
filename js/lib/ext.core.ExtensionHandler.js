"use strict";

var TemplateHandler = require('./ext.core.TemplateHandler.js').TemplateHandler,
	coreutil = require('util'),
	Util = require('./mediawiki.Util.js').Util,
	DOMUtils = require('./mediawiki.DOMUtils.js').DOMUtils,
	PHPParseRequest = require('./mediawiki.ApiRequest.js').PHPParseRequest;

function ExtensionHandler(manager, options) {
	this.manager = manager;
	this.options = options;
	this.usePHPPreProcessor = manager.env.conf.parsoid.usePHPPreProcessor &&
			(manager.env.conf.parsoid.apiURI !== null);

	// Native extension handlers
	this.citeHandler = manager.env.conf.parsoid.nativeExtensions.cite;
	this.nativeExtHandlers = {
		"ref": this.citeHandler.handleRef.bind(this.citeHandler, manager),
		"references": this.citeHandler.handleReferences.bind(this.citeHandler, manager)
	};

	// Extension content expansion
	manager.addTransform( this.onExtension.bind(this), "ExtensionHandler:onExtension",
			this.rank, 'tag', 'extension' );
}

// Inherit from TemplateHandler to get access to all the nifty functions there
// (code reuse inheritance -- maybe better to refactor the common code out to
// a helper class and use that in both Template and Extension handlers)
coreutil.inherits(ExtensionHandler, TemplateHandler);

ExtensionHandler.prototype.rank = 1.11;

ExtensionHandler.prototype.parseExtensionHTML = function(extToken, cb, err, html) {
	// document -> html -> body -> children
	var topNodes = Util.parseHTML(html).body.childNodes;
	var toks = [];
	for (var i = 0, n = topNodes.length; i < n; i++) {
		toks = DOMUtils.convertDOMtoTokens(toks, topNodes[i]);
	}

	var state = { token: extToken };
	if (this.options.wrapTemplates) {
		state.wrapperType = 'mw:Object/Extension/' + extToken.getAttribute('name');
		state.wrappedObjectId = this.manager.env.newObjectId();
		toks = this.addEncapsulationInfo(state, toks);
		toks.push(this.getEncapsulationInfoEndTag(state));
	}

	cb({ tokens: [new InternalTk([new KV('tokens', toks)])] });
};

/**
 * Fetch the preprocessed wikitext for an extension
 */
ExtensionHandler.prototype.fetchExpandedExtension = function ( title, text, processor, parentCB, cb ) {
	var env = this.manager.env;
	if ( ! env.conf.parsoid.expandExtensions ) {
		parentCB(  { tokens: [ 'Warning: Extension tag expansion disabled, and no cache for ' +
				title ] } );
	} else {
		// We are about to start an async request for an extension
		env.dp( 'Note: trying to expand ', text );

		// Start a new request if none is outstanding
		//env.dp( 'requestQueue: ', env.requestQueue );
		if ( env.requestQueue[text] === undefined ) {
			env.tp( 'Note: Starting new request for ' + text );
			env.requestQueue[text] = new processor( env, title, text );
		}
		// append request, process in document order
		env.requestQueue[text].listeners( 'src' ).push( cb );

		parentCB ( { async: true } );
	}
};

ExtensionHandler.prototype.onExtension = function ( token, frame, cb ) {
	var extensionName = token.getAttribute('name'),
	    nativeHandler = this.nativeExtHandlers[extensionName];
	if ( nativeHandler ) {
		nativeHandler(token, cb);
	} else if ( this.manager.env.conf.parsoid.expandExtensions ) {
		// Use MediaWiki's action=parse preprocessor
		this.fetchExpandedExtension(
			extensionName,
			token.getAttribute('source'),
			PHPParseRequest,
			cb,
			this.parseExtensionHTML.bind(this, token, cb)
		);
	} else {
		/* Convert this into a span with extension content as plain text */
		var span = new TagTk('span', [
					new KV('typeof', 'mw:Object/Extension/' + extensionName),
					new KV('about', token.getAttribute('about'))
				], token.dataAttribs);

		cb({ tokens: [span, token.getAttribute('source'), new EndTagTk('span')] });
	}
};

if (typeof module === "object") {
	module.exports.ExtensionHandler = ExtensionHandler;
}
