(function () {
  'use strict';

  const reader = document.querySelector('.reader');
  if (!reader) return;

  // vertical-rl + direction:ltr: scrollLeft 0 is the rightmost (first) column;
  // later columns are at negative scrollLeft. Reset on load so a reload always
  // opens at the beginning instead of a restored scroll offset.
  function toStart() {
    reader.scrollLeft = 0;
  }
  toStart();
  window.addEventListener('load', toStart);

  reader.addEventListener('wheel', function (event) {
    // Let horizontal gestures keep their native handling; only map vertical wheels.
    if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) return;

    const factor = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? reader.clientWidth : 1;
    const delta = event.deltaY * factor;
    if (delta === 0) return;

    event.preventDefault();
    // Scrolling down advances to later columns, i.e. toward negative scrollLeft.
    reader.scrollLeft -= delta;
  }, { passive: false });
})();
