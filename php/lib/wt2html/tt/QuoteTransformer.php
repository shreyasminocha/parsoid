<?php
/**
 * MediaWiki-compatible italic/bold handling as a token stream transformation.
 * @module - port of javascript QuoteTransformer.js to php
 */

namespace Parsoid\Lib\Wt2html\TT;

require_once (__DIR__.'/TokenHandler.php');
require_once (__DIR__.'/../parser.defines.php');
require_once (__DIR__.'/../../utils/Utils.php');

use Parsoid\Lib\Utils\Util;
use Parsoid\Lib\Wt2html\TagTk;
use Parsoid\Lib\Wt2html\EndTagTk;
use Parsoid\Lib\Wt2html\SelfclosingTagTk;

function array_flatten($array) {
   $ret = [];
   foreach ($array as $key => $value) {
       if (is_array($value)) {
		   $ret = array_merge($ret, array_flatten($value));
		  }
       else {$ret[$key] = $value;}
   }
   return $ret;

}

/**
 * @class
 * @extends module:wt2html/tt/TokenHandler
 * @constructor
 */
class QuoteTransformer extends TokenHandler {

	const quoteAndNewlineRank = 2.1;
	const anyRank = 2.101; // Just after regular quote and newline

	public function __construct($manager, $options)
	{
		parent::__construct($manager, $options);
	}

	public function init() {
		$onQuoteTransformer = [$this, 'onQuote'];
		$this->manager->addTransform($onQuoteTransformer,
			'QuoteTransformer:onQuote', self::quoteAndNewlineRank, 'tag', 'mw-quote');
		$this->reset();
	}

	public function reset() {
	// Chunks alternate between quote tokens and sequences of non-quote
	// tokens.  The quote tokens are later replaced with the actual tag
	// token for italic or bold.  The first chunk is a non-quote chunk.
		$this->chunks = [];
	// The current chunk we're accumulating into.
		$this->currentChunk = [];
	// last italic / last bold open tag seen.  Used to add autoInserted flags
	// where necessary.
		$this->last = [];

		$this->isActive = false;
	}

// Make a copy of the token context
	public function _startNewChunk() {
		$this->chunks[] = $this->currentChunk;
		$this->currentChunk = [];
	}

/**
 * Handle QUOTE tags. These are collected in italic/bold lists depending on
 * the length of quote string. Actual analysis and conversion to the
 * appropriate tag tokens is deferred until the next NEWLINE token triggers
 * processQuotes.
 */
	public function onQuote($token, $tokenManager, $prevToken) {
		#var_dump($token);
		$v = $token->getAttribute('value');
		$qlen = strlen($v);
		$this->manager->env["log"]("trace/quote", $this->manager->pipelineId, "QUOTE |", json_encode($token));

		if (!$this->isActive) {
			$this->manager->addTransform([$this, 'processQuotes'],
				'QuoteTransformer:processQuotes', self::quoteAndNewlineRank, 'newline');
			// Treat 'th' just the same as a newline
			$this->manager->addTransform([$this, 'processQuotes'],
				'QuoteTransformer:processQuotes', self::quoteAndNewlineRank, 'tag', 'td');
			// Treat 'td' just the same as a newline
			$this->manager->addTransform([$this, 'processQuotes'],
				'QuoteTransformer:processQuotes', self::quoteAndNewlineRank, 'tag', 'th');
			// Treat end-of-input just the same as a newline
			$this->manager->addTransform([$this, 'processQuotes'],
				'QuoteTransformer:processQuotes:end', self::quoteAndNewlineRank, 'end');
			// Register for any token if not yet active
			$this->manager->addTransform([$this, 'onAny'],
				'QuoteTransformer:onAny', self::anyRank, 'any', null);
			$this->isActive = true;
			// Add initial context to chunks (we'll get rid of it later)
			$this->currentChunk[] = $prevToken ? $prevToken : '';
		}

		if ($qlen === 2 || $qlen === 3 || $qlen === 5) {
			$this->_startNewChunk();
			$this->currentChunk[] = $token;
			$this->_startNewChunk();
		} else {
			assert(false, "should be transformed by tokenizer");
		}

		return [];
	}

