# HM Media PII Cleaner: scope and limitations

Publicly downloadable files often carry hidden metadata (author names, usernames, software
versions, file paths, GPS, camera details). This plugin removes it at upload time and gives
WP-CLI tooling to remediate files that already exist.

## Allowlist: what survives sanitization

By default everything is removed except: document/file title, organisation name, copyright
notice, publication/created date, document version, and language. Developers can remove, add
or remap fields with the `hm_media_pii_cleaner_allowed_fields` filter (see the README); the
defaults live in `inc/fields.php`. The default fields are kept only where a safe, reliable
mapping to a real file-format field exists:

- **PDF**: `/Title` and `/CreationDate` in the `/Info` dictionary are read from the source
  document and passed through (Stage 1, `rebuilder.php`). `/CreationDate` is only preserved
  when the source value uses the same fixed format FPDF generates
  (`D:YYYYMMDDHHMMSS+HH'MM'`, 23 bytes). This is required for the same-length in-place swap
  that keeps every other object's byte offset valid; a source date in any other format falls
  back to the processing timestamp. The Catalog's document-level XMP is filtered and written
  into a new Metadata stream during FPDI's normal object-writing phase, before its xref table
  is generated. Residual per-object XMP streams handled by Stage 2 are filtered down to
  `dc:title`/`dc:rights`/`dc:language`/`dc:publisher`/`xmp:CreateDate`/`xmpMM:VersionID`,
  padded to their original byte length.
- **JPEG/WebP**: plain XMP is filtered through the same allowlist. Before binary EXIF is
  removed, its standard title, copyright, and created-date values are converted into a fresh
  filtered XMP packet. JPEG applies the same conversion to allowlisted IPTC values.
