import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { NAV } from '../nav';

/**
 * The navy rail with the pink active state (handoff §2 rule 9).
 *
 * The flyout is rendered OUTSIDE the rail as a position:fixed panel. handoff §3:
 * "A nav flyout cannot live inside the rail. overflow-y:auto on the rail makes
 * the horizontal axis a scroll container too and clips the submenu."
 *
 * THAT DECISION IS RIGHT, BUT IT HAS CONSEQUENCES THE FIRST PASS MISSED. All were
 * reproduced in a browser at 1440x640, where the rail's 17 items genuinely
 * overflow (767px of content in 640px of viewport):
 *
 *   1. CLOSED BEFORE IT COULD BE REACHED. `onMouseLeave` sat on the <nav>, and the
 *      flyout is a fixed SIBLING rather than a child — so moving the pointer right,
 *      off the rail and toward the panel, left the nav and closed the panel
 *      mid-travel. Measured: gone before the pointer arrived. A menu you cannot
 *      move the mouse onto is not a menu.
 *
 *   2. DETACHED FROM ITS ANCHOR ON SCROLL. `top` was captured once, at click, and
 *      never recomputed. Scrolling the rail with the panel open left it pinned to
 *      the old viewport position while its anchor moved away underneath — measured
 *      at 127px of drift, which puts the panel beside a different nav item
 *      entirely. This is the one that reads as "the scroll goes away".
 *
 *   3. NO WAY TO REACH A CLIPPED PANEL. Settings has eight children, 262px tall,
 *      and the panel had neither max-height nor overflow. Anchored low in a short
 *      viewport its lower items fall past the fold with nothing to scroll.
 *
 * HOVER OPENS THE PANEL, which is what Creator does and what was asked for. Hover
 * menus are exactly where defect 1 bites hardest, so the pointer handling is the
 * careful part: leaving the rail does not close the panel immediately, it schedules
 * a close, and entering either the rail or the panel cancels it. That grace period
 * is what makes the diagonal trip from "Settings" to its eighth child survivable.
 * Click still toggles, so touch and keyboard are not left behind.
 */

/** Keep the panel this far from the viewport edges when clamping. */
const VIEWPORT_MARGIN = 8;

/**
 * Grace period before a hover-out actually closes the panel, in ms.
 *
 * Long enough to cross the rail/panel boundary and to cut a corner on the way to a
 * lower child; short enough that the panel does not linger over the grid after the
 * pointer has plainly gone elsewhere. 220ms is the low end of the usual 200-300ms
 * range for this pattern — the rail and panel are flush (`left: var(--rail-w)`),
 * so there is no dead gap to cross, only the boundary itself.
 */
const CLOSE_DELAY = 220;

