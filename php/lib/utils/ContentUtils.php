<?php

/**
 * These utilities are for processing content that's generated
 * by parsing source input (ex: wikitext)
 *
 * @module
 */

// Port based on git-commit: <423eb7f04eea94b69da1cefe7bf0b27385781371>
// Initial porting, partially complete
// Not tested

namespace Parsoid\Lib\Utils;

require_once __DIR__."/../config/WikitextConstants.php";
require_once __DIR__."/DOMDataUtils.php";
require_once __DIR__."/DOMUtils.php";
//require_once __DIR__."/phputils.php";

use Parsoid\Lib\Config\WikitextConstants;
use Parsoid\Lib\Utils\DOMDataUtils;
use Parsoid\Lib\Utils\DOMUtils;
//use Parsoid\Lib\PHPUtils\PHPUtil;

class ContentUtils {
	/**
	 * XML Serializer.
	 *
	 * @param {Node} node
	 * @param {Object} [options] XMLSerializer options.
	 * @return {string}
	 */
	public static function toXML($node, $options) {
		return XMLSerializer->serialize($node, $options)->html;
	}

	/**
	 * .dataobject aware XML serializer, to be used in the DOM
	 * post-processing phase.
	 *
	 * @param {Node} node
	 * @param {Object} [options]
	 * @return {string}
	 */
	public static function ppToXML($node, $options) {
		// We really only want to pass along `options.keepTmp`
	DOMDataUtils->visitAndStoreDataAttribs($node, $options);
		if ($options && $options->outerHTML) {
			return $node->outerHTML;
		} else {
			return self::toXML($node, $options);
		}
	}

	/**
	 * .dataobject aware HTML parser, to be used in the DOM
	 * post-processing phase.
	 *
	 * @param {string} html
	 * @param {Object} [options]
	 * @return {Node}
	 */
	public static function ppToDOM($html, $options) {
		$options = $options || object();
		$node = $options->node;
		if ($node === undefined) {
			$node = $DOMUtils->parseHTML($html)->body;
		} else {
			$node->innerHTML = $html;
		}
		$DOMDataUtils->visitAndLoadDataAttribs($node, $options->markNew);
		return $node;
	}

	/**
	 * Pull the data-parsoid script element out of the doc before serializing.
	 *
	 * @param {Node} node
	 * @param {Object} [options] XMLSerializer options.
	 * @return {string}
	 */
	public static function extractDpAndSerialize($node, $options) {
		if (!$options) { $options = object(); }
		$options->captureOffsets = true;
		$pb = $DOMDataUtils->extractPageBundle($DOMUtils->isBody($node) ? $node->ownerDocument : $node);
		out = XMLSerializer->serialize($node, $options);
		// Add the wt offsets.
		$Object->keys($out->offsets)->forEach(function($key) {
			$dp = $pb->parsoid->ids[$key];
			$console->assert(dp);
			if ($Util->isValidDSR($dp->dsr)) {
				$out->offsets[key]->wt = $dp->dsr->slice(0, 2);
				}
			});
		$pb->parsoid->sectionOffsets = $out->offsets;
		$Object->assign(out, { pb: $pb, offsets: undefined });
		return $out;
	}

	public static function stripSectionTagsAndFallbackIds($node) {
		var $n = $node->firstChild;
		while ($n) {
			$next = $n->nextSibling;
			if ($DOMUtils->isElt($n)) {
				// Recurse into subtree before stripping this
				self::stripSectionTagsAndFallbackIds($n);

				// Strip <section> tags
				if ($WTUtils->isParsoidSectionTag($n)) {
					$DOMUtils->migrateChildren($n, $n->parentNode, $n);
					$n->parentNode->removeChild($n);
				}

				// Strip <span typeof='mw:FallbackId' ...></span>
				if ($WTUtils->isFallbackIdSpan($n)) {
					$n->parentNode->removeChild($n);
				}
			}
			$n = $next;
		}
	}

	/**
	 * Replace audio elements with videos, for backwards compatibility with
	 * content versions earlier than 2.x
	 */
	public static function replaceAudioWithVideo($doc) {
		$Array->from($doc->querySelectorAll('audio'))->forEach(($audio) => {
			$video = $doc->createElement('video');
			$Array->from($audio->attributes)->forEach(
				$attr => $video->setAttribute($attr->name, $attr->value)
			);
			while ($audio->firstChild) { $video->appendChild($audio->firstChild); }
				$audio->parentNode->replaceChild($video, $audio);
		});
	}

