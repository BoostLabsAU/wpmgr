-- M43 — plugin_signatures v2 seed.
--
-- Extends the corpus from ~144 to ~280 entries by adding ~136 additional
-- high-install wordpress.org plugins across the categories most likely to
-- leave orphaned wp_options rows, WP-Cron events, and custom tables:
-- backup, SEO, cache, security, e-commerce / WooCommerce extensions, forms,
-- page builders, membership, email/newsletter, analytics, media, multilingual,
-- anti-spam, and redirects.
--
-- Patterns are RE2-compatible anchored regexps or plain string literals.
-- Exact literals (no regexp metacharacters) classify as ConfidenceExact.
-- Anchored prefix patterns (^prefix_) classify as ConfidencePrefix.
--
-- SECURITY INVARIANT — minimum prefix body length:
-- Every anchored prefix pattern (^X_) has a body of >= 4 characters before
-- the first wildcard / end-anchor.  Short roots (2-3 chars) are omitted or
-- replaced with longer co-prefixes already present.
--
-- corpus_version is bumped to 2 for all rows; ON CONFLICT DO UPDATE refreshes
-- existing rows that may carry stale v1 patterns.
--
-- WRITE-GUARD HANDLING: m40.1 REVOKEd INSERT/UPDATE/DELETE on plugin_signatures
-- from wpmgr_app (the runtime write guard). In deployments where wpmgr_app is
-- ALSO the migration role, that revocation blocks this seed (SQLSTATE 42501).
-- wpmgr_app still OWNS the table, so it may re-grant DML to itself: we restore
-- INSERT/UPDATE for the duration of this seed and re-REVOKE at the end, leaving
-- the guard exactly as m40.1 left it. (If the migration role is a distinct
-- owner, the GRANT/REVOKE on wpmgr_app is a harmless no-op for the writes.)
GRANT INSERT, UPDATE ON plugin_signatures TO wpmgr_app;

INSERT INTO plugin_signatures
    (slug, corpus_version, option_patterns, transient_patterns, table_patterns, cron_hook_patterns, updated_at)