export default function Rail({ active, onNavigate }) {
  const [openKey, setOpenKey] = useState(null);
  const [position, setPosition] = useState({ top: 0, maxHeight: null });

  const railRef = useRef(null);
  const flyoutRef = useRef(null);
  /** key -> the rail button, so the panel can re-measure its anchor at any time. */
  const anchorRefs = useRef(new Map());
  const closeTimer = useRef(null);

  const open = NAV.find((item) => item.key === openKey);

  const cancelClose = useCallback(() => {
    if (closeTimer.current !== null) {
      clearTimeout(closeTimer.current);
      closeTimer.current = null;
    }
  }, []);

  /** Close after the grace period — see CLOSE_DELAY. */
  const scheduleClose = useCallback(() => {
    cancelClose();
    closeTimer.current = setTimeout(() => {
      closeTimer.current = null;
      setOpenKey(null);
    }, CLOSE_DELAY);
  }, [cancelClose]);

  /** A pending close must not fire after unmount. */
  useEffect(() => cancelClose, [cancelClose]);

  const openNow = useCallback((key) => {
    cancelClose();
    setOpenKey(key);
  }, [cancelClose]);

  /**
   * Put the panel beside its anchor, clamped into the viewport.
   *
   * Defect 2's fix. Reads the anchor's CURRENT rect every time rather than a value
   * captured at open, which is what let the two drift apart.
   *
   * Two clamps, in this order: never run past the bottom edge, then never start
   * above the top edge. If the panel is taller than the viewport the second clamp
   * wins and `maxHeight` makes it scroll internally — defect 3's fix — instead of
   * being silently cut off.
   */
  const reposition = useCallback(() => {
    const anchor = anchorRefs.current.get(openKey);
    const rail = railRef.current;

    if (!anchor || !rail) {
      return;
    }

    const anchorRect = anchor.getBoundingClientRect();
    const railRect = rail.getBoundingClientRect();

    /*
     * If the anchor has scrolled out of the rail's visible band, there is nothing
     * to anchor TO. Leaving the panel floating beside whatever now occupies that
     * strip is exactly defect 2, so close instead.
     */
    if (anchorRect.bottom < railRect.top || anchorRect.top > railRect.bottom) {
      setOpenKey(null);
      return;
    }

    const height = flyoutRef.current?.offsetHeight ?? 0;
    const available = window.innerHeight - VIEWPORT_MARGIN * 2;

    let top = anchorRect.top;

    if (height > 0 && top + height > window.innerHeight - VIEWPORT_MARGIN) {
      top = window.innerHeight - VIEWPORT_MARGIN - height;
    }

    setPosition({
      top: Math.max(VIEWPORT_MARGIN, top),
      maxHeight: height > available ? available : null,
    });
  }, [openKey]);

  /**
   * Measure after layout, before paint — a panel that appears in the wrong place
   * and corrects itself one frame later reads as a flicker.
   */
  useLayoutEffect(() => {
    if (openKey) {
      reposition();
    }
  }, [openKey, reposition]);

  /** Follow the anchor while the rail scrolls, and through window resizes. */
  useEffect(() => {
    if (!openKey) {
      return undefined;
    }

    const rail = railRef.current;

    rail?.addEventListener('scroll', reposition, { passive: true });
    window.addEventListener('resize', reposition);

    return () => {
      rail?.removeEventListener('scroll', reposition);
      window.removeEventListener('resize', reposition);
    };
  }, [openKey, reposition]);

  /**
   * Close on an outside press or Escape.
   *
   * The hover grace period handles the common case; these cover the rest — a click
   * into the grid, or a keyboard user who never hovers at all. `pointerdown`
   * rather than `click` so the panel closes on press instead of waiting for a
   * release that may land somewhere else.
   */
  useEffect(() => {
    if (!openKey) {
      return undefined;
    }

    const onPointerDown = (event) => {
      if (flyoutRef.current?.contains(event.target)) return;
      if (railRef.current?.contains(event.target)) return;   // the rail handles its own
      cancelClose();
      setOpenKey(null);
    };

    const onKeyDown = (event) => {
      if (event.key !== 'Escape') return;
      cancelClose();
      setOpenKey(null);
      anchorRefs.current.get(openKey)?.focus();              // return focus to the trigger
    };

    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);

    return () => {
      document.removeEventListener('pointerdown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [openKey, cancelClose]);

  /**
   * Hovering a rail item.
   *
   * A parent opens its panel immediately. A leaf SCHEDULES a close rather than
   * closing outright, and that distinction is load-bearing: the trip from
   * "Settings" to its eighth child is a diagonal one, and its early frames land on
   * the rail items BELOW Settings. Closing on the first leaf hover made the lower
   * half of every panel unreachable by the obvious mouse path — the panel vanished
   * mid-journey. Scheduling instead lets entering the panel cancel it, while a
   * pointer that really is just sliding down the rail still dismisses it a beat
   * later.
   */
  const hover = (item) => {
    if (item.flyout) {
      openNow(item.key);
      return;
    }

    scheduleClose();
  };

  const click = (item) => {
    if (item.flyout) {
      cancelClose();
      // Toggle, so a tap opens and a second tap closes on touch, where there is no
      // hover to open it in the first place.
      setOpenKey((current) => (current === item.key ? null : item.key));
      return;
    }

    cancelClose();
    setOpenKey(null);
    onNavigate?.(item.key);
  };

  const choose = (childKey) => {
    cancelClose();
    setOpenKey(null);
    onNavigate?.(childKey);
  };

  /** Is `key` the item the current report belongs to? Parents highlight too. */
  const isActive = (item) =>
    active === item.key || (item.flyout ?? []).some((child) => child.key === active);

  return (
    <>
      <nav
        className="zc-rail"
        ref={railRef}
        onMouseEnter={cancelClose}
        onMouseLeave={scheduleClose}
      >
        <div className="zc-rail-brand">ACC</div>
        {NAV.map((item) => (
          <button
            key={item.key}
            type="button"
            ref={(node) => {
              if (node) anchorRefs.current.set(item.key, node);
              else anchorRefs.current.delete(item.key);
            }}
            className="zc-rail-item"
            aria-current={isActive(item) || openKey === item.key ? 'true' : undefined}
            aria-haspopup={item.flyout ? 'menu' : undefined}
            aria-expanded={item.flyout ? openKey === item.key : undefined}
            title={item.truncated ? `${item.label} (label truncated in Creator)` : item.label}
            onMouseEnter={() => hover(item)}
            // Keyboard tabbing through the rail opens the same panels hover does.
            onFocus={() => item.flyout && openNow(item.key)}
            onClick={() => click(item)}
          >
            {item.label}
          </button>
        ))}
      </nav>

      {open && (
        <div
          className="zc-flyout"
          ref={flyoutRef}
          role="menu"
          aria-label={open.label}
          onMouseEnter={cancelClose}
          onMouseLeave={scheduleClose}
          style={{
            top: position.top,
            ...(position.maxHeight ? { maxHeight: position.maxHeight } : null),
          }}
        >
          {open.flyout.map((child) => (
            <button
              key={child.key}
              type="button"
              role="menuitem"
              aria-current={active === child.key ? 'true' : undefined}
              onClick={() => choose(child.key)}
            >
              {child.label}
            </button>
          ))}
        </div>
      )}
    </>
  );
}
