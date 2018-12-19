/** @module */

'use strict';

const { Util } = require('../../utils/Util.js');
const TokenHandler = require('./TokenHandler.js');
const { KV,SelfclosingTagTk } = require('../../tokens/TokenTypes.js');

/**
 * Handler for behavior switches, like '__TOC__' and similar.
 *
 * @class
 * @extends module:wt2html/tt/TokenHandler
 */
class BehaviorSwitchHandler extends TokenHandler {
	constructor(manager, options) {
		super(manager, options);
		this.manager.addTransform(
			(token, prevToken, cb) => this.onBehaviorSwitch(token),
			'BehaviorSwitchHandler:onBehaviorSwitch',
			BehaviorSwitchHandler.rank(),
			'tag',
			'behavior-switch'
		);
	}

	static rank() { return 2.14; }

	/**
	 * Main handler.
	 * See {@link TokenTransformManager#addTransform}'s transformation parameter.
	 */
	onBehaviorSwitch(token) {
		const env = this.manager.env;
		const magicWord = env.conf.wiki.magicWordCanonicalName(token.attribs[0].v);

		env.setVariable(magicWord, true);

		const metaToken = new SelfclosingTagTk(
			'meta',
			[ new KV('property', 'mw:PageProp/' + magicWord) ],
			Util.clone(token.dataAttribs)
		);

		return { tokens: [ metaToken ] };
	}
}


if (typeof module === "object") {
	module.exports.BehaviorSwitchHandler = BehaviorSwitchHandler;
}
