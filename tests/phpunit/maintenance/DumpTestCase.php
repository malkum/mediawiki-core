<?php

/**
 * Base TestCase for dumps
 */
abstract class DumpTestCase extends MediaWikiLangTestCase {

	/**
	 * exception to be rethrown once in sound PHPUnit surrounding
	 *
	 * As the current MediaWikiTestCase::run is not robust enough to recover
	 * from thrown exceptions directly, we cannot throw frow within
	 * self::addDBData, although it would be appropriate. Hence, we catch the
	 * exception and store it until we are in setUp and may finally rethrow
	 * the exception without crashing the test suite.
	 *
	 * @var Exception|null
	 */
	protected $exceptionFromAddDBData = null;

	/**
	 * Holds the xmlreader used for analyzing an xml dump
	 *
	 * @var XMLReader|null
	 */
	protected $xml = null;

	/**
	 * Adds a revision to a page, while returning the resuting revision's id
	 *
	 * @param $page WikiPage: page to add the revision to
	 * @param $text string: revisions text
	 * @param $text string: revisions summare
	 *
	 * @throws MWExcepion
	 */
	protected function addRevision( Page $page, $text, $summary ) {
		$status = $page->doEditContent( ContentHandler::makeContent( $text, $page->getTitle() ), $summary );
		if ( $status->isGood() ) {
			$value = $status->getValue();
			$revision = $value['revision'];
			$revision_id = $revision->getId();
			$text_id = $revision->getTextId();
			if ( ( $revision_id > 0 ) && ( $text_id > 0 ) ) {
				return array( $revision_id, $text_id );
			}
		}
		throw new MWException( "Could not determine revision id (" . $status->getWikiText() . ")" );
	}


	/**
	 * gunzips the given file and stores the result in the original file name
	 *
	 * @param $fname string: filename to read the gzipped data from and stored
	 *             the gunzipped data into
	 */
	protected function gunzip( $fname ) {
		$gzipped_contents = file_get_contents( $fname );
		if ( $gzipped_contents === FALSE ) {
			$this->fail( "Could not get contents of $fname" );
		}
		// We resort to use gzinflate instead of gzdecode, as gzdecode
		// need not be available
		$contents = gzinflate( substr( $gzipped_contents, 10, -8 ) );
		$this->assertEquals( strlen( $contents ),
			file_put_contents( $fname, $contents ), "# bytes written" );
	}

	/**
	 * Default set up function.
	 *
	 * Clears $wgUser, and reports errors from addDBData to PHPUnit
	 */
	protected function setUp() {
		global $wgUser;

		parent::setUp();

		// Check if any Exception is stored for rethrowing from addDBData
		// @see self::exceptionFromAddDBData
		if ( $this->exceptionFromAddDBData !== null ) {
			throw $this->exceptionFromAddDBData;
		}

		$wgUser = new User();
	}

	/**
	 * Checks for test output consisting only of lines containing ETA announcements
	 */
	function expectETAOutput() {
		// Newer PHPUnits require assertion about the output using PHPUnit's own
		// expectOutput[...] functions. However, the PHPUnit shipped prediactes
		// do not allow to check /each/ line of the output using /readable/ REs.
		// So we ...
		//
		// 1. ... add a dummy output checking to make PHPUnit not complain
		//    about unchecked test output
		$this->expectOutputRegex( '//' );

		// 2. Do the real output checking on our own.
		$lines = explode( "\n", $this->getActualOutput() );
		$this->assertGreaterThan( 1, count( $lines ), "Minimal lines of produced output" );
		$this->assertEquals( '', array_pop( $lines ), "Output ends in LF" );
		$timestamp_re = "[0-9]{4}-[01][0-9]-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-6][0-9]";
		foreach ( $lines as $line ) {
			$this->assertRegExp( "/$timestamp_re: .* \(ID [0-9]+\) [0-9]* pages .*, [0-9]* revs .*, ETA/", $line );
		}
	}