VALUES

    -- -----------------------------------------------------------------------
    -- BACKUP / MIGRATION
    -- -----------------------------------------------------------------------

    ('blogvault-real-time-backup', 2,
        '["^blogvault_","blogvault_version","blogvault_api_key","blogvault_account_email","blogvault_site_id"]',
        '["^blogvault_"]',
        '[]',
        '["^blogvault_","blogvault_perform_cron"]',
        now()),

    ('xcloner-backup-and-restore', 2,
        '["^xcloner_","xcloner_version","xcloner_settings","xcloner_db_version"]',
        '["^xcloner_"]',
        '["^wp_xcloner"]',
        '["^xcloner_","xcloner_cleanup_old_archives"]',
        now()),

    ('wpvivid-backups', 2,
        '["^wpvivid_","wpvivid_version","wpvivid_settings","wpvivid_backup_schedule"]',
        '["^wpvivid_"]',
        '[]',
        '["^wpvivid_","wpvivid_cron_backup","wpvivid_cron_cleanup"]',
        now()),

    ('migrate-guru', 2,
        '["^mgmt_","mgmt_version","mgmt_api_key","mgmt_site_url","migrate_guru_version"]',
        '["^mgmt_"]',
        '[]',
        '["^mgmt_","mgmt_cron_push"]',
        now()),

    -- -----------------------------------------------------------------------
    -- SEO
    -- -----------------------------------------------------------------------

    ('squirrly-seo', 2,
        '["sq_options","sq_version","sq_db_version","squirrly_version"]',
        '[]',
        '[]',
        '["squirrly_hourly_cron","squirrly_daily_cron"]',
        now()),

    ('seopress', 2,
        '["^seopress_","seopress_version","seopress_db_version","seopress_settings","seopress_toggle"]',
        '["^seopress_"]',
        '[]',
        '["^seopress_","seopress_daily_cron"]',
        now()),

    ('slim-seo', 2,
        '["slim_seo_version","slim_seo_settings","slim_seo_schema"]',
        '[]',
        '[]',
        '[]',
        now()),

    ('wp-seopress', 2,
        '["^seopress_","seopress_titles","seopress_social","seopress_xml_sitemap"]',
        '["^seopress_"]',
        '[]',
        '["^seopress_"]',
        now()),

    ('broken-link-checker-lite', 2,
        '["blcx_version","blcx_db_version","blcx_options","blcx_settings"]',
        '[]',
        '["^wp_blcx_"]',
        '["blcx_check_links","blcx_cleanup_data"]',
        now()),

    -- -----------------------------------------------------------------------
    -- CACHE
    -- -----------------------------------------------------------------------

    ('swift-performance-lite', 2,
        '["^swift_performance_","swift_performance_version","swift_performance_settings","swift_performance_db_version"]',
        '["^swift_performance_"]',
        '[]',
        '["^swift_performance_","swift_performance_cron"]',
        now()),

    ('hummingbird-performance', 2,
        '["^wphb_","wphb_version","wphb_settings","wphb_caching_settings","wphb_minify_group"]',
        '["^wphb_"]',
        '[]',
        '["^wphb_","wphb_cron_run","wphb_admin_notices"]',
        now()),

    ('comet-cache', 2,
        '["^comet_cache_","comet_cache_version","comet_cache_options","quick_cache_version"]',
        '["^comet_cache_","^quick_cache_"]',
        '[]',
        '["^comet_cache_","comet_cache_auto_purge"]',
        now()),

    ('litespeed-cache', 2,
        '["^litespeed_","litespeed_version","litespeed_conf","litespeed_cache_conf","lscwp_db_version"]',
        '["^litespeed_"]',
        '[]',
        '["^litespeed_","litespeed_crawl_cron","litespeed_queue_cron"]',
        now()),

    ('sg-cachepress', 2,
        '["^siteground_optimizer_","sgc_version","sg_cachepress_version","siteground_optimizer_settings"]',
        '["^siteground_optimizer_"]',
        '[]',
        '["^siteground_optimizer_","siteground_optimizer_cron"]',
        now()),

    ('cache-enabler', 2,
        '["^cache_enabler_","cache_enabler_version","cache_enabler_settings"]',
        '["^cache_enabler_"]',
        '[]',
        '["^cache_enabler_","cache_enabler_check"]',
        now()),

    ('nitropack', 2,
        '["^nitropack_","nitropack_version","nitropack_cache_key","nitropack_config"]',
        '["^nitropack_"]',
        '[]',
        '["^nitropack_","nitropack_sync_cron","nitropack_purge_cron"]',
        now()),

    -- -----------------------------------------------------------------------
    -- SECURITY
    -- -----------------------------------------------------------------------

    ('wp-cerber', 2,
        '["^cerber_","wp_cerber_version","cerber_settings","cerber_acl","cerber_traffic"]',
        '["^cerber_"]',
        '["^wp_cerber"]',
        '["^cerber_","cerber_cron_integrity_check","cerber_cron_request_scan"]',
        now()),

    ('all-in-one-wp-security-and-firewall', 2,
        '["^aiowps_","^aio_wp_security_","aiowps_version","aiowps_settings","aio_wp_security_configs"]',
        '["^aiowps_","^aio_wp_security_"]',
        '["^wp_aiowps_"]',
        '["^aiowps_","aiowps_scan_and_blacklist_check","aiowps_email_alert_digest"]',
        now()),

    ('security-ninja', 2,
        '["^wf_sn_","^secnin_","security_ninja_version","secnin_settings","wf_sn_db_version"]',
        '["^wf_sn_","^secnin_"]',
        '["^wp_wf_sn"]',
        '["^secnin_","security_ninja_scheduled_scan"]',
        now()),

    ('anti-malware', 2,
        '["^gotmls_","gotmls_version","gotmls_login_offset","gotmls_settings"]',
        '["^gotmls_"]',
        '[]',
        '["^gotmls_","gotmls_db_cleanup","gotmls_scheduled_scan"]',
        now()),

    ('defender-security', 2,
        '["^wpdef_","^wd_disable_","defender_security_version","wpdef_settings","wpdef_db_version"]',
        '["^wpdef_","^wd_disable_"]',
        '["^wp_defender"]',
        '["^wpdef_","wpdef_hub_sync","wpdef_security_scan_cron"]',
        now()),

    ('malcare-security', 2,
        '["^malcare_","^mcare_","malcare_version","malcare_api_key","malcare_site_id","mcare_db_version"]',
        '["^malcare_","^mcare_"]',
        '[]',
        '["^malcare_","malcare_cron_push_data"]',
        now()),

    ('two-factor', 2,
        '["^two_factor_","two_factor_version","two_factor_backup_codes"]',
        '["^two_factor_"]',
        '[]',
        '[]',
        now()),

    ('wp-2fa', 2,
        '["^wp_2fa_","wp_2fa_version","wp_2fa_settings","wp_2fa_db_version"]',
        '["^wp_2fa_"]',
        '[]',
        '["^wp_2fa_","wp_2fa_clear_expired_graceful"]',
        now()),

    ('clef', 2,
        '["^clef_","clef_settings","clef_version"]',
        '["^clef_"]',
        '[]',
        '[]',
        now()),

    -- -----------------------------------------------------------------------
    -- WOOCOMMERCE EXTENSIONS
    -- -----------------------------------------------------------------------

    ('woocommerce-payments', 2,
        '["^wcpay_","^woocommerce_payments_","wcpay_version","woocommerce_woocommerce_payments_version","wcpay_db_version"]',
        '["^wcpay_","^woocommerce_payments_"]',
        '["^wp_wcpay"]',
        '["^wcpay_","wcpay_send_scheduled_action","wcpay_delete_expired_transient"]',
        now()),

    ('woo-paypal-gateway', 2,
        '["^woo_paypal_","woo_paypal_version","woo_paypal_settings"]',
        '["^woo_paypal_"]',
        '[]',
        '["^woo_paypal_"]',
        now()),

    ('woocommerce-coupon-campaigns', 2,
        '["^wc_coupon_campaigns_","woocommerce_coupon_campaigns_version"]',
        '["^wc_coupon_campaigns_"]',
        '[]',
        '[]',
        now()),

    ('woocommerce-extra-checkout-fields-for-brazil', 2,
        '["^wcbcf_","wcbcf_settings","wcbcf_version"]',
        '["^wcbcf_"]',
        '[]',
        '[]',
        now()),

    ('woocommerce-product-bundles', 2,
        '["^wc_pb_","woocommerce_product_bundles_version","wc_pb_version","wc_product_bundles_version"]',
        '["^wc_pb_"]',
        '[]',
        '["^wc_pb_","wc_pb_sync_bundled_stock"]',
        now()),

    ('woocommerce-mix-and-match-products', 2,
        '["^wc_mnm_","woocommerce_mix_and_match_products_version","wc_mnm_version"]',
        '["^wc_mnm_"]',
        '[]',
        '[]',
        now()),

    ('woocommerce-checkout-field-editor', 2,
        '["thwcfd_version","thwcfd_fields","thwcfd_settings"]',
        '[]',
        '[]',
        '[]',
        now()),

    ('woocommerce-bulk-discount', 2,
        '["^woobd_","woobd_version","woobd_settings"]',
        '["^woobd_"]',
        '[]',
        '[]',
        now()),

    ('woocommerce-wishlist-plugin', 2,
        '["^wlfmc_","^yith_wcwl_","yith_wcwl_version","wlfmc_version","wlfmc_settings"]',
        '["^wlfmc_","^yith_wcwl_"]',
        '["^wp_yith_wcwl","^wp_wlfmc"]',
        '["^wlfmc_","yith_wcwl_maybe_expire_wishlist"]',
        now()),

    ('yith-woocommerce-wishlist', 2,
        '["^yith_wcwl_","yith_wcwl_version","yith_wcwl_options","yith_wcwl_db_version"]',
        '["^yith_wcwl_"]',
        '["^wp_yith_wcwl"]',
        '["^yith_wcwl_","yith_wcwl_delete_expired_wishlists"]',
        now()),

    ('woo-variation-swatches', 2,
        '["wvs_version","wvs_settings","wvs_pro_version"]',
        '[]',
        '[]',
        '[]',
        now()),

    ('woo-smart-quick-view', 2,
        '["^woosqv_","woosqv_version","woosqv_settings"]',
        '["^woosqv_"]',
        '[]',
        '[]',
        now()),

    ('woocommerce-order-export', 2,
        '["woe_version","woe_settings","woe_db_version"]',
        '[]',
        '[]',
        '["woe_cron_schedule_export"]',
        now()),

    ('woocommerce-warranty', 2,
        '["^wc_warranty_","woocommerce_warranty_version"]',
        '["^wc_warranty_"]',
        '[]',
        '[]',
        now()),

    ('woocommerce-shipping-labels', 2,
        '["^wc_shipping_labels_","wc_shipping_labels_version","wc_shipping_labels_settings"]',
        '["^wc_shipping_labels_"]',
        '[]',
        '[]',
        now()),

    ('woocommerce-sequential-order-numbers', 2,
        '["woocommerce_seq_order_number","woocommerce_sequential_order_numbers_version"]',
        '[]',
        '[]',
        '[]',
        now()),

    ('cartflows', 2,
        '["^cartflows_","cartflows_version","cartflows_db_version","cartflows_settings","cartflows_license"]',
        '["^cartflows_"]',
        '["^wp_cartflows"]',
        '["^cartflows_","cartflows_cleanup_ghost_orders"]',
        now()),

    ('checkout-plugins-stripe-woo', 2,
        '["^cpsw_","cpsw_version","cpsw_settings","cpsw_publishable_key"]',
        '["^cpsw_"]',
        '[]',
        '["^cpsw_"]',
        now()),

    -- -----------------------------------------------------------------------
    -- FORMS
    -- -----------------------------------------------------------------------

    ('caldera-forms', 2,
        '["^caldera_forms_","caldera_forms_version","caldera_forms_db_version"]',
        '["^caldera_forms_"]',
        '["^wp_cf_"]',
        '["^caldera_forms_","caldera_forms_send_usage_data"]',
        now()),

    ('formidable', 2,
        '["frm_version","frm_db_version","frm_options","frm_license"]',
        '[]',
        '["^wp_frm_"]',
        '["frm_run_jobs","frm_cleanup_drafts"]',
        now()),

    ('happyforms', 2,
        '["^happyforms_","happyforms_version","happyforms_settings","happyforms_db_version"]',
        '["^happyforms_"]',
        '["^wp_happyforms"]',
        '["^happyforms_","happyforms_cleanup_entries"]',
        now()),

    ('weforms', 2,
        '["^weforms_","weforms_version","weforms_db_version","weforms_license_key"]',
        '["^weforms_"]',
        '["^wp_weforms"]',
        '["^weforms_","weforms_clear_logs"]',
        now()),

    ('everest-forms', 2,
        '["^everest_forms_","evf_version","evf_db_version","everest_forms_settings"]',
        '["^everest_forms_"]',
        '["^wp_evf_"]',
        '["^everest_forms_","evf_email_report"]',
        now()),

    ('piotnetforms', 2,
        '["pfb_version","piotnetforms_version","pfb_settings"]',
        '[]',
        '[]',
        '["pfb_cleanup_entries"]',
        now()),

    ('forminator', 2,
        '["^forminator_","forminator_version","forminator_db_version","forminator_license"]',
        '["^forminator_"]',
        '["^wp_frmt_"]',
        '["^forminator_","forminator_truncate_ip","forminator_delete_old_submissions"]',
        now()),

    ('fluent-forms', 2,
        '["^fluentform_","^fluent_form_","fluentform_version","fluentform_settings","fluentform_db_version"]',
        '["^fluentform_"]',
        '["^wp_fluentform"]',
        '["^fluentform_","fluentform_scheduled_cleanup","fluentform_report_email"]',
        now()),

    -- -----------------------------------------------------------------------
    -- PAGE BUILDERS
    -- -----------------------------------------------------------------------

    ('brizy', 2,
        '["^brizy_","brizy_version","brizy_db_version","brizy_settings","brizy_cloud_"]',
        '["^brizy_"]',
        '[]',
        '["^brizy_","brizy_db_cleanup"]',
        now()),

    ('bricks', 2,
        '["^bricks_","bricks_version","bricks_settings","bricks_db_version","bricks_license"]',
        '["^bricks_"]',
        '[]',
        '["^bricks_"]',
        now()),

    ('zion-builder', 2,
        '["^zionbuilder_","zionbuilder_version","zionbuilder_db_version"]',
        '["^zionbuilder_"]',
        '[]',
        '[]',
        now()),

    ('seedprod', 2,
        '["^seedprod_","seedprod_version","seedprod_settings","seedprod_db_version"]',
        '["^seedprod_"]',
        '[]',
        '["^seedprod_","seedprod_scheduled_cleanup"]',
        now()),

    ('thrive-visual-editor', 2,
        '["tve_version","tve_db_version","^tve_leads_","^tve_quiz_"]',
        '[]',
        '[]',
        '["tve_leads_cleanup"]',
        now()),

    ('otter-blocks', 2,
        '["^otter_","otter_blocks_version","otter_blocks_settings","otter_db_version"]',
        '["^otter_"]',
        '[]',
        '["^otter_","otter_feedback_notice"]',
        now()),

    ('blocksy-companion', 2,
        '["^blocksy_","blocksy_version","blocksy_settings"]',
        '["^blocksy_"]',
        '[]',
        '[]',
        now()),

    ('spectra', 2,
        '["^spectra_","spectra_version","spectra_settings","spectra_free_version"]',
        '["^spectra_"]',
        '[]',
        '["^spectra_"]',
        now()),

    -- -----------------------------------------------------------------------
    -- MEMBERSHIP
    -- -----------------------------------------------------------------------

    ('restrict-content-pro', 2,
        '["rcp_version","rcp_db_version","rcp_settings","rcp_license"]',
        '[]',
        '["^wp_rcp_"]',
        '["rcp_check_member_expiration","rcp_email_expiring_members"]',
        now()),

    ('wishlist-member', 2,
        '["^wishlistmember_","wishlistmember_version","wlm_db_version","wlm_settings"]',
        '["^wishlistmember_"]',
        '["^wp_wlm"]',
        '["^wishlistmember_","wlm_cron"]',
        now()),

    ('groups', 2,
        '["^groups_","groups_version","groups_db_version","groups_options"]',
        '["^groups_"]',
        '["^wp_groups"]',
        '["^groups_"]',
        now()),

    ('indeed-membership-pro', 2,
        '["^indeed_","indeed_version","ump_version","ump_db_version"]',
        '["^indeed_"]',
        '["^wp_ump_"]',
        '["ump_cron_expire"]',
        now()),

    -- -----------------------------------------------------------------------
    -- EMAIL / NEWSLETTER / CRM
    -- -----------------------------------------------------------------------

    ('sendinblue', 2,
        '["^sendinblue_","sib_version","sib_db_version","sendinblue_api_key","sendinblue_settings"]',
        '["^sendinblue_"]',
        '[]',
        '["^sendinblue_","sib_cron_workflow_runner"]',
        now()),

    ('brevo', 2,
        '["^brevo_","brevo_version","brevo_api_key","brevo_settings","brevo_db_version"]',
        '["^brevo_"]',
        '[]',
        '["^brevo_","brevo_cron_sync"]',
        now()),

    ('klaviyo', 2,
        '["^klaviyo_","klaviyo_version","klaviyo_settings","klaviyo_api_key"]',
        '["^klaviyo_"]',
        '[]',
        '["^klaviyo_","klaviyo_cron_sync"]',
        now()),

    ('hubspot', 2,
        '["^hubspot_","^leadin_","hubspot_version","leadin_version","leadin_api_key","hubspot_portal_id"]',
        '["^hubspot_","^leadin_"]',
        '[]',
        '["^hubspot_","leadin_analytics_cron"]',
        now()),

    ('activecampaign', 2,
        '["^activecampaign_","activecampaign_version","activecampaign_api_key","activecampaign_url"]',
        '["^activecampaign_"]',
        '[]',
        '["^activecampaign_"]',
        now()),

    ('constant-contact-forms', 2,
        '["^ctct_","ctct_version","ctct_plus_version","ctct_settings","ctct_api_key"]',
        '["^ctct_"]',
        '[]',
        '["^ctct_","ctct_cron_task"]',
        now()),

    ('fluentcrm', 2,
        '["^fluentcrm_","fluentcrm_version","fluentcrm_db_version","fluentcrm_settings"]',
        '["^fluentcrm_"]',
        '["^wp_fc_"]',
        '["^fluentcrm_","fluentcrm_scheduled_task","fluentcrm_run_sequences","fluentcrm_bulk_email_runner"]',
        now()),

    ('mailster', 2,
        '["^mailster_","mailster_version","mailster_db_version","mailster_settings","mailster_license"]',
        '["^mailster_"]',
        '["^wp_mailster"]',
        '["^mailster_","mailster_cron_check_lists","mailster_cron_queue"]',
        now()),

    ('emailoctopus', 2,
        '["^emailoctopus_","emailoctopus_version","emailoctopus_api_key"]',
        '["^emailoctopus_"]',
        '[]',
        '["^emailoctopus_"]',
        now()),

    ('aweber-web-form-widget', 2,
        '["^aweber_","aweber_version","aweber_settings","aweber_consumer_key"]',
        '["^aweber_"]',
        '[]',
        '["^aweber_"]',
        now()),

    ('drip', 2,
        '["^drip_","drip_version","drip_settings","drip_api_token","drip_account_id"]',
        '["^drip_"]',
        '[]',
        '["^drip_"]',
        now()),

    -- -----------------------------------------------------------------------
    -- ANALYTICS / TRACKING
    -- -----------------------------------------------------------------------

    ('independent-analytics', 2,
        '["^independent_analytics_","iawp_version","iawp_db_version","iawp_settings"]',
        '["^iawp_","^independent_analytics_"]',
        '["^wp_iawp"]',
        '["^iawp_","iawp_daily_maintenance","iawp_prune_salted_ids"]',
        now()),

    ('matomo', 2,
        '["^matomo_","^piwik_","matomo_version","matomo_db_version","matomo_settings","piwik_tracking_code"]',
        '["^matomo_","^piwik_"]',
        '["^wp_matomo","^wp_piwik"]',
        '["^matomo_","matomo_archive_cron","matomo_prune_old_data"]',
        now()),

    ('exactmetrics', 2,
        '["^exactmetrics_","exactmetrics_version","exactmetrics_settings","exactmetrics_license"]',
        '["^exactmetrics_"]',
        '[]',
        '["^exactmetrics_","exactmetrics_send_tracking_data"]',
        now()),

    ('pixel-cat', 2,
        '["^pixel_cat_","pixel_cat_version","pixel_cat_options","pxlct_version"]',
        '["^pixel_cat_","^pxlct_"]',
        '[]',
        '[]',
        now()),

    ('woocommerce-google-analytics', 2,
        '["^wc_google_analytics_","woocommerce_google_analytics_version","wgap_settings"]',
        '["^wc_google_analytics_"]',
        '[]',
        '[]',
        now()),

    -- -----------------------------------------------------------------------
    -- MEDIA
    -- -----------------------------------------------------------------------

    ('envato-elements', 2,
        '["^envato_elements_","envato_elements_version","envato_elements_token"]',
        '["^envato_elements_"]',
        '[]',
        '["^envato_elements_","envato_elements_sync"]',
        now()),

    ('fifu', 2,
        '["^fifu_","fifu_version","fifu_settings","fifu_db_version"]',
        '["^fifu_"]',
        '[]',
        '["^fifu_","fifu_cron_cleanup"]',
        now()),

    ('meow-gallery', 2,
        '["mwg_version","mwg_settings"]',
        '[]',
        '[]',
        '[]',
        now()),

    ('modula-best-grid-gallery', 2,
        '["^modula_","modula_version","modula_db_version","modula_settings"]',
        '["^modula_"]',
        '[]',
        '["^modula_","modula_cleanup"]',
        now()),

    ('robo-gallery', 2,
        '["^robo_gallery_","robo_gallery_version","robo_gallery_db_version"]',
        '["^robo_gallery_"]',
        '["^wp_robo_gallery"]',
        '[]',
        now()),

    ('photo-gallery', 2,
        '["bwg_version","bwg_db_version","bwg_settings","photo_gallery_version"]',
        '[]',
        '["^wp_bwg"]',
        '["bwg_cleanup"]',
        now()),

    ('video-gallery', 2,
        '["^videowhisper_","^vdgr_","videowhisper_version","vdgr_settings"]',
        '["^vdgr_","^videowhisper_"]',
        '[]',
        '[]',
        now()),

    ('wp-video-lightbox', 2,
        '["^wpvl_","wpvl_version","wpvl_settings"]',
        '["^wpvl_"]',
        '[]',
        '[]',
        now()),

    ('sirv', 2,
        '["^sirv_","sirv_version","sirv_settings","sirv_api_key"]',
        '["^sirv_"]',
        '[]',
        '["^sirv_","sirv_cron_sync"]',
        now()),

    ('optimus', 2,
        '["^optimus_","optimus_version","optimus_settings","optimus_apikey"]',
        '["^optimus_"]',
        '[]',
        '["^optimus_","optimus_cron_cleanup"]',
        now()),

    ('imageshop', 2,
        '["^imageshop_","imageshop_version","imageshop_settings"]',
        '["^imageshop_"]',
        '[]',
        '[]',
        now()),

    ('webp-express', 2,
        '["^webp_express_","webp_express_version","webp_express_settings"]',
        '["^webp_express_"]',
        '[]',
        '["^webp_express_"]',
        now()),

    -- -----------------------------------------------------------------------
    -- MULTILINGUAL
    -- -----------------------------------------------------------------------

    ('gtranslate', 2,
        '["^gtranslate_","gtranslate_version","gtranslate_settings","gt_widget_lang","gt_preferred_language"]',
        '["^gtranslate_","^gt_widget"]',
        '[]',
        '["^gtranslate_"]',
        now()),

    ('weglot', 2,
        '["^weglot_","weglot_version","weglot_settings","weglot_api_key","weglot_db_version"]',
        '["^weglot_"]',
        '[]',
        '["^weglot_","weglot_cron_cleanup"]',
        now()),

    ('transposh-translation-filter', 2,
        '["^transposh_","transposh_version","transposh_settings","transposh_db_version"]',
        '["^transposh_"]',
        '[]',
        '["^transposh_","transposh_cleanup"]',
        now()),

    ('loco-translate-pro', 2,
        '["^loco_","loco_translate_pro_version","loco_pro_options"]',
        '["^loco_"]',
        '[]',
        '[]',
        now()),

    -- -----------------------------------------------------------------------
    -- ANTI-SPAM
    -- -----------------------------------------------------------------------

    ('antispam-bee', 2,
        '["^antispam_bee_","antispam_bee_version","antispam_bee_settings","asb_version"]',
        '["^antispam_bee_"]',
        '[]',
        '["^antispam_bee_","antispam_bee_delete_old_spam"]',
        now()),

    ('wp-spamshield', 2,
        '["^wpsqt_","^spamshield_","wpsqt_version","spamshield_version","spamshield_settings"]',
        '["^wpsqt_","^spamshield_"]',
        '[]',
        '["^wpsqt_","spamshield_scheduled_cleanup"]',
        now()),

    ('zero-spam', 2,
        '["^zerospam_","zero_spam_version","zerospam_settings","zerospam_db_version"]',
        '["^zerospam_"]',
        '["^wp_zerospam"]',
        '["^zerospam_","zerospam_cleanup_logs"]',
        now()),

    ('cleantalk-spam-protect', 2,
        '["^cleantalk_","cleantalk_version","cleantalk_settings","cleantalk_api_key"]',
        '["^cleantalk_"]',
        '[]',
        '["^cleantalk_","cleantalk_cron_task"]',
        now()),

    -- -----------------------------------------------------------------------
    -- REDIRECTS / URL MANAGEMENT
    -- -----------------------------------------------------------------------

    ('safe-redirect-manager', 2,
        '["srm_version","srm_db_version","srm_max_redirects"]',
        '[]',
        '[]',
        '[]',
        now()),

    ('301-redirects', 2,
        '["^eps_redirects_","eps_redirects_version","301redirects_version"]',
        '["^eps_redirects_"]',
        '[]',
        '["^eps_redirects_","eps_redirect_check_hits"]',
        now()),

    ('rank-math-seo', 2,
        '["^rank_math_","rank_math_version","rank_math_db_version","rank_math_modules","rank_math_redirections_"]',
        '["^rank_math_"]',
        '["^wp_rank_math"]',
        '["^rank_math_","rank_math_redirection_cleanup","rank_math_sitemap_ping"]',
        now()),

    ('simple-301-redirects', 2,
        '["^sm_redirects_","sm301_version","301_redirects_version"]',
        '["^sm_redirects_"]',
        '[]',
        '[]',
        now()),

    -- -----------------------------------------------------------------------
    -- PERFORMANCE / MISC UTILITIES
    -- -----------------------------------------------------------------------

    ('query-monitor', 2,
        '["qm_version","qm_db_version","qm_settings"]',
        '[]',
        '[]',
        '[]',
        now()),

    ('debug-bar', 2,
        '["debug_bar_version","debug_bar_enabled"]',
        '[]',
        '[]',
        '[]',
        now()),

    ('redis-cache', 2,
        '["^redis_cache_","redis_cache_version","redis_cache_settings","redisobject_cache_version"]',
        '["^redis_cache_"]',
        '[]',
        '["^redis_cache_","redis_cache_health_check"]',
        now()),

    ('object-cache-pro', 2,
        '["ocp_version","object_cache_pro_version","object_cache_pro_license"]',
        '[]',
        '[]',
        '["ocp_health_check"]',
        now()),

    ('wp-crontrol', 2,
        '["crontrol_version","crontrol_settings","crontrol_disable"]',
        '[]',
        '[]',
        '[]',
        now()),

    ('health-check', 2,
        '["^health_check_","health_check_version","health_check_settings"]',
        '["^health_check_"]',
        '[]',
        '["^health_check_"]',
        now()),

    ('asset-cleanup', 2,
        '["^wpassetcleanup_","wpassetcleanup_version","wpassetcleanup_db_version","wpassetcleanup_settings"]',
        '["^wpassetcleanup_"]',
        '[]',
        '["^wpassetcleanup_","wpassetcleanup_cron_clear_cache"]',
        now()),

    ('perfmatters', 2,
        '["^perfmatters_","perfmatters_version","perfmatters_settings","perfmatters_license"]',
        '["^perfmatters_"]',
        '[]',
        '["^perfmatters_","perfmatters_cleanup"]',
        now()),

    ('flying-pages', 2,
        '["^flying_pages_","flying_pages_version","flying_pages_settings"]',
        '["^flying_pages_"]',
        '[]',
        '[]',
        now()),

    -- -----------------------------------------------------------------------
    -- SOCIAL / SHARING / RATINGS
    -- -----------------------------------------------------------------------

    ('sumome', 2,
        '["^sumome_","^sumo_","sumome_version","sumo_version","sumome_api_key"]',
        '["^sumome_","^sumo_"]',
        '[]',
        '["^sumome_","sumome_cron"]',
        now()),

    ('kk-star-ratings', 2,
        '["^kksr_","kksr_version","kksr_settings","kksr_db_version"]',
        '["^kksr_"]',
        '["^wp_kksr"]',
        '["^kksr_","kksr_cleanup"]',
        now()),

    ('wp-postratings', 2,
        '["^postratings_","wp_postratings_version","postratings_db_version","postratings_settings"]',
        '["^postratings_"]',
        '["^wp_ratings"]',
        '["^postratings_","postratings_garbage_collection"]',
        now()),

    ('easy-social-sharing', 2,
        '["ess_version","ess_settings","easy_social_sharing_version"]',
        '[]',
        '[]',
        '[]',
        now()),

    ('add-to-any', 2,
        '["^addtoany_","addtoany_version","addtoany_options"]',
        '["^addtoany_"]',
        '[]',
        '[]',
        now()),

    -- -----------------------------------------------------------------------
    -- WOOCOMMERCE REVIEWS / LOYALTY
    -- -----------------------------------------------------------------------

    ('judge-me-product-reviews-woocommerce', 2,
        '["^judgeme_","judgeme_version","judgeme_settings","judgeme_api_token"]',
        '["^judgeme_"]',
        '[]',
        '["^judgeme_","judgeme_sync_cron"]',
        now()),

    ('woocommerce-points-and-rewards', 2,
        '["^wc_points_rewards_","woocommerce_points_rewards_version","wc_points_rewards_points_label"]',
        '["^wc_points_rewards_"]',
        '[]',
        '["^wc_points_rewards_","wc_points_rewards_cron"]',
        now()),

    -- -----------------------------------------------------------------------
    -- LIVE CHAT / SUPPORT
    -- -----------------------------------------------------------------------

    ('tidio-live-chat', 2,
        '["^tidio_","tidio_version","tidio_api_key","tidio_track_user_email"]',
        '["^tidio_"]',
        '[]',
        '["^tidio_"]',
        now()),

    ('crisp', 2,
        '["^crisp_","crisp_version","crisp_website_id","crisp_settings"]',
        '["^crisp_"]',
        '[]',
        '[]',
        now()),

    ('tawk-to-live-chat', 2,
        '["^tawkto_","tawkto_version","tawkto_widget_id","tawkto_property_id"]',
        '["^tawkto_"]',
        '[]',
        '[]',
        now()),

    ('live-chat', 2,
        '["^livechat_","livechat_version","livechat_license","livechat_settings"]',
        '["^livechat_"]',
        '[]',
        '[]',
        now()),

    -- -----------------------------------------------------------------------
    -- WP-CLI / DEVELOPER UTILITIES
    -- -----------------------------------------------------------------------

    ('woocommerce-dev-helper', 2,
        '["^wc_dev_helper_","wc_dev_helper_version"]',
        '["^wc_dev_helper_"]',
        '[]',
        '[]',
        now()),

    ('wp-reset', 2,
        '["wpr_version","wp_reset_version","wpr_settings"]',
        '[]',
        '[]',
        '[]',
        now()),

    -- -----------------------------------------------------------------------
    -- COOKIE / GDPR COMPLIANCE
    -- -----------------------------------------------------------------------

    ('cookie-notice', 2,
        '["^cookie_notice_","cookie_notice_version","cookie_notice_options","cn_cookies_accepted"]',
        '["^cookie_notice_","^cn_cookies"]',
        '[]',
        '["^cookie_notice_","cookie_notice_check"]',
        now()),

    ('cookieyes', 2,
        '["^cookieyes_","cookieyes_version","cky_api_key","cky_db_version"]',
        '["^cookieyes_"]',
        '[]',
        '["^cookieyes_","cky_sync_cron"]',
        now()),

    ('gdpr-cookie-consent', 2,
        '["^gdpr_cookie_consent_","gdpr_cookie_consent_version","wt_cli_version","gdpr_cookie_settings"]',
        '["^gdpr_cookie_consent_","^wt_cli_"]',
        '[]',
        '["^gdpr_cookie_consent_","wt_cli_auto_clear_cookies"]',
        now()),

    ('complianz-gdpr', 2,
        '["^cmplz_","^complianz_","cmplz_version","complianz_version","complianz_settings","cmplz_db_version"]',
        '["^cmplz_","^complianz_"]',
        '["^wp_cmplz"]',
        '["^cmplz_","cmplz_daily_mailchimp_cleanup","cmplz_daily_stats_cleanup"]',
        now()),

    ('real-cookie-banner', 2,
        '["rcb_version","rcb_db_version","rcb_settings"]',
        '[]',
        '["^wp_rcb"]',
        '["rcb_cleanup_outdated"]',
        now()),

    -- -----------------------------------------------------------------------
    -- MAP / LOCATION
    -- -----------------------------------------------------------------------

    ('wp-google-maps', 2,
        '["^wpgmza_","wpgmza_version","wpgmza_db_version","wpgmza_settings","WPGMZA_VERSION"]',
        '["^wpgmza_"]',
        '["^wp_wpgmza"]',
        '["^wpgmza_","wpgmza_cleanup"]',
        now()),

    ('maps-marker-pro', 2,
        '["^leafletmapsmarker_","mmp_version","leafletmapsmarker_version","mmp_db_version"]',
        '["^leafletmapsmarker_"]',
        '["^wp_leafletmapsmarker","^wp_mmp"]',
        '["mmp_cron_cleanup"]',
        now()),

    -- -----------------------------------------------------------------------
    -- BOOKING
    -- -----------------------------------------------------------------------

    ('amelia', 2,
        '["^amelia_","amelia_version","amelia_db_version","amelia_settings","amelia_license"]',
        '["^amelia_"]',
        '["^wp_amelia"]',
        '["^amelia_","amelia_daily_cron","amelia_reminder_cron"]',
        now()),

    ('bookly-responsive-appointment-booking-tool', 2,
        '["^bookly_","bookly_version","bookly_db_version","bookly_settings","bookly_license"]',
        '["^bookly_"]',
        '["^wp_bookly"]',
        '["^bookly_","bookly_cron_send_notifications","bookly_cron_clean_cache"]',
        now()),

    ('simply-schedule-appointments', 2,
        '["ssa_version","ssa_db_version","ssa_settings"]',
        '[]',
        '["^wp_ssa"]',
        '["ssa_cleanup_cron"]',
        now()),

    -- -----------------------------------------------------------------------
    -- INVOICING / PAYMENTS
    -- -----------------------------------------------------------------------

    ('wpinvoices', 2,
        '["^wpinv_","wpinv_version","wpinv_db_version","wpinv_settings"]',
        '["^wpinv_"]',
        '["^wp_getpaid_"]',
        '["^wpinv_","wpinv_cleanup_scheduled_actions"]',
        now()),

    ('invoiceninja', 2,
        '["^ninja_forms_","^invoiceninja_","invoiceninja_version","invoiceninja_token"]',
        '["^invoiceninja_"]',
        '[]',
        '["^invoiceninja_"]',
        now()),

    -- -----------------------------------------------------------------------
    -- TABLEPRESS EXTENSIONS / DATA TABLES
    -- -----------------------------------------------------------------------

    ('datatables-manager', 2,
        '["^dtman_","dtman_version","dtman_settings"]',
        '["^dtman_"]',
        '[]',
        '[]',
        now()),

    -- -----------------------------------------------------------------------
    -- MISCELLANEOUS HIGH-INSTALL PLUGINS
    -- -----------------------------------------------------------------------

    ('wp-statistics', 2,
        '["^wp_statistics_","wpstatistics_version","wp_statistics_db_version","wp_statistics_visitor"]',
        '["^wp_statistics_"]',
        '["^wp_statistics"]',
        '["^wp_statistics_","wp_statistics_cleanup","wp_statistics_schedule_send_report"]',
        now()),

    ('surecart', 2,
        '["^surecart_","surecart_version","surecart_db_version","surecart_api_key","surecart_settings"]',
        '["^surecart_"]',
        '["^wp_surecart"]',
        '["^surecart_","surecart_sync_cron"]',
        now()),

    ('wcb2b', 2,
        '["^wcb2b_","wcb2b_version","wcb2b_settings","wcb2b_db_version"]',
        '["^wcb2b_"]',
        '[]',
        '["^wcb2b_"]',
        now()),

    ('happy-addons-for-elementor', 2,
        '["^happy_addons_","happy_addons_version","hap_version","happy_addons_settings"]',
        '["^happy_addons_"]',
        '[]',
        '["^happy_addons_"]',
        now()),

    ('premium-addons-for-elementor', 2,
        '["^premium_addons_","premium_addons_version","premium_addons_settings","premium_addons_license"]',
        '["^premium_addons_"]',
        '[]',
        '["^premium_addons_"]',
        now()),

    ('elementor-pro', 2,
        '["^elementor_pro_","elementor_pro_version","elementor_pro_license","elementor_pro_license_data"]',
        '["^elementor_pro_"]',
        '[]',
        '["^elementor_pro_","elementor_pro_update_kit"]',
        now()),

    ('wc-product-table', 2,
        '["^wc_product_table_","wc_product_table_version","wc_product_table_settings"]',
        '["^wc_product_table_"]',
        '[]',
        '["^wc_product_table_"]',
        now()),

    ('affiliate-wp', 2,
        '["^affwp_","affiliate_wp_version","affwp_db_version","affwp_settings","affwp_license"]',
        '["^affwp_"]',
        '["^wp_affiliate"]',
        '["^affwp_","affwp_cleanup_payouts","affwp_schedule_send_report"]',
        now()),

    ('presto-player', 2,
        '["^presto_player_","presto_player_version","presto_player_settings"]',
        '["^presto_player_"]',
        '["^wp_presto_player"]',
        '["^presto_player_"]',
        now()),

    ('restrict-content', 2,
        '["^rcno_","rcno_version","rcno_db_version","rcno_settings"]',
        '["^rcno_"]',
        '["^wp_rcno"]',
        '["^rcno_","rcno_scheduled_task"]',
        now()),

    ('better-click-to-tweet', 2,
        '["^bctt_","bctt_version","bctt_settings"]',
        '["^bctt_"]',
        '[]',
        '[]',
        now()),

    ('loginwp', 2,
        '["^loginwp_","loginwp_version","loginwp_settings","loginwp_db_version","peter_loginwp_"]',
        '["^loginwp_","^peter_loginwp_"]',
        '[]',
        '["^loginwp_"]',
        now()),

    ('woocommerce-germanized', 2,
        '["^woocommerce_gzd_","woocommerce_gzd_version","woocommerce_gzd_db_version"]',
        '["^woocommerce_gzd_"]',
        '["^wp_woocommerce_gzd"]',
        '["^woocommerce_gzd_","woocommerce_gzd_daily_maintenance"]',
        now()),

    ('user-registration', 2,
        '["^user_registration_","ur_version","ur_db_version","user_registration_version"]',
        '["^user_registration_"]',
        '["^wp_ur_"]',
        '["^user_registration_","ur_cleanup_sessions","user_registration_send_usage"]',
        now())

ON CONFLICT (slug) DO UPDATE SET
    corpus_version     = EXCLUDED.corpus_version,
    option_patterns    = EXCLUDED.option_patterns,
    transient_patterns = EXCLUDED.transient_patterns,
    table_patterns     = EXCLUDED.table_patterns,
    cron_hook_patterns = EXCLUDED.cron_hook_patterns,
    updated_at         = now();

-- Bump all v1 rows (seeded by m40.1) to corpus_version=2 so the
-- OrphansReport.CorpusVersion field consistently returns 2 after this
-- migration runs. Pattern content for those rows is unchanged; only the
-- version integer is updated.
UPDATE plugin_signatures
   SET corpus_version = 2,
       updated_at     = now()
 WHERE corpus_version = 1;

-- Restore the runtime write guard (mirrors m40.1): wpmgr_app keeps SELECT only.
REVOKE INSERT, UPDATE, DELETE ON plugin_signatures FROM wpmgr_app;
