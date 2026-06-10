Hi, thanks for the review. I've addressed the flagged items. The corrected WordPress.org build is at this direct download link:

<PASTE DIRECT DOWNLOAD LINK TO fleet-agent-for-wpmgr.zip HERE>

A slug request and a few clarifications for the parts that are intentional:

**Permalink and duplicate submission:** I accidentally submitted this same plugin twice, so there are two pending entries with the same plugin name: "fleet-agent-for-wpmgr" and "fleet-agent-for-wpmgr-2" (this thread). I'd like to keep just **one** entry, approved under the permalink **fleet-agent-for-wpmgr**, and have the other discarded. The corrected build's text domain is `fleet-agent-for-wpmgr` to match that permalink. I understand the slug can be changed while the plugin is still in review, so please rename/assign this reviewed entry to **fleet-agent-for-wpmgr** and discard the duplicate — or just tell me which entry you want to keep and I'll follow your lead. Apologies for the accidental double submission.

**Direct mysqli in the backup/restore/search-replace paths is deliberate.** A backup must stream a full dump, and a restore replays it row by row. `$wpdb` buffers the entire result set into PHP memory and has no unbuffered/streaming API, so dumping or restoring a large table through `$wpdb` would exhaust memory and fatal the site. For those three operations we use a dedicated `mysqli` connection in unbuffered mode, which is the same approach the approved backup plugins UpdraftPlus and WPvivid use for their dump/restore engines. All values are parameterized or escaped and table names are validated against `information_schema`; there is no user-controlled SQL on these paths.

**file_get_contents / readfile:** all remote fetches use the WP HTTP API (`wp_remote_get`). The remaining `file_get_contents`/`readfile` calls are local-file reads only (the `advanced-cache.php` drop-in streams the already-generated cache file, and one local snapshot metadata file), never remote URLs.

**External services** are now documented in the readme under "== External services ==" (the control plane, the object-storage destination it configures, ipify, Cloudflare, Google Fonts, Gravatar, and the optional self-hosting of third-party assets your own pages reference), each with what is sent, the trigger, and terms/privacy links. The source for the bundled minified scripts is documented under "== Source code ==" (public repository, plus the readable source ships in the zip for the delay script).

The remaining items are fixed in the upload: request inputs including `$_SERVER` and `$_COOKIE` are sanitized, the quarantine and snapshot data now write under the uploads directory, the `/info` REST route now binds its signed token to the site and command, and the login-screen style is enqueued. The control-plane self-updater is excluded from the directory build (updates come from WordPress.org).

Thanks!
