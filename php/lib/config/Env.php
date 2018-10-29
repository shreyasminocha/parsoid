<?php

namespace Parsoid\Lib\Config;

class Env {
	public function __construct() {
		$this->wrapSections = true;
	}

	public function log() {
		/*
	   $args = func_get_args();
		$traceType = array_shift($args);
		print "$traceType ";
		foreach ($args as $arg) {
			if (is_callable($arg)) {
				print $arg();
			} else {
				print $arg;
			}
		}
		print "\n";
		*/
	}
}
