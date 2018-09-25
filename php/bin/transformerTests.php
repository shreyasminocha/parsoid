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
 is used by transfoermTest.js to properly order the replaying of the
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

require_once (__DIR__.'/../lib/config/Env.php');
require_once (__DIR__.'/../lib/config/WikitextConstants.php');

require_once (__DIR__.'/../lib/wt2html/parser.defines.php');
require_once (__DIR__.'/../lib/wt2html/tt/QuoteTransformer.php');

use Parsoid\Lib\Config;
use Parsoid\Lib\Config\Env;
use Parsoid\Lib\Config\WikitextConstants;
use Parsoid\Lib\Wt2html\KV;
use Parsoid\Lib\Wt2html\TagTk;
use Parsoid\Lib\Wt2html\EndTagTk;
use Parsoid\Lib\Wt2html\SelfclosingTagTk;
use Parsoid\Lib\Wt2html\NlTk;
use Parsoid\Lib\Wt2html\CommentTk;
use Parsoid\Lib\Wt2html\EOFTk;

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
		$kvs[] = new KV($e["k"], $e["v"]);
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
		$this->init();
	}

	public function log() {
		$output = $arguments[0];
		for ($index = 1; $index < sizeof($arguments); $index++) {
            if (is_callable($arguments[$index])) {
				$output = $output . ' ' . $arguments[$index];
			} else {
				$output = $output . ' ' . $arguments[$index];
			}
		}
		$console->log($output);
	}

    public function init()
    {
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
				})(), "Trying to add a duplicate transformer: " . $t['name']);

			$this->tokenTransformers[$key][] = $t;
			usort($this->tokenTransformers[$key], "self::_cmpTransformations");
		}
	}

	private function removeMatchingTransform($transformers, $rank) {
		$i = 0;
		$n = sizeof($transformers);
		while ($i < $n && $rank !== $transformers[$i]["rank"]) {
			$i++;
		}
		$transformers = array_splice($transformers, $i, 1);
	}

	public function removeTransform($rank, $type, $name = null) {
		if ($type === 'any') {
			// Remove from default transformers
			$this->removeMatchingTransform($this->defaultTransformers, $rank);
		} else {
			$key = $this->tokenTransformersKey($type, $name);
			if (isset($this->tokenTransformers[$key])) {
				$this->removeMatchingTransform($this->tokenTransformers[$key], $rank);
			}
		}
	}

	public function getTransforms($token, $minRank) {
		$isStr = gettype($token) == "string";
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
		return [ 'first' => $i, 'transforms' => $tts, 'empty' => ($i >= sizeof($tts)) ];
	}

