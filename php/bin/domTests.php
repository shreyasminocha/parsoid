<?php

namespace Parsoid\Lib\Wt2Html\PP\Processors;

require_once (__DIR__.'/../vendor/autoload.php');

use RemexHtml\DOM;
use RemexHtml\Tokenizer;
use RemexHtml\TreeBuilder;
use RemexHtml\Serializer;

require_once (__DIR__.'/../lib/config/Env.php');
require_once (__DIR__.'/../lib/config/WikitextConstants.php');
require_once (__DIR__.'/../lib/utils/phputils.php');
require_once (__DIR__.'/../lib/utils/DU.php');
require_once (__DIR__.'/../lib/wt2html/pp/processors/computeDSR.php');
require_once (__DIR__.'/../lib/wt2html/pp/processors/wrapSections.php');
require_once (__DIR__.'/../lib/wt2html/pp/processors/cleanupFormattingTagFixup.php');
require_once (__DIR__.'/../lib/wt2html/pp/processors/pwrap.php');

use Parsoid\Lib\Config\Env;
use Parsoid\Lib\Config\WikitextConstants;
use Parsoid\Lib\PHPUtils\PHPUtil;
use Parsoid\Lib\Utils\DU;

$cachedState = false;
$cachedFilePre = '';
$cachedFilePost = '';

WikitextConstants::init();
DU::init();

class console {
	public function log($string) {
		echo $string;
	}

	public function assert($condition, $message) {
		if ($condition) {
			echo $message;
		};
	}
}

$console = new console;

function console_log($arguments) {
	global $console;
	$console->log($arguments);
}

function buildDOM( $domBuilder, $text ) {
	$treeBuilder = new TreeBuilder\TreeBuilder( $domBuilder, [ 'ignoreErrors' => true ] );
	$dispatcher = new TreeBuilder\Dispatcher( $treeBuilder );
	$tokenizer = new Tokenizer\Tokenizer( $dispatcher, $text, [] );
	$tokenizer->execute( [] );
	return $domBuilder->getFragment();
}

class MockDOMPostProcessor
{
	public $t;
	public $console;
	public $env;

	public function __construct($env, $options) {
		// $env->log = function($log){};
		$env->conf = (object)[];
		$env->conf->parsoid = (object)[];
		$env->conf->parsoid->rtTestMode = false;
		$this->env = $env;
		$this->pipelineId = 0;
		$this->options = $options;
		$this->domTransforms = [];
		$this->transformTime = 0;
	}

	public function log() {
		$arguments = func_get_args();
		$output = $arguments[0];
		for ($index = 1; $index < sizeof($arguments); $index++) {
			if (is_callable($arguments[$index])) {
				$output = $output . ' ' . $arguments[$index]();
			} else {
				$output = $output . ' ' . $arguments[$index];
			}
		}
		$this->console->log($output . "\n");
	}

	public function processWikitextFile($opts) {
		global $console;
		global $cachedState;
		global $cachedFilePre;
		global $cachedFilePost;
		$numFailures = 0;

		if ($cachedState == false) {
			$cachedState = true;
			$testFilePre = file_get_contents($opts->inputFile . '-' . $opts->transformer . '-pre.txt');
			$testFilePost = file_get_contents($opts->inputFile . '-' . $opts->transformer . '-post.txt');

			$testFilePre = mb_convert_encoding($testFilePre, 'UTF-8', mb_detect_encoding($testFilePre, 'UTF-8, ISO-8859-1', true));
			$testFilePost = mb_convert_encoding($testFilePost, 'UTF-8', mb_detect_encoding($testFilePost, 'UTF-8, ISO-8859-1', true));

			// Hack to fix trailing newline being moved to bode final </body> by domino, remove when fixed in domino
			if ($testFilePre[strlen($testFilePre) - 1] === '\n') { $testFilePre = substr($testFilePre, 0, -1); }
			if ($testFilePost[strlen($testFilePost)- 1] === '\n') { $testFilePost = substr($testFilePost, 0, -1); }
			$cachedFilePre = $testFilePre;
			$cachedFilePost = $testFilePost;
		} else {
			$testFilePre = $cachedFilePre;
			$testFilePost = $cachedFilePost;
		}

		$domBuilder = new DOM\DOMBuilder;
		$serializer = new DOM\DOMSerializer($domBuilder, new Serializer\HtmlFormatter);
		$env = new Env();

		$dom = buildDOM($domBuilder, $testFilePre);

		$startTime = PHPUtil::getStartHRTime();

		switch ($opts->transformer) {
			case 'dsr':
				computeDSR($dom->getElementsByTagName('body')->item(0),
				// genTest must specify dsr sourceOffsets as data-parsoid info
				$env, ['sourceOffsets' => [DU::getDataParsoid($dom->getElementsByTagName('body')->item(0))], 'attrExpansion' => false]);
				break;
			case 'cleanupFormattingTagFixup':
				cleanupFormattingTagFixup($dom->getElementsByTagName('body')->item(0), $env);
				break;
			case 'sections' :
				wrapSections($dom->getElementsByTagName('body')->item(0), $env, null);
				break;
			case 'pwrap' :
				pwrapDOM($dom->getElementsByTagName('body')->item(0), $env, null);
				break;
		}

		$this->transformTime += PHPUtil::getHRTimeDifferential($startTime);

		if (isset($dumpDOM)) {
			print $serializer->getResult();
		}

		return $numFailures;
	}