- **PNG**: `tEXt`/`zTXt`/`iTXt` chunks are kept when their keyword is exactly `Title`,
  `Copyright`, or `Creation Time` (the PNG spec's standard keywords for those concepts). XMP
  carried by `XML:com.adobe.xmp` iTXt is decoded, allowlist-filtered, and rewritten in the
  recommended uncompressed iTXt form. Allowlisted binary `eXIf` values are converted to XMP
  before the original chunk is removed. Every other text keyword, `tIME` (modification date),
  and unknown/private ancillary chunk is dropped. Only critical image chunks and an explicit
  rendering/color/animation ancillary allowlist survive.
- **SVG**: the `<metadata>` element's RDF content is filtered the same way instead of being
  removed outright. Unnamespaced RDFa/editor/data attributes are also removed outside that
  element. `<title>`/`<desc>` are preserved because they are accessibility content.
- **GIF**: Comment, non-NETSCAPE Application, and unknown extension blocks are removed.
  Graphic Control and Plain Text extensions remain because they affect animation/visible
  content. GIF has no structured allowlisted metadata mapping, so nothing is guessed.

**Organisation name and document version are preserved only through their standard XMP
mappings:** Dublin Core `dc:publisher` and XMP Media Management `xmpMM:VersionID`. Formats
without those structured fields do not guess from Author/Credit/Creator, since those fields
routinely hold personal usernames, exactly the PII this plugin removes.

**Binary EXIF/IPTC segments are never rewritten.** JPEG, PNG, and WebP read their standard
allowlisted values and convert them into a fresh filtered XMP packet before dropping the
original TIFF/IPTC bytes. This preserves allowlisted semantics without the corruption risk of
a hand-written binary metadata writer; camera/software/author/GPS values never enter the
replacement XMP.

**Orientation-dependent images are flagged, not rewritten.** An EXIF orientation other than
the normal value `1` requires rotating or mirroring the pixels before removing EXIF. The
byte-level sanitizer does not re-encode pixels, so these files follow the quarantine policy
with their original bytes intact (subject to the deletion fallback if quarantine storage
fails). Normalize them off-host before re-uploading. This check also applies to TIFF EXIF in
PNG/WebP.

**WordPress metadata and image-edit backups are covered too.** The stored `image_meta` is
limited to title, copyright, created timestamp, and the orientation instruction needed by the
image editor. Credit, camera, keywords and other disallowed fields are removed before metadata
writes and during image CLI backfills. Files retained in `_wp_attachment_backup_sizes` are
included in both sanitization and quarantine, alongside current sizes and `original_image`.

## What this covers

- **Images** (JPEG, PNG, WebP, GIF, SVG) in the Media Library, including all generated
  sub-sizes (thumbnail/medium/large/scaled).
- **PDFs** uploaded to the Media Library, including every JPEG preview and preview sub-size
  WordPress generates for the PDF.
- **Video** (MP4, QuickTime `.mov`): `udta`/`meta` authoring metadata (encoder tool, camera
  make/model, GPS, free-text titles/comments) is stripped wholesale, not field-filtered. See
  "Video" below for why, and for the fast-start layout limitation.
- **OOXML documents** (`.docx`/`.xlsx`/`.pptx`): `docProps/core.xml`/`app.xml` filtered to
  the allowlist, `docProps/custom.xml` emptied. See "OOXML" below.

## Host requirements

- **No shell access or custom binaries needed.** The sanitizers never call
  `shell_exec`/`exec`/`proc_open`/`system`/`popen`. They use byte-level chunk/segment
  rewriting in plain PHP, `DOMDocument`/`DOMXPath` for XMP/SVG, and `setasign/fpdi` (a
  pure-PHP Composer library) for PDF. This makes the plugin usable on managed hosts that
  disable process execution, where exiftool/qpdf/ghostscript are not an option.
- **Imagick and GD are not required.** Nothing is decoded and re-encoded.
- XMP and SVG parsing never expands XML entities, never resolves external resources, and
  rejects DTD-bearing documents. Allowlisted XMP properties are rebuilt from text-only scalar
  values or standard RDF `Alt`/`Bag`/`Seq` containers; source DOM subtrees are never cloned,
  and a property with any unexpected child or attribute is dropped in full.

## EWWW Image Optimizer

EWWW's "Remove Metadata" setting (`ewww_image_optimizer_metadata_remove`) is a blanket strip
that would erase allowlisted XMP before it could be retained, so this plugin forces it **off**
for both site and network option reads and shows an admin notice explaining why. Supported
images are sanitized at priority 7, EWWW optimizes at priorities 8/15 without stripping
metadata, and an idempotent priority-20 pass verifies/sanitizes the result again. EWWW still
owns image compression; this plugin owns metadata policy.

JPEG, PNG, and WebP stripping is never delegated to EWWW, whether through its local binaries,
its Cloud API, or Imagick. EWWW cannot implement the field-level allowlist, and its real
coverage (IPTC/XMP as well as EXIF) depends on its configuration and available binaries.

## JPEG: byte-level stripping

JPEG metadata (EXIF, XMP, IPTC, free-text comments, and private APP payloads) is stripped
directly. APP segments fail closed: only positively identified JFIF/JFXX structure, ICC color
profiles, Adobe color-transform data, and rebuilt allowlisted XMP survive; every other APP
payload and COM comment is dropped. The complete marker stream is walked, including markers
between progressive scans and before EOI; byte-stuffed scan bytes and restart markers are
recognized and copied without modification.

## PNG/WebP: byte-level chunk stripping, not re-encoding

A GD decode/re-encode round trip is deliberately avoided. `imagesavealpha()` without a
preceding `imagealphablending(false)` is a known cause of alpha-channel corruption on
transparent PNGs; re-encoding WebP at a fixed quality is a lossy step that degrades a
lossless-source WebP; and `imagepalettetotruecolor()` needlessly inflates indexed PNGs.

PNG and WebP are chunk-structured formats (PNG: length-prefixed chunks; WebP: a RIFF
container), so metadata-carrying chunks (`tEXt`/`zTXt`/`iTXt`/`eXIf`/`tIME` for PNG,
`EXIF`/`XMP ` for WebP) can be filtered, converted, or dropped by rewriting the chunk stream
directly. Unknown/private ancillary chunks are also dropped at PNG top level and at WebP top
level/inside `ANMF`; known pixel, alpha, color-profile, animation, and rendering chunks remain
byte-for-byte untouched. Lossless by construction.

## Video

MP4 and QuickTime `.mov` are a tree of length-prefixed "boxes"; authoring metadata (encoder
tool, camera make/model, GPS, free-text titles) lives in `udta`/`meta` boxes inside `moov`.
Sample video/audio bytes live in one or more `mdat` boxes, and every sample-location table
(`stco`/`co64`, inside `moov`) records **absolute file offsets** into `mdat`, so any edit that
shifts a byte's position before the end of the last `mdat` box would silently invalidate those
offsets and corrupt playback.

- **No field-level allowlist for video.** Real-world files mix at least two competing
  metadata conventions inside a single `udta` tree (QuickTime "classic" atoms like a
  per-track `udta/name`, and iTunes-style `meta`/`hdlr`/`ilst`/`©too` atoms) with no single
  reliable field mapping to the allowlist. Rather than guess, `udta`/`meta` is dropped
  wholesale, the same reasoning applied to GIF.
- **Only content positioned after the last `mdat` box's end is ever rewritten.** Nothing
  before that point moves, so no `stco`/`co64` offset is invalidated. This is the default
  layout (`moov` last) for common encoders such as HandBrake.
- **"Fast-start" files (`moov` before `mdat`) are flagged, not fixed.** This layout lets
  playback start before the whole file downloads and is common for web video. Safely editing
  `udta`/`meta` here would require patching every `stco`/`co64` entry by the exact byte delta
  removed, which this plugin does not implement. Expect a meaningful share of web video to be
  flagged for this reason.
- **Flagged video is never quarantined or blocked from resolving its URL**, a deliberate
  exception to how every other covered format handles a flagged status. Video is often
  referenced as a hardcoded `<video src="...">` literal in saved block content (for example a
  Cover block background) rather than resolved through `wp_get_attachment_url()`.
  Quarantining such a file would 404 the page with no graceful fallback, and the URL filter
  would never see the literal-URL case anyway. Flagged video status is recorded for admin
  visibility only (`status-report --format=video`, admin notice).
- **Fragmented MP4** (`moof`/`mfra` boxes, used for adaptive-bitrate streaming) is rejected
  outright. It has additional per-fragment `tfra`/`trun` offset tables this plugin does not
  reason about.
- **WebM is not covered.** It is Matroska/EBML, a container format unrelated to
  MP4/QuickTime's ISOBMFF, and would need a separate parser.

## OOXML (.docx/.xlsx/.pptx): field mapping

An OOXML file is a ZIP archive; the metadata equivalent of a PDF's `/Info` dict lives in two
small package parts, identical across Word/Excel/PowerPoint: `docProps/core.xml` (Dublin Core
+ OPC "Core Properties") and `docProps/app.xml` ("Extended Properties"). A third part,
`docProps/custom.xml`, holds arbitrary author-defined key/value pairs with nothing allowlisted
among them, so it is emptied outright.

- `dc:title` (title), `dc:rights` (copyright notice), `dc:language` (language), and
  `dcterms:created` (publication/created date) are kept from `core.xml`.
- `dc:creator` and `cp:lastModifiedBy` usually hold usernames and are dropped, the same as
  every other format's Author/Creator field.
- `cp:revision` (an auto-incrementing Office edit counter) and `dcterms:modified` are
  deliberately **not** treated as "document version"/"publication date". `xmpMM:VersionID`,
  used for the same allowlist field everywhere else, is a genuine authorial version marker;
  `cp:revision` is closer to internal edit telemetry.
- `app.xml`'s `Company` field maps to organisation name, OOXML's one direct mapping for that
  allowlist field. `Application`/`AppVersion` (software-version disclosure) and everything
  else in `app.xml` is dropped.
- A ZIP container has no absolute byte-offset structure to preserve. PHP's `ZipArchive`
  rewrites the central directory when a part's content is replaced, so each part is simply
  replaced outright.
- **Legacy binary Office formats** (`.doc`/`.xls`/`.ppt`, Compound File Binary Format) are a
  different, non-ZIP container and are not covered.

## Resource-exhaustion guardrails

The format parsers run synchronously during attachment processing, and some still need one or
more complete in-memory copies of the file. Before every such read the plugin applies two
limits: a configurable per-format ceiling and a lower runtime ceiling derived from the PHP
worker's remaining memory. The runtime calculation reserves 16 MiB for WordPress/error
handling and budgets for the number of simultaneous file copies the parser can create. The
lower limit always wins.

Default hard ceilings are 50 MiB for images, 100 MiB for PDF, 512 MiB for MP4/QuickTime, and
50 MiB for OOXML. Override them in `wp-config.php`:

- `HM_MEDIA_PII_CLEANER_MAX_IMAGE_BYTES`
- `HM_MEDIA_PII_CLEANER_MAX_PDF_BYTES`
- `HM_MEDIA_PII_CLEANER_MAX_VIDEO_BYTES`
- `HM_MEDIA_PII_CLEANER_MAX_OOXML_BYTES`
- `HM_MEDIA_PII_CLEANER_MEMORY_RESERVE_BYTES`

OOXML gets additional ZIP-bomb defenses before any property XML is decompressed: 10,000
entries maximum, 64 MiB per expanded entry, 256 MiB total expanded content, 1 MiB per
`docProps` XML part, and a maximum 200:1 expansion ratio. Encrypted, path-traversing, and
duplicate/case-ambiguous entry names are rejected. These defaults can be changed with the
corresponding `HM_MEDIA_PII_CLEANER_MAX_OOXML_*` constants (see `inc/constants.php`).

The upload prefilter rejects an obviously oversized covered upload early; each sanitizer
checks again immediately before processing, using a capped stream read. The second check is
authoritative because MIME classification and available worker memory can change between the
upload and attachment generation. Files that cross the later limit are flagged through the
same fail-closed path as parse failures.

These controls bound the synchronous design; they do not make the parsers streaming. Sites
that must accept files above the effective worker limit need an isolated background worker
with a larger memory budget rather than a higher hard ceiling in a web request.

## Status, quarantine and the render guard

- **Fail closed on anything that can't be proven safe.** Parse errors, non-idempotent strips,
  dimension/page-count mismatches, and generated sub-sizes that are unreadable on disk
  (missing, permissions, partial sync) all flag the whole attachment rather than marking it
  sanitized.
- **Flagged or failed attachments are quarantined.** Every attachment file, including a large
  upload's retained `original_image` and all generated sizes, is moved out of the document
  root. `wp_get_attachment_url()` also returns no URL for that attachment. Define
  `HM_MEDIA_PII_CLEANER_QUARANTINE_DIR` to point at a host-managed private volume; otherwise
  the default is `hm-media-pii-cleaner-quarantine` in the parent directory of `ABSPATH`. If quarantine storage cannot be
  created, the public copy is removed rather than left reachable. Restore a quarantined
  original only after off-host remediation and re-verification.
- **Never-processed attachments are treated as safe to expose (fail-open).** Every attachment
  that existed before activation has no status on day one, and treating "not yet scanned" the
  same as "flagged" would break every existing download link the moment the plugin is
  activated. Only an explicit `flagged`/`failed` verdict blocks exposure. Use the bulk CLI
  commands below to clear the backlog.
- **Other plugins and themes can check an attachment before exposing a link** with
  `HM\MediaPiiCleaner\RenderGuard\is_attachment_sanitized( $attachment_id )`. Wrap the call
  in `function_exists()` so the consumer does not hard-depend on this plugin being active.

## What this deliberately does not cover

- **Plain `.zip` files.** A raw ZIP archive has no established metadata convention to filter,
  and the plugin cannot know what is inside a given archive without assuming its contents.
  Audit the contents of any public ZIPs directly.
- **Files not registered as Media Library attachments.** Anything written straight to the
  uploads directory by another plugin (logs, generated snapshots, exports) is outside the
  attachment-based scope. Raw file exposure of that kind needs a web-server rule or a
  different storage location.
- **Product-specific exposure paths** such as WooCommerce downloadable files. The underlying
  attachments still pass through the dispatcher (it hooks the generic
  `wp_generate_attachment_metadata` filter), but there is no product-specific guard; use the
  render guard above if you need one.
- **Complex PDFs using compressed cross-reference/object streams.** Stage 2 (metadata-stream
  stripping) only operates on the classic, uncompressed xref structure that Stage 1's FPDI
  rebuild produces. A PDF that retains that structure after rebuild is flagged for manual
  review rather than silently passed. This is a structural limit of pure-PHP PDF rewriting
  without shell tools.
- **Compressed (`/Filter`-encoded) Metadata streams inside a PDF.** Stage 2 only rewrites
  uncompressed Metadata stream content (a same-length in-place patch that never needs to touch
  the xref table). A compressed Metadata stream is left alone and the attachment is flagged
  rather than risking a corrupt decompress/recompress round-trip.
- **Media replacement plugins** (e.g. "Enable Media Replace") are untested. A replaced file
  that goes through `wp_generate_attachment_metadata` will be re-sanitized like any other
  upload.

## Remediating existing files

After activating the plugin (or deploying changes to its sanitizers), review existing
attachments with a dry run first. `--force` revisits attachments already marked sanitized.
Dry runs do not update stored metadata or files. Explicit IDs with the wrong MIME type are
reported and skipped.

Per format:

```
wp hm-media-pii-cleaner sanitize-pdfs --all --dry-run --report=/tmp/pdf-report.csv   # audit first
wp hm-media-pii-cleaner sanitize-pdfs --all                                          # then apply
wp ewwwio optimize media 0 --force --reset --noprompt                                # only if EWWW is active
wp hm-media-pii-cleaner sanitize-gifs --all --report=/tmp/gif-report.csv
wp hm-media-pii-cleaner sanitize-svgs --all --report=/tmp/svg-report.csv
wp hm-media-pii-cleaner sanitize-ooxml --all --report=/tmp/ooxml-report.csv
wp hm-media-pii-cleaner verify-images --all --report=/tmp/image-report.csv
wp hm-media-pii-cleaner status-report --output=/tmp/final-status.csv
```

Or in one pass, with a single summary and report. `sanitize-all` walks PDF, GIF, SVG, OOXML,
then JPEG/PNG/WebP last, so if EWWW is active it still needs to run first on its own:

```
wp ewwwio optimize media 0 --force --reset --noprompt
wp hm-media-pii-cleaner sanitize-all --dry-run --report=/tmp/sanitize-all-report.csv   # audit first
wp hm-media-pii-cleaner sanitize-all --report=/tmp/sanitize-all-report.csv             # then apply
wp hm-media-pii-cleaner status-report --output=/tmp/final-status.csv
```

**Video is deliberately not in either list**, and `sanitize-all` skips it unless
`--include-videos` is passed. `sanitize-videos --all` is safe to run (it never quarantines or
blocks a flagged file), but since fast-start files are flagged rather than fixed and that
verdict is not enforced, run it as a deliberate step:

```
wp hm-media-pii-cleaner sanitize-videos --all --dry-run --report=/tmp/video-report.csv   # audit first
wp hm-media-pii-cleaner sanitize-videos --all                                            # then apply
```

If a file comes back flagged, remediate it off-host (for example `exiftool -All=`, then
verify with `exiftool` again and confirm visual fidelity). Replace the file at its exact
existing path rather than uploading a new attachment, so the attachment ID and URL are
preserved, and purge any CDN cache for that path before re-verifying. Then record the
outcome:

```
wp hm-media-pii-cleaner set-status <id> sanitized --detail="manually remediated off-host"
```

## Verification approach

Production verification stays pure PHP: the PDF verifier's residual-token and page-count
checks, `find_residual_jpeg_metadata()`'s `exif_read_data()`/`iptcparse()` spot-check, and
`wp hm-media-pii-cleaner status-report`. `exiftool` is useful as a local development aid for
spot-checking changes to the sanitizers, but it is never part of the runtime path.

The PDF verifier's residual checks follow the active field rules. A property allowed through
the filter (for example `dc:creator` or `/Author`) is expected in the output and does not flag
the file. Tokens that cover a whole XMP prefix (`photoshop:`, `illustrator:`) are skipped once
any property with that prefix is allowed, so the XMP filter alone then keeps the rest of that
namespace out. `/Producer` is always checked.

The unit suite uses hand-crafted fixtures with known metadata for each format: allowlisted
values (Title, Copyright, Language) alongside disallowed ones (creator usernames,
`xmp:CreatorTool`, `Software`). Per format it confirms allowlisted values survive, disallowed
values are gone, dimensions/page counts are unchanged, and the output re-parses as a
structurally valid file. XMP in WebP is only legal in the Extended File Format, so WebP
fixtures carry a proper VP8X chunk.

## Security notes

The XMP filter parses metadata with `DOMDocument`, which is new attack surface compared to
treating metadata as opaque byte ranges. Covered by tests and review:

- **XXE (local file disclosure)**: a packet with `<!ENTITY xxe SYSTEM "file:///...">`
  referenced from an allowlisted element fails to parse; external entities are never resolved.
- **SSRF via external entity**: a packet with an `http://` external entity makes no network
  call, and the unresolved entity reference is dropped.
- **Billion-laughs entity expansion**: nested-entity packets fail to parse without a
  memory/time blowup.
- **Empty input**: `DOMDocument::loadXML('')` throws an uncaught `\ValueError` on PHP 8 rather
  than returning a parse failure. This is reachable with a JPEG whose XMP `APP1` segment is
  empty or a 0-byte SVG. Every `loadXML()` call site (`filter_xmp_packet()`,
  `strip_svg_metadata()`, `get_svg_dimensions()`) and the related `saveXML()`-returns-`false`
  path fail via `\RuntimeException` instead. Keep this in mind for any new `DOMDocument` code.
- **PDF `/CreationDate` passthrough**: the value is located structurally via the rendered
  file's own trailer/xref and the search is bounded to the `/Info` object. A global search
  for `/CreationDate (` would match the same literal text inside an attacker-controlled,
  uncompressed page content stream and patch visible content instead of the metadata.

This is not a full security audit. The binary chunk/segment parsers (PNG/WebP/JPEG/GIF) have
been exercised against a large corpus of real files, but have not been adversarially reviewed
for crafted-input attacks.
