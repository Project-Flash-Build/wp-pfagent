// WordPress-tab reflection helpers.
//
// The "WordPress" tab reflects the agent's DIRECT actions on WordPress core and
// popular third-party plugins. This module is self-contained and owns two
// things:
//   1. mapping a WpTarget → a native wp-admin URL, and
//   2. turning a runtime execution of a wp_*, wc_*, seo_*, forms_*, ld_, mp_
//      tool into a human "activity" item (label + target) for the turn strip.
//
// Backend contract: the runtime emits `focus.wpTarget` on each transversal tool
// (live, via progress polling) and those executions land in the end-of-turn
// `executions[]` trail. Shapes match DISENO_PFA_WP_TRANSVERSAL_WORDPRESS_ORG.md
// §3 + CONTRATO_wptarget_screens_pfa.md.
import { __, sprintf } from '@wordpress/i18n';

import type { AgentRuntimeExecution } from './types';

/** Native wp-admin screen family the agent's WordPress action maps to. */
export interface WpTarget {
  screen:
    | 'edit'        // edit.php (list) or post.php (single, when id set) — any post type / CPT
    | 'terms'       // edit-tags.php?taxonomy=<taxonomy>&post_type=<postType> — taxonomy TERMS
    | 'upload'      // upload.php (library) or the attachment editor (id)
    | 'users'       // users.php (list) or user-edit.php (id)
    | 'comments'    // edit-comments.php
    | 'menus'       // nav-menus.php
    | 'widgets'     // widgets.php
    | 'options'     // options-general.php
    | 'plugins'     // plugins.php
    | 'site'        // index.php (dashboard / site overview)
    | 'admin_page'; // admin.php?page=<page> (+ query pairs + id) — escape hatch for
                    // plugin dashboards that are NOT a CPT list (forms entries,
                    // LMS reports, MemberPress subscriptions/transactions…)
  /** Post type for `edit` screens — any CPT: post, page, product, shop_coupon,
   *  sfwd-courses, memberpressproduct, … (defaults to 'post'). For `terms` it
   *  is the taxonomy's owning CPT so wp-admin scopes the edit-tags screen. */
  postType?: string;
  /** Object id for a single-resource screen (post / user / attachment). */
  id?: number;
  /** For `admin_page`: the wp-admin page slug (admin.php?page=<page>). */
  page?: string;
  /** For `terms`: the taxonomy slug (edit-tags.php?taxonomy=<taxonomy>). */
  taxonomy?: string;
  /** For `admin_page`: extra query params appended after the page slug — a
   *  plugin deep-link (e.g. Fluent Forms entries { route:'entries', form_id }). */
  query?: Record<string, string>;
}

// Namespaces of the transversal WordPress layer (WP core + third-party
// adapters). MUST stay in sync with the backend tool prefixes — if a plugin
// family is added under a new prefix, add it here or its actions won't reflect.
const WP_TOOL_PREFIXES = ['wp_', 'wc_', 'seo_', 'forms_', 'ld_', 'mp_'];

// Read-only tools the backend maps to NO screen (focus.wpTarget = null): they
// have no natural wp-admin destination, so they produce no chip/jump.
const NO_REFLECTION_TOOLS = new Set(['wp_widget_list', 'wp_posttypes_list']);

// Owning post type of the common core + WooCommerce taxonomies, so the `terms`
// chip can scope edit-tags.php exactly like the backend's get_taxonomy()-derived
// focus.wpTarget. Not exhaustive by design — an unlisted custom taxonomy simply
// omits post_type (edit-tags.php still resolves the correct terms screen).
const TAX_OWNER: Record<string, string> = {
  category: 'post',
  post_tag: 'post',
  product_cat: 'product',
  product_tag: 'product',
  product_brand: 'product',
};

/** Whether a tool name belongs to the transversal WordPress layer. */
export function isWpTool(name: string): boolean {
  return WP_TOOL_PREFIXES.some((p) => name.startsWith(p));
}

/** Map a WpTarget to a native wp-admin URL relative to the wp-admin base. A
 *  `pfa_rev` cache-buster forces the iframe to re-mount when the agent touches
 *  the same screen twice (wp-admin ignores unknown query params). */