// Use the TokenTransformManager.js guts (extracted essential functionality)
// to dispatch each token to the registered token transform function
	public function ProcessTestFile($fileName) {
		global $console;

		$testFile = file_get_contents($fileName);
		$testFile = mb_convert_encoding($testFile, 'UTF-8', mb_detect_encoding($testFile, 'UTF-8, ISO-8859-1', true));
		$testLines = explode("\n", $testFile);
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
						$stringResult = json_encode($result['tokens']);
						print "SR  : $stringResult\n";
						print "LINE: $line\n";
						if ($stringResult === $line) {
							$console->log($testName . ' ==> passed\n');
						} else {
							$console->log($testName . ' ==> failed');
							$console->log('line to debug => ' . $line);
							$console->log('result line ===> ' . $stringResult . "\n");
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
					switch(gettype($jsTk)) {
						case "string":
						   break;
						case "array":
							switch($jsTk['type']) {
							case "SelfclosingTagTk":
								$token = new SelfclosingTagTk($jsTk['name'], kvsFromArray($jsTk['attribs']), $jsTk['dataAttribs']);
								// HACK!
								if ($jsTk['value']) {
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
								$token = new NlTk($jsTk['dataAttribs']['tsr']);
								break;
							case "EOFTk":
								$token = new EOFTk();
								break;
							case "COMMENT":
								$token = new CommentTk($jsTk["value"], $jsTk['dataAttribs']);
								break;
							}
							break;
					}
					$res = [ 'token' => $token ];
					print "PROCESSING $line\n";
					$ts = $this->getTransforms($token, 2.0);
					// Push the token through the transformations till it morphs
					$j = $ts['first'];
					$numTransforms = sizeof($ts['transforms']);
					#print "T: ".$token->getType().": ".$numTransforms."\n";
					while ($j < $numTransforms && isset($res["token"]) && ($token === $res["token"])) {
						$transformer = $ts['transforms'][$j];
						if ($transformerName === substr($transformer["name"], 0, strlen($transformerName))) {
							// Transform the token.
							$res = $transformer["transform"]($token, $this, null);
							if (isset($res['tokens'])) {
								$result['tokens'] = array_merge($result['tokens'], $res['tokens']);
							} else if (isset($res['token']) && $res['token'] !== $token) {
								$result['tokens'][] = $res['token'];
							}
						}
						$j++;
					}
					break;
			}
		}
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
		$i;
		$pipe;
		for ($i = 0; $i < $numberOfTextLines; ++$i) {
			$number = substr($lines[$i], 0, 4);
			preg_match('/\\d+/', $number, $number);
			$number = implode("", $number);
			if (ctype_digit($number)) {
				$pipe = intval($number, 10);    // pipeline ID's should not exceed 9999\
				if ($maxPipelineID < $pipe) {
					$maxPipelineID = $pipe;
				}
			} else {
				$pipe = NAN;
				$LineToPipeMap[$i] = $pipe;
			}
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
	public function ProcessWikitextFile($tokenTransformer, $fileName) {
		global $console;

		$testFile = file_get_contents($fileName);
		$testFile = mb_convert_encoding($testFile, 'UTF-8', mb_detect_encoding($testFile, 'UTF-8, ISO-8859-1', true));
		$testLines = explode("\n", $testFile);
		$pipeLines = self::CreatePipelines($testLines);
		$pipeLinesLength = sizeof($pipeLines);
		for ($index = 0; $index < $pipeLinesLength; $index++) {
			if (isset($pipeLines[$index])) {
				$tokenTransformer->manager->pipelineId = $index;
				$pipeLength = sizeof($pipeLines[$index]);
				for ($element = 0; $element < $pipeLength; $element++) {
					$line = substr($testLines[($pipeLines[$index])[$element]], 36);
					switch ($line{0}) {
						case '[':	// desired result json string for test result verification
							$stringResult = json_encode($result['tokens']);
							if ($stringResult === $line) {
								$console->log('line ' . (($pipeLines[$index])[$element] + 1) . ' ==> passed\n');
							} else {
								$console->log('line ' . (($pipeLines[$index])[$element] + 1) . ' ==> failed');
								$console->log('line to debug => ' . $line);
								$console->log('result line ===> ' . $stringResult . "\n");
							}
							$result = null;
							break;
						case '{':
						default:
/*							if (!isset($result)) {
								$result = [ 'tokens' => [] ];
							}
							$token = json_encode($line);
							if ($token->constructor !== String) {	// cast object to token type
								$token->prototype = $token->constructor = $defines[$token->type];
							}
							$ts = $this->getTransforms($token, 2.0);
							$res = [ 'token' => $token ];

							// Push the token through the transformations till it morphs
							$j = $ts['first'];
							$numTransforms = sizeof($ts['transforms']);
							while ($j < $numTransforms && ($token === $res->token)) {
								$transformer = $ts['transforms'][$j];
								// Transform the token.
								$res = $transformer["transform"]($token, $this);
								if ($res->tokens) {
									$result->tokens = array_merge($result->tokens, $res->tokens);
								} else if ($res->token && $res->token !== $token) {
									$result->tokens[] = $res->token;
								}
								$j++;
							}
*/
						if (!isset($result)) {
							$result = [ 'tokens' => [] ];
						}
						$jsTk = json_decode($line, true);
						switch(gettype($jsTk)) {
							case "string":
								break;
							case "array":
								switch($jsTk['type']) {
									case "SelfclosingTagTk":
										$token = new SelfclosingTagTk($jsTk['name'], kvsFromArray($jsTk['attribs']), $jsTk['dataAttribs']);
										// HACK!
										if ($jsTk['value']) {
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
										$token = new NlTk($jsTk['dataAttribs']['tsr']);
										break;
									case "EOFTk":
										$token = new EOFTk();
										break;
									case "COMMENT":
										$token = new CommentTk($jsTk["value"], $jsTk['dataAttribs']);
										break;
								}
								break;
						}
						$res = [ 'token' => $token ];
						print "PROCESSING $line\n";
						$ts = $this->getTransforms($token, 2.0);
						// Push the token through the transformations till it morphs
						$j = $ts['first'];
						$numTransforms = sizeof($ts['transforms']);
						#print "T: ".$token->getType().": ".$numTransforms."\n";
						while ($j < $numTransforms && isset($res["token"]) && ($token === $res["token"])) {
							$transformer = $ts['transforms'][$j];
							// Transform the token.
							$res = $transformer["transform"]($token, $this, null);
							if (isset($res['tokens'])) {
								$result['tokens'] = array_merge($result['tokens'], $res['tokens']);
							} else if (isset($res['token']) && $res['token'] !== $token) {
								$result['tokens'][] = $res['token'];
							}
							$j++;
						}

							break;
					}
				}
			}
		}
	}

	public static function unitTest($tokenTransformer, $testFile) {
		global $console;

		$console->log("Starting stand alone unit test running file " . $testFile . "\n");
		$tokenTransformer->manager->ProcessTestFile($testFile);
		$console->log("Ending stand alone unit test running file " . $testFile . "\n");
	}

	public static function wikitextTest($tokenTransformer, $testFile) {
		global $console;

		$console->log("Starting stand alone wikitext test running file " . $testFile . "\n");
		$tokenTransformer->manager->ProcessWikitextFile($tokenTransformer, $testFile);
		$console->log("Ending stand alone wikitext test running file " . $testFile . "\n");
	}
};

function selectTestType($commandLine, $manager, $handler) {
	if (isset($commandLine->manual)) {
		$manager->unitTest($handler, $commandLine->inputFile);
	} else {
		$manager->wikitextTest($handler, $commandLine->inputFile);
	}
}

// processArguments handles a subset of javascript yargs like processing for command line
// parameters setting object elements to the key name. If no value follows the key,
// it is set to true, otherwise it is set to the value. The key can be followed by a
// space then value, or an equals symbol then the value. Parameters that are not
// preceded with -- are stored in the element _array at their argv index as text.
// There is no security checking for the text being processed by the dangerous eval() function.
function processArguments($argc, $argv) {
	$opts = new stdClass();
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

    $opts = processArguments($argc, $argv);

	if (isset($opts->help)) {
		//$opts->showHelp();
		return;
	}

	if (!isset($opts->inputFile)) {
		$console->log('must specify [--manual] [--log] --TransformerName --inputFile /path/filename');
		$console->log('type: "node bin/transformerTests.js --help" for more information');
		return;
	}

	$mockEnv = [];
	if (isset($opts->log)) {
		$mockEnv = [ 'log' => $MockTTM->log ];
	} else {
		$mockEnv = [ 'log' => function () {} ];	// this disables detailed logging
	}

	$manager = new MockTTM($mockEnv, function () {});

	$startTime = microtime(true);

	if ($opts->QuoteTransformer) {
		$qt = new Parsoid\Lib\Wt2html\TT\QuoteTransformer($manager, function () {});
		selectTestType($opts, $manager, $qt);
	}
	/*
	  else if ($opts->ListHandler) {
		var lh = new ListHandler(manager, {});
		selectTestType(argv, manager, lh);
	} else if ($opts->ParagraphWrapper) {
		var pw = new ParagraphWrapper(manager, {});
		selectTestType(argv, manager, pw);
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
	}

	$totalTime = microtime(true) - $startTime;
	$console->log('Total transformer execution time = ' . $totalTime . ' milliseconds');
}

runTests($argc, $argv);

?>