	/**
	 * Step the current XML reader until node end of given name is found.
	 *
	 * @param $name string: name of the closing element to look for
	 *           (e.g.: "mediawiki" when looking for </mediawiki>)
	 *
	 * @return bool: true iff the end node could be found. false otherwise.
	 */
	protected function skipToNodeEnd( $name ) {
		while ( $this->xml->read() ) {
			if ( $this->xml->nodeType == XMLReader::END_ELEMENT &&
				$this->xml->name == $name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Step the current XML reader to the first element start after the node
	 * end of a given name.
	 *
	 * @param $name string: name of the closing element to look for
	 *           (e.g.: "mediawiki" when looking for </mediawiki>)
	 *
	 * @return bool: true iff new element after the closing of $name could be
	 *           found. false otherwise.
	 */
	protected function skipPastNodeEnd( $name ) {
		$this->assertTrue( $this->skipToNodeEnd( $name ),
			"Skipping to end of $name" );
		while ( $this->xml->read() ) {
			if ( $this->xml->nodeType == XMLReader::ELEMENT ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Opens an XML file to analyze and optionally skips past siteinfo.
	 *
	 * @param $fname string: name of file to analyze
	 * @param $skip_siteinfo bool: (optional) If true, step the xml reader
	 *           to the first element after </siteinfo>
	 */
	protected function assertDumpStart( $fname, $skip_siteinfo = true ) {
		$this->xml = new XMLReader();
		$this->assertTrue( $this->xml->open( $fname ),
			"Opening temporary file $fname via XMLReader failed" );
		if ( $skip_siteinfo ) {
			$this->assertTrue( $this->skipPastNodeEnd( "siteinfo" ),
				"Skipping past end of siteinfo" );
		}
	}

	/**
	 * Asserts that the xml reader is at the final closing tag of an xml file and
	 * closes the reader.
	 *
	 * @param $tag string: (optional) the name of the final tag
	 *           (e.g.: "mediawiki" for </mediawiki>)
	 */
	protected function assertDumpEnd( $name = "mediawiki" ) {
		$this->assertNodeEnd( $name, false );
		if ( $this->xml->read() ) {
			$this->skipWhitespace();
		}
		$this->assertEquals( $this->xml->nodeType, XMLReader::NONE,
			"No proper entity left to parse" );
		$this->xml->close();
	}

	/**
	 * Steps the xml reader over white space
	 */
	protected function skipWhitespace() {
		$cont = true;
		while ( $cont && ( ( $this->xml->nodeType == XMLReader::WHITESPACE )
				|| ( $this->xml->nodeType == XMLReader::SIGNIFICANT_WHITESPACE ) ) ) {
			$cont = $this->xml->read();
		}
	}

	/**
	 * Asserts that the xml reader is at an element of given name, and optionally
	 * skips past it.
	 *
	 * @param $name string: the name of the element to check for
	 *           (e.g.: "mediawiki" for <mediawiki>)
	 * @param $skip bool: (optional) if true, skip past the found element
	 */
	protected function assertNodeStart( $name, $skip = true ) {
		$this->assertEquals( $name, $this->xml->name, "Node name" );
		$this->assertEquals( XMLReader::ELEMENT, $this->xml->nodeType, "Node type" );
		if ( $skip ) {
			$this->assertTrue( $this->xml->read(), "Skipping past start tag" );
		}
	}

	/**
	 * Asserts that the xml reader is at an closing element of given name, and optionally
	 * skips past it.
	 *
	 * @param $name string: the name of the closing element to check for
	 *           (e.g.: "mediawiki" for </mediawiki>)
	 * @param $skip bool: (optional) if true, skip past the found element
	 */
	protected function assertNodeEnd( $name, $skip = true ) {
		$this->assertEquals( $name, $this->xml->name, "Node name" );
		$this->assertEquals( XMLReader::END_ELEMENT, $this->xml->nodeType, "Node type" );
		if ( $skip ) {
			$this->assertTrue( $this->xml->read(), "Skipping past end tag" );
		}
	}


	/**
	 * Asserts that the xml reader is at an element of given tag that contains a given text,
	 * and skips over the element.
	 *
	 * @param $name string: the name of the element to check for
	 *           (e.g.: "mediawiki" for <mediawiki>...</mediawiki>)
	 * @param $text string|false: If string, check if it equals the elements text.
	 *           If false, ignore the element's text
	 * @param $skip_ws bool: (optional) if true, skip past white spaces that trail the
	 *           closing element.
	 */
	protected function assertTextNode( $name, $text, $skip_ws = true ) {
		$this->assertNodeStart( $name );

		if ( $text !== false ) {
			$this->assertEquals( $text, $this->xml->value, "Text of node " . $name );
		}
		$this->assertTrue( $this->xml->read(), "Skipping past processed text of " . $name );
		$this->assertNodeEnd( $name );

		if ( $skip_ws ) {
			$this->skipWhitespace();
		}
	}

	/**
	 * Asserts that the xml reader is at the start of a page element and skips over the first
	 * tags, after checking them.
	 *
	 * Besides the opening page element, this function also checks for and skips over the
	 * title, ns, and id tags. Hence after this function, the xml reader is at the first
	 * revision of the current page.
	 *
	 * @param $id int: id of the page to assert
	 * @param $ns int: number of namespage to assert
	 * @param $name string: title of the current page
	 */
	protected function assertPageStart( $id, $ns, $name ) {

		$this->assertNodeStart( "page" );
		$this->skipWhitespace();

		$this->assertTextNode( "title", $name );
		$this->assertTextNode( "ns", $ns );
		$this->assertTextNode( "id", $id );

	}

	/**
	 * Asserts that the xml reader is at the page's closing element and skips to the next
	 * element.
	 */
	protected function assertPageEnd() {
		$this->assertNodeEnd( "page" );
		$this->skipWhitespace();
	}

	/**
	 * Asserts that the xml reader is at a revision and checks its representation before
	 * skipping over it.
	 *
	 * @param $id int: id of the revision
	 * @param $summary string: summary of the revision
	 * @param $text_id int: id of the revision's text
	 * @param $text_bytes int: # of bytes in the revision's text
	 * @param $text_sha1 string: the base36 SHA-1 of the revision's text
	 * @param $text string|false: (optional) The revision's string, or false to check for a
	 *            revision stub
	 * @param $model String: the expected content model id (default: CONTENT_MODEL_WIKITEXT)
	 * @param $format String: the expected format model id (default: CONTENT_FORMAT_WIKITEXT)
	 * @param $parentid int|false: (optional) id of the parent revision
	 */
	protected function assertRevision( $id, $summary, $text_id, $text_bytes, $text_sha1, $text = false, $parentid = false,
						$model = CONTENT_MODEL_WIKITEXT, $format = CONTENT_FORMAT_WIKITEXT ) {

		$this->assertNodeStart( "revision" );
		$this->skipWhitespace();

		$this->assertTextNode( "id", $id );
		if ( $parentid !== false ) {
			$this->assertTextNode( "parentid", $parentid );
		}
		$this->assertTextNode( "timestamp", false );

		$this->assertNodeStart( "contributor" );
		$this->skipWhitespace();
		$this->assertTextNode( "ip", false );
		$this->assertNodeEnd( "contributor" );
		$this->skipWhitespace();

		$this->assertTextNode( "comment", $summary );
		$this->skipWhitespace();

		if ( $this->xml->name == "text" ) {
			// note: <text> tag may occur here or at the very end.
			$text_found = true;
			$this->assertText( $id, $text_id, $text_bytes, $text );
		} else {
			$text_found = false;
		}

		$this->assertTextNode( "sha1", $text_sha1 );

		$this->assertTextNode( "model", $model );
		$this->skipWhitespace();

		$this->assertTextNode( "format", $format );
		$this->skipWhitespace();

		if ( !$text_found ) {
			$this->assertText( $id, $text_id, $text_bytes, $text );
		}

		$this->assertNodeEnd( "revision" );
		$this->skipWhitespace();
	}

	protected function assertText( $id, $text_id, $text_bytes, $text ) {
		$this->assertNodeStart( "text", false );
		if ( $text_bytes !== false ) {
			$this->assertEquals( $this->xml->getAttribute( "bytes" ), $text_bytes,
				"Attribute 'bytes' of revision " . $id );
		}

		if ( $text === false ) {
			// Testing for a stub
			$this->assertEquals( $this->xml->getAttribute( "id" ), $text_id,
				"Text id of revision " . $id );
			$this->assertFalse( $this->xml->hasValue, "Revision has text" );
			$this->assertTrue( $this->xml->read(), "Skipping text start tag" );
			if ( ( $this->xml->nodeType == XMLReader::END_ELEMENT )
				&& ( $this->xml->name == "text" ) ) {

				$this->xml->read();
			}
			$this->skipWhitespace();
		} else {
			// Testing for a real dump
			$this->assertTrue( $this->xml->read(), "Skipping text start tag" );
			$this->assertEquals( $text, $this->xml->value, "Text of revision " . $id );
			$this->assertTrue( $this->xml->read(), "Skipping past text" );
			$this->assertNodeEnd( "text" );
			$this->skipWhitespace();
		}
	}
}
