package agentcmd

// media_clean_contract.go — CP->agent contract for the media_clean command (#190).
//
// The agent implements this in
// apps/agent/includes/commands/class-media-clean-command.php (supported by
// MediaReferenceIndex and MediaQuarantine under includes/media/).
// Field names are JSON wire names — do not rename without updating both sides.
//
// Transport: POST {site_url}/wp-json/wpmgr/v1/command/media_clean
//   Header:  Authorization: Bearer <minted EdDSA JWT, cmd="media_clean">
//   Body:    MediaCleanRequest (application/json)
//   Response: MediaCleanResult
//
// Actions:
//   scan     — read-only; returns one page of unused attachment candidates.
//              Paginates via offset/limit (agent is stateless per request).
//   isolate  — moves attachment files to the quarantine dir + writes a JSON
//              manifest; the attachment posts are left in the media library
//              but their files are gone from uploads. REVERSIBLE via restore.
//   restore  — reverses an isolate using the manifest (quarantine_ids).
//   delete   — PERMANENT removal of quarantined files + force-deletes the
//              attachment posts. Requires confirm="DELETE". Only operates on
//              manifest IDs that exist in the quarantine directory.

// MediaCleanRequest is the POST body for the `media_clean` command.
//
//	action          One of "scan", "isolate", "restore", "delete". Required.
//	job_id          CP-minted UUID v4; required for isolate/restore/delete.
//	                The agent echoes it in the ACK for idempotency correlation.
//	                Omitted (or empty) for scan.
//	attachment_ids  Attachment post IDs to isolate (isolate only).
//	                Not used for scan/restore/delete.
//	quarantine_ids  Opaque manifest IDs returned by a prior isolate ACK (restore
//	                and delete only). Not used for scan/isolate.
//	limit           Maximum candidates per scan page (1–500, default 100).
//	offset          Zero-based attachment offset for scan pagination.
//	confirm         Must equal "DELETE" for the delete action (destructive gate).
type MediaCleanRequest struct {
	Action         string   `json:"action"`
	JobID          string   `json:"job_id,omitempty"`
	AttachmentIDs  []int64  `json:"attachment_ids,omitempty"`
	QuarantineIDs  []string `json:"quarantine_ids,omitempty"`
	Limit          int      `json:"limit,omitempty"`
	Offset         int      `json:"offset,omitempty"`
	Confirm        string   `json:"confirm,omitempty"`
}

// MediaCleanCandidate is one unused attachment returned by action=scan.
//
//	id           WordPress attachment post ID.
//	title        Post title (or the basename of the file when the title is empty).
//	url          Public URL of the attachment (guid).
//	thumb        URL of the thumbnail-size variant; null when unavailable.
//	file_size    Size of the original file in bytes. 0 when missing from disk.
//	sizes_count  Number of generated intermediate sizes in attachment metadata.
type MediaCleanCandidate struct {
	ID         int64   `json:"id"`
	Title      string  `json:"title"`
	URL        string  `json:"url"`
	Thumb      *string `json:"thumb"`
	FileSize   int64   `json:"file_size"`
	SizesCount int     `json:"sizes_count"`
}

// MediaCleanUsage describes one in-use location for a referenced attachment.
//
// surface is one of:
//
//	post_content  — attachment URL appears in the body of a post/page.
//	post_excerpt  — attachment URL appears in a post excerpt.
//	revision      — attachment URL appears in a post revision body.
//	thumbnail     — attachment is set as the featured image (_thumbnail_id meta).
//	postmeta      — attachment ID or URL stored in a non-thumbnail post meta key.
//	gallery       — attachment appears in a [gallery] shortcode ids list.
//	option        — attachment ID or URL stored in a wp_options row.
//	widget        — attachment ID or URL stored in a widget setting.
//	menu          — attachment ID or URL stored in a nav menu item.
//	term_meta     — attachment ID or URL stored in term meta.
//	user_meta     — attachment ID or URL stored in user meta.
//	direct_id     — attachment referenced by its ID from another relation.
//	path          — attachment on-disk path referenced by a setting or meta.
//
// source_id, source_label, edit_url, and detail are optional context; they may
// be null when the surface does not correspond to a single addressable object.
type MediaCleanUsage struct {
	Surface     string  `json:"surface"`
	SourceID    *int64  `json:"source_id"`
	SourceLabel *string `json:"source_label"`
	EditURL     *string `json:"edit_url"`
	Detail      *string `json:"detail"`
}

// MediaCleanReferenced is an attachment that the scan classified as in-use,
// together with its usage locations.
type MediaCleanReferenced struct {
	ID     int64             `json:"id"`
	Title  string            `json:"title"`
	URL    string            `json:"url"`
	Thumb  *string           `json:"thumb"`
	Usages []MediaCleanUsage `json:"usages"`
}

// MediaCleanManifestEntry is one attachment record inside a quarantine manifest.
//
//	attachment_id  WordPress attachment post ID of the quarantined file set.
//	title          Post title (or file basename when the title is empty).
//	file_count     Number of physical files moved to quarantine for this attachment.
type MediaCleanManifestEntry struct {
	AttachmentID int64  `json:"attachment_id"`
	Title        string `json:"title"`
	FileCount    int    `json:"file_count"`
}

