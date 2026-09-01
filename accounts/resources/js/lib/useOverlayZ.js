import { useEffect, useState } from 'react';

/**
 * Stack overlays by MOUNT ORDER instead of DOM order.
 *
 * WHY THIS EXISTS. Every `.zc-overlay` took its `z-index: 50` from the class, so
 * when two were open at once the winner was decided by document order — and JSX
 * puts a module's form BEFORE its detail panel. On Payments that buried the edit
 * form under the detail overlay: the form mounted, rendered, and was invisible.
 * Clicking `Edit` looked like a dead button, and it read as one for two rounds of
 * debugging because the dialog was genuinely THERE in the DOM. Presence is not
 * visibility.
 *
 * Two modules hit this with different causes, so a local guard in each is a fix
 * for today and a trap for the 76 forms still to be built. Modal semantics are
 * "last opened wins", which is mount order, not source order.
 *
 * The stack is module-level rather than context, deliberately: overlays here are
 * SIBLINGS in the tree, not nested, so a context provider would report the same
 * depth for both and decide nothing.
 */
const BASE = 50;
const STEP = 10;

let stack = [];
let nextId = 1;

export default function useOverlayZ() {
  const [z, setZ] = useState(BASE);

  useEffect(() => {
    const id = nextId += 1;
    stack.push(id);
    setZ(BASE + stack.indexOf(id) * STEP);

    return () => {
      stack = stack.filter((x) => x !== id);
    };
  }, []);

  return z;
}
