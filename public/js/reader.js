(function () {
  'use strict';

  const scroller = document.querySelector('.reader-scroll');
  const reader = scroller.querySelector('.reader');
  const space = scroller.querySelector('.reader-scroll-space');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  let maxOffset = 0;
  let offset = 0;
  let lastTop = 0;
  let lastLeft = 0;
  let target = null;
  let animationFrame = 0;
  let previousFrameTime = 0;

  function syncPosition(position) {
    offset = Math.max(0, Math.min(position, maxOffset));
    // In vertical-rl the right edge is scrollLeft 0, and later columns are negative.
    if (scroller.scrollTop !== offset) scroller.scrollTop = offset;
    if (reader.scrollLeft !== -offset) reader.scrollLeft = -offset;

    // Read back the actual positions because browsers can round scroll offsets.
    lastTop = scroller.scrollTop;
    lastLeft = reader.scrollLeft;
  }

  function stopAnimation() {
    cancelAnimationFrame(animationFrame);
    animationFrame = 0;
    previousFrameTime = 0;
    target = null;
  }

  function animate(time) {
    const elapsed = previousFrameTime ? time - previousFrameTime : 16;
    const distance = target - offset;
    if (Math.abs(distance) <= 0.5) {
      syncPosition(target);
      stopAnimation();
      return;
    }

    // A shared target lets rapid wheel and key inputs accumulate without restarting the animation.
    syncPosition(offset + distance * (1 - Math.exp(-elapsed / 65)));
    previousFrameTime = time;
    animationFrame = requestAnimationFrame(animate);
  }

  function scrollTo(position) {
    const next = Math.max(0, Math.min(position, maxOffset));
    if (reducedMotion.matches) {
      stopAnimation();
      syncPosition(next);
    } else {
      target = next;
      if (!animationFrame) animationFrame = requestAnimationFrame(animate);
    }
  }

  function measure() {
    stopAnimation();
    maxOffset = reader.scrollWidth - reader.clientWidth;
    space.style.height = maxOffset + 'px';
    syncPosition(offset);
  }

  scroller.tabIndex = 0;
  scroller.classList.add('is-synced');
  measure();
  scroller.focus({ preventScroll: true });

  scroller.addEventListener('scroll', () => {
    if (scroller.scrollTop === lastTop) return;
    stopAnimation();
    syncPosition(scroller.scrollTop);
  }, { passive: true });

  reader.addEventListener('scroll', () => {
    if (reader.scrollLeft === lastLeft) return;
    stopAnimation();
    syncPosition(-reader.scrollLeft);
  }, { passive: true });

  scroller.addEventListener('pointerdown', stopAnimation, { passive: true });

  function columnWidth() {
    return Math.round(parseFloat(getComputedStyle(reader).lineHeight));
  }

  scroller.addEventListener('wheel', (event) => {
    if (event.defaultPrevented || !event.cancelable || event.ctrlKey || event.metaKey) return;
    // Horizontal wheel input follows the physical direction of the vertical text.
    const delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? -event.deltaX : event.deltaY;
    if (!delta) return;

    const unit = event.deltaMode === 1 ? columnWidth()
      : event.deltaMode === 2 ? scroller.clientHeight : 1;
    event.preventDefault();
    scrollTo((target ?? offset) + delta * unit);
  }, { passive: false });

  scroller.addEventListener('keydown', (event) => {
    if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return;
    if (event.shiftKey && event.key !== ' ') return;

    const position = target ?? offset;
    const page = Math.max(1, scroller.clientHeight - columnWidth());
    let next;
    switch (event.key) {
      case 'ArrowLeft':
      case 'ArrowDown':
        next = position + columnWidth();
        break;
      case 'ArrowRight':
      case 'ArrowUp':
        next = position - columnWidth();
        break;
      case 'PageDown':
        next = position + page;
        break;
      case 'PageUp':
        next = position - page;
        break;
      case ' ':
        next = position + (event.shiftKey ? -page : page);
        break;
      case 'Home':
        next = 0;
        break;
      case 'End':
        next = maxOffset;
        break;
      default:
        return;
    }
    event.preventDefault();
    scrollTo(next);
  });

  reducedMotion.addEventListener('change', () => {
    if (reducedMotion.matches && target !== null) scrollTo(target);
  });

  new ResizeObserver(measure).observe(reader);
  document.fonts.ready.then(measure);
  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) {
      stopAnimation();
      syncPosition(0);
    }
  });
})();
