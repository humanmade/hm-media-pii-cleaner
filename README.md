# HM Media PII Cleaner

WordPress plugin that strips hidden metadata (EXIF/IPTC/XMP, PDF Info/XMP, video `udta`, OOXML `docProps`) from Media Library uploads. By default title, organisation, copyright, created date, version and language are kept (see [Allowed fields](#allowed-fields)); author, software, camera, GPS and similar fields are removed.

Files that can't be proven clean are flagged in the Media Library and moved out of the document root.

Covered: JPEG, PNG, WebP, GIF, SVG, PDF, MP4/QuickTime, DOCX/XLSX/PPTX. No shell access, exiftool, Imagick or GD required.

See [LIMITATIONS.md](LIMITATIONS.md) for per-format behaviour, what isn't covered and the remediation workflow.

[Try it in WordPress Playground][playground] (latest `main`, with sample media already imported).

## Requirements

- WordPress 6.2+
- PHP 8.3+

## Installation

```
composer install --no-dev
```

Or require `humanmade/hm-media-pii-cleaner` from a site's `composer.json`. If the site already autoloads `setasign/fpdi`, the plugin uses that instead of its own `vendor/`.

## WP-CLI

```
wp hm-media-pii-cleaner sanitize-all [--include-videos] [--force] [--dry-run] [--report=<path>]
wp hm-media-pii-cleaner sanitize-pdfs|sanitize-gifs|sanitize-svgs|sanitize-videos|sanitize-ooxml|verify-images [<id>...] [--all] [--force] [--dry-run] [--report=<path>]
wp hm-media-pii-cleaner set-status <id> <status> [--detail=<text>]
wp hm-media-pii-cleaner status-report [--format=<all|pdf|image|video|ooxml>] [--output=<path>]
```

## Checking an attachment before exposing a link

```php
if (
	function_exists( '\HM\MediaPiiCleaner\RenderGuard\is_attachment_sanitized' )
	&& ! \HM\MediaPiiCleaner\RenderGuard\is_attachment_sanitized( $attachment_id )
) {
	return null;
}
```

## Allowed fields

The fields that survive sanitization are defined in `inc/fields.php` and can be changed with the `hm_media_pii_cleaner_allowed_fields` filter. Each field maps onto the metadata formats it applies to:

| Key | Value | Used for |
|---|---|---|
| `xmp` | `[ namespace, local name, prefix ]` list | XMP in JPEG, WebP, PNG, SVG and PDF. The first entry also receives converted EXIF/IPTC values. |
| `exif` | EXIF tag names, in precedence order | Converted to the field's XMP property before binary EXIF is removed |
| `iptc` | IPTC dataset codes, joined in order | Converted to the field's XMP property (JPEG) |
| `png` | PNG text keywords | `tEXt`/`zTXt`/`iTXt` chunks kept as-is |
| `pdf_info` | `Title`, `Author`, `Subject`, `Keywords`, `Creator`, `CreationDate` | PDF `/Info` dictionary |
| `ooxml_core` | `[ namespace, local name ]` list (`dc`, `dcterms` or `cp`) | `docProps/core.xml` |
| `ooxml_app` | Element names | `docProps/app.xml` |
| `image_meta` | WordPress `image_meta` keys | Stored attachment metadata and the REST API |
| `date` | `true` | Normalizes converted EXIF/IPTC values to ISO 8601 |

Remove a field:

```php
add_filter( 'hm_media_pii_cleaner_allowed_fields', function ( array $fields ) : array {
	unset( $fields['organisation'] );
	return $fields;
} );
```

Add a field, or change an existing one:

```php
add_filter( 'hm_media_pii_cleaner_allowed_fields', function ( array $fields ) : array {
	$fields['credit'] = [
		'xmp'  => [ [ 'http://ns.adobe.com/photoshop/1.0/', 'Credit', 'photoshop' ] ],
		'iptc' => [ '2#110' ],
	];
	$fields['title']['png'][] = 'Description';
	return $fields;
} );
```

Notes:

- Invalid mappings are dropped and reported through `_doing_it_wrong()`. A filter that doesn't return an array keeps nothing.
- Reserved XMP namespaces (`rdf`, `x`, `xml`), PNG's raw XMP keyword and the PDF `Producer` key can't be allowed. `orientation` is always kept in `image_meta`, since the image editor needs it.
- Anything you add is published with every matching file. Author, creator and software fields usually contain usernames and version numbers.
- The PDF verifier follows the same rules, so an allowed field doesn't cause a PDF to be flagged.
- Changes only apply to files sanitized afterwards. Run `wp hm-media-pii-cleaner sanitize-all --force` to re-process existing files. Fields that were already stripped can't be restored.

## Configuration

Optional constants for `wp-config.php`:

- `HM_MEDIA_PII_CLEANER_QUARANTINE_DIR`: private directory for flagged files.
- `HM_MEDIA_PII_CLEANER_MAX_{IMAGE,PDF,VIDEO,OOXML}_BYTES`, `HM_MEDIA_PII_CLEANER_MEMORY_RESERVE_BYTES`: size and memory ceilings.

## Development

```
composer install
composer test
composer lint
```

### WordPress Playground

```
composer install
composer playground
```

This starts a disposable [WordPress Playground](https://wordpress.github.io/wordpress-playground/) site on port 9400, or the next free port (Node.js 20.18+ required), and mounts this directory as the plugin, including `vendor/`. The site boots from `blueprint.json`: PHP 8.3, latest WordPress, `WP_DEBUG_LOG` on, plugin active, logged in as `admin`.

It also imports a sample JPEG, PNG and PDF from `tests/fixtures/playground/`. Each one has fake personal metadata (author, software, camera) as well as a title and copyright, and opens in the Media Library list view, where the Metadata column shows the result. Pass extra CLI flags after `--`, e.g. `composer playground -- --port=9500 --php=8.4`. To rebuild the sample files, run `php tests/fixtures/playground/generate.php`.

Online previews use the same `blueprint.json`, with the plugin installed from a zip built by `bin/build-playground.sh` (production `vendor/` plus the sample files) instead of mounted:

- **Pull requests:** `playground-preview-build.yml` builds the zip from the PR with read-only permissions. `playground-preview-publish.yml` then uploads it to the `ci-artifacts` prerelease and adds an "Open in Playground" button to the PR description.
- **`main`:** `playground-main.yml` uploads the zip to the `playground-main` prerelease on every push. The README link above installs from there.

The README link carries the blueprint inline, because playground.wordpress.net can't fetch a blueprint from a GitHub release (no CORS headers). After changing `blueprint.json`, regenerate it with the command below; `composer test` fails while the link is out of date.

```
php bin/playground-blueprint.php --link https://github.com/humanmade/hm-media-pii-cleaner/releases/download/playground-main/hm-media-pii-cleaner.zip
```

[playground]: https://playground.wordpress.net/?blueprint-url=data:application/json,%7B%22%24schema%22%3A%22https%3A%2F%2Fplayground.wordpress.net%2Fblueprint-schema.json%22%2C%22meta%22%3A%7B%22title%22%3A%22HM%20Media%20PII%20Cleaner%22%2C%22author%22%3A%22Human%20Made%22%2C%22description%22%3A%22Activates%20HM%20Media%20PII%20Cleaner%20and%20imports%20sample%20media%20carrying%20personal%20metadata.%22%7D%2C%22landingPage%22%3A%22%2Fwp-admin%2Fupload.php%3Fmode%3Dlist%22%2C%22login%22%3Atrue%2C%22preferredVersions%22%3A%7B%22php%22%3A%228.3%22%2C%22wp%22%3A%22latest%22%7D%2C%22features%22%3A%7B%22networking%22%3Afalse%2C%22intl%22%3Atrue%7D%2C%22steps%22%3A%5B%7B%22step%22%3A%22installPlugin%22%2C%22pluginData%22%3A%7B%22resource%22%3A%22url%22%2C%22url%22%3A%22https%3A%2F%2Fgithub.com%2Fhumanmade%2Fhm-media-pii-cleaner%2Freleases%2Fdownload%2Fplayground-main%2Fhm-media-pii-cleaner.zip%22%7D%2C%22options%22%3A%7B%22activate%22%3Atrue%2C%22targetFolderName%22%3A%22hm-media-pii-cleaner%22%7D%7D%2C%7B%22step%22%3A%22defineWpConfigConsts%22%2C%22consts%22%3A%7B%22WP_DEBUG%22%3Atrue%2C%22WP_DEBUG_LOG%22%3Atrue%2C%22WP_DEBUG_DISPLAY%22%3Afalse%7D%7D%2C%7B%22step%22%3A%22wp-cli%22%2C%22command%22%3A%22wp%20media%20import%20%2Fwordpress%2Fwp-content%2Fplugins%2Fhm-media-pii-cleaner%2Ftests%2Ffixtures%2Fplayground%2Fsample-photo.jpg%20%2Fwordpress%2Fwp-content%2Fplugins%2Fhm-media-pii-cleaner%2Ftests%2Ffixtures%2Fplayground%2Fsample-graphic.png%20%2Fwordpress%2Fwp-content%2Fplugins%2Fhm-media-pii-cleaner%2Ftests%2Ffixtures%2Fplayground%2Fsample-document.pdf%22%7D%5D%7D
