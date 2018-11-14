/**
 * Utilities used in the tokenizer.
 * @module wt2html/tokenizer_utils
 */

'use strict';

var JSUtils = require('../utils/jsutils.js').JSUtils;
const { KV, TagTk, EndTagTk, SelfclosingTagTk, CommentTk } = require('../tokens/TokenTypes.js');

var tu = module.exports = {

	flattenIfArray: function(a) {
		function internalFlatten(e, res) {
			// Don't bother flattening if we dont have an array
			if (!Array.isArray(e)) {
				return e;
			}

			for (var i = 0; i < e.length; i++) {
				var v = e[i];
				if (Array.isArray(v)) {
					// Change in assumption from a shallow array to a nested array.
					if (res === null) { res = e.slice(0, i); }
					internalFlatten(v, res);
				} else if (v !== null && v !== undefined) {
					if (res !== null) {
						res.push(v);
					}
				} else {
					throw new Error("falsy " + e);
				}
			}

			if (res) {
				e = res;
			}
			return e;
		}
		return internalFlatten(a, null);
	},

	flattenString: function(c) {
		var out = tu.flattenStringlist(c);
		if (out.length === 1 && out[0].constructor === String) {
			return out[0];
		} else {
			return out;
		}
	},

	flattenStringlist: function(c) {
		var out = [];
		var text = '';
		// c will always be an array
		c = tu.flattenIfArray(c);
		for (var i = 0, l = c.length; i < l; i++) {
			var ci = c[i];
			if (ci.constructor === String) {
				if (ci !== '') {
					text += ci;
				}
			} else {
				if (text !== '') {
					out.push(text);
					text = '';
				}
				out.push(ci);
			}
		}
		if (text !== '') {
			out.push(text);
		}
		return out;
	},

	/** Simple string formatting using `%s`. */
	sprintf: function(format) {
		var args = Array.prototype.slice.call(arguments, 1);
		return format.replace(/%s/g, function() {
			return args.length ? args.shift() : '';
		});
	},

	getAttrVal: function(value, start, end) {
		return { value: value, srcOffsets: [start, end] };
	},

	buildTableTokens: function(tagName, wtChar, attrInfo, tsr, endPos, content, addEndTag) {
		var a;
		var dp = { tsr: tsr };

		if (!attrInfo) {
			a = [];
			if (tagName === 'td' || tagName === 'th') {
				// Add a flag that indicates that the tokenizer didn't
				// encounter a "|...|" attribute box. This is useful when
				// deciding which <td>/<th> cells need attribute fixups.
				dp.tmp = { noAttrs: true };
			}
		} else {
			a = attrInfo[0];
			if (a.length === 0) {
				dp.startTagSrc = wtChar + attrInfo[1].join('');
			}
			if ((a.length === 0 && attrInfo[2]) || attrInfo[2] !== "|") {
				// Variation from default
				// 1. Separator present with an empty attribute block
				// 2. Not "|"
				dp.attrSepSrc = attrInfo[2];
			}
		}

		var dataAttribs = { tsr: [endPos, endPos] };
		var endTag;
		if (addEndTag) {
			endTag = new EndTagTk(tagName, [], dataAttribs);
		} else {
			// We rely on our tree builder to close the table cell (td/th) as needed.
			// We cannot close the cell here because cell content can come from
			// multiple parsing contexts and we cannot close the tag in the same
			// parsing context in which the td was opened:
			//   Ex: {{echo|{{!}}foo}}{{echo|bar}} has to output <td>foobar</td>
			//
			// But, add a marker meta-tag to capture tsr info.
			// SSS FIXME: Unsure if this is actually helpful, but adding it in just in case.
			// Can test later and strip it out if it doesn't make any diff to rting.
			endTag = new SelfclosingTagTk('meta', [
				new KV('typeof', 'mw:TSRMarker'),
				new KV('data-etag', tagName),
			], dataAttribs);
		}

		return [new TagTk(tagName, a, dp)].concat(content, endTag);
	},

	buildXMLTag: function(name, lcName, attribs, endTag, selfClose, tsr) {
		var tok;
		var da = { tsr: tsr, stx: 'html' };

		if (name !== lcName) {
			da.srcTagName = name;
		}

		if (endTag !== null) {
			tok = new EndTagTk(lcName, attribs, da);
		} else if (selfClose) {
			da.selfClose = true;
			tok = new SelfclosingTagTk(lcName, attribs, da);
		} else {
			tok = new TagTk(lcName, attribs, da);
		}

		return tok;
	},

	/**
	 * Inline breaks, flag-enabled rule which detects end positions for
	 * active higher-level rules in inline and other nested rules.
	 * Those inner rules are then exited, so that the outer rule can
	 * handle the end marker.
	 */
	inlineBreaks: function(input, pos, stops) {
		var c = input[pos];
		if (!/[=|!{}:;\r\n[\]<\-]/.test(c)) {
			return false;
		}

		var counters = stops.counters;
		switch (c) {
			case '=':
				if (stops.onStack('arrow') && input[pos + 1] === ">") {
					return true;
				}
				return stops.onStack('equal') ||
					(counters.h &&
						(pos === input.length - 1
						// possibly more equals followed by spaces or comments
						|| /^=*(?:[ \t]|<\!--(?:(?!-->)[^])*-->)*(?:[\r\n]|$)/
							.test(input.substr(pos + 1)))
					);
			case '|':
				return (stops.onStack('templateArg') &&
						!stops.onStack('extTag')) ||
					stops.onStack('tableCellArg') ||
					stops.onStack('linkdesc') ||
					(stops.onStack('table') && (
						pos < input.length - 1 &&
						/[}|]/.test(input[pos + 1])));
			case '!':
				return stops.onStack('th') !== false &&
					!stops.onCount('templatedepth') &&
					input[pos + 1] === "!";
			case '{':
				// {{!}} pipe templates..
				// FIXME: Presumably these should mix with and match | above.
				return (
					(stops.onStack('tableCellArg') &&
						input.substr(pos, 5) === '{{!}}') ||
					(stops.onStack('table') &&
						input.substr(pos, 10) === '{{!}}{{!}}')
				);
			case '}':
				return input.substr(pos, 2) === stops.onStack('preproc');
			case ':':
				return counters.colon &&
					!stops.onStack('extlink') &&
					!stops.onCount('templatedepth') &&
					!stops.onStack('linkdesc') &&
					!(stops.onStack('preproc') === '}-');
			case ";":
				return stops.onStack('semicolon');
			case '\r':
				return stops.onStack('table') &&
					/\r\n?\s*[!|]/.test(input.substr(pos));
			case '\n':
				// The code below is just a manual / efficient
				// version of this check.
				//
				// stops.onStack('table') && /^\n\s*[!|]/.test(input.substr(pos));
				//
				// It eliminates a substr on the string and eliminates
				// a potential perf problem since "\n" and the inline_breaks
				// test is common during tokenization.
				if (!stops.onStack('table')) {
					return false;
				}

				// Allow leading whitespace in tables

				// Since we switched on 'c' which is input[pos],
				// we know that input[pos] is "\n".
				// So, the /^\n/ part of the regexp is already satisfied.
				// Look for /\s*[!|]/ below.
				var n = input.length;
				for (var i = pos + 1; i < n; i++) {
					var d = input[i];
					if (/[!|]/.test(d)) {
						return true;
					} else if (!(/\s/.test(d))) {
						return false;
					}
				}
				return false;
			case '[':
				// This is a special case in php's doTableStuff, added in
				// response to T2553.  If it encounters a `[[`, it bails on
				// parsing attributes and interprets it all as content.
				return stops.onStack('tableCellArg') &&
					input.substr(pos, 2) === '[[';
			case '-':
				// Same as above: a special case in doTableStuff, added
				// as part of T153140
				return stops.onStack('tableCellArg') &&
					input.substr(pos, 2) === '-{';
			case ']':
				if (stops.onStack('extlink')) { return true; }
				return input.substr(pos, 2) === stops.onStack('preproc');
			case '<':
				return (counters.noinclude && input.substr(pos, 12) === '</noinclude>') ||
					(counters.includeonly && input.substr(pos, 14) === '</includeonly>') ||
					(counters.onlyinclude && input.substr(pos, 14) === '</onlyinclude>');
			default:
				throw new Error('Unhandled case!');
		}
	},

	/** Pop off the end comments, if any. */
	popComments: function(attrs) {
		var buf = [];
		for (var i = attrs.length - 1; i > -1; i--) {
			var kv = attrs[i];
			if (typeof kv.k === "string" && !kv.v && /^\s*$/.test(kv.k)) {
				// permit whitespace
				buf.unshift(kv.k);
			} else if (Array.isArray(kv.k) && !kv.v) {
				// all should be comments
				if (kv.k.some(function(k) {
					return !(k instanceof CommentTk);
				})) { break; }
				buf.unshift.apply(buf, kv.k);
			} else {
				break;
			}
		}
		// ensure we found a comment
		while (buf.length && !(buf[0] instanceof CommentTk)) {
			buf.shift();
		}
		if (buf.length) {
			attrs.splice(-buf.length, buf.length);
			return { buf: buf, commentStartPos: buf[0].dataAttribs.tsr[0] };
		} else {
			return null;
		}
	},

	tsrOffsets: function(location, flag) {
		switch (flag) {
			case 'start':
				return [location.start.offset, location.start.offset];
			case 'end':
				return [location.end.offset, location.end.offset];
			default:
				return [location.start.offset, location.end.offset];
		}
	},

	enforceParserResourceLimits: function(env, token) {
		if (token && (token.constructor === TagTk || token.constructor === SelfclosingTagTk)) {
			switch (token.name) {
				case 'listItem':
					env.bumpParserResourceUse('listItem');
					break;
				case 'template':
					env.bumpParserResourceUse('transclusion');
					break;
				case 'td':
				case 'th':
					env.bumpParserResourceUse('tableCell');
					break;
			}
		}
	},

	protectAttrs: function(name) {
		return name.replace(
			/^(about|data-mw.*|data-parsoid.*|data-x.*|property|rel|typeof)$/i,
			'data-x-$1');
	},

	isIncludeTag: function(name) {
		return name === 'includeonly' || name === 'noinclude' || name === 'onlyinclude';
	},

};


