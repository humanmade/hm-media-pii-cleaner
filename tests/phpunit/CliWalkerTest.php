<?php

namespace HM\MediaPiiCleaner\Tests;

use Brain\Monkey\Functions;
use HM\MediaPiiCleaner\Cli;

class CliWalkerTest extends TestCase {

	private $previous_wpdb;

	protected function setUp() : void {
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['hm_test_registered_hooks'] = [];
		$GLOBALS['wpdb'] = new class {
			public string $posts = 'wp_posts';
			public array $queries = [];
			public function prepare( $sql, $id ) { return sprintf( $sql, $id ); }
		};
	}

	protected function tearDown() : void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		parent::tearDown();
	}

	public function test_cursor_survives_deletion_and_skipped_sanitized_posts() : void {
		$records = [];
		foreach ( range( 1, 201 ) as $id ) {
			$records[ $id ] = (object) [ 'ID' => $id, 'post_type' => 'attachment' ];
		}
		$active_filter = null;
		Functions\expect( 'remove_filter' )->times( 3 )->andReturnUsing(
			function ( $hook, $callback ) use ( &$active_filter ) {
				$this->assertSame( $active_filter, $callback );
				$active_filter = null;
				return true;
			}
		);
		Functions\expect( 'get_posts' )->times( 3 )->andReturnUsing(
			function ( $args ) use ( &$records, &$active_filter ) {
				$hooks = $GLOBALS['hm_test_registered_hooks'];
				$active_filter = end( $hooks )[2];
				$query = new class( $args ) {
					public function __construct( private array $args ) {}
					public function get( $key ) { return $this->args[ $key ] ?? null; }
				};
				$this->assertSame( 'WHERE 1=1', $active_filter( 'WHERE 1=1', new class {
					public function get( $key ) { return null; }
				} ) );
				$where = $active_filter( 'WHERE 1=1', $query );
				preg_match( '/ID > (\d+)/', $where, $match );
				$this->assertNotEmpty( $match );
				$matching = array_filter( $records, fn( $p ) => $p->ID > (int) $match[1] );
				return array_slice( array_values( $matching ), 0, $args['posts_per_page'] );
			}
		);
		Functions\when( 'get_post_meta' )->alias( fn( $id ) => 100 === $id ? 'sanitized' : '' );
		Functions\expect( 'clean_attachment_cache' )->times( 201 );
		$visited = [];
		Cli\walk_attachments( [], [ 'all' => true ], 'application/pdf',
			function ( $post ) use ( &$records, &$visited, &$active_filter ) {
				$this->assertNull( $active_filter, 'Cursor filter must be removed before processing.' );
				$visited[] = $post->ID;
				if ( 99 === $post->ID ) {
					unset( $records[1] );
				}
			}
		);
		$this->assertSame( array_values( array_diff( range( 1, 201 ), [ 100 ] ) ), $visited );
	}

	public function test_explicit_ids_include_private_attachments_and_report_invalid_ids() : void {
		$cli = \Mockery::mock( 'alias:WP_CLI' );
		$cli->shouldReceive( 'warning' )->times( 3 );
		Functions\expect( 'get_post' )->with( 1 )->once()->andReturn( (object) [ 'ID' => 1, 'post_type' => 'attachment', 'post_status' => 'private', 'post_mime_type' => 'application/pdf' ] );
		Functions\expect( 'get_post' )->with( 2 )->once()->andReturn( (object) [ 'ID' => 2, 'post_type' => 'post' ] );
		Functions\expect( 'get_post' )->with( 999 )->once()->andReturnNull();
		Functions\expect( 'clean_attachment_cache' )->with( 1 )->once();
		$path = tempnam( sys_get_temp_dir(), 'hm-cli-test-' );
		$results = new Cli\Results( $path );
		$visited = [];
		try {
			Cli\walk_attachments( [ '1', '2', '999', 'oops' ], [], 'application/pdf',
				function ( $post ) use ( &$visited, $results ) {
					$visited[] = $post->ID;
					$results->add( [ 'attachment_id' => $post->ID, 'file' => 'test.pdf', 'ok' => 'yes', 'reason' => 'clean' ], true );
				}, $results
			);
			$this->assertTrue( $results->finish() );
			$this->assertSame( [ 1 ], $visited );
			$this->assertSame( 4, $results->total() );
			$this->assertSame( 1, $results->passed() );
			$lines = file( $path );
			$this->assertCount( 5, $lines );
			$this->assertStringContainsString( '999,,no,', $lines[3] );
			$this->assertStringContainsString( 'oops,,no,', $lines[4] );
		} finally {
			unlink( $path );
		}
	}

	public function test_cursor_filter_is_removed_when_query_throws() : void {
		Functions\expect( 'get_posts' )->once()->andThrow( new \RuntimeException( 'Query failed' ) );
		Functions\expect( 'remove_filter' )->once()->with( 'posts_where', \Mockery::type( \Closure::class ), 10 )->andReturnTrue();
		$this->expectExceptionMessage( 'Query failed' );
		Cli\walk_attachments( [], [ 'all' => true ], 'application/pdf', static function () {} );
	}
}
