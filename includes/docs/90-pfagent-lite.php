<?php
/**
 * @pfw-doc-page    wp-pfagent/pfagent-lite
 * @pfw-doc-title   PFAgent Lite
 * @pfw-doc-order   90
 *
 * **PFAgent Lite** is the free edition published on WordPress.org. It is the
 * same agent as the full plugin, limited to the parts that stand on their own
 * on any WordPress install.
 *
 * ## What PFAgent Lite is
 *
 * PFAgent Lite ships the whole **[transversal WordPress layer](wp-pfagent/wordpress/)**
 * — content, taxonomies, media, users, comments, settings, menus and site
 * discovery — plus the third-party adapters (WooCommerce, SEO, Forms,
 * LearnDash, MemberPress) that light up when their plugin is active. Same chat,
 * same bring-your-own-AI providers, same human confirmation gate on every
 * change, same "WordPress" tab. It needs no other plugin and no account.
 *
 * ## What it does NOT include
 *
 * PFAgent Lite leaves out the **Setyenv suite** half of the full plugin — the
 * capabilities that only make sense with WP-PFManagement (a low-code data
 * platform) and WP-PFWorkflow (a visual workflow engine) installed:
 *
 * - modelling your data and reading/writing PFM records through the agent;
 * - designing, editing, running and inspecting **workflows**;
 * - the `.pfflow` authoring surface and its file tools;
 * - the PFM / PFW bridges and any suite-only tool.
 *
 * Everything the Lite edition advertises is complete and fully functional on
 * its own — no suite required, no locked features.
 *
 * ## Lite is an extraction, not a fork
 *
 * There is **one codebase**. The full plugin is presence-aware: it carries the
 * complete set of tools and, when the suite is present, additionally exposes the
 * data-modelling and workflow half; when the suite is absent it degrades by
 * presence to the WordPress-only surface. PFAgent Lite is that WordPress-only
 * surface, produced by the release tooling **extracting** it from the same
 * source — stripping the suite files and renaming the edition for WordPress.org.
 * The full plugin's own runtime carries no "Lite" branch; the Lite ZIP is a
 * derived artifact of the same engine.
 *
 * If you already run — or later add — the Setyenv suite, install the full
 * plugin instead: the same conversation then also models your data and builds
 * automations, deciding per request whether to act directly on WordPress or to
 * build a workflow.
 */
