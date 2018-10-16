<?php
/** @module */

//'use strict';

namespace Parsoid\Lib\Wt2html\TT;

/**
 * @class
 */
class TokenHandler {
	/**
	 * @param {TokenTransformManager} manager
	 *   The manager for this stage of the parse.
	 * @param {Object} options
	 *   Any options for the expander.
	 */
	public function __construct($manager, $options) {
		$this->manager = $manager;
		$this->env = $manager->env;
		$this->options = [ $options ];
		$this->init();
	}

	/**
	 */
	public function init() {    // subclass must implement init and not call this method
		global $console;
		$console->assert(false, '`init` unimplemented!');
	}

	/**
	 */
	public function resetState($opts) {
		$this->atTopLevel = $opts && $opts->toplevel;
	}
}

?>