export function wpAdminUrlForTarget(adminBaseUrl: string, target: WpTarget, rev?: number): string {
  const base = adminBaseUrl.endsWith('/') ? adminBaseUrl : `${adminBaseUrl}/`;
  const q = new URLSearchParams();
  let path: string;
  switch (target.screen) {
    case 'edit':
      if (target.id) {
        path = 'post.php';
        q.set('post', String(target.id));
        q.set('action', 'edit');
      } else {
        path = 'edit.php';
        if (target.postType && target.postType !== 'post') q.set('post_type', target.postType);
      }
      break;
    case 'terms':
      // Taxonomy TERMS live on edit-tags.php, NOT the posts list. `taxonomy` is
      // required; `post_type` (the taxonomy's owning CPT) scopes the screen so
      // wp-admin highlights the right menu and returns the correct terms.
      path = 'edit-tags.php';
      if (target.taxonomy) q.set('taxonomy', target.taxonomy);
      if (target.postType && target.postType !== 'post') q.set('post_type', target.postType);
      break;
    case 'upload':
      if (target.id) {
        path = 'post.php';
        q.set('post', String(target.id));
        q.set('action', 'edit');
      } else {
        path = 'upload.php';
      }
      break;
    case 'users':
      if (target.id) {
        path = 'user-edit.php';
        q.set('user_id', String(target.id));
      } else {
        path = 'users.php';
      }
      break;
    case 'comments':
      path = 'edit-comments.php';
      break;
    case 'menus':
      path = 'nav-menus.php';
      break;
    case 'widgets':
      path = 'widgets.php';
      break;
    case 'options':
      path = 'options-general.php';
      break;
    case 'plugins':
      path = 'plugins.php';
      break;
    case 'admin_page':
      // Plugin dashboard: admin.php?page=<page> + each query pair (a plugin
      // deep-link like Fluent Forms entries route=entries&form_id=<id>) + id.
      path = 'admin.php';
      if (target.page) q.set('page', target.page);
      if (target.query) {
        for (const [k, v] of Object.entries(target.query)) {
          if (k !== '' && v !== undefined && v !== null) q.set(k, String(v));
        }
      }
      if (target.id) q.set('id', String(target.id));
      break;
    case 'site':
    default:
      path = 'index.php';
      break;
  }
  if (rev) q.set('pfa_rev', String(rev));
  const qs = q.toString();
  return base + path + (qs ? `?${qs}` : '');
}

/** A single reflected WordPress action for the turn activity strip. */
export interface WpActivityItem {
  /** Product-language description - NEVER the raw tool name. */
  label: string;
  /** Native wp-admin screen this action maps to (drives the iframe link). */
  target: WpTarget;
}

function rec(v: unknown): Record<string, unknown> {
  return v && typeof v === 'object' ? (v as Record<string, unknown>) : {};
}
function str(v: unknown): string {
  return typeof v === 'string' ? v : '';
}
function num(v: unknown): number | undefined {
  if (typeof v === 'number' && Number.isFinite(v)) return v;
  if (typeof v === 'string' && v.trim() !== '' && Number.isFinite(Number(v))) return Number(v);
  return undefined;
}
/** First non-empty of a set of candidate keys across result then args. */
function pick(result: Record<string, unknown>, args: Record<string, unknown>, keys: string[]): unknown {
  for (const k of keys) {
    if (result[k] !== undefined && result[k] !== null && result[k] !== '') return result[k];
  }
  for (const k of keys) {
    if (args[k] !== undefined && args[k] !== null && args[k] !== '') return args[k];
  }
  return undefined;
}
/** A quoted title fragment (" 'Precios'") or empty when unknown. */
function titleFrag(title: string): string {
  return title ? ` "${title}"` : '';
}
/** A "(#123)" id fragment or empty. */
function idFrag(id?: number): string {
  return id ? ` (#${id})` : '';
}
/** A past-tense verb for a tool name's action segment, for generic labels of
 *  tools that don't have an explicit case (keeps new/unmapped tools readable). */
