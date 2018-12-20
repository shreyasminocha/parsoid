<?php
/**
 * Insert paragraph tags where needed -- smartly and carefully
 * -- there is much fun to be had mimicking "wikitext visual newlines"
 * behavior as implemented by the PHP parser.
 * @module
 */

namespace Parsoid\Lib\Wt2html\TT;

require_once (__DIR__.'/TokenHandler.php');
require_once (__DIR__.'/../parser.defines.php');
require_once (__DIR__.'/../../utils/Utils.php');
require_once (__DIR__.'/../../utils/phputils.php');

use Parsoid\Lib\PHPUtils\PHPUtil;
use Parsoid\Lib\Utils\Util;
use Parsoid\Lib\Wt2html\TagTk;
use Parsoid\Lib\Wt2html\EndTagTk;
use Parsoid\Lib\Wt2html\SelfclosingTagTk;

function makeSet( $a ) {
	$set = [];
	foreach ( $a as $e ) {
		$set[$e] = true;
	}

	return $set;
}

// These are defined in the php parser's `BlockLevelPass`
$blockElems = makeSet([ 'TABLE', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'PRE', 'P', 'UL', 'OL', 'DL' ]);
$antiBlockElems = makeSet([ 'TD', 'TH' ]);
$alwaysSuppress = makeSet([ 'TR', 'DT', 'DD', 'LI' ]);
$neverSuppress = makeSet([ 'CENTER', 'BLOCKQUOTE', 'DIV', 'HR', 'FIGURE' ]);

/**
 * @class
 * @extends module:wt2html/tt/TokenHandler
 */
class ParagraphWrapper extends TokenHandler {
	public $inPre;
	public $hasOpenPTag;
	public $inBlockElem;
	public $tokenBuffer;
	public $nlWsTokens;
	public $newLineCount;
	public $currLine;

	public function __construct($manager, $options) {
		parent::__construct($manager, $options);
		$this->inPre = false;
		$this->hasOpenPTag = false;
		$this->inBlockElem = false;
		$this->tokenBuffer = [];
		$this->nlWsTokens = [];
		$this->newLineCount = 0;
		$this->currLine = null;

		// Disable p-wrapper
		if (!isset($this->options->inlineContext) && !isset($this->options->inPHPBlock)) {
			$this->manager->addTransform(
				function ($token) { return $this->onNewLineOrEOF($token); },
				'ParagraphWrapper:onNewLine',
				ParagraphWrapper::NEWLINE_RANK(),
				'newline'
			);
			$this->manager->addTransform(
				function ($token) { return $this->onAny($token); },
				'ParagraphWrapper:onAny',
				ParagraphWrapper::ANY_RANK(),
				'any'
			);
			$this->manager->addTransform(
				function ($token) { return $this->onNewLineOrEOF($token); },
				'ParagraphWrapper:onEnd',
				ParagraphWrapper::END_RANK(),
				'end');
		}
		$this->reset();
	}

	// Ranks for token handlers
	// EOF/NL tokens will also match 'ANY' handlers.
	// However, we want them in the custom eof/newline handlers.
	// So, set them to a lower rank (= higher priority) than
	// the any handler.
	public static function END_RANK() { return 2.95; }
	public static function NEWLINE_RANK() { return 2.96; }
	public static function ANY_RANK() { return 2.97; }
	// If a handler sends back the incoming 'token' back without change,
	// the SyncTTM (or AsyncTTM) will dispatch it to other matching handlers.
	// But, we don't want processed tokens coming back into the P-handler again.
	// To prevent this, we can set a rank on the token block to a higher value
	// than all handlers here. Hence, SKIP_RANK has to be larger than the
	// others above.
	public static function SKIP_RANK() { return 2.971; }

	public function reset() {
		if ($this->inPre) {
			// Clean up in case we run into EOF before seeing a </pre>
			$this->manager->addTransform(
				function ($token) { return $this->onNewLineOrEOF($token); },
				"ParagraphWrapper:onNewLine",
				ParagraphWrapper::NEWLINE_RANK(),
				'newline'
			);
		}
		// This is the ordering of buffered tokens and how they should get emitted:
		//
		//   token-buffer         (from previous lines if newLineCount > 0)
		//   newline-ws-tokens    (buffered nl+sol-transparent tokens since last non-nl-token)
		//   current-line-tokens  (all tokens after newline-ws-tokens)
		//
		// newline-token-count is > 0 only when we encounter multiple "empty lines".
		//
		// Periodically, when it is clear where an open/close p-tag is required, the buffers
		// are collapsed and emitted. Wherever tokens are buffered/emitted, verify that this
		// order is preserved.
		$this->resetBuffers();
		$this->resetCurrLine();
		$this->hasOpenPTag = false;
		$this->inPre = false;
		// NOTE: This flag is the local equivalent of what we're mimicking with
		// the inPHPBlock pipeline option.
		$this->inBlockElem = false;
	}

