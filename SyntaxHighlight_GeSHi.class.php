<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

use KzykHys\Pygments\Pygments;

class SyntaxHighlight_GeSHi {

	/** @var const The maximum number of lines that may be selected for highlighting. **/
	const HIGHLIGHT_MAX_LINES = 1000;

	/** @var const Maximum input size for the highlighter (100 kB). **/
	const HIGHLIGHT_MAX_BYTES = 102400;

	/** @var const CSS class for syntax-highlighted code. **/
	const HIGHLIGHT_CSS_CLASS = 'mw-highlight';

	/** @var const Cache version. Increment whenever the HTML changes. */
	const CACHE_VERSION = 1;

	/** @var array Mapping of MIME-types to lexer names. **/
	private static $mimeLexers = array(
		'text/javascript'  => 'javascript',
		'application/json' => 'javascript',
		'text/xml'         => 'xml',
	);

	public static function onSetup() {
		global $wgPygmentizePath;

		// If $wgPygmentizePath is unset, use the bundled copy.
		if ( $wgPygmentizePath === false ) {
			$wgPygmentizePath = __DIR__ . '/pygments/pygmentize';
		}
	}
	/**
	 * Get the Pygments lexer name for a particular language.
	 *
	 * @param string $lang Language name.
	 * @return string|null Lexer name, or null if no matching lexer.
	 */
	private static function getLexer( $lang ) {
		static $lexers = null;

		if ( !$lexers ) {
			$lexers = require __DIR__ . '/SyntaxHighlight_GeSHi.lexers.php';
		}

		$lexer = strtolower( $lang );

		if ( in_array( $lexer, $lexers ) ) {
			return $lexer;
		}

		// Check if this is a GeSHi lexer name for which there exists
		// a compatible Pygments lexer with a different name.
		if ( isset( GeSHi::$compatibleLexers[$lexer] ) ) {
			$lexer = GeSHi::$compatibleLexers[$lexer];
			if ( in_array( $lexer, $lexers ) ) {
				return $lexer;
			}
		}

		return null;
	}

	/**
	 * Register parser hook
	 *
	 * @param $parser Parser
	 * @return bool
	 */
	public static function onParserFirstCallInit( Parser &$parser ) {
		foreach ( array( 'source', 'syntaxhighlight' ) as $tag ) {
			$parser->setHook( $tag, array( 'SyntaxHighlight_GeSHi', 'parserHook' ) );
		}
	}

	/**
	 * Parser hook
	 *
	 * @param string $text
	 * @param array $args
	 * @param Parser $parser
	 * @return string
	 */
	public static function parserHook( $text, $args = array(), $parser ) {
		global $wgUseTidy;

		// Don't trim leading spaces away, just the linefeeds
		$out = preg_replace( '/^\n+/', '', rtrim( $text ) );

		// Validate language
		if ( isset( $args['lang'] ) ) {
			$lexer = self::getLexer( $args['lang'] );
		} else {
			$lexer = null;
		}

		$out = self::highlight( $out, $lexer, $args );

		// HTML Tidy will convert tabs to spaces incorrectly (bug 30930).
		// But the conversion from tab to space occurs while reading the input,
		// before the conversion from &#9; to tab, so we can armor it that way.
		if ( $wgUseTidy ) {
			$out = str_replace( "\t", '&#9;', $out );
		}

		// Register CSS
		$parser->getOutput()->addModuleStyles( 'ext.pygments' );

		return trim( $out );
	}