function verbOf(name: string): string {
  const map: Record<string, string> = {
    create: __('Created', 'wp-pfagent'), update: __('Updated', 'wp-pfagent'),
    set: __('Updated', 'wp-pfagent'), delete: __('Deleted', 'wp-pfagent'),
    trash: __('Trashed', 'wp-pfagent'), read: __('Read', 'wp-pfagent'),
    get: __('Viewed', 'wp-pfagent'), list: __('Listed', 'wp-pfagent'),
    apply: __('Applied', 'wp-pfagent'), refund: __('Refunded', 'wp-pfagent'),
    cancel: __('Cancelled', 'wp-pfagent'), add: __('Added', 'wp-pfagent'),
    enroll: __('Enrolled', 'wp-pfagent'), grant: __('Granted', 'wp-pfagent'),
    revoke: __('Revoked', 'wp-pfagent'), moderate: __('Moderated', 'wp-pfagent'),
    adjust: __('Adjusted', 'wp-pfagent'), assign: __('Assigned', 'wp-pfagent'),
  };
  for (const seg of name.split('_')) {
    if (map[seg]) return map[seg];
  }
  return __('Ran', 'wp-pfagent');
}

/**
 * Turn a wp_*, wc_*, seo_*, forms_* execution into a human activity item (label +
 * wp-admin target). Best-effort + defensive: an unknown result shape degrades
 * to a list-screen target with a generic label rather than throwing. Returns
 * null for tools with nothing visible to anchor.
 */
