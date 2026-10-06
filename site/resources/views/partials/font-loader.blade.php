<script>
  (function () {
    var FONT_CSS = "css2?family=Playfair+Display:wght@700;900&family=Inter:wght@400;500;700&display=swap";
    function loadFonts(cdn) {
      var l = document.createElement("link");
      l.rel = "stylesheet";
      l.href = "https://" + cdn + "/" + FONT_CSS;
      if (cdn !== "fonts.googleapis.com") {
        l.onerror = function () { loadFonts("fonts.googleapis.com"); };
      }
      document.head.appendChild(l);
    }
    loadFonts("fonts.googleapis.cn"); /* 国内优先，失败自动切谷歌 */
  })();
</script>
