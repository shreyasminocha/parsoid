/**
 * Tokenizer for wikitext, using {@link https://pegjs.org/ PEG.js} and a
 * separate PEG grammar file
 * (pegTokenizer.pegjs)
 *
 * Use along with a {@link module:wt2html/HTML5TreeBuilder} and the
 * {@link DOMPostProcessor}(s) for HTML output.
 * @module
 */

'use strict';

require('../../core-upgrade.js');

var PEG = require('pegjs');
var path = require('path');
var fs = require('fs');
var events = require('events');
var util = require('util');
var JSUtils = require('../utils/jsutils.js').JSUtils;


// allow dumping compiled tokenizer to disk, for debugging.
var PARSOID_DUMP_TOKENIZER = process.env.PARSOID_DUMP_TOKENIZER || false;
// allow dumping tokenizer rules (only) to disk, for linting.
var PARSOID_DUMP_TOKENIZER_RULES = process.env.PARSOID_DUMP_TOKENIZER_RULES || false;

/**
 * Includes passed to the tokenizer, so that it does not need to require those
 * on each call. They are available as pegArgs.pegIncludes, and are unpacked
 * in the head of pegTokenizer.pegjs.
 * @namespace
 * @private
 */
var pegIncludes = {
	constants: require('../config/WikitextConstants.js').WikitextConstants,
	ContentUtils: require('../utils/ContentUtils.js').ContentUtils,
	DOMDataUtils: require('../utils/DOMDataUtils.js').DOMDataUtils,
	DOMUtils: require('../utils/DOMUtils.js').DOMUtils,
	JSUtils: JSUtils,
	// defined below to satisfy JSHint
	PegTokenizer: null,
	TokenTypes: require('../tokens/TokenTypes.js'),
	TokenUtils: require('../utils/TokenUtils.js').TokenUtils,
	tu: require('./tokenizer.utils.js'),
	Util: require('../utils/Util.js').Util,
	WTUtils: require('../utils/WTUtils.js').WTUtils,
};

/**
 * @class
 * @extends EventEmitter
 * @param {MWParserEnvironment} env
 * @param {Object} options
 */
function PegTokenizer(env, options) {
	events.EventEmitter.call(this);
	this.env = env;
	// env can be null during code linting
	var traceFlags = env ? env.conf.parsoid.traceFlags : null;
	this.traceTime = traceFlags && traceFlags.has('time');
	this.options = options || {};
	this.offsets = {};
}

pegIncludes.PegTokenizer = PegTokenizer;

// Inherit from EventEmitter
util.inherits(PegTokenizer, events.EventEmitter);

PegTokenizer.prototype.src = '';

