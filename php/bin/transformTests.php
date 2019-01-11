<?php

/* Starting point for transformerTests.php
*/

/*
Token transform unit test system

Purpose:
 During the porting of Parsoid to PHP, we need a system to capture
 and replay Javascript Parsoid token handler behavior and performance
 so we can duplicate the functionality and verify adequate performance.

 The transformerTest.js program works in concert with Parsoid and special
 capabilities added to the TokenTransformationManager.js file which
 now has token transformer test generation capabilities that produce test
 files from existing wiki pages or any wikitext. The Parsoid generated tests
 contain the specific handler name chosen for generation and the pipeline
 that was associated with the transformation execution. The pipeline ID
 is used by transformTest.js to properly order the replaying of the
 transformers input and output sequencing for validation.

 Manually written tests are supported and use a slightly different format
 which more closely resembles parserTest.txt and allows the test writer
 to identify each test with a unique description and combine tests
 for different token handlers in the same file, though only one handlers
 code can be validated and performance timed.

Technical details:
 The test validator and handler runtime emulates the normal
 Parsoid token transform manager behavior and handles tests sequences that
 were generated by multiple pipelines and uses the pipeline ID to call
 the transformers in sorted execution order to deal with parsoids
 execution order not completing each pipelined sequence in order.
 The system utilizes the transformers initialization code to install handler
 functions in a generalized way and run the test without specific
 transformer bindings.

 To create a test from an existing wikitext page, run the following
 commands, for example:
 $ node bin/parse.js --genTest QuoteTransformer,quoteTestFile.txt
 --pageName 'skating' < /dev/null > /tmp/output

 For command line options and required parameters, type:
 $ node bin/transformerTest.js --help

 An example command line to validate and performance test the 'skating'
 wikipage created as a QuoteTransformer test:
 $ node bin/transformTests.js --log --QuoteTransformer --inputFile quoteTestFile.txt

 TokenStreamPatcher, BehaviorSwitchHandler and SanitizerHandler are
 implemented but may need further debugging and manual tests written.
 */

//'use strict';

require_once (__DIR__.'/../vendor/autoload.php');

require_once (__DIR__.'/../lib/config/WikitextConstants.php');
require_once (__DIR__.'/../lib/utils/phputils.php');

require_once (__DIR__.'/../lib/wt2html/parser.defines.php');
require_once (__DIR__.'/../lib/wt2html/tt/QuoteTransformer.php');
require_once (__DIR__.'/../lib/wt2html/tt/ParagraphWrapper.php');
require_once (__DIR__.'/../tests/MockEnv.php');

use Parsoid\Tests\MockEnv;

use Parsoid\Lib\Config;
use Parsoid\Lib\Config\WikitextConstants;
use Parsoid\Lib\PHPUtils\PHPUtils;
use Parsoid\Lib\Wt2html\KV;
use Parsoid\Lib\Wt2html\TagTk;
use Parsoid\Lib\Wt2html\EndTagTk;
use Parsoid\Lib\Wt2html\SelfclosingTagTk;
use Parsoid\Lib\Wt2html\NlTk;
use Parsoid\Lib\Wt2html\CommentTk;
use Parsoid\Lib\Wt2html\EOFTk;

$cachedState = false;
$cachedTestLines = '';
$cachedPipeLines = '';
$cachedPipeLinesLength = [];

function makeMap( $a ) {
	$map = [];
	foreach ( $a as $e ) {
		$map[$e[0]] = $e[1];
	}

	return $map;
}

function kvsFromArray( $a ) {
	$kvs = [];
	foreach ( $a as $e ) {
		$kvs[] = new KV(
			$e["k"],
			$e["v"],
			isset($e["srcOffsets"]) ? $e["srcOffsets"] : null,
			isset($e["vsrc"]) ? $e["vsrc"] : null
		);
	};
	return $kvs;
}

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

class MockTTM {
	public $t;
	public $console;
	public $env;

