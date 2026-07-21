// Suite switcher — hamburger in the fullscreen header, next to the
// back-to-admin chevron. Opens a floating card listing the sibling
// products of the suite plus a WP Admin shortcut. The list comes from the
// PHP bootstrap (admin-gated there): empty for non-admins → this renders
// nothing, so the hamburger simply isn't there. Mirrors the PF Manage /
// PF Workflow switcher so the affordance is identical wherever an admin
// sees it.
import { useEffect, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';

export interface SuiteProduct {
  slug: string;
  label: string;
  url: string;
}

// Each entry links with the same dashicon its own admin menu uses.
const PRODUCT_ICONS: Record<string, string> = {
  home: 'dashicons-admin-home',
  pfmanagement: 'dashicons-database',
  pfworkflow: 'dashicons-networking',
  pfagent: 'dashicons-format-chat',
  wpadmin: 'dashicons-wordpress-alt',
};

export function ProductSwitcher({ products }: { products: SuiteProduct[] }) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  if (products.length === 0) return null;

  return (
    <div className="pfa-products" ref={rootRef}>
      <button
        type="button"
        className={`pfa-products-btn ${open ? 'is-open' : ''}`}
        aria-haspopup="true"
        aria-expanded={open}
        title={__('Products', 'wp-pfagent')}
        aria-label={__('Products', 'wp-pfagent')}
        onClick={() => setOpen((v) => !v)}
      >
        <span className="dashicons dashicons-menu-alt" aria-hidden="true" />
      </button>
      {open && (
        <div className="pfa-products-menu" role="dialog">
          <div className="pfa-products-menu-header">{__('Products', 'wp-pfagent')}</div>
          <div className="pfa-products-list">
            {products.map((p) => (
              // WP Admin isn't a sibling product — it jumps back to the
              // WordPress dashboard, so a divider sets it apart from the suite.
              <div key={p.slug} className="pfa-products-slot">
                {p.slug === 'wpadmin' && <div className="pfa-products-divider" />}
                <a className="pfa-products-item" href={p.url}>
                  <span
                    className={`dashicons ${PRODUCT_ICONS[p.slug] ?? 'dashicons-admin-plugins'}`}
                    aria-hidden="true"
                  />
                  <span>{p.label}</span>
                </a>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