/**
 * Syntax stops: Avoid eating significant tokens for higher-level rules
 * in nested inline rules.
 *
 * Flags for specific parse environments (inside tables, links etc). Flags
 * trigger syntactic stops in the inline_breaks rule, which
 * terminates inline and attribute matches. Flags merely reduce the number
 * of rules needed: The grammar is still context-free as the
 * rules can just be unrolled for all combinations of environments
 * at the cost of a much larger grammar.
 * @class
 */
function SyntaxStops() {
	this.counters = {};
	this.stacks = {};
	this.key = '';
	this._counterKey = '';
	this._stackKey = '';
}

SyntaxStops.prototype.inc = function(flag) {
	if (this.counters[flag] !== undefined) {
		this.counters[flag]++;
	} else {
		this.counters[flag] = 1;
	}
	this._updateCounterKey();
	return true;
};

SyntaxStops.prototype.dec = function(flag) {
	if (this.counters[flag] !== undefined) {
		this.counters[flag]--;
	}
	this._updateCounterKey();
	return false;
};

SyntaxStops.prototype.onCount = function(flag) {
	return this.counters[flag];
};

/**
 * A stack for nested, but not cumulative syntactic stops.
 * Example: '=' is allowed in values of template arguments, even if those
 * are nested in attribute names.
 */