	public function resetBuffers() {
		$this->tokenBuffer = [];
		$this->nlWsTokens = [];
		$this->newLineCount = 0;
	}

	public function resetCurrLine() {
		if ($this->currLine && $this->currLine["openMatch"] || $this->currLine["closeMatch"]) {
			$this->inBlockElem = !$this->currLine["closeMatch"];
		}
		$this->currLine = [
			"tokens" => [],
			"hasWrappableTokens" => false,
			// These flags, along with `inBlockElem` are concepts from the
			// php parser's `BlockLevelPass`.
			"openMatch" => false,
			"closeMatch" => false
		];
	}

	public function _processBuffers($token, $flushCurrentLine) {
		$res = $this->processPendingNLs();
		$this->currLine["tokens"][] = $token;
		if ($flushCurrentLine) {
			$res = array_merge($res, $this->currLine["tokens"]);
			$this->resetCurrLine();
		}
		$this->env->log("trace/p-wrap", $this->manager->pipelineId, "---->  ", function () use($res) {
			return PHPUtil::json_encode($res);
		});
		# FIXME!!
		# $res["rank"] = ParagraphWrapper::SKIP_RANK(); // ensure this propagates further in the pipeline, and doesn't hit the AnyHandler
		return $res;
	}

	public function _flushBuffers() {
		// Assertion to catch bugs in p-wrapping; both cannot be true.
		if ($this->newLineCount > 0) {
			$this->manager->env->log("error/p-wrap", "Failed assertion in _flushBuffers: newline-count:", $this->newLineCount, "; buffered tokens: ", PHPUtil::json_encode($this->nlWsTokens));
		}
		$resToks = array_merge($this->tokenBuffer, $this->nlWsTokens);
		$this->resetBuffers();
		$this->env->log("trace/p-wrap", $this->manager->pipelineId, "---->  ", function () use($resToks) {
			return PHPUtil::json_encode($resToks);
		});
		# FIXME!!
		# $resToks["rank"] = ParagraphWrapper::SKIP_RANK(); // ensure this propagates further in the pipeline, and doesn't hit the AnyHandler
		return $resToks;
	}

	public function discardOneNlTk(&$out) {
		$i = 0;
		$n = count($this->nlWsTokens);
		while ($i < $n) {
			$t = array_shift($this->nlWsTokens);
			if (Util::getType($t) === "NlTk") {
				return $t;
			} else {
				$out[] = $t;
			}
			$i++;
		}
		return "";
	}

	public function openPTag(&$out) {
		if (!$this->hasOpenPTag) {
			$tplStartIndex = -1;
			// Be careful not to expand template ranges unnecessarily.
			// Look for open markers before starting a p-tag.
			for ($i = 0; $i < count($out); $i++) {
				$t = $out[$i];
				$tt = Util::getType($t);
				if ($tt !== "String" && $t->name === "meta") {
					$typeOf = $t->getAttribute("typeof");
					if (preg_match('/^mw:Transclusion$/', $typeOf)) {
						// We hit a start tag and everything before it is sol-transparent.
						$tplStartIndex = $i;
						continue;
					} else if (preg_match('/^mw:Transclusion/', $typeOf)) {
						// End tag. All tokens before this are sol-transparent.
						// Let us leave them all out of the p-wrapping.
						$tplStartIndex = -1;
						continue;
					}
				}
				// Not a transclusion meta; Check for nl/sol-transparent tokens
				// and leave them out of the p-wrapping.
				if (!Util::isSolTransparent($this->env, $t) && $tt !== "NlTk") {
					break;
				}
			}
			if ($tplStartIndex > -1) {
				$i = $tplStartIndex;
			}
			array_splice($out, $i, 0, [new TagTk('p')]);
			$this->hasOpenPTag = true;
		}
	}