PegTokenizer.prototype.initTokenizer = function() {
	var env = this.env;

	// Construct a singleton static tokenizer.
	var pegSrcPath = path.join(__dirname, 'pegTokenizer.pegjs');
	this.src = fs.readFileSync(pegSrcPath, 'utf8');

	// FIXME: Don't report infinite loops, i.e. repeated subexpressions which
	// can match the empty string, since our grammar gives several false
	// positives (or perhaps true positives).
	delete PEG.compiler.passes.check.reportInfiniteLoops;

	function cacheRuleHook(opts) {
		var maxVisitCount = 20;
		return {
			start: [
				[
					'var checkCache = visitCounts[', opts.startPos,
					'] > ', maxVisitCount, ';',
				].join(''),
				'var cached, bucket, key;',
				'if (checkCache) {',
				[
					'  key = (', opts.variantIndex, '+',
					opts.variantCount, '*', opts.ruleIndex,
					').toString() + stops.key;',
				].join(''),
				[
					'  bucket = ', opts.startPos, ';',
				].join(''),
				'  if ( !peg$cache[bucket] ) { peg$cache[bucket] = {}; }',
				'  cached = peg$cache[bucket][key];',
				'} else {',
				'  visitCounts[' + opts.startPos + ']++;',
				'}',
			].join('\n'),
			hitCondition: 'cached',
			nextPos: 'cached.nextPos',
			result: 'cached.result',
			store: [
				'if (checkCache) {',
				[
					'  peg$cache[bucket][key] = { nextPos: ', opts.endPos, ', ',
					'result: ',
					env && env.immutable ? [
						'JSUtils.deepFreeze(', opts.result, ')'
					].join('') : opts.result,
					' };',
				].join(''),
				'}',
			].join('\n'),
		};
	}

	function cacheInitHook(opts) {
		return [
			'var peg$cache = {};',
			'var visitCounts = new Uint8Array(input.length);',
		].join('\n');
	}

	if (PARSOID_DUMP_TOKENIZER_RULES) {
		var visitor = require('pegjs/lib/compiler/visitor');
		var ast = PEG.parser.parse(this.src);
		// Current code style seems to use spaces in the tokenizer.
		var tab = '    ';
		// Add some eslint overrides and define globals.
		var rulesSource = '/* eslint-disable indent,camelcase,no-unused-vars */\n';
		rulesSource += "\n'use strict';\n\n";
		rulesSource += 'var options, location, input, text, peg$cache, peg$currPos;\n';
		// Prevent redefinitions of variables involved in choice expressions
		var seen = new Set();
		var addVar = function(name) {
			if (!seen.has(name)) {
				rulesSource += tab + 'var ' + name + ' = null;\n';
				seen.add(name);
			}
		};
		// Collect all the code blocks in the AST.
		var dumpCode = function(node) {
			if (node.code) {
				// remove trailing whitespace for single-line predicates
				var code = node.code.replace(/[ \t]+$/, '');
				// wrap with a function, to prevent spurious errors caused
				// by redeclarations or multiple returns in a block.
				rulesSource += tab + '(function() {\n' + code + '\n' +
					tab + '})();\n';
			}
		};
		var visit = visitor.build({
			initializer: function(node) {
				if (node.code) {
					rulesSource += node.code + '\n';
				}
			},
			semantic_and: dumpCode,
			semantic_node: dumpCode,
			rule: function(node) {
				rulesSource += 'function rule_' + node.name + '() {\n';
				seen.clear();
				visit(node.expression);
				rulesSource += '}\n';
			},
			labeled: function(node) {
				addVar(node.label);
				visit(node.expression);
			},
			named: function(node) {
				addVar(node.name);
				visit(node.expression);
			},
			action: function(node) {
				visit(node.expression);
				dumpCode(node);
			},
		});
		visit(ast);
		// Write rules to file.
		var rulesFilename = path.join(__dirname, '/mediawiki.tokenizer.rules.js');
		fs.writeFileSync(rulesFilename, rulesSource, 'utf8');
	}

	var tokenizerSource = PEG.buildParser(this.src, {
		cache: true,
		trackLineAndColumn: false,
		output: "source",
		cacheRuleHook: cacheRuleHook,
		cacheInitHook: cacheInitHook,
		allowedStartRules: [
			"start",
			"table_start_tag",
			"url",
			"row_syntax_table_args",
			"table_attributes",
			"generic_newline_attributes",
			"tplarg_or_template_or_bust",
			"extlink",
		],
		allowedStreamRules: [
			"start_async",
		],
	});

	if (!PARSOID_DUMP_TOKENIZER) {
		// eval is not evil in the case of a grammar-generated tokenizer.
		PegTokenizer.prototype.tokenizer = new Function('return ' + tokenizerSource)();  // eslint-disable-line
	} else {
		// Optionally save & require the tokenizer source
		tokenizerSource =
			'require(\'../../core-upgrade.js\');\n' +
			'module.exports = ' + tokenizerSource;
		// write tokenizer to a file.
		var tokenizerFilename = path.join(__dirname, '/mediawiki.tokenizer.js');
		fs.writeFileSync(tokenizerFilename, tokenizerSource, 'utf8');
		PegTokenizer.prototype.tokenizer = require(tokenizerFilename);
	}
};

/**
 * Process text.  The text is tokenized in chunks and control
 * is yielded to the event loop after each top-level block is
 * tokenized enabling the tokenized chunks to be processed at
 * the earliest possible opportunity.
 */
PegTokenizer.prototype.process = function(text) {
	this.tokenizeAsync(text);
};

/**
 * Debugging aid: Set pipeline id.
 */
PegTokenizer.prototype.setPipelineId = function(id) {
	this.pipelineId = id;
};

/**
 * Set start and end offsets of the source that generated this DOM.
 */
PegTokenizer.prototype.setSourceOffsets = function(start, end) {
	this.offsets.startOffset = start;
	this.offsets.endOffset = end;
};

PegTokenizer.prototype._tokenize = function(text, args) {
	var ret = this.tokenizer.parse(text, args);
	return ret;
};