	public function onAny($token) {
		$this->manager->env["log"]("trace/quote", $this->manager->pipelineId, "ANY   |", function () use($token) {
			return (!isset($this->isActive) ? " ---> " : "") . json_encode($token);
		});

		$this->currentChunk[] = $token;
		return [];
	}

/**
 * Handle NEWLINE tokens, which trigger the actual quote analysis on the
 * collected quote tokens so far.
 */
	public function processQuotes($token) {
		$this->manager->env["log"]("trace/quote", $this->manager->pipelineId, "NL    |", function () use($token) {
			return (!isset($this->isActive) ? " ---> " : "") . json_encode($token);
		});

		if (Util::getType($token) !== 'String' &&
			isset($token->dataAttribs->stx) &&
			$token->dataAttribs->stx === 'html' &&
			($token->name === 'td' || $token->name === 'th')
		) {
			return [ "tokens" => [$token] ];
		}

		if (!$this->isActive) {
		// Nothing to do, quick abort.
			return [ "tokens" => [$token] ];
		}
	// token.rank = this.quoteAndNewlineRank;

	// count number of bold and italics
	//	$res, $qlen, $i;
		$numbold = 0;
		$numitalics = 0;
		for ($i = 1; $i < sizeof($this->chunks); $i += 2) {
			assert(sizeof($this->chunks[$i]) === 1); // quote token
			$qlen = strlen($this->chunks[$i][0]->getAttribute("value"));
			if ($qlen === 2 || $qlen === 5) { $numitalics++; }
			if ($qlen === 3 || $qlen === 5) { $numbold++; }
		}

	// balance out tokens, convert placeholders into tags
		if (($numitalics % 2 === 1) && ($numbold % 2 === 1)) {
			$firstsingleletterword = -1;
			$firstmultiletterword = -1;
			$firstspace = -1;
			for ($i = 1; $i < sizeof($this->chunks); $i += 2) {
			// only look at bold tags
				if (strlen($this->chunks[$i][0]->getAttribute("value")) !== 3) { continue; }
			// find the first previous token which is text
			// (this is an approximation, since the wikitext algorithm looks
			// at the raw unparsed source here)
					$prevChunk = $this->chunks[$i - 1];
					$ctxPrevToken = '';
					for ($j = sizeof($prevChunk) - 1; strlen($ctxPrevToken) < 2 && $j >= 0; $j--) {
						if (gettype($prevChunk[$j]) == 'string') {
							$ctxPrevToken = $prevChunk[$j] . $ctxPrevToken;
					}
				}
				$lastCharIndex = strlen($ctxPrevToken);
				if ($lastCharIndex >= 1) {
					$lastchar = $ctxPrevToken[$lastCharIndex - 1];
				} else {
					$lastchar = "";
				}
				if ($lastCharIndex >= 2) {
					$secondtolastchar = $ctxPrevToken[$lastCharIndex - 2];
				} else {
					$secondtolastchar = "";
				}
				if ($lastchar === ' ' && $firstspace === -1) {
					$firstspace = $i;
				} else if ($lastchar !== ' ') {
					if ($secondtolastchar === ' ' &&
							$firstsingleletterword === -1) {
						$firstsingleletterword = i;
					// if firstsingleletterword is set, we don't need
					// to look at the options options, so we can bail early
						break;
					} else if ($firstmultiletterword === -1) {
						$firstmultiletterword = $i;
					}
				}
			}

		// console.log("fslw: " + firstsingleletterword + "; fmlw: " + firstmultiletterword + "; fs: " + firstspace);

		// now see if we can convert a bold to an italic and
		// an apostrophe
			if ($firstsingleletterword > -1) {
				$this->convertBold($firstsingleletterword);
			} else if ($firstmultiletterword > -1) {
				$this->convertBold($firstmultiletterword);
			} else if ($firstspace > -1) {
				$this->convertBold($firstspace);
			} else {
			// (notice that it is possible for all three to be -1 if, for
			// example, there is only one pentuple-apostrophe in the line)
			// In this case, do no balancing.
			}
		}

	// convert the quote tokens into tags
		$this->convertQuotesToTags();

	// return all collected tokens including the newline
		$this->currentChunk[] = $token;
		$this->_startNewChunk();
		array_shift($this->chunks[0]); // remove 'prevToken' before first quote.
		$res = [ "tokens" => array_flatten(array_merge([], $this->chunks)) ];

		$this->manager->env["log"]("trace/quote", $this->manager->pipelineId, "----->", json_encode($res["tokens"]));

	// prepare for next line
		$this->reset();

	// remove registrations
		$this->manager->removeTransform(self::quoteAndNewlineRank, 'end');
		$this->manager->removeTransform(self::quoteAndNewlineRank, 'tag', 'td');
		$this->manager->removeTransform(self::quoteAndNewlineRank, 'tag', 'th');
		$this->manager->removeTransform(self::quoteAndNewlineRank, 'newline');
		$this->manager->removeTransform(self::anyRank, 'any');

		return $res;
	}

/**
 * Convert a bold token to italic to balance an uneven number of both bold and
 * italic tags. In the process, one quote needs to be converted back to text.
 */
	public function convertBold($i) {
	// this should be a bold tag.
		assert($i > 0 && sizeof($this->chunks[$i]) === 1 && strlen($this->chunks[$i][0]->getAttribute("value")) === 3);
	// we're going to convert it to a single plain text ' plus an italic tag
		$this->chunks[$i - 1][] = "'";
		$oldbold = $this->chunks[$i][0];
		$tsr = $oldbold->dataAttribs && isset($oldbold->dataAttribs->tsr) ? $oldbold->dataAttribs->tsr : null;
		if ($tsr) {
			$tsr = [ $tsr[0] + 1, $tsr[1] ];
		}
		$newbold = new SelfclosingTagTk('mw-quote', [], [ "tsr" => $tsr ]);
		$newbold->setAttribute("value", "''"); // italic!
		$this->chunks[$i] = [ $newbold ];
	}

// Convert quote tokens to tags, using the same state machine as the
// PHP parser uses.
	public function convertQuotesToTags() {
		$lastboth = -1;
		$state = '';

		for ($i = 1; $i < sizeof($this->chunks); $i += 2) {
			assert(sizeof($this->chunks[$i]) === 1);
			$qlen = strlen($this->chunks[$i][0]->getAttribute("value"));
			if ($qlen === 2) {
				if ($state === 'i') {
					$this->quoteToTag($i, [new EndTagTk('i')]);
					$state = '';
				} else if ($state === 'bi') {
					$this->quoteToTag($i, [new EndTagTk('i')]);
					$state = 'b';
				} else if ($state === 'ib') {
					// annoying!
					$this->quoteToTag($i, [
						new EndTagTk('b'),
						new EndTagTk('i'),
						new TagTk('b'),
					], "bogus two");
					$state = 'b';
				} else if ($state === 'both') {
					$this->quoteToTag(lastboth, [new TagTk('b'), new TagTk('i')]);
					$this->quoteToTag($i, [new EndTagTk('i')]);
					$state = 'b';
				} else { // state can be 'b' or ''
					$this->quoteToTag($i, [new TagTk('i')]);
					$state .= 'i';
				}
			} else if ($qlen === 3) {
				if ($state === 'b') {
					$this->quoteToTag($i, [new EndTagTk('b')]);
					$state = '';
				} else if ($state === 'ib') {
					$this->quoteToTag($i, [new EndTagTk('b')]);
					$state = 'i';
				} else if ($state === 'bi') {
					// annoying!
					$this->quoteToTag($i, [
						new EndTagTk('i'),
						new EndTagTk('b'),
						new TagTk('i'),
					], "bogus two");
					$state = 'i';
				} else if ($state === 'both') {
					$this->quoteToTag($lastboth, [new TagTk('i'), new TagTk('b')]);
					$this->quoteToTag($i, [new EndTagTk('b')]);
					$state = 'i';
				} else { // state can be 'i' or ''
					$this->quoteToTag($i, [new TagTk('b')]);
					$state .= 'b';
				}
			} else if ($qlen === 5) {
				if ($state === 'b') {
					$this->quoteToTag($i, [new EndTagTk('b'), new TagTk('i')]);
					$state = 'i';
				} else if ($state === 'i') {
					$this->quoteToTag($i, [new EndTagTk('i'), new TagTk('b')]);
					$state = 'b';
				} else if ($state === 'bi') {
					$this->quoteToTag($i, [new EndTagTk('i'), new EndTagTk('b')]);
					$state = '';
				} else if ($state === 'ib') {
					$this->quoteToTag($i, [new EndTagTk('b'), new EndTagTk('i')]);
					$state = '';
				} else if ($state === 'both') {
					$this->quoteToTag($lastboth, [new TagTk('i'), new TagTk('b')]);
					$this->quoteToTag($i, [new EndTagTk('b'), new EndTagTk('i')]);
					$state = '';
				} else { // state == ''
					$lastboth = $i;
					$state = 'both';
				}
			}
		}

	// now close all remaining tags.  notice that order is important.
		if ($state === 'both') {
			$this->quoteToTag($lastboth, [new TagTk('b'), new TagTk('i')]);
			$state = 'bi';
		}
		if ($state === 'b' || $state === 'ib') {
			$this->currentChunk[] = new EndTagTk('b');
			$this->last["b"]->dataAttribs["autoInsertedEnd"] = true;
		}
		if ($state === 'i' || $state === 'bi' || $state === 'ib') {
			$this->currentChunk[] = new EndTagTk('i');
			$this->last["i"]->dataAttribs["autoInsertedEnd"] = true;
		}
		if ($state === 'bi') {
			$this->currentChunk[] = new EndTagTk('b');
			$this->last["b"]->dataAttribs["autoInsertedEnd"] = true;
		}
	}

/** Convert italics/bolds into tags. */
	public function quoteToTag($chunk, $tags, $ignoreBogusTwo = false) {
		assert(sizeof($this->chunks[$chunk]) === 1);
		$result = [];
		$oldtag = $this->chunks[$chunk][0];
		// make tsr
		$tsr = $oldtag->dataAttribs && isset($oldtag->dataAttribs->tsr) ? $oldtag->dataAttribs->tsr : null;
		$startpos = $tsr ? $tsr[0] : null;
		$endpos = $tsr ? $tsr[1] : null;
		for ($i = 0; $i < sizeof($tags); $i++) {
			if ($tsr) {
				if ($i === 0 && $ignoreBogusTwo) {
					$this->last[$tags[$i]->name]->dataAttribs["autoInsertedEnd"] = true;
				} else if ($i === 2 && $ignoreBogusTwo) {
					$tags[$i]->dataAttribs["autoInsertedStart"] = true;
				} else if ($tags[$i]->name === 'b') {
					$tags[$i]->dataAttribs->tsr = [ $startpos, $startpos + 3 ];
					$startpos = $tags[$i]->dataAttribs->tsr[1];
				} else if ($tags[$i]->name === 'i') {
					$tags[$i]->dataAttribs->tsr = [ $startpos, $startpos + 2 ];
					$startpos = $tags[$i]->dataAttribs->tsr[1];
				} else { assert(false); }
			}
			$this->last[$tags[$i]->name] = ($tags[$i]->getType() === "EndTagTk") ? null : $tags[$i];
			$result[] = $tags[$i];
		}
		if ($tsr) { assert($startpos === $endpos, "Start: $startpos, end: $endpos"); }
		$this->chunks[$chunk] = $result;
	}
}

?>