	public function __construct($env, $options) {
		$this->env = $env;
		$this->pipelineId = 0;
		$this->options = $options;
		$this->defaultTransformers = [];	// any transforms
		$this->tokenTransformers   = [];	// non-any transforms
		$this->console = new console;
		$this->tokenTime = 0;
		$this->addXformTime = 0;
		$this->removeXformTime = 0;
		$this->getXformTime = 0;
		$this->init();
	}

	public function init() {
// Map of: token constructor ==> transfomer type
// Used for returning active transformers for a token
		$this->tkConstructorToTkTypeMap = makeMap([
			['String', 'text'],
			['NlTk', 'newline'],
			['CommentTk', 'comment'],
			['EOFTk', 'end'],
			['TagTk', 'tag'],
			['EndTagTk', 'tag'],
			['SelfclosingTagTk', 'tag'],
		]);
	}

	public static function tokenTransformersKey($tkType, $tagName) {
		return ($tkType === 'tag') ? "tag:" . $tagName : $tkType;
	}

	public static function _cmpTransformations($a, $b) {
		$value = $a["rank"] - $b["rank"];
		if ($value === 0) return 0;
		return ($value < 0) ? -1 : 1;
	}

	public function addTransform($transformation, $debugName, $rank, $type, $name = null) {
		global $console;
		$startTime = PHPUtils::getStartHRTime();

		$this->pipeLineModified = true;

		$t = makeMap([
			[ 'rank', $rank ],
			[ 'name', $debugName ],
			[ 'transform', $transformation ],
		]);

		if ($type === 'any') {
			// Record the any transformation
			$this->defaultTransformers[] = $t;
		} else {
			$key = $this->tokenTransformersKey($type, $name);
			if (!isset($this->tokenTransformers[$key])) {
				$this->tokenTransformers[$key] = [];
			}

			// assure no duplicate transformers
			$console->assert((function() use ($key, $t) {
					foreach ($this->tokenTransformers[$key] as $value) {
						if ($t["rank"] === $value["rank"]) {
							return true;
						}
					}
					return false;
				})(), "Trying to add a duplicate transformer: " . $t['name'] . "\n");

			$this->tokenTransformers[$key][] = $t;
			usort($this->tokenTransformers[$key], "self::_cmpTransformations");
		}
		$this->addXformTime += PHPUtils::getHRTimeDifferential($startTime);
	}

	private function removeMatchingTransform(&$transformers, $rank) {
		$i = 0;
		$n = sizeof($transformers);
		while ($i < $n && $rank !== $transformers[$i]["rank"]) {
			$i++;
		}
		array_splice($transformers, $i, 1);
	}

	public function removeTransform($rank, $type, $name = null) {
		$startTime = PHPUtils::getStartHRTime();

		$this->pipeLineModified = true;
		if ($type === 'any') {
			// Remove from default transformers
			$this->removeMatchingTransform($this->defaultTransformers, $rank);
		} else {
			$key = $this->tokenTransformersKey($type, $name);
			if (isset($this->tokenTransformers[$key])) {
				$this->removeMatchingTransform($this->tokenTransformers[$key], $rank);
			}
		}
		$this->removeXformTime += PHPUtils::getHRTimeDifferential($startTime);
	}

