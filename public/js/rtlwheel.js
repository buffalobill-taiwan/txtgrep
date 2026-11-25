(function() {
  function scrollHorizontally(e) {
    e = window.event || e;
    var delta = Math.max(-1, Math.min(1, (e.wheelDelta || -e.detail)));
    document.documentElement.scrollLeft -= (delta * -50);
    document.body.scrollLeft -= (delta * -50);
    //e.preventDefault();
  }
  if (window.addEventListener) {
    window.addEventListener("mousewheel", scrollHorizontally, false);
    window.addEventListener("DOMMouseScroll", scrollHorizontally, false);
  } else {
    window.attachEvent("onmousewheel", scrollHorizontally);
  }
})();