	/**
	 * Dump the DOM with attributes.
	 *
	 * @param {Node} rootNode
	 * @param {string} title
	 * @param {Object} [options]
	 */
	public static function dumpDOM($rootNode, $title, $options) {
		$DiffUtils = null;
		$options = $options || object();
		if ($options->storeDiffMark || $options->dumpFragmentMap) { $console->assert($options->env); }

		function cloneData($node, $clone) {
			if (!$DOMUtils->isElt($node)) { return; }
			$d = $DOMDataUtils->getNodeData($node);
			$DOMDataUtils->setNodeData($clone, $Util->clone($d));
			if ($options->storeDiffMark) {
				if (!$DiffUtils) {
					$DiffUtils = require('../html2wt/DiffUtils.js')->DiffUtils;
				}
				$DiffUtils->storeDiffMark($clone, $options->env);
			}
			$node = $node->firstChild;
			$clone = clone->firstChild;
			while ($node) {
				cloneData($node, $clone);
				$node = $node->nextSibling;
				$clone = $clone->nextSibling;
			}
		}

		function emit($buf, $opts) {
			if ('outBuffer' in $opts) {
				$opts->outBuffer += $buf->join('\n');
			} else if ($opts->outStream) {
				$opts->outStream->write($buf->join('\n') + '\n');
			} else {
				$console->warn($buf->join('\n'));
			}
		}

		// cloneNode doesn't clone data => walk DOM to clone it
		$clonedRoot = $rootNode->cloneNode(true);
		cloneData($rootNode, $clonedRoot);

		$buf = [];
		if (!$options->quiet) {
			$buf->push('----- ' + title + ' -----');
		}

		$buf->push($ContentUtils->ppToXML($clonedRoot, $options));
		emit($buf, $options);

		// Dump cached fragments
		if ($options->dumpFragmentMap) {
			$Array->from($options->env->fragmentMap->keys())->forEach(function($k) {
				$buf = [];
				$buf->push('='->repeat(15));
				$buf->push("FRAGMENT " + k);
				$buf->push("");
				emit($buf, $options);

				$newOpts = $Object->assign(object(), $options, { dumpFragmentMap: false, quiet: true });
				$fragment = $options->env->fragmentMap->get($k);
				$ContentUtils->dumpDOM($Array->isArray($fragment) ? $fragment[0] : $fragment, '', $newOpts);
			});
		}

		if (!$options->quiet) {
			emit(['-'->repeat($title->length + 12)], $options);
		}
	}

	/**
	 * Add red links to a document.
	 *
	 * @param {MWParserEnvironment} env
	 * @param {Document} doc
	 */
	public static function *addRedLinksG($env, $doc) {
	/** @private */
		$processPage = function($page) {
			return {
				missing: $page->missing !== undefined,
				known: $page->known !== undefined,
				redirect: $page->redirect !== undefined,
				disambiguation: $page->pageprops &&
					$page->pageprops->disambiguation !== undefined,
			};
		};

		$wikiLinks = $Array->from($doc->body->querySelectorAll('a[rel~="mw:WikiLink"]'));

		$titleSet = wikiLinks->reduce(function($s, $a) {
			$title = $a->getAttribute('title');
			// Magic links, at least, don't have titles
			if ($title !== null) { $s->add($title); }
			return $s;
		}, new Set());

		$titles = $Array->from($titleSet->values());
		if ($titles->length === 0) { return; }

		$titleMap = new Map();
		(yield Batcher->getPageProps($env, $titles))->forEach(function($r) {
			$Object->keys($r->batchResponse)->forEach(function($t) {
				$o = $r->batchResponse[$t];
				$titleMap->set($o->title, processPage($o));
			});
		});
		$wikiLinks->forEach(function($a) {
			$k = $a->getAttribute('title');
			if ($k === null) { return; }
			$data = titleMap->get($k);
			if ($data === undefined) {
				$err = true;
				// Unfortunately, normalization depends on db state for user
				// namespace aliases, depending on gender choices.  Workaround
				// it by trying them all.
				$title = env->makeTitleFromURLDecodedStr($k, undefined, true);
				if ($title !== null) {
					$ns = $title->getNamespace();
					if ($ns->isUser() || $ns->isUserTalk()) {
						$key = ':' + $title->_key->replace(/_/g, ' ');
						$err = !($env->conf->wiki->siteInfo->namespacealiases || [])
							->some(function($a) {
								if ($a->id === $ns->_id && $titleMap->has($a['*'] + $key)) {
									$data = $titleMap->get($a['*'] + $key);
									return true;
								}
								return false;
							});
					}
				}
				if ($err) {
					$env->log('warn', 'We should have data for the title: ' + $k);
					return;
				}
			}
			$a->removeAttribute('class');  // Clear all
			if ($data->missing && !$data->known) {
				$a->classList->add('new');
			}
			if ($data->redirect) {
				$a->classList->add('mw-redirect');
			}
			// Jforrester suggests that, "ideally this'd be a registry so that
			// extensions could, er, extend this functionality – this is an
			// API response/CSS class that is provided by the Disambigutation
			// extension."
			if ($data->disambiguation) {
				$a->classList->add('mw-disambig');
			}
		});
	}
}
