<?php
/**
 * Tally of a WP-CLI pass, streamed to an optional CSV report.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Cli;

/**
 * Counts result rows as they are produced and writes each one straight to
 * the report, so memory stays flat however many attachments a run covers.
 *
 * A report is a by-product of the run, never the run itself: the path is
 * checked before any work starts, and a write that still fails mid-run is
 * reported without stopping the pass.
 */
final class Results {

	/**
	 * CSV destination, or '' when no report was requested.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Open report, created when the first row arrives.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Whether every row so far reached the report.
	 *
	 * @var bool
	 */
	private bool $complete = true;

	/**
	 * Rows counted.
	 *
	 * @var int
	 */
	private int $total = 0;

	/**
	 * Rows counted as passed.
	 *
	 * @var int
	 */
	private int $passed = 0;

	/**
	 * Check the report path up front, so a bad one stops the command before
	 * any attachment is touched rather than after a long run.
	 *
	 * @param string $path CSV destination, or '' for no report.
	 */
	public function __construct( string $path ) {
		$this->path = $path;

		if ( '' === $path ) {
			return;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem has no CLI context to write a local report through.
		$writable = file_exists( $path )
			? is_file( $path ) && is_writable( $path )
			: is_dir( dirname( $path ) ) && is_writable( dirname( $path ) );
		// phpcs:enable

		if ( ! $writable ) {
			\WP_CLI::error( sprintf( 'Cannot write a report to %s. Nothing was processed.', $path ) );
		}
	}

	/**
	 * Count a result row and append it to the report.
	 *
	 * @param array $row    Row keyed by column name. The first row's keys become the header.
	 * @param bool  $passed Whether the row counts towards the passed total.
	 */
	public function add( array $row, bool $passed = false ) : void {
		++$this->total;
		$this->passed += (int) $passed;

		if ( '' === $this->path || ! $this->complete ) {
			return;
		}

		if ( null === $this->handle ) {
			$handle = fopen( $this->path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- fputcsv() needs a stream handle.
			if ( false === $handle ) {
				$this->complete = false;
				\WP_CLI::warning( sprintf( 'Could not open %s for writing. The run continues without a report.', $this->path ) );
				return;
			}

			$this->handle = $handle;
			$this->write( array_keys( $row ) );
		}

		$this->write( $row );
	}

	/**
	 * Close the report.
	 *
	 * @return bool Whether a complete report was written.
	 */
	public function finish() : bool {
		if ( null === $this->handle ) {
			return false;
		}

		// fclose() flushes, so it is the last chance to notice a full disk.
		$closed       = fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen() above.
		$this->handle = null;

		if ( ! $closed || ! $this->complete ) {
			\WP_CLI::warning( sprintf( 'Only part of the report reached %s.', $this->path ) );
			return false;
		}

		return true;
	}

	/**
	 * Report destination.
	 *
	 * @return string
	 */
	public function path() : string {
		return $this->path;
	}

	/**
	 * Rows counted.
	 *
	 * @return int
	 */
	public function total() : int {
		return $this->total;
	}

	/**
	 * Rows counted as passed.
	 *
	 * @return int
	 */
	public function passed() : int {
		return $this->passed;
	}

	/**
	 * Write one CSV line.
	 *
	 * @param array $fields Values to write.
	 */
	private function write( array $fields ) : void {
		// An empty $escape is both RFC 4180 correct and required from PHP 8.4,
		// where relying on the default backslash escaping is deprecated. Detail
		// columns carry file paths, so backslashes do reach this.
		if ( false === fputcsv( $this->handle, $fields, ',', '"', '' ) ) {
			$this->complete = false;
		}
	}
}