	public function wikitextFile($opts) {
		global $console;
		$numFailures = 0;
		$iterator = 1;

		if (isset($opts->timingMode)) {
			if (isset($opts->iterationCount)) {
				$iterator = $opts->iterationCount;
			} else {
				$iterator = 10000;  // defaults to 10000 iterations
			}
		}

		if(!isset($commandLine->timingMode)) {
			$console->log("Starting wikitext dom test, file = " . $opts->inputFile . "-" . $opts->transformer . "-pre.txt and -post.txt\n\n");
		}

		while ($iterator--) {
			$numFailures += $this->ProcessWikitextFile($opts);
		}

		if(!isset($commandLine->timingMode)) {
			$console->log("Ending wikitext dom test, file = " . $opts->inputFile . "-" . $opts->transformer . "-pre.txt and -post.txt\n\n");
		}
		return $numFailures;
	}
};

// processArguments handles a subset of javascript yargs like processing for command line
// parameters setting object elements to the key name. If no value follows the key,
// it is set to true, otherwise it is set to the value. The key can be followed by a
// space then value, or an equals symbol then the value. Parameters that are not
// preceded with -- are stored in the element _array at their argv index as text.
// There is no security checking for the text being processed by the dangerous eval() function.
function processArguments($argc, $argv) {
	$opts = (object)[];
	$last = false;
	for ($index=1; $index < $argc; $index++) {
		$text = $argv[$index];
		if ('--' === substr($text, 0, 2)) {
			$assignOffset = strpos($text, '=', 3);
			if ($assignOffset === false) {
				$key = substr($text, 2);
				$last = $key;
				eval('$opts->' . $key . '=true;');
			} else {
				$value = substr($text, $assignOffset+1);
				$key = substr($text, 2, $assignOffset-2);
				$last = false;
				eval('$opts->' . $key . '=\'' . $value . '\';');
			}
		} else
			if ($last === false) {
				eval('$opts->_array[' . ($index-1) . ']=\'' . $text . '\';');
			} else {
				eval('$opts->' . $last . '=\'' . $text . '\';');
			}
	}
	return $opts;
}

function runTests($argc, $argv) {
	global $console;
	$numFailures = 0;

	$opts = processArguments($argc, $argv);

	if (isset($opts->help)) {
		$console->log("must specify [--timingMode] [--iterationCount=XXX] --transformer NAME --inputFile path/wikiName");
		return;
	}

	if (!isset($opts->inputFile)) {
		$console->log("must specify --transformer NAME --inputFile /path/wikiName\n");
		$console->log("Run node bin/domTests.php --help for more information\n");
		return;
	}

	$mockEnv = (object)[];
	$manager = new MockDOMPostProcessor($mockEnv, function () {});
	if (isset($opts->log)) {
		$manager->env = [ 'log' => [ $manager, 'log' ] ];
	} else {
		$manager->env = [ 'log' => function () {} ];	// this disables detailed logging
	}

	if (isset($opts->timingMode)) {
		$console->log("Timing Mode enabled, no console output expected till test completes\n");
	}

	$startTime = PHPUtil::getStartHRTime();

	$numFailures = $manager->wikitextFile($opts);

	$totalTime = PHPUtil::getHRTimeDifferential($startTime);

	$console->log("Total DOM test execution time        = " . $totalTime . " milliseconds\n");
	$console->log("Total time processing DOM transforms = " . round($manager->transformTime, 3) . " milliseconds\n");

	if ($numFailures) {
		$console->log('Total failures: ' . $numFailures);
		exit(1);
	}
}

runTests($argc, $argv);

?>