	public function getTransforms($token, $minRank) {
		$startTime = PHPUtils::getStartHRTime();

		$isStr = gettype($token) === "string";
		$type = $isStr ? "String" : $token->getType();
		$name = $isStr ? "" : (isset($token->name) ? $token->name : "");
		$tkType = $this->tkConstructorToTkTypeMap[$type];
		$key = $this->tokenTransformersKey($tkType, $name);
		#print "type: $type; name: $name; tkType: $tkType; key: $key"."\n";
		#var_dump($this->tokenTransformers);
		$tts = isset($this->tokenTransformers[$key]) ? $this->tokenTransformers[$key] : [];
		if (sizeof($this->defaultTransformers) > 0) {
			$tts = array_merge($tts, $this->defaultTransformers);
			usort($tts, "self::_cmpTransformations");
		}

		$i = 0;
		if ($minRank) {
			// skip transforms <= minRank
			while ($i < sizeof($tts) && $tts[$i]["rank"] <= $minRank) {
				$i += 1;
			}
		}
		$this->getXformTime += PHPUtils::getHRTimeDifferential($startTime);
		return [ 'first' => $i, 'transforms' => $tts, 'empty' => ($i >= sizeof($tts)) ];
	}

// Use the TokenTransformManager.js guts (extracted essential functionality)
// to dispatch each token to the registered token transform function
	public function ProcessTestFile($commandLine) {
		global $console;
		global $cachedState;
		global $cachedTestLines;
		$numFailures = 0;

		if (isset($commandLine->timingMode)) {
			if ($cachedState == false) {
				$cachedState = true;
				$testFile = file_get_contents($commandLine->inputFile);
				$testFile = mb_convert_encoding($testFile, 'UTF-8', mb_detect_encoding($testFile, 'UTF-8, ISO-8859-1', true));
				$testLines = explode("\n", $testFile);
				$cachedTestLines = $testLines;
			} else {
				$testLines = $cachedTestLines;
			}
		} else {
			$testFile = file_get_contents($commandLine->inputFile);
			$testFile = mb_convert_encoding($testFile, 'UTF-8', mb_detect_encoding($testFile, 'UTF-8, ISO-8859-1', true));
			$testLines = explode("\n", $testFile);
		}

		for ($index = 0; $index < sizeof($testLines); $index++) {
			$line = $testLines[$index];
			if (mb_strlen($line) < 1) {
				continue;
			}
			switch ($line[0]) {
				case '#':	// comment line
				case ' ':	// blank character at start of line
				case '':	// empty line
					break;
				case ':':
					$transformerName = substr($line, 2);
					break;
				case '!':	// start of test with name
					$testName = substr($line, 2);
					break;
				case '[':	// desired result json string for test result verification
					if (isset($result) && sizeof($result['tokens']) !== 0) {
						$stringResult = PHPUtils::json_encode($result['tokens']);
						# print "SR  : $stringResult\n";
						# print "LINE: $line\n";
						$line = preg_replace('/{}/', '[]', $line);
						$stringResult = preg_replace('/{}/', '[]', $stringResult);
						if ($stringResult === $line) {
							if(!isset($commandLine->timingMode)) {
								$console->log($testName . " ==> passed\n\n");
							}
						} else {
							$numFailures++;
							$console->log($testName . " ==> failed\n");
							$console->log("line to debug => " . $line . "\n");
							$console->log("result line ===> " . $stringResult . "\n");
						}
					}
					$result = null;
					break;
				case '{':
				default:
					if (!isset($result)) {
						$result = [ 'tokens' => [] ];
					}
					$jsTk = json_decode($line, true);
					if (gettype($jsTk) === "string") {
						$token = $jsTk;
					} else {
						switch($jsTk['type']) {
							case "SelfclosingTagTk":
								$token = new SelfclosingTagTk($jsTk['name'], kvsFromArray($jsTk['attribs']), $jsTk['dataAttribs']);
								// HACK!
								if (isset($jsTk['value'])) {
									$token->addAttribute("value", $jsTk['value']);
								}
								break;
							case "TagTk":
								$token = new TagTk($jsTk['name'], kvsFromArray($jsTk['attribs']), $jsTk['dataAttribs']);
								break;
							case "EndTagTk":
								$token = new EndTagTk($jsTk['name'], kvsFromArray($jsTk['attribs']), $jsTk['dataAttribs']);
								break;
							case "NlTk":
								$token = new NlTk($jsTk['dataAttribs']['tsr'], $jsTk['dataAttribs']);
								break;
							case "EOFTk":
								$token = new EOFTk();
								break;
							case "CommentTk":
								$token = new CommentTk($jsTk["value"], $jsTk['dataAttribs']);
								break;
						}
					}

					# print "PROCESSING $line\n";
					$startTime = PHPUtils::getStartHRTime();

					$ts = $this->getTransforms($token, 2.0);

					// Push the token through the transformations till it morphs
					$j = $ts['first'];
					$this->pipelineModified = false;
					$numTransforms = sizeof($ts['transforms']);
					while ($j < $numTransforms && !$this->pipelineModified) {
						$transformer = $ts['transforms'][$j];
						if ($transformerName === substr($transformer["name"], 0, strlen($transformerName))) {
							// Transform the token.
							$result = $res = $transformer["transform"]($token, $this, null);
							$resT = isset($res['tokens']) && !isset($res["tokens"]["rank"]) && count($res["tokens"]) === 1 ? $res["tokens"][0] : null;
							if ($resT !== $token) {
								break;
							}
						}
						$j++;
					}
					$this->tokenTime += PHPUtils::getHRTimeDifferential($startTime);
					break;
			}
		}
		return $numFailures;
	}

// Because tokens are processed in pipelines which can execute out of
// order, the unit test system creates an array of arrays to hold
// the pipeline ID which was used to process each token.
// The ProcessWikitextFile function uses the pipeline IDs to ensure
// that all token processing for each pipeline occurs in order to completion.
	private static function CreatePipelines($lines) {
		$numberOfTextLines = sizeof($lines);
		$maxPipelineID = 0;
		$LineToPipeMap = array();
		$LineToPipeMap = array_pad($LineToPipeMap, $numberOfTextLines, 0);
		for ($i = 0; $i < $numberOfTextLines; ++$i) {
			preg_match('/(\d+)/', substr($lines[$i], 0, 4), $matches);
			if (sizeof($matches) > 0) {
				$pipe = $matches[0];
				if ($maxPipelineID < $pipe) {
					$maxPipelineID = $pipe;
				}
			} else {
				$pipe = NAN;
			}
			$LineToPipeMap[$i] = $pipe;
		}
		$pipelines = array();
		$pipelines = array_pad($pipelines, $maxPipelineID + 1, []);
		for ($i = 0; $i < $numberOfTextLines; ++$i) {
			$pipe = $LineToPipeMap[$i];
			if (!is_nan($pipe)) {
				$pipelines[$pipe][] = $i;
			}
		}
		return $pipelines;
	}

// Use the TokenTransformManager.js guts (extracted essential functionality)
// to dispatch each token to the registered token transform function
	public function ProcessWikitextFile($tokenTransformer, $commandLine) {
		global $console;
		global $cachedState;
		global $cachedTestLines;
		global $cachedPipeLines;
		global $cachedPipeLinesLength;
		$numFailures = 0;

		if (isset($commandLine->timingMode)) {
			if ($cachedState == false) {
				$cachedState = true;
				$testFile = file_get_contents($commandLine->inputFile);
				$testFile = mb_convert_encoding($testFile, 'UTF-8', mb_detect_encoding($testFile, 'UTF-8, ISO-8859-1', true));
				$testLines = explode("\n", $testFile);
				$pipeLines = self::CreatePipelines($testLines);
				$numPipelines = sizeof($pipeLines);
				$cachedTestLines = $testLines;
				$cachedPipeLines = $pipeLines;
				$cachedPipeLinesLength = $numPipelines;
			} else {
				$testLines = $cachedTestLines;
				$pipeLines = $cachedPipeLines;
				$numPipelines = $cachedPipeLinesLength;
			}
		} else {
			$testFile = file_get_contents($commandLine->inputFile);
			$testFile = mb_convert_encoding($testFile, 'UTF-8', mb_detect_encoding($testFile, 'UTF-8, ISO-8859-1', true));
			$testLines = explode("\n", $testFile);
			$pipeLines = self::CreatePipelines($testLines);
			$numPipelines = sizeof($pipeLines);
		}

		for ($i = 0; $i < $numPipelines; $i++) {
			if (!isset($pipeLines[$i])) {
				continue;
			}

			$tokenTransformer->manager->pipelineId = $i;
			$p = $pipeLines[$i];
			$pLen = sizeof($p);
			for ($element = 0; $element < $pLen; $element++) {
				$line = substr($testLines[$p[$element]], 36);
				switch ($line{0}) {
					case '[':	// desired result json string for test result verification
						$stringResult = PHPUtils::json_encode($result['tokens']);
						# print "SR  : $stringResult\n";
						$line = preg_replace('/{}/', '[]', $line);
						$stringResult = preg_replace('/{}/', '[]', $stringResult);
						if ($stringResult === $line) {
							if(!isset($commandLine->timingMode)) {
								$console->log("line " . ($p[$element] + 1) . " ==> passed\n\n");
							}
						} else {
							$numFailures++;
							$console->log("line " . ($p[$element] + 1) . " ==> failed\n");
							$console->log("line to debug => " . $line . "\n");
							$console->log("result line ===> " . $stringResult . "\n");
						}
						$result = null;
						break;
					case '{':
					default:
						if (!isset($result)) {
							$result = [ 'tokens' => [] ];
						}
						$jsTk = json_decode($line, true);
						if (gettype($jsTk) === "string") {
							$token = $jsTk;
						} else {
							switch($jsTk['type']) {
								case "SelfclosingTagTk":
									$token = new SelfclosingTagTk($jsTk['name'], kvsFromArray($jsTk['attribs']), $jsTk['dataAttribs']);
									// HACK!
									if (isset($jsTk['value'])) {
										$token->addAttribute("value", $jsTk['value']);
									}
									break;
								case "TagTk":
									$token = new TagTk($jsTk['name'], kvsFromArray($jsTk['attribs']), $jsTk['dataAttribs']);
									break;
								case "EndTagTk":
									$token = new EndTagTk($jsTk['name'], kvsFromArray($jsTk['attribs']), $jsTk['dataAttribs']);
									break;
								case "NlTk":
									$token = new NlTk(isset($jsTk['dataAttribs']['tsr']) ? $jsTk['dataAttribs']['tsr'] : null, $jsTk['dataAttribs']);
									break;
								case "EOFTk":
									$token = new EOFTk();
									break;
								case "CommentTk":
									$token = new CommentTk($jsTk["value"], $jsTk['dataAttribs']);
									break;
							}
						}

						# print "PROCESSING $line\n";
						$startTime = PHPUtils::getStartHRTime();

						$ts = $this->getTransforms($token, 2.0);

						// Push the token through the transformations till it morphs
						$j = $ts['first'];
						$this->pipelineModified = false;
						$numTransforms = sizeof($ts['transforms']);
						while ($j < $numTransforms && !$this->pipelineModified) {
							$transformer = $ts['transforms'][$j];
							// Transform the token.
							$result = $res = $transformer["transform"]($token, $this, null);
							$resT = isset($res['tokens']) && !isset($res["tokens"]["rank"]) && count($res["tokens"]) === 1 ? $res["tokens"][0] : null;
							if ($resT !== $token) {
								break;
							}
							$j++;
						}
						$this->tokenTime += PHPUtils::getHRTimeDifferential($startTime);
						break;
				}
			}
		}
		return $numFailures;
	}

