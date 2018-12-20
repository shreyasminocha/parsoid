<?php

namespace Parsoid\Tests;

class MockEnv {
	public function __construct($opts, $pageSrc = "testing testing testing testing") {
		$this->wrapSections = true;
		$this->logFlag = isset($opts->log);
		$this->page = (object)[ "src" => $pageSrc ];
		$this->conf = (object)[ "parsoid" => (object)[ "rtTestMode" => false ] ];

		// Hack in bswPagePropRegexp to support Util.js function "isBehaviorSwitch: function(... "
		$this->conf->wiki = [
			"bswPagePropRegexp" =>
				'/(?:^|\\s)mw:PageProp\/' .
				'(?:NOGLOBAL|DISAMBIG|NOCOLLABORATIONHUBTOC|nocollaborationhubtoc|NOTOC|notoc|NOGALLERY|nogallery|FORCETOC|forcetoc|TOC|toc|NOEDITSECTION|noeditsection|NOTITLECONVERT|notitleconvert|NOTC|notc|NOCONTENTCONVERT|nocontentconvert|NOCC|nocc|NEWSECTIONLINK|NONEWSECTIONLINK|HIDDENCAT|INDEX|NOINDEX|STATICREDIRECT)' .
				'(?=$|\\s)/'
		];
	}

	public function log() {
		if ($this->logFlag) {
			$arguments = func_get_args();
			$output = $arguments[0];
			for ($index = 1; $index < sizeof($arguments); $index++) {
				if (is_callable($arguments[$index])) {
					$output = $output . ' ' . $arguments[$index]();
				} else {
					$output = $output . ' ' . $arguments[$index];
				}
			}
			echo $output . "\n";
		}
	}
}