	/**
	 * Use the Pyglet web service (if configured) to highlight code.
	 *
	 * @param string $source Source code to highlight.
	 * @param string $lexer Lexer name.
	 * @param array $options Additional options to pass to the Pygments formatter.
	 * @return string|bool Highlighted HTML, or false if Pyglet is unreachable
	 *   or not configured.
	 */
	protected static function highlightWithPyglet( $source, $lexer, $options = array() ) {
		global $wgPygletURL;

		if ( !$wgPygletURL ) {
			return false;
		}

		$data = array_merge( array( 'source' => $source, 'lexer' => $lexer ), $options );
		$client = new Http;
		$output = $client->post( $wgPygletURL, array(
			'timeout'        => 1.5,
			'connectTimeout' => 0.1,
			'postData'       => $data,
		), __METHOD__ );

		if ( $output === false ) {
			wfWarn( "Failed to connect to Pyglet server at {$wgPygletURL}." );
		}

		return false;
	}
	/**
	 * Highlight a code-block using a particular lexer.
	 *
	 * @param string $code Code to highlight.
	 * @param string|null $lexer Lexer name, or null to use plain markup.
	 * @param array $args Associative array of additional arguments. If it
	 *  contains a 'line' key, the output will include line numbers. If it
	 *  includes a 'highlight' key, the value will be parsed as a
	 *  comma-separated list of lines and line-ranges to highlight. If it
	 *  contains a 'start' key, the value will be used as the line at which to
	 *  start highlighting.
	 * @return string Highlighted code as HTML.
	 */
	protected static function highlight( $code, $lexer = null, $args = array() ) {
		global $wgPygmentizePath;

		if ( strlen( $code ) > self::HIGHLIGHT_MAX_BYTES ) {
			$lexer = null;
		}

		if ( wfShellExecDisabled() !== false ) {
			wfWarn(
				'MediaWiki determined that it cannot invoke Pygments. ' .
				'As a result, SyntaxHighlight_GeSHi will not perform any syntax highlighting. ' .
				'See the debug log for details: ' .
				'https://www.mediawiki.org/wiki/Manual:$wgDebugLogFile'
			);
			$lexer = null;
		}

		$inline = ( isset( $args['enclose'] ) && $args['enclose'] === 'none' )
			|| isset( $args['inline'] );
		$attrs = array( 'class' => self::HIGHLIGHT_CSS_CLASS );

		if ( $lexer === null ) {
			if ( $inline ) {
				return Html::element( 'code', $attrs, trim( $code ) );
			}
			$pre = Html::element( 'pre', array(), $code );
			return Html::rawElement( 'div', $attrs, $pre );
		}

		$options = array(
			'cssclass' => self::HIGHLIGHT_CSS_CLASS,
			'encoding' => 'utf-8',
		);

		// Line numbers
		if ( isset( $args['line'] ) ) {
			$options['linenos'] = 'inline';
		}

		if ( $lexer === 'php' && strpos( $code, '<?php' ) === false ) {
			$options['startinline'] = 1;
		}

		// Highlight specific lines
		if ( isset( $args['highlight'] ) ) {
			$lines = self::parseHighlightLines( $args['highlight'] );
			if ( count( $lines ) ) {
				$options['hl_lines'] = implode( ' ', $lines );
			}
		}

		// Starting line number
		if ( isset( $args['start'] ) ) {
			$options['linenostart'] = $args['start'];
		}

		if ( $inline ) {
			$options['nowrap'] = 1;
		}

		$cache = wfGetMainCache();
		$cacheKey = self::makeCacheKey( $code, $lexer, $options );
		$output = $cache->get( $cacheKey );

		if ( $output === false ) {
			// Try the Pyglet web service, if configured.
			$output = self::highlightWithPyglet( $code, $lexer, $options );
			if ( $output === false ) {
				try {
					$pygments = new Pygments( $wgPygmentizePath );
					$output = $pygments->highlight( $code, $lexer, 'html', $options );
				} catch ( RuntimeException $e ) {
					wfWarn( 'Failed to invoke Pygments. Please check that Pygments is installed ' .
						'and that $wgPygmentizePath is accurate.' );
					return self::highlight( $code, null, $args );
				}
			}
			$cache->set( $cacheKey, $output );
		}

		if ( $inline ) {
			return Html::rawElement( 'code', $attrs, trim( $output ) );
		}

		return $output;

	}

	/**
	 * Construct a cache key for the results of a Pygments invocation.
	 *
	 * @param string $code Code to be highlighted.
	 * @param string $lexer Lexer name.
	 * @param array $options Options array.
	 * @return string Cache key.
	 */
	private static function makeCacheKey( $code, $lexer, $options ) {
		$optionString = FormatJson::encode( $options, false, FormatJson::ALL_OK );
		$hash = md5( "{$code}|{$lexer}|{$optionString}|" . self::CACHE_VERSION );
		if ( function_exists( 'wfGlobalCacheKey' ) ) {
			return wfGlobalCacheKey( 'highlight', $hash );
		} else {
			return 'highlight:' . $hash;
		}
	}

	/**
	 * Take an input specifying a list of lines to highlight, returning
	 * a raw list of matching line numbers.
	 *
	 * Input is comma-separated list of lines or line ranges.
	 *
	 * @param string $lineSpec
	 * @return int[] Line numbers.
	 */
	protected static function parseHighlightLines( $lineSpec ) {
		$lines = array();
		$values = array_map( 'trim', explode( ',', $lineSpec ) );
		foreach ( $values as $value ) {
			if ( ctype_digit( $value ) ) {
				$lines[] = (int)$value;
			} elseif ( strpos( $value, '-' ) !== false ) {
				list( $start, $end ) = array_map( 'trim', explode( '-', $value ) );
				if ( self::validHighlightRange( $start, $end ) ) {
					for ($i = intval( $start ); $i <= $end; $i++ ) {
						$lines[] = $i;
					}
				}
			}
			if ( count( $lines ) > self::HIGHLIGHT_MAX_LINES ) {
				$lines = array_slice( $lines, 0, self::HIGHLIGHT_MAX_LINES );
				break;
			}
		}
		return $lines;
	}

