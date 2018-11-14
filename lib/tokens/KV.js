/** @module tokens/KV */

'use strict';

/**
 * @class
 *
 * Key-value pair.
 */
class KV {
	/**
	 * @param {any} k
	 * @param {any} v
	 * @param {Array} srcOffsets The source offsets.
	 */
	constructor(k, v, srcOffsets) {
		/** Key. */
		this.k = k;
		/** Value. */
		this.v = v;
		if (srcOffsets) {
			/** The source offsets. */
			this.srcOffsets = srcOffsets;
		}
	}

	static lookupKV(kvs, key) {
		if (!kvs) {
			return null;
		}
		var kv;
		for (var i = 0, l = kvs.length; i < l; i++) {
			kv = kvs[i];
			if (kv.k.constructor === String && kv.k.trim() === key) {
				// found, return it.
				return kv;
			}
		}
		// nothing found!
		return null;
	}

	static lookup(kvs, key) {
		var kv = this.lookupKV(kvs, key);
		return kv === null ? null : kv.v;
	}
}

if (typeof module === "object") {
	module.exports = {
		KV: KV
	};
}
