<?php
/**
 * Plugin-wide constants.
 *
 * This file only calls define() — no side effects beyond constant registration.
 *
 * @package HM\MediaPiiCleaner
 */

define( 'HM_MEDIA_PII_CLEANER_DIR', __DIR__ );
define( 'HM_MEDIA_PII_CLEANER_URL', plugin_dir_url( dirname( __DIR__ ) . '/hm-media-pii-cleaner.php' ) );

// Post-meta keys used on attachment posts.
define( 'HM_MEDIA_PII_CLEANER_STATUS_META', '_hm_media_pii_cleaner_status' );
define( 'HM_MEDIA_PII_CLEANER_DETAIL_META', '_hm_media_pii_cleaner_detail' );
define( 'HM_MEDIA_PII_CLEANER_QUARANTINE_META', '_hm_media_pii_cleaner_quarantine' );

// Status values stored in HM_MEDIA_PII_CLEANER_STATUS_META.
define( 'HM_MEDIA_PII_CLEANER_STATUS_SANITIZED', 'sanitized' );
define( 'HM_MEDIA_PII_CLEANER_STATUS_FLAGGED', 'flagged' );
define( 'HM_MEDIA_PII_CLEANER_STATUS_FAILED', 'failed' );

// Attachments the WP-CLI commands process between object cache cleanups.
define( 'HM_MEDIA_PII_CLEANER_CLI_BATCH_SIZE', 100 );

// MIME types this plugin actively processes.
define(
	'HM_MEDIA_PII_CLEANER_IMAGE_MIMES',
	[ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml' ]
);
define( 'HM_MEDIA_PII_CLEANER_PDF_MIME', 'application/pdf' );

// ISOBMFF containers (MP4 and QuickTime .mov share the same box structure).
// WebM (Matroska/EBML) is a different container format and is not covered —
// see LIMITATIONS.md.
define(
	'HM_MEDIA_PII_CLEANER_VIDEO_MIMES',
	[ 'video/mp4', 'video/quicktime' ]
);

// Modern (ZIP-based) OOXML documents only — legacy binary .doc/.xls/.ppt
// (Compound File Binary Format) are a different container and are not
// covered — see LIMITATIONS.md.
define(
	'HM_MEDIA_PII_CLEANER_OOXML_MIMES',
	[
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	]
);

/*
 * Resource ceilings. Hosts may define any of these in wp-config.php before
 * WordPress loads the plugin. The effective per-file limit is also reduced at
 * runtime when PHP's remaining memory cannot safely hold the parser's working
 * copies of the file.
 */
defined( 'HM_MEDIA_PII_CLEANER_MAX_IMAGE_BYTES' ) || define( 'HM_MEDIA_PII_CLEANER_MAX_IMAGE_BYTES', 50 * 1024 * 1024 );
defined( 'HM_MEDIA_PII_CLEANER_MAX_PDF_BYTES' ) || define( 'HM_MEDIA_PII_CLEANER_MAX_PDF_BYTES', 100 * 1024 * 1024 );
defined( 'HM_MEDIA_PII_CLEANER_MAX_VIDEO_BYTES' ) || define( 'HM_MEDIA_PII_CLEANER_MAX_VIDEO_BYTES', 512 * 1024 * 1024 );
defined( 'HM_MEDIA_PII_CLEANER_MAX_OOXML_BYTES' ) || define( 'HM_MEDIA_PII_CLEANER_MAX_OOXML_BYTES', 50 * 1024 * 1024 );
defined( 'HM_MEDIA_PII_CLEANER_MEMORY_RESERVE_BYTES' ) || define( 'HM_MEDIA_PII_CLEANER_MEMORY_RESERVE_BYTES', 16 * 1024 * 1024 );

// Expanded-ZIP budgets for OOXML packages. These are checked before an entry is decompressed.
defined( 'HM_MEDIA_PII_CLEANER_MAX_OOXML_ENTRIES' ) || define( 'HM_MEDIA_PII_CLEANER_MAX_OOXML_ENTRIES', 10000 );
defined( 'HM_MEDIA_PII_CLEANER_MAX_OOXML_ENTRY_BYTES' ) || define( 'HM_MEDIA_PII_CLEANER_MAX_OOXML_ENTRY_BYTES', 64 * 1024 * 1024 );
defined( 'HM_MEDIA_PII_CLEANER_MAX_OOXML_EXPANDED_BYTES' ) || define( 'HM_MEDIA_PII_CLEANER_MAX_OOXML_EXPANDED_BYTES', 256 * 1024 * 1024 );
defined( 'HM_MEDIA_PII_CLEANER_MAX_OOXML_PROPERTY_BYTES' ) || define( 'HM_MEDIA_PII_CLEANER_MAX_OOXML_PROPERTY_BYTES', 1024 * 1024 );
defined( 'HM_MEDIA_PII_CLEANER_MAX_OOXML_COMPRESSION_RATIO' ) || define( 'HM_MEDIA_PII_CLEANER_MAX_OOXML_COMPRESSION_RATIO', 200 );