// MediaCleanManifest is one quarantine manifest returned by action=list.
//
//	manifest_id   Opaque stable ID written by the agent when isolate completes.
//	              Pass this back as a quarantine_id entry in restore/delete.
//	job_id        The CP-minted job UUID echoed by the agent in the isolate ACK.
//	isolated_at   Unix timestamp (seconds) when the manifest was created.
//	total_files   Total number of physical files across all entries.
//	entries       Per-attachment breakdown of what was quarantined.
type MediaCleanManifest struct {
	ManifestID  string                    `json:"manifest_id"`
	JobID       string                    `json:"job_id"`
	IsolatedAt  int64                     `json:"isolated_at"`
	TotalFiles  int                       `json:"total_files"`
	Entries     []MediaCleanManifestEntry `json:"entries"`
}

// MediaCleanResult is the agent's synchronous ACK for `media_clean`.
//
// ok=false with detail means the agent refused or the operation failed.
//
// scan:
//
//	total             Unused attachment count (capped to limit; for pagination).
//	candidates        Current page of unused attachment candidates.
//	has_more          true when there are more pages (offset+limit < total).
//	truncated         true when the library has more unused attachments than the
//	                  cap returned; distinct from pagination has_more.
//	total_attachments Total attachment rows visited by the agent during the scan.
//	referenced_count  Number of attachments classified as in-use.
//	unused_count      Number of attachments classified as unused (== total).
//	referenced        In-use attachments among those examined, with usage details.
//
// list:
//
//	manifests   All quarantine manifests currently present on the site.
//
// isolate:
//
//	job_id      Echoed from the request for correlation.
//	moved       Number of files successfully moved to quarantine.
//	manifest_id Opaque stable ID of the quarantine manifest. Pass this back as
//	            a quarantine_id entry in restore/delete requests.
//
// restore:
//
//	job_id      Echoed from the request.
//	restored    Number of manifest entries successfully restored.
//
// delete:
//
//	job_id            Echoed from the request.
//	deleted           Number of manifest entries permanently deleted.
//	posts_deleted     Number of attachment posts force-deleted.
//	posts_failed      Number of attachment posts that could not be deleted.
//	files_deleted     Total number of physical files removed from disk.
//	entries_processed Number of quarantine entries examined.
//	results           Per-attachment breakdown of the delete outcome.
//
// isolate (extended):
//
//	entries_recorded  Number of manifest entries written.
//	per_attachment    Per-attachment breakdown of files moved.
//
// all:
//
//	detail      Human-readable result or error description.
type MediaCleanResult struct {
	OK               bool                   `json:"ok"`
	Total            int                    `json:"total,omitempty"`
	Candidates       []MediaCleanCandidate  `json:"candidates,omitempty"`
	HasMore          bool                   `json:"has_more,omitempty"`
	Truncated        bool                   `json:"truncated,omitempty"`
	TotalAttachments int                    `json:"total_attachments,omitempty"`
	ReferencedCount  int                    `json:"referenced_count,omitempty"`
	UnusedCount      int                    `json:"unused_count,omitempty"`
	Referenced       []MediaCleanReferenced `json:"referenced,omitempty"`
	Manifests        []MediaCleanManifest   `json:"manifests,omitempty"`
	JobID            string                 `json:"job_id,omitempty"`
	Moved            int                    `json:"moved,omitempty"`
	ManifestID       string                 `json:"manifest_id,omitempty"`
	Restored         int                    `json:"restored,omitempty"`
	Deleted          int                    `json:"deleted,omitempty"`
	// delete extended fields
	PostsDeleted     int                         `json:"posts_deleted,omitempty"`
	PostsFailed      int                         `json:"posts_failed,omitempty"`
	FilesDeleted     int                         `json:"files_deleted,omitempty"`
	EntriesProcessed int                         `json:"entries_processed,omitempty"`
	Results          []MediaCleanDeleteResult    `json:"results,omitempty"`
	// isolate extended fields
	EntriesRecorded int                      `json:"entries_recorded,omitempty"`
	PerAttachment   []MediaCleanIsolatePer   `json:"per_attachment,omitempty"`
	Detail           string                 `json:"detail,omitempty"`
}

// MediaCleanDeleteResult is the per-attachment outcome of a media_clean delete.
//
//	attachment_id  WordPress attachment post ID.
//	post_deleted   Whether the attachment post was successfully force-deleted.
//	files_deleted  Number of physical files removed for this attachment.
type MediaCleanDeleteResult struct {
	AttachmentID int64 `json:"attachment_id"`
	PostDeleted  bool  `json:"post_deleted"`
	FilesDeleted int   `json:"files_deleted"`
}

// MediaCleanIsolatePer is the per-attachment outcome of a media_clean isolate.
//
//	attachment_id  WordPress attachment post ID.
//	moved          Number of physical files moved to quarantine for this attachment.
type MediaCleanIsolatePer struct {
	AttachmentID int64 `json:"attachment_id"`
	Moved        int   `json:"moved"`
}