	public function closeOpenPTag(&$out) {
		if ($this->hasOpenPTag) {
			$tplEndIndex = -1;
			// Be careful not to expand template ranges unnecessarily.
			// Look for open markers before closing.
			for ($i = count($out) - 1; $i > -1; $i--) {
				$t = $out[$i];
				$tt = Util::getType($t);
				if (($tt == 'TagTk' || $tt == 'EndTagTk' || $tt == 'SelfclosingTagTk') && $t->name === "meta") {
					$typeOf = $t->getAttribute("typeof");
					if (preg_match('/^mw:Transclusion$/', $typeOf)) {
						// We hit a start tag and everything after it is sol-transparent.
						// Don't include the sol-transparent tags OR the start tag.
						$tplEndIndex = -1;
						continue;
					} else if (preg_match('/^mw:Transclusion/', $typeOf)) {
						// End tag. The rest of the tags past this are sol-transparent.
						// Let us leave them all out of the p-wrapping.
						$tplEndIndex = $i;
						continue;
					}
				}
				// Not a transclusion meta; Check for nl/sol-transparent tokens
				// and leave them out of the p-wrapping.
				if (!Util::isSolTransparent($this->env, $t) && $tt !== "NlTk") {
					break;
				}
			}
			if ($tplEndIndex > -1) {
				$i = $tplEndIndex;
			}
			array_splice($out, $i + 1, 0, [new EndTagTk('p')]);
			$this->hasOpenPTag = false;
		}
	}

	// Handle NEWLINE tokens
	public function onNewLineOrEOF($token) {
		$this->manager->env->log("trace/p-wrap", $this->manager->pipelineId, "NL    |", function () use($token) {
			return PHPUtil::json_encode($token);
		});
		$l = $this->currLine;
		if ($this->currLine["openMatch"] || $this->currLine["closeMatch"]) {
			$this->closeOpenPTag($l["tokens"]);
		} else if (!$this->inBlockElem && !$this->hasOpenPTag && $l["hasWrappableTokens"]) {
			$this->openPTag($l["tokens"]);
		}

		// Assertion to catch bugs in p-wrapping; both cannot be true.
		if ($this->newLineCount > 0 && count($l["tokens"]) > 0) {
			$this->env->log("error/p-wrap", "Failed assertion in onNewLineOrEOF: newline-count:", $this->newLineCount, "; current line tokens: ", PHPUtil::json_encode($l["tokens"]));
		}

		$this->tokenBuffer = array_merge($this->tokenBuffer, $l["tokens"]);

		if ($token->getType() === "EOFTk") {
			$this->nlWsTokens[] = $token;
			$this->closeOpenPTag($this->tokenBuffer);
			$res = $this->processPendingNLs();
			$this->reset();
			$this->env->log("trace/p-wrap", $this->manager->pipelineId, "---->  ", function () use($res) {
				return PHPUtil::json_encode($res);
			});
			# FIXME!!
			# $res["rank"] = ParagraphWrapper::SKIP_RANK(); // ensure this propagates further in the pipeline, and doesn't hit the AnyHandler
			return [ "tokens" => $res ];
		} else {
			$this->resetCurrLine();
			$this->newLineCount++;
			$this->nlWsTokens[] = $token;
			return [ "tokens" => [] ];
		}
	}

	public function processPendingNLs() {
		$resToks = $this->tokenBuffer;
		$newLineCount = $this->newLineCount;
		$nlTk = null;

		$this->manager->env->log("trace/p-wrap", $this->manager->pipelineId, "        NL-count:", $newLineCount);

		if ($newLineCount >= 2 && !$this->inBlockElem) {
			$this->closeOpenPTag($resToks);

			// First is emitted as a literal newline
			$resToks[] = $this->discardOneNlTk($resToks);
			$newLineCount -= 1;

			$remainder = $newLineCount % 2;

			while ($newLineCount > 0) {
				$nlTk = $this->discardOneNlTk($resToks);
				if ($newLineCount % 2 === $remainder) {
					if ($this->hasOpenPTag) {
						$resToks[] = new EndTagTk('p');
						$this->hasOpenPTag = false;
					}
					if ($newLineCount > 1) {
						$resToks[] = new TagTk('p');
						$this->hasOpenPTag = true;
					}
				} else {
					$resToks[] = new SelfclosingTagTk('br');
				}
				$resToks[] = $nlTk;
				$newLineCount -= 1;
			}
		}

		if ($this->currLine["openMatch"] || $this->currLine["closeMatch"]) {
			$this->closeOpenPTag($resToks);
			if ($newLineCount === 1) {
				$resToks[] = $this->discardOneNlTk($resToks);
			}
		}

		// Gather remaining ws and nl tokens

		$resToks = array_merge($resToks, $this->nlWsTokens);

		// reset buffers
		$this->resetBuffers();

		return $resToks;
	}