/**
 * The main worker. Sets up event emission ('chunk' and 'end' events).
 * Consumers are supposed to register with PegTokenizer before calling
 * process().
 */
PegTokenizer.prototype.tokenizeAsync = function(text) {
	if (!this.tokenizer) {
		this.initTokenizer();
	}

	// ensure we're processing text
	text = String(text || "");

	var chunkCB = tokens => this.emit('chunk', tokens);

	// Kick it off!
	var pipelineOffset = this.offsets.startOffset || 0;
	var args = {
		cb: chunkCB,
		pegTokenizer: this,
		pipelineOffset: pipelineOffset,
		pegIncludes: pegIncludes,
	};

	args.startRule = "start_async";
	args.stream = true;

	var iterator;
	var pegTokenizer = this;

	var tokenizeChunk = () => {
		var next;
		try {
			let start;
			if (this.traceTime) {
				start = JSUtils.startTime();
			}
			if (iterator === undefined) {
				iterator = pegTokenizer._tokenize(text, args);
			}
			next = iterator.next();
			if (this.traceTime) {
				this.env.bumpTimeUse("PEG-async", JSUtils.elapsedTime(start), 'PEG');
			}
		} catch (e) {
			pegTokenizer.env.log("fatal", e);
			return;
		}

		if (next.done) {
			pegTokenizer.onEnd();
		} else {
			setImmediate(tokenizeChunk);
		}
	};

	tokenizeChunk();
};


PegTokenizer.prototype.onEnd = function() {
	// Reset source offsets
	this.setSourceOffsets();
	this.emit('end');
};

/**
 * Tokenize via a rule passed in as an arg.
 * The text is tokenized synchronously in one shot.
 */
PegTokenizer.prototype.tokenizeSync = function(text, rule, args, sol) {
	if (!this.tokenizer) {
		this.initTokenizer();
	}
	// Some rules use callbacks: start, tlb, toplevelblock.
	// All other rules return tokens directly.
	var toks = [];
	if (!args) {
		args = {
			cb: function(r) { toks = JSUtils.pushArray(toks, r); },
			pegTokenizer: this,
			pipelineOffset: this.offsets.startOffset || 0,
			pegIncludes: pegIncludes,
			startRule: rule || 'start',
			sol: sol,
		};
	}
	let start;
	if (this.traceTime) {
		start = JSUtils.startTime();
	}
	var retToks = this._tokenize(text, args);
	if (this.traceTime) {
		this.env.bumpTimeUse("PEG-sync", JSUtils.elapsedTime(start), 'PEG');
	}
	if (Array.isArray(retToks) && retToks.length > 0) {
		toks = JSUtils.pushArray(toks, retToks);
	}
	return toks;
};

/**
 * Tokenizes a string as a rule, otherwise returns an `Error`
 */
PegTokenizer.prototype.tokenizeAs = function(text, rule, sol) {
	try {
		const args = {
			pegTokenizer: this,
			pegIncludes: pegIncludes,
			startRule: rule,
			sol: sol,
		};
		return this.tokenizeSync(text, null, args);
	} catch (e) {
		// console.warn("Input: " + text);
		// console.warn("Rule : " + rule);
		// console.warn("ERROR: " + e);
		// console.warn("Stack: " + e.stack);
		return (e instanceof Error) ? e : new Error(e);
	}
};

/**
 * Tokenize a URL.
 * @return {boolean}
 */
PegTokenizer.prototype.tokenizesAsURL = function(text, sol) {
	const e = this.tokenizeAs(text, 'url', sol);
	return !(e instanceof Error);
};

/**
 * Tokenize an extlink.
 */
PegTokenizer.prototype.tokenizeExtlink = function(text, sol) {
	return this.tokenizeAs(text, 'extlink', sol);
};

/**
 * Tokenize table cell attributes.
 */
PegTokenizer.prototype.tokenizeTableCellAttributes = function(text, sol) {
	return this.tokenizeAs(text, 'row_syntax_table_args', sol);
};


if (require.main === module) {
	PARSOID_DUMP_TOKENIZER = true;
	PARSOID_DUMP_TOKENIZER_RULES = true;
	new PegTokenizer().initTokenizer();
} else if (typeof module === "object") {
	module.exports.PegTokenizer = PegTokenizer;
	module.exports.pegIncludes = pegIncludes;
}