export function describeWpExecution(exec: AgentRuntimeExecution): WpActivityItem | null {
  const name = exec.tool?.name ?? '';
  if (!isWpTool(name) || NO_REFLECTION_TOOLS.has(name)) return null;
  const args = rec(exec.tool?.arguments);
  const result = rec(exec.result);
  const failed = exec.status === 'error';

  const postType = str(pick(result, args, ['post_type', 'postType'])) || 'post';
  const title = str(pick(result, args, ['title', 'post_title', 'name', 'display_name', 'slug']));
  const postId = num(pick(result, args, ['post', 'post_id', 'postId', 'id', 'ID']));
  // WooCommerce / plugin references expose the object id under their own key.
  const refId = num(pick(result, args, ['order_id', 'orderId', 'product_id', 'productId', 'post', 'id', 'ID']));
  const userId = num(pick(result, args, ['user_id', 'userId', 'ID', 'id']));
  const optionKey = str(pick(result, args, ['option', 'key', 'name']));
  // Taxonomy term screens: the taxonomy slug + its owning CPT so the chip lands
  // on the SAME edit-tags.php the backend focus.wpTarget does. The backend derives
  // the owner via get_taxonomy(); the browser can't, so read it from the result
  // when present, else fall back to the core/WooCommerce ownership map. Unknown
  // custom taxonomies still land on the right terms screen (just unscoped).
  const taxonomy = str(pick(result, args, ['taxonomy']));
  const taxPostType = str(pick(result, args, ['object_type', 'post_type'])) || TAX_OWNER[taxonomy] || undefined;

  // Prefix that makes a failed action legible without hiding it.
  const failPrefix = failed ? __('Failed: ', 'wp-pfagent') : '';
  const wrap = (label: string, target: WpTarget): WpActivityItem => ({ label: failPrefix + label, target });

  switch (name) {
    // ---- posts / pages / CPT ----
    case 'wp_post_create':
      return wrap(sprintf(__('Created %1$s%2$s%3$s', 'wp-pfagent'), postType, titleFrag(title), idFrag(postId)), { screen: 'edit', postType, id: postId });
    case 'wp_post_update':
      return wrap(sprintf(__('Updated %1$s%2$s%3$s', 'wp-pfagent'), postType, titleFrag(title), idFrag(postId)), { screen: 'edit', postType, id: postId });
    case 'wp_post_trash':
      return wrap(sprintf(__('Trashed %1$s%2$s%3$s', 'wp-pfagent'), postType, titleFrag(title), idFrag(postId)), { screen: 'edit', postType });
    case 'wp_post_list':
    case 'wp_post_get':
      return wrap(sprintf(__('Viewed %s', 'wp-pfagent'), postType), { screen: 'edit', postType, id: name === 'wp_post_get' ? postId : undefined });
    case 'wp_post_meta_set':
      return wrap(sprintf(__('Updated %1$s meta%2$s', 'wp-pfagent'), postType, idFrag(postId)), { screen: 'edit', postType, id: postId });

    // ---- taxonomies / terms ----
    // Terms live on edit-tags.php, NOT the posts list. Mirrors the backend
    // focus.wpTarget { screen:'terms', taxonomy, postType(owning cpt) }.
    // wp_term_assign stays 'edit' — it targets a POST, not the terms screen.
    case 'wp_taxonomy_list':
      return wrap(__('Viewed taxonomies', 'wp-pfagent'), { screen: 'terms', taxonomy, postType: taxPostType });
    case 'wp_term_create':
      return wrap(sprintf(__('Created term%s', 'wp-pfagent'), titleFrag(title)), { screen: 'terms', taxonomy, postType: taxPostType });
    case 'wp_term_assign':
      return wrap(__('Assigned terms', 'wp-pfagent'), { screen: 'edit', postType, id: postId });

    // ---- media ----
    case 'wp_media_list':
      return wrap(__('Viewed media library', 'wp-pfagent'), { screen: 'upload' });
    case 'wp_media_get':
      return wrap(sprintf(__('Viewed media item%s', 'wp-pfagent'), idFrag(postId)), { screen: 'upload', id: postId });
    case 'wp_media_sideload':
      return wrap(sprintf(__('Imported media%s', 'wp-pfagent'), idFrag(postId)), { screen: 'upload', id: postId });

    // ---- users ----
    case 'wp_user_create':
      return wrap(sprintf(__('Created user%1$s%2$s', 'wp-pfagent'), titleFrag(title), idFrag(userId)), { screen: 'users', id: userId });
    case 'wp_user_update':
      return wrap(sprintf(__('Updated user%1$s%2$s', 'wp-pfagent'), titleFrag(title), idFrag(userId)), { screen: 'users', id: userId });
    case 'wp_user_list':
    case 'wp_user_get':
      return wrap(__('Viewed users', 'wp-pfagent'), { screen: 'users', id: name === 'wp_user_get' ? userId : undefined });

    // ---- comments ----
    case 'wp_comment_list':
      return wrap(__('Viewed comments', 'wp-pfagent'), { screen: 'comments' });
    case 'wp_comment_moderate':
      return wrap(__('Moderated a comment', 'wp-pfagent'), { screen: 'comments' });

    // ---- menus ----
    case 'wp_menu_list':
      return wrap(__('Viewed navigation menus', 'wp-pfagent'), { screen: 'menus' });
    case 'wp_menu_manage':
      return wrap(sprintf(__('Updated menu%s', 'wp-pfagent'), titleFrag(title)), { screen: 'menus' });

    // ---- options / site ----
    case 'wp_option_get':
    case 'wp_option_set':
      return wrap(
        name === 'wp_option_set'
          ? sprintf(__('Updated setting%s', 'wp-pfagent'), optionKey ? ` "${optionKey}"` : '')
          : sprintf(__('Read setting%s', 'wp-pfagent'), optionKey ? ` "${optionKey}"` : ''),
        { screen: 'options' },
      );
    case 'wp_site_info':
      return wrap(__('Site overview', 'wp-pfagent'), { screen: 'site' });
    case 'wp_plugins_list':
      return wrap(__('Listed plugins', 'wp-pfagent'), { screen: 'plugins' });

    // ---- WooCommerce (adapter) — real backend tool names ----
    // Orders stay at the LIST screen (edit.php?post_type=shop_order): a post.php
    // deep-link breaks under WooCommerce HPOS; the label carries the #order.
    // Products are a classic CPT and deep-link cleanly.
    case 'wc_read':
      return wrap(__('Read WooCommerce data', 'wp-pfagent'), { screen: 'edit', postType: 'product' });
    case 'wc_order_note':
      return wrap(sprintf(__('Added an order note%s', 'wp-pfagent'), idFrag(refId)), { screen: 'edit', postType: 'shop_order' });
    case 'wc_order_create':
      return wrap(sprintf(__('Created an order%s', 'wp-pfagent'), idFrag(refId)), { screen: 'edit', postType: 'shop_order' });
    case 'wc_order_update':
      return wrap(sprintf(__('Updated order%s', 'wp-pfagent'), idFrag(refId)), { screen: 'edit', postType: 'shop_order' });
    case 'wc_order_cancel':
      return wrap(sprintf(__('Cancelled order%s', 'wp-pfagent'), idFrag(refId)), { screen: 'edit', postType: 'shop_order' });
    case 'wc_order_line':
      return wrap(sprintf(__('Updated order items%s', 'wp-pfagent'), idFrag(refId)), { screen: 'edit', postType: 'shop_order' });
    case 'wc_apply_coupon':
      return wrap(sprintf(__('Applied a coupon to order%s', 'wp-pfagent'), idFrag(refId)), { screen: 'edit', postType: 'shop_order' });
    case 'wc_refund_request':
      return wrap(sprintf(__('Requested a refund%s', 'wp-pfagent'), idFrag(refId)), { screen: 'edit', postType: 'shop_order' });
    case 'wc_stock_set':
      return wrap(sprintf(__('Updated stock%s', 'wp-pfagent'), idFrag(refId)), { screen: 'edit', postType: 'product', id: refId });
    case 'wc_product_upsert':
      return wrap(sprintf(__('Saved product%1$s%2$s', 'wp-pfagent'), titleFrag(title), idFrag(refId)), { screen: 'edit', postType: 'product', id: refId });

    // ---- SEO plugins (Yoast / Rank Math / SEOPress) ----
    // seo_set covers title, meta description, focus keyword, robots and OG via
    // its arguments; all land on the post editor.
    case 'seo_get':
      return wrap(sprintf(__('Read SEO fields%s', 'wp-pfagent'), titleFrag(title)), { screen: 'edit', postType, id: postId });
    case 'seo_set':
      return wrap(sprintf(__('Optimized SEO%1$s%2$s', 'wp-pfagent'), titleFrag(title), idFrag(postId)), { screen: 'edit', postType, id: postId });

    // ---- forms plugins (Fluent / GF / WPForms / CF7) ----
    // Land on the active engine's OWN screen (entries view for a specific form,
    // else its forms page), never the generic plugins list. Mirrors the backend
    // forms_focus() per-engine map exactly so auto-jump and chip align.
    case 'forms_list':
      return wrap(__('Listed forms', 'wp-pfagent'), formsTarget(result, args));
    case 'forms_entries':
      return wrap(__('Viewed form entries', 'wp-pfagent'), formsTarget(result, args));
    case 'forms_entry_manage':
      return wrap(__('Updated a form entry', 'wp-pfagent'), formsTarget(result, args));

    // ---- LearnDash (ld_) / MemberPress (mp_) — CPT-backed; land on the plugin's
    // CPT list/editor. NOT installed on :8095 → verified by unit only. The
    // runtime's focus.wpTarget drives the live jump when they are present.
    case 'ld_read':
      return wrap(__('Read LearnDash courses', 'wp-pfagent'), { screen: 'edit', postType: 'sfwd-courses' });
    case 'ld_enroll':
      return wrap(__('Enrolled a user in a course', 'wp-pfagent'), { screen: 'edit', postType: 'sfwd-courses' });
    case 'mp_read':
      return wrap(__('Read MemberPress data', 'wp-pfagent'), { screen: 'edit', postType: 'memberpressproduct' });
    case 'mp_access':
      return wrap(__('Updated membership access', 'wp-pfagent'), { screen: 'edit', postType: 'memberpressproduct' });

    default:
      return genericWpItem(name, wrap, { postType, title, postId, refId });
  }
}