	public function onAny($token) {
		global $blockElems, $antiBlockElems, $alwaysSuppress, $neverSuppress;
		$this->manager->env->log("trace/p-wrap", $this->manager->pipelineId, "ANY   |", function () use($token) {
			return PHPUtil::json_encode($token);
		});
		$res = null;
		$tc = Util::getType($token);
		if ($tc === "TagTk" && $token->name === 'pre' && !Util::isHTMLTag($token)) {
			if ($this->inBlockElem) {
				$this->currLine["tokens"][] = ' ';
				return [ "tokens" => [] ];
			} else {
				$this->manager->removeTransform(ParagraphWrapper::NEWLINE_RANK(), 'newline');
				$this->inPre = true;
				// This will put us `inBlockElem`, so we need the extra `!inPre`
				// condition below.  Presumably, we couldn't have entered
				// `inBlockElem` while being `inPre`.  Alternatively, we could say
				// that index-pre is "never suppressing" and set the `closeMatch`
				// flag.  The point of all this is that we want to close any open
				// p-tags.
				$this->currLine["openMatch"] = true;
				return [ "tokens" => $this->_processBuffers($token, true) ];
			}
		} else if ($tc === "EndTagTk" && $token->name === 'pre' && !Util::isHTMLTag($token)) {
			if ($this->inBlockElem && !$this->inPre) {
				// No pre-tokens inside block tags -- swallow it.
				return [ "tokens" => [] ];
			} else {
				if ($this->inPre) {
					$this->manager->addTransform(function ($token) {
						return $this->onNewLineOrEOF($token);
					}
					, "ParagraphWrapper:onNewLine", ParagraphWrapper::NEWLINE_RANK(), 'newline');
					$this->inPre = false;
				}
				$this->currLine["closeMatch"] = true;
				$this->env->log("trace/p-wrap", $this->manager->pipelineId, "---->  ", function () use($token) {
					return PHPUtil::json_encode($token);
				});
				$res = [ $token ];
				# FIXME!!
				# $res["rank"] = ParagraphWrapper::SKIP_RANK(); // ensure this propagates further in the pipeline, and doesn't hit the AnyHandler
				return [ "tokens" => $res ];
			}
		} else if ($tc === "EOFTk" || $this->inPre) {
			$this->env->log("trace/p-wrap", $this->manager->pipelineId, "---->  ", function () use($token) {
				return PHPUtil::json_encode($token);
			});
			$res = [ $token ];
			# FIXME!!
			# $res["rank"] = ParagraphWrapper::SKIP_RANK(); // ensure this propagates further in the pipeline, and doesn't hit the AnyHandler
			return [ "tokens" => $res ];
		} else if ($tc === "CommentTk" || $tc === "String" && preg_match('/^[\t ]*$/', $token) || Util::isEmptyLineMetaToken($token)) {
			if ($this->newLineCount === 0) {
				$this->currLine["tokens"][] = $token;
				// Since we have no pending newlines to trip us up,
				// no need to buffer -- just flush everything
				return [ "tokens" => $this->_flushBuffers() ];
			} else {
				// We are in buffering mode waiting till we are ready to
				// process pending newlines.
				$this->nlWsTokens[] = $token;
				return [ "tokens" => [] ];
			}
		} else if ($tc !== "String" && Util::isSolTransparent($this->env, $token)) {
			if ($this->newLineCount === 0) {
				$this->currLine["tokens"][] = $token;
				// Since we have no pending newlines to trip us up,
				// no need to buffer -- just flush everything
				return [ "tokens" => $this->_flushBuffers() ];
			} else if ($this->newLineCount === 1) {
				// Swallow newline, whitespace, comments, and the current line
				$this->tokenBuffer = array_merge($this->tokenBuffer, $this->nlWsTokens);
				$this->tokenBuffer = array_merge($this->tokenBuffer, $this->currLine["tokens"]);
				$this->newLineCount = 0;
				$this->nlWsTokens = [];
				$this->resetCurrLine();

				// But, don't process the new token yet.
				$this->currLine["tokens"][] = $token;
				return [ "tokens" => [] ];
			} else {
				return [ "tokens" => $this->_processBuffers($token, false) ];
			}
		} else {
			$name = strtoupper($tc == 'String' ? "" : $token->name);
			if (isset($blockElems[$name]) && $tc !== "EndTagTk" || isset($antiBlockElems[$name]) && $tc === "EndTagTk" || isset($alwaysSuppress[$name])) {
				$this->currLine["openMatch"] = true;
			}
			if (isset($blockElems[$name]) && $tc === "EndTagTk" || isset($antiBlockElems[$name]) && $tc !== "EndTagTk" || isset($neverSuppress[$name])) {
				$this->currLine["closeMatch"] = true;
			}
			$this->currLine["hasWrappableTokens"] = true;
			return [ "tokens" => $this->_processBuffers($token, false) ];
		}
	}
}

?>
