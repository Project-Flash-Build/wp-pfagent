// The "WordPress" tab: a native wp-admin iframe of the resource the agent last
// touched, with the turn's activity strip overlaid on top. Self-contained and
// suite-free — shown alongside the Management/Workflow tabs when the Setyenv
// suite is present, and as the only tab when it is absent (standalone). It owns
// no state; the parent drives it via props (the reflection lives in the shared
// chat engine).
import { __ } from '@wordpress/i18n';

import type { WpActivityItem, WpTarget } from './wpTarget';

export function WordPressTabButton({ active, onSelect }: { active: boolean; onSelect: () => void }) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      data-active={active}
      onClick={onSelect}
      className="pfa-iframe-tab"
    >
      { __('WordPress', 'wp-pfagent') }
    </button>
  );
}

interface WordPressPaneProps {
  /** Whether this pane is the visible tab. Stays mounted when false so its
   *  iframe navigation is never discarded. */
  active: boolean;
  /** Current wp-admin URL (null → the wp-admin dashboard base). */
  wpUrl: string | null;
  /** wp-admin base URL, used as the default landing when wpUrl is null. */
  wpAdminBase: string;
  /** The turn's WordPress actions, each a clickable chip that points the iframe. */
  wpActivity: WpActivityItem[];
  /** Point the iframe at an activity item's native wp-admin screen. */
  onShowTarget: (target: WpTarget) => void;
}

export function WordPressPane({ active, wpUrl, wpAdminBase, wpActivity, onShowTarget }: WordPressPaneProps) {
  return (
    <div className="pfa-iframe-wp" data-active={active}>
      {wpActivity.length > 0 ? (
        <div className="pfa-wp-activity" role="list" aria-label={ __('WordPress actions this turn', 'wp-pfagent') }>
          {wpActivity.map((item, i) => (
            <button
              type="button"
              role="listitem"
              key={i}
              className="pfa-wp-activity-item"
              title={ __('Open this in WordPress admin', 'wp-pfagent') }
              onClick={() => onShowTarget(item.target)}
            >
              {item.label}
            </button>
          ))}
        </div>
      ) : null}
      <iframe
        className="pfa-iframe-wp-frame"
        title={ __('WordPress admin', 'wp-pfagent') }
        src={wpUrl ?? wpAdminBase}
      />
    </div>
  );
}