/**
 * Forms engine → wp-admin target, mirroring the backend `forms_focus()` per-engine
 * map exactly so the chip lands where the live auto-jump did. The engine comes
 * from the tool's result/args `plugin`; a specific `form_id` deep-links that
 * engine's entries view, otherwise its forms page. The backend also SERVER-detects
 * the active plugin when the tool omits it — the browser can't, so an absent
 * `plugin` degrades to the plugins list, matching the backend's unknown fallback.
 */
function formsTarget(result: Record<string, unknown>, args: Record<string, unknown>): WpTarget {
  const plugin = str(pick(result, args, ['plugin', 'engine']));
  const formId = num(pick(result, args, ['formId', 'form_id']));
  const engines: Record<string, { list: string; entries: string; idParam: string | null; fixed: Record<string, string> }> = {
    fluentforms:  { list: 'fluent_forms',     entries: 'fluent_forms',     idParam: 'form_id', fixed: { route: 'entries' } },
    gravityforms: { list: 'gf_edit_forms',    entries: 'gf_entries',       idParam: 'id',      fixed: {} },
    wpforms:      { list: 'wpforms-overview', entries: 'wpforms-entries',  idParam: 'form_id', fixed: { view: 'list' } },
    contactform7: { list: 'wpcf7',            entries: 'wpcf7',            idParam: null,      fixed: {} },
  };
  const e = engines[plugin];
  if (!e) return { screen: 'plugins' }; // unknown engine — plugins list is the safe fallback
  if (formId !== undefined && e.idParam !== null) {
    return { screen: 'admin_page', page: e.entries, query: { ...e.fixed, [e.idParam]: String(formId) } };
  }
  return { screen: 'admin_page', page: e.list };
}

