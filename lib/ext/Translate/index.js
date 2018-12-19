/** @module ext/Translate */

'use strict';

module.parent.require('./extapi.js').versionCheck('^0.10.0');

// Translate constructor
module.exports = function() {
	this.config = {
		tags: [
			{ name: 'translate' },
			{ name: 'tvar' },
		],
	};
};
