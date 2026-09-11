<?php

namespace HM\MediaPiiCleaner\Tests;

use HM\MediaPiiCleaner\Images;

/**
 * Tests for Images\strip_svg_metadata() and get_svg_dimensions().
 */
class SvgSanitizerTest extends TestCase {

	private const DIRTY_SVG = <<<'SVG'
		<?xml version="1.0" encoding="UTF-8"?>
		<svg xmlns="http://www.w3.org/2000/svg" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape"
			xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.0.dtd"
			viewBox="0 0 100 100" width="100" height="100" inkscape:version="1.0">
			<!-- a comment left by the editor -->
			<metadata><rdf:RDF>authoring info</rdf:RDF></metadata>
			<title>My Icon</title>
			<desc>Made in Illustrator</desc>
			<circle sodipodi:nodetypes="ccc" cx="50" cy="50" r="40" />
		</svg>
		SVG;

	public function test_strips_metadata_and_comments() : void {
		$clean = Images\strip_svg_metadata( self::DIRTY_SVG );

		$this->assertStringNotContainsString( '<metadata', $clean );
		$this->assertStringNotContainsString( 'a comment left by the editor', $clean );
		$this->assertStringNotContainsString( 'authoring info', $clean );
	}

	public function test_keeps_title_and_desc() : void {
		// <title>/<desc> are accessibility content (accessible name/description,
		// native hover tooltip for inline SVG), not hidden metadata — the
		// actual privacy concern (authors/usernames/software versions) lives
		// in <metadata>'s RDF blob instead.
		$clean = Images\strip_svg_metadata( self::DIRTY_SVG );

		$this->assertStringContainsString( '<title>My Icon</title>', $clean );
		$this->assertStringContainsString( '<desc>Made in Illustrator</desc>', $clean );
	}

	public function test_strips_editor_namespaced_attributes_anywhere_in_the_tree() : void {
		$clean = Images\strip_svg_metadata( self::DIRTY_SVG );

		$this->assertStringNotContainsString( 'inkscape:version', $clean );
		$this->assertStringNotContainsString( 'sodipodi:nodetypes', $clean );
	}

	public function test_strips_arbitrary_namespaced_metadata_but_keeps_xml_and_xlink() : void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"'
			. ' xmlns:foo="urn:private" xmlns:xlink="http://www.w3.org/1999/xlink"'
			. ' foo:Producer="Secret Tool 9" xml:lang="en">'
			. '<use xlink:href="#shape"/><path id="shape" d="M0 0h1v1z"/>'
			. '</svg>';

		$clean = Images\strip_svg_metadata( $svg );

		$this->assertStringNotContainsString( 'Secret Tool 9', $clean );
		$this->assertStringNotContainsString( 'foo:Producer', $clean );
		$this->assertStringContainsString( 'xml:lang="en"', $clean );
		$this->assertStringContainsString( 'xlink:href="#shape"', $clean );
	}

	public function test_strips_unnamespaced_rdfa_editor_and_data_metadata_attributes() : void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"'
			. ' about="Jane Doe" version="SecretTool 9" data-author="Jane Doe" aria-label="Public icon">'
			. '<path d="M0 0h10v10z" property="dc:creator" content="Jane Doe"/>'
			. '</svg>';

		$clean = Images\strip_svg_metadata( $svg );

		$this->assertStringNotContainsString( 'Jane Doe', $clean );
		$this->assertStringNotContainsString( 'SecretTool 9', $clean );
		$this->assertStringNotContainsString( 'property=', $clean );
		$this->assertStringNotContainsString( 'content=', $clean );
		$this->assertStringContainsString( 'aria-label="Public icon"', $clean );
		$this->assertStringContainsString( 'viewBox="0 0 10 10"', $clean );
	}

	public function test_keeps_rendering_relevant_attributes() : void {
		$clean = Images\strip_svg_metadata( self::DIRTY_SVG );

		// Unused xmlns:inkscape/xmlns:sodipodi namespace declarations are left
		// in place (libxml can't reliably remove them once no attribute uses
		// them, and an unused declaration isn't itself an identifying leak) —
		// what matters is the circle itself and its geometry survive untouched.
		$this->assertStringContainsString( '<circle', $clean );
		$this->assertStringContainsString( 'cx="50"', $clean );
	}

	public function test_preserves_viewbox_width_height() : void {
		$before = Images\get_svg_dimensions( self::DIRTY_SVG );
		$clean  = Images\strip_svg_metadata( self::DIRTY_SVG );
		$after  = Images\get_svg_dimensions( $clean );

		$this->assertSame( $before, $after );
		$this->assertSame( '0 0 100 100', $before['viewBox'] );
	}

	public function test_rejects_invalid_xml() : void {
		$this->expectException( \RuntimeException::class );
		Images\strip_svg_metadata( '<svg><unclosed>' );
	}

	public function test_rejects_dtd_and_external_entities() : void {
		$svg = '<!DOCTYPE svg [<!ENTITY local SYSTEM "file:///etc/hosts">]>'
			. '<svg xmlns="http://www.w3.org/2000/svg"><title>&local;</title></svg>';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'DTD' );
		Images\strip_svg_metadata( $svg );
	}

	public function test_rebuilds_allowed_rdf_without_nested_disallowed_metadata() : void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"'
			. ' xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:pdf="http://ns.adobe.com/pdf/1.3/">'
			. '<metadata><rdf:RDF><rdf:Description>'
			. '<dc:rights>Copyright 2026</dc:rights>'
			. '<dc:title><pdf:Producer>Secret Tool</pdf:Producer></dc:title>'
			. '</rdf:Description></rdf:RDF></metadata><rect width="10" height="10"/>'
			. '</svg>';

		$clean = Images\strip_svg_metadata( $svg );

		$this->assertStringContainsString( 'Copyright 2026', $clean );
		$this->assertStringNotContainsString( 'Secret Tool', $clean );
		$this->assertStringNotContainsString( 'pdf:Producer', $clean );
	}
}