	/**
	 * Validate a provided input range
	 * @param $start
	 * @param $end
	 * @return bool
	 */
	protected static function validHighlightRange( $start, $end ) {
		// Since we're taking this tiny range and producing a an
		// array of every integer between them, it would be trivial
		// to DoS the system by asking for a huge range.
		// Impose an arbitrary limit on the number of lines in a
		// given range to reduce the impact.
		return
			ctype_digit( $start ) &&
			ctype_digit( $end ) &&
			$start > 0 &&
			$start < $end &&
			$end - $start < self::HIGHLIGHT_MAX_LINES;
	}

	/**
	 * Hook into Content::getParserOutput to provide syntax highlighting for
	 * script content.
	 *
	 * @return bool
	 * @since MW 1.21
	 */
	public static function onContentGetParserOutput( Content $content, Title $title,
			$revId, ParserOptions $options, $generateHtml, ParserOutput &$output ) {

		global $wgParser, $wgTextModelsToParse;

		if ( !$generateHtml ) {
			// Nothing special for us to do, let MediaWiki handle this.
			return true;
		}

		// Determine the language
		$extension = ExtensionRegistry::getInstance();
		$models = $extension->getAttribute( 'SyntaxHighlightModels' );
		$model = $content->getModel();
		if ( !isset( $models[$model] ) ) {
			// We don't care about this model, carry on.
			return true;
		}
		$lexer = $models[$model];

		// Hope that $wgSyntaxHighlightModels does not contain silly types.
		$text = ContentHandler::getContentText( $content );
		if ( !$text ) {
			// Oops! Non-text content? Let MediaWiki handle this.
			return true;
		}

		// Parse using the standard parser to get links etc. into the database, HTML is replaced below.
		// We could do this using $content->fillParserOutput(), but alas it is 'protected'.
		if ( $content instanceof TextContent && in_array( $model, $wgTextModelsToParse ) ) {
			$output = $wgParser->parse( $text, $title, $options, true, true, $revId );
		}

		$out = self::highlight( $text, $lexer );
		if ( !$out ) {
			return true;
		}

		$output->addModuleStyles( 'ext.pygments' );
		$output->setText( '<div dir="ltr">' . $out . '</div>' );

		// Inform MediaWiki that we have parsed this page and it shouldn't mess with it.
		return false;
	}

	/**
	 * Hook to provide syntax highlighting for API pretty-printed output
	 *
	 * @param IContextSource $context
	 * @param string $text
	 * @param string $mime
	 * @param string $format
	 * @since MW 1.24
	 */
	public static function onApiFormatHighlight( IContextSource $context, $text, $mime, $format ) {
		if ( !isset( self::$mimeLexers[$mime] ) ) {
			return true;
		}

		$lexer = self::$mimeLexers[$mime];
		$out = self::highlight( $text, $lexer );

		if ( !$out ) {
			return true;
		}

		if ( preg_match( '/^<pre([^>]*)>/i', $out, $m ) ) {
			$attrs = Sanitizer::decodeTagAttributes( $m[1] );
			$attrs['class'] .= ' api-pretty-content';
			$encodedAttrs = Sanitizer::safeEncodeTagAttributes( $attrs );
			$out = '<pre' . $encodedAttrs. '>' .  substr( $out, strlen( $m[0] ) );
		}
		$output = $context->getOutput();
		$output->addModuleStyles( 'ext.pygments' );
		$output->addHTML( '<div dir="ltr">' . $out . '</div>' );

		// Inform MediaWiki that we have parsed this page and it shouldn't mess with it.
		return false;
	}

	/**
	 * Reject parser cache values that are for GeSHi since those
	 * ResourceLoader modules no longer exist
	 *
	 * @param ParserOutput $parserOutput
	 * @param WikiPage|Article $page
	 * @param ParserOptions $popts
	 * @return bool
	 */
	public static function onRejectParserCacheValue( ParserOutput $parserOutput, $page, ParserOptions $popts ) {
		foreach ( $parserOutput->getModuleStyles() as $module ) {
			if ( strpos( $module, 'ext.geshi.' ) === 0 ) {
				$page->getTitle()->purgeSquid();
				return false;
			}
		}
		return true;
	}

	/** Backward-compatibility shim for extensions.  */
	public static function prepare( $text, $lang ) {
		wfDeprecated( __METHOD__ );
		$html = self::highlight( $text, $lang );
		return new GeSHi( $html );
	}

	/** Backward-compatibility shim for extensions. */
	public static function buildHeadItem( $geshi ) {
			wfDeprecated( __METHOD__ );
			$geshi->parse_code();
			return '';
	}
}