	public function unitTest($tokenTransformer, $commandLine) {
		global $console;

		if(!isset($commandLine->timingMode)) {
			$console->log("Starting stand alone unit test running file " . $commandLine->inputFile . "\n\n");
		}
		$numFailures = $tokenTransformer->manager->ProcessTestFile($commandLine);
		if(!isset($commandLine->timingMode)) {
			$console->log("Ending stand alone unit test running file " . $commandLine->inputFile . "\n\n");
		}
		return $numFailures;
	}

	public function wikitextTest($tokenTransformer, $commandLine) {
		global $console;

		if(!isset($commandLine->timingMode)) {
			$console->log("Starting stand alone wikitext test running file " . $commandLine->inputFile . "\n\n");
		}
		$numFailures = $tokenTransformer->manager->ProcessWikitextFile($tokenTransformer, $commandLine);
		if(!isset($commandLine->timingMode)) {
			$console->log("Ending stand alone wikitext test running file " . $commandLine->inputFile . "\n\n");
		}
		return $numFailures;
	}
};

function selectTestType($commandLine, $manager, $handler) {
	$iterator = 1;
	$numFailures = 0;
	if (isset($commandLine->timingMode)) {
		if (isset($commandLine->iterationCount)) {
			$iterator = $commandLine->iterationCount;
		} else {
			$iterator = 10000;  // defaults to 10000 iterations
		}
	}
	while ($iterator--) {
		if (isset($commandLine->manual)) {
			$numFailures = $manager->unitTest($handler, $commandLine);
		} else {
			$numFailures = $manager->wikitextTest($handler, $commandLine);
		}
	}
	return $numFailures;
}

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
		$console->log('must specify [--manual] [--log] [--timingMode] [--iterationCount=XXX] --TransformerName --inputFile /path/filename');
		return;
	}

	if (!isset($opts->inputFile)) {
		$console->log("must specify [--manual] [--log] --TransformerName --inputFile /path/filename\n");
		$console->log('Run "node bin/transformerTests.js --help" for more information'. "\n");
		return;
	}

	$mockEnv = new MockEnv($opts);
	$manager = new MockTTM($mockEnv, []);

	if (isset($opts->timingMode)) {
		$console->log("Timing Mode enabled, no console output expected till test completes\n");
	}

	$startTime = PHPUtils::getStartHRTime();

	if (isset($opts->QuoteTransformer)) {
		$qt = new Parsoid\Lib\Wt2html\TT\QuoteTransformer($manager, function () {});
		$numFailures = selectTestType($opts, $manager, $qt);
	} else if (isset($opts->ParagraphWrapper)) {
		$pw = new Parsoid\Lib\Wt2html\TT\ParagraphWrapper($manager, function () {});
		$numFailures = selectTestType($opts, $manager, $pw);
	}
	/*
	  else if ($opts->ListHandler) {
		var lh = new ListHandler(manager, {});
		selectTestType(argv, manager, lh);
	} else if ($opts->PreHandler) {
		var ph = new PreHandler(manager, {});
		selectTestType(argv, manager, ph);
	} else if ($opts->TokenStreamPatcher) {
		var tsp = new TokenStreamPatcher(manager, {});
		selectTestType(argv, manager, tsp);
	} else if ($opts->BehaviorSwitchHandler) {
		var bsh = new BehaviorSwitchHandler(manager, {});
		selectTestType(argv, manager, bsh);
	} else if ($opts->SanitizerHandler) {
		var sh = new SanitizerHandler(manager, {});
		selectTestType(argv, manager, sh);
	} */
	else {
		$console->log('No valid TransformerName was specified');
		$numFailures++;
	}

	$totalTime = PHPUtils::getHRTimeDifferential($startTime);
	$console->log('Total transformer execution time = ' . $totalTime . " milliseconds\n");
	$console->log('Total time processing tokens     = ' . round($manager->tokenTime, 3) . " milliseconds\n");
	$console->log('Total time adding transformers   = ' . round($manager->addXformTime, 3) . " milliseconds\n");
	$console->log('Total time removing transformers = ' . round($manager->removeXformTime, 3) . " milliseconds\n");
	$console->log('Total time getting transformers  = ' . round($manager->getXformTime, 3) . " milliseconds\n");
	if ($numFailures) {
		$console->log('Total failures: ' . $numFailures);
		exit(1);
	}
}

runTests($argc, $argv);

?>
