<?php

namespace Parsoid\Lib\Wt2Html\PP\Processors;

require_once __DIR__.'/../../../../vendor/autoload.php';

use RemexHtml\DOM;
use RemexHtml\Tokenizer;
use RemexHtml\TreeBuilder;
use RemexHtml\Serializer;

require_once __DIR__."/../../../config/Env.php";
require_once __DIR__."/../../../config/WikitextConstants.php";
require_once __DIR__."/../../../utils/DU.php";
require_once "computeDSR.php";
require_once "wrapSections.php";
require_once "cleanupFormattingTagFixup.php";
require_once "pwrap.php";

use Parsoid\Lib\Config\Env;
use Parsoid\Lib\Config\WikitextConstants;
use Parsoid\Lib\Utils\DU;

function buildDOM( $domBuilder, $text ) {
	$treeBuilder = new TreeBuilder\TreeBuilder( $domBuilder, [ 'ignoreErrors' => true ] );
	$dispatcher = new TreeBuilder\Dispatcher( $treeBuilder );
	$tokenizer = new Tokenizer\Tokenizer( $dispatcher, $text, [] );
	$tokenizer->execute( [] );
	return $domBuilder->getFragment();
}

/* --------------------------------------------------------------------------------------------------
 * 1. parse.js --dump dom:pre-dsr,dom:post-dsr --pageName TITLE --prefix WIKI < /dev/null >&! /tmp/out
 * 2. extract dumped doms from /tmp/out into TITLE.predsr.html and TITLE.postdsr.html
 * 3. feed the TITLE.predsr.html file to this script alongwith the wikitext size for the file
 *    which you can get looking at body.dsr[1] in TITLE.postdsr.html
 * 4. verify that computed dsrs are identical
 *
 * All this could be automated. But for now, manual works.
 * -------------------------------------------------------------------------------------------------- */
function test( $argc, $argv, $dumpDOM = false ) {
	WikitextConstants::init();
	DU::init();

	if ( $argc < 3 ) {
		print "USAGE: php $argv[0] COMMAND FILE [optional-args]\n";
		exit(1);
	}

	$func = $argv[1];
	$fileName = $argv[2];

	$domBuilder = new DOM\DOMBuilder;
	$serializer = new DOM\DOMSerializer($domBuilder, new Serializer\HtmlFormatter);
	$env = new Env();

	$text = file_get_contents( $fileName );
	$dom = buildDOM( $domBuilder, $text );

	$time = -microtime( true );
	switch ( $func ) {
		case 'computeDSR' :
			if ( $argc < 4 ) {
				print "Please provide end-offset to compute DSR\n";
				print "USAGE: php $argv[0] computeDSR FILE END-OFFSET\n";
				exit(1);
			}
			computeDSR( $dom->getElementsByTagName('body')->item(0),
				$env, [ 'sourceOffsets' => [ 0, $argv[3] ], 'attrExpansion' => false ] );
			break;
		case 'cleanupFormattingTagFixup' :
			cleanupFormattingTagFixup( $dom->getElementsByTagName('body')->item(0), $env );
			break;
		case 'wrapSections' :
			wrapSections( $dom->getElementsByTagName('body')->item(0), $env, null );
			break;
		case 'pwrap' :
			pwrapDOM( $dom->getElementsByTagName('body')->item(0), $env, null );
			break;
	}
	$time += microtime( true );

	print "time - $time\n";

	if ( $dumpDOM ) {
		print $serializer->getResult();
	}
}

test( $argc, $argv, false );