/**
 * Fallback describer for a namespaced tool with no explicit case — so a new
 * backend tool never silently drops from the strip. Infers the screen from the
 * tool family + a declared post type, and a readable past-tense label from the
 * tool name. The runtime's own focus.wpTarget still drives the LIVE jump; this
 * covers the chip label + the end-of-turn fallback jump.
 */
function genericWpItem(
  name: string,
  wrap: (label: string, target: WpTarget) => WpActivityItem,
  ctx: { postType: string; title: string; postId?: number; refId?: number },
): WpActivityItem {
  const verb = verbOf(name);
  // WooCommerce sub-noun inference for any wc_* not matched above.
  if (name.startsWith('wc_')) {
    if (name.includes('product')) return wrap(sprintf(__('%1$s product%2$s', 'wp-pfagent'), verb, idFrag(ctx.refId)), { screen: 'edit', postType: 'product', id: ctx.refId });
    if (name.includes('coupon')) return wrap(sprintf(__('%1$s coupon%2$s', 'wp-pfagent'), verb, idFrag(ctx.refId)), { screen: 'edit', postType: 'shop_coupon', id: ctx.refId });
    if (name.includes('order')) return wrap(sprintf(__('%1$s order%2$s', 'wp-pfagent'), verb, idFrag(ctx.refId)), { screen: 'edit', postType: 'shop_order' });
    if (name.includes('stock')) return wrap(__('Adjusted stock', 'wp-pfagent'), { screen: 'edit', postType: 'product', id: ctx.refId });
    return wrap(__('WooCommerce action', 'wp-pfagent'), { screen: 'edit', postType: 'product' });
  }
  // A tool that declared a post type lands on that CPT's list/editor (covers
  // LMS/membership CPTs like sfwd-courses, memberpressproduct, …).
  if (ctx.postType && ctx.postType !== 'post') {
    return wrap(sprintf(__('%1$s %2$s%3$s', 'wp-pfagent'), verb, ctx.postType, idFrag(ctx.postId)), { screen: 'edit', postType: ctx.postType, id: ctx.postId });
  }
  // Last resort: a readable name on the dashboard.
  return wrap(sprintf(__('WordPress action: %s', 'wp-pfagent'), name.replace(/_/g, ' ')), { screen: 'site' });
}

/** The wp target of the LAST anchoring wp_* execution in a turn - the screen
 *  the WordPress iframe should jump to (write actions win over reads). Returns
 *  null when no wp_* tool ran. */
export function wpTargetFromExecutions(executions: AgentRuntimeExecution[]): WpTarget | null {
  if (!executions.length) return null;
  const writeTools = new Set([
    'wp_post_create', 'wp_post_update', 'wp_post_trash', 'wp_post_meta_set', 'wp_term_create', 'wp_term_assign',
    'wp_user_create', 'wp_user_update', 'wp_comment_moderate', 'wp_option_set', 'wp_media_sideload', 'wp_menu_manage',
    'seo_set', 'forms_entry_manage',
    'wc_order_note', 'wc_order_create', 'wc_order_update', 'wc_order_cancel', 'wc_order_line',
    'wc_apply_coupon', 'wc_refund_request', 'wc_stock_set', 'wc_product_upsert',
    'ld_enroll', 'mp_access',
  ]);
  // Any write-ish verb on a namespaced tool also counts (covers new tools).
  const looksWrite = (n: string) => /(_create|_update|_set|_delete|_trash|_moderate|_refund|_cancel|_apply|_adjust|_add|_enroll|_grant|_revoke|_assign|_manage)(\b|$)/.test(n);
  const reversed = [...executions].reverse();
  const lastWrite = reversed.find((e) => e.status === 'success' && (writeTools.has(e.tool?.name ?? '') || (isWpTool(e.tool?.name ?? '') && looksWrite(e.tool?.name ?? ''))));
  const anchor = lastWrite ?? reversed.find((e) => e.status === 'success' && isWpTool(e.tool?.name ?? ''));
  if (!anchor) return null;
  return describeWpExecution(anchor)?.target ?? null;
}
