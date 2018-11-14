<?php

namespace Parsoid\Lib\Config;

class Env {
	public function __construct() {
		$this->wrapSections = true;
	}

	public function log() {
		/*
		$arguments = func_get_args();
		$output = $arguments[0];
		for ($index = 1; $index < sizeof($arguments); $index++) {
            if (is_callable($arguments[$index])) {
				$output = $output . ' ' . $arguments[$index]();
			} else {
				$output = $output . ' ' . $arguments[$index];
			}
		}
		echo  $output . "\n";
		*/
	}
}