SyntaxStops.prototype.push = function(name, value) {
	if (this.stacks[name] === undefined) {
		this.stacks[name] = [value];
	} else {
		this.stacks[name].push(value);
	}
	this._updateStackKey();
	return this.stacks[name].length; // always truthy
};

SyntaxStops.prototype.pop = function(name) {
	if (this.stacks[name] !== undefined) {
		this.stacks[name].pop();
	} else {
		throw "SyntaxStops.pop: unknown stop for " + name;
	}
	this._updateStackKey();
	return false;
};

SyntaxStops.prototype.popTo = function(name, len) {
	if (this.stacks[name] === undefined) {
		throw "SyntaxStops.popTo: unknown stop for " + name;
	} else if (this.stacks[name].length < (len - 1)) {
		throw "SyntaxStops.popTo: stop stack too short for " + name;
	} else {
		this.stacks[name].length = len - 1;
	}
	this._updateStackKey();
	return false;
};

SyntaxStops.prototype.onStack = function(name) {
	var stack = this.stacks[name];
	if (stack === undefined || stack.length === 0) {
		return false;
	} else {
		return JSUtils.lastItem(stack);
	}
};

SyntaxStops.prototype._updateKey = function() {
	this._updateCounterKey();
	this._updateStackKey();
};

SyntaxStops.prototype._updateCounterKey = function() {
	var counters = '';
	for (var k in this.counters) {
		if (this.counters[k] > 0) {
			counters += 'c' + k;
		}
	}
	this._counterKey = counters;
	this.key = this._counterKey + this._stackKey;
};

SyntaxStops.prototype._updateStackKey = function() {
	var stackStops = '';
	for (var k in this.stacks) {
		if (this.onStack(k)) {
			stackStops += 's' + k;
		}
	}
	this._stackKey = stackStops;
	this.key = this._counterKey + this._stackKey;
};

tu.SyntaxStops = SyntaxStops;
