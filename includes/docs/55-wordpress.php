<?php
/**
 * @pfw-doc-page    wp-pfagent/wordpress
 * @pfw-doc-title   Managing WordPress directly
 * @pfw-doc-order   55
 *
 * Beyond the Setyenv suite, the agent works on **plain WordPress**. This
 * transversal layer needs no other plugin: on any WordPress install the agent
 * can read and manage your content, media, users, comments, settings and menus
 * in natural language. Every action that changes the site pauses at the
 * [side-effect confirmation gate](wp-pfagent/side-effect-gate/) for your
 * approval before it runs; read-only requests (listing, reading, searching)
 * run without a prompt.
 *
 * Each tool is capability-checked against the logged-in user: the agent can
 * never do more than you could yourself in wp-admin, and it re-checks the real
 * WordPress permission for every object it touches.
 *
 * ## Content — posts, pages and custom post types
 *
 * List, search, read, create (drafts by default, with the content sanitized),
 * edit and send to trash — for posts, pages, and any custom post type your
 * plugins register. The agent can also set a post's public custom fields;
 * protected internal fields (leading-underscore meta) stay off-limits.
 *
 * ## Categories, tags and taxonomies
 *
 * List taxonomies and their terms, create terms, and assign terms to content.
 *
 * ## Media
 *
 * Browse the media library, read an item's details, and import an image or
 * file from a URL. URL imports pass an SSRF guard that rejects private and
 * internal addresses (loopback, link-local, RFC-1918) — only public URLs are
 * fetched.
 *
 * ## Users
 *
 * List users and read profiles; create users and update profiles or roles.
 * Passwords are auto-generated and never revealed, and role changes are guarded
 * against privilege escalation (the agent cannot grant a capability the current
 * user does not hold).
 *
 * ## Comments
 *
 * List and moderate comments — approve, hold, mark as spam, trash, or reply.
 *
 * ## Site settings
 *
 * Read and adjust a safe allow-list of basic options: site title, tagline,
 * timezone, date and time format, posts per page, and front-page settings.
 * Other plugins' options and arbitrary settings are out of reach.
 *
 * ## Menus and widgets
 *
 * List navigation menus and widget areas, create a menu, and add items to it.
 *
 * ## Site overview and discovery
 *
 * A snapshot of the install — WordPress version, active theme, language,
 * content counts, and installed plugins — plus the content types and taxonomies
 * every plugin registers, which the agent can then read and manage with the
 * same content tools above.
 *
 * ## Working with popular plugins
 *
 * These integrations appear **only when the matching plugin is active** (the
 * agent detects each one and hides its tools when it is absent), and each talks
 * to that plugin's own public API. Reads run without a prompt; every write pauses
 * at the confirmation gate. The complete set of capabilities:
 *
 * **WooCommerce**
 *
 * - Read recent orders or products — a list, or a single one by id.
 * - Add a note to an order, private or customer-visible.
 * - Change an order's status (processing, completed, on-hold, …).
 * - Cancel an order.
 * - Create a pending order, optionally with a customer and line items.
 * - Add or remove a product line on an order.
 * - Apply a coupon code to an order.
 * - Set a product's stock quantity (and its derived stock status).
 * - Create or edit a simple product (name, price, SKU, description, status).
 * - Record a refund **request** on an order for a person to process — the agent
 *   **never issues a refund automatically** (our policy).
 *
 * **SEO** — Yoast SEO, Rank Math or SEOPress (auto-detected)
 *
 * - Read a post or page's SEO title, meta description and focus keyword.
 * - Set a post or page's SEO title, meta description and/or focus keyword.
 *
 * **Forms** — Contact Form 7, Fluent Forms, Gravity Forms, WPForms
 *
 * - List the forms across every installed form plugin, noting which ones store entries.
 * - List a form's stored entries (Fluent Forms and Gravity Forms; Contact Form 7 stores none).
 * - Manage a stored entry: mark read/unread, spam/unspam, trash, star/unstar, or add a note (Gravity Forms, Fluent Forms).
 *
 * Honest limits: Contact Form 7 keeps no submissions, and WPForms entries require WPForms Pro.
 *
 * **LearnDash**
 *
 * - Read courses, lessons, or a user's enrollments.
 * - Enroll or unenroll a user in a course.
 *
 * **MemberPress**
 *
 * - Read membership products, or a member's active memberships.
 * - Grant or revoke a member's access to a membership (recorded as a manual transaction).
 *
 * ## The "WordPress" tab
 *
 * The agent's screen has a **WordPress** tab: a live view of your own wp-admin
 * that follows the agent's actions. When it reads or changes something, the tab
 * jumps to the relevant native screen — the post it edited, the product whose
 * stock changed, the users list, the form's entries — so you see the result in
 * place, on your real admin, not a mock-up. Above the chat, an activity strip
 * lists each WordPress action of the turn as a chip you can click to point the
 * tab at that screen.
 */
