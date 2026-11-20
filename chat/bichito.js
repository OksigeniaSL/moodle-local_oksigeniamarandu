/* Bichito Hechauka: a stable little creature for each name, drawn as SVG.
   The same engine as Ksiopea's and Benthos's avatars (a name -> xmur3 -> mulberry32 -> pieces), with Hechauka's own
   look: the family of the screens' Surprises (round, chunky creatures with big glossy amber eyes, fuzz, stripes and
   striped socks), not Ksiopea's glowing robots. Made to be read in a circle on a TV at 3, 5 and 8 m, in light and dark.
   ES5, no dependencies, safe for Chrome 56: hsl() with commas, no padStart, no template strings.
   API:  BichitoHk.svg(seed, opts) -> SVG string     opts: { bg: true, dark: false, title: '' }
           bg   draws its own soft disc behind the creature (recommended: the contrast is then always right);
                with bg:false the creature sits on whatever is behind it (keep a light or mid tone there).
           dark the disc goes deep instead of pale, for a dark skin.
         BichitoHk.paint(el, seed, opts)   BichitoHk.hash(seed) -> short stable hex label
   The seed is the person's name; it is trimmed and lower-cased, so «Ana Ruiz» and «ana ruiz » give the same creature. */
(function (root) {
  "use strict";

  function xmur3(str) {
    var h = 1779033703 ^ str.length;
    for (var i = 0; i < str.length; i++) {
      h = Math.imul(h ^ str.charCodeAt(i), 3432918353);
      h = (h << 13) | (h >>> 19);
    }
    return function () {
      h = Math.imul(h ^ (h >>> 16), 2246822507);
      h = Math.imul(h ^ (h >>> 13), 3266489909);
      return (h ^= h >>> 16) >>> 0;
    };
  }
  function mulberry32(a) {
    return function () {
      a |= 0; a = (a + 0x6D2B79F5) | 0;
      var t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }
  function norm(seed) { return String(seed == null ? "" : seed).replace(/^\s+|\s+$/g, "").toLowerCase(); }
  function makeR(seed) {
    var r = mulberry32(xmur3(norm(seed))());
    return {
      f: r,
      i: function (n) { return Math.floor(r() * n); },
      pick: function (a) { return a[Math.floor(r() * a.length)]; },
      rng: function (a, b) { return a + r() * (b - a); }
    };
  }
  function n1(x) { return Math.round(x * 10) / 10; }

  // Body colours: [base, shade, light, fuzz-or-pattern]. Warm and natural, like the Surprises; no neon.
  var BODIES = [
    ["#8E6CD1", "#5E3FA3", "#C4B0EE", "#4A2F86"],   // lilac (the spider)
    ["#F2B33D", "#C9821B", "#FFDC8A", "#5A3A14"],   // honey (the bee)
    ["#7CC24A", "#4E8F2A", "#B7E58F", "#2F5E17"],   // leaf (the caterpillar)
    ["#F07B5A", "#C24E33", "#FFB59E", "#7A2A18"],   // coral (the octopus)
    ["#59A9E6", "#2F78B8", "#A8D4F7", "#1C4C7A"],   // sky
    ["#E2556F", "#B0304A", "#F7A0B0", "#6E1A2C"],   // berry (the ladybird)
    ["#4CC3A8", "#26907A", "#9BE6D4", "#145C4D"],   // mint
    ["#F4D04E", "#C9A21E", "#FFF0A0", "#6B5410"],   // lemon (the puffer fish)
    ["#B98A5E", "#86603B", "#E3C3A0", "#4E3520"],   // hazel (the snail)
    ["#F29BC6", "#C96A9A", "#FFD0E6", "#7A2E58"]    // pink
  ];
  var IRIS = ["#E39B2E", "#E39B2E", "#5BAF3C", "#3C86D0", "#9A6B3A"];
  var DISC_LIGHT = ["#FFF4DE", "#EAF6E4", "#E6F1FB", "#FBE9F0", "#F1ECFB", "#FFF0E6"];
  var DISC_DARK = ["#2B2438", "#1F3328", "#1C2B3D", "#3A2430", "#2E2A20", "#3A2A22"];
  var SOCKS = [["#F05A4F", "#FFFFFF"], ["#3C86D0", "#FFD65A"], ["#5BAF3C", "#FFFFFF"], ["#F2A33D", "#7A3FA0"], ["#E2556F", "#FFE7A0"]];

  function body(kind, c, R, id) {
    var s = "", cx = 50, cy = 57, r = 30, i, a, pts = [];
    var fill = 'fill="url(#' + id + 'b)"';
    if (kind === "peludo") {                       // fuzzy round body, scalloped edge
      var n = 30;
      for (i = 0; i < n * 2; i++) {
        a = Math.PI * i / n; var rr = i % 2 ? r + 3.4 : r;
        pts.push(n1(cx + rr * Math.cos(a)) + "," + n1(cy + rr * Math.sin(a)));
      }
      s += '<polygon points="' + pts.join(" ") + '" ' + fill + '/>';
    } else if (kind === "globo") {                 // puffer fish: round with soft spikes
      var k = 16;
      for (i = 0; i < k; i++) {
        a = Math.PI * 2 * i / k + 0.1;
        var x1 = cx + (r - 1) * Math.cos(a - 0.12), y1 = cy + (r - 1) * Math.sin(a - 0.12);
        var x2 = cx + (r + 7) * Math.cos(a), y2 = cy + (r + 7) * Math.sin(a);
        var x3 = cx + (r - 1) * Math.cos(a + 0.12), y3 = cy + (r - 1) * Math.sin(a + 0.12);
        s += '<path d="M' + n1(x1) + ' ' + n1(y1) + 'L' + n1(x2) + ' ' + n1(y2) + 'L' + n1(x3) + ' ' + n1(y3) + 'Z" fill="' + c[1] + '"/>';
      }
      s += '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" ' + fill + '/>';
    } else if (kind === "gusano") {                // caterpillar head seen from the front, with a collar
      s += '<ellipse cx="50" cy="88" rx="24" ry="12" fill="' + c[1] + '"/>';
      s += '<ellipse cx="50" cy="55" rx="27" ry="31" ' + fill + '/>';
    } else if (kind === "pulpo") {                 // a round head and six arms curling out, never a ghost
      var arms = ["M38 70 C30 78 22 80 18 90 C16 96 22 98 24 93", "M44 72 C40 82 36 90 38 98", "M50 73 C50 84 48 92 52 100",
                  "M56 72 C60 82 64 90 62 98", "M62 70 C70 78 78 80 82 90 C84 96 78 98 76 93", "M32 66 C22 70 14 70 10 78"];
      for (i = 0; i < arms.length; i++) s += '<path d="' + arms[i] + '" fill="none" stroke="' + c[1] + '" stroke-width="7" stroke-linecap="round"/>';
      s += '<ellipse cx="50" cy="50" rx="29" ry="27" ' + fill + '/>';
      s += '<circle cx="21" cy="88" r="1.4" fill="' + c[2] + '"/><circle cx="79" cy="88" r="1.4" fill="' + c[2] + '"/>';
    } else if (kind === "caracol") {               // snail: the shell behind, the soft body in front
      var sx = R.f() < .5 ? 70 : 30;
      s += '<circle cx="' + sx + '" cy="48" r="23" fill="' + c[3] + '" opacity=".85"/>';
      s += '<circle cx="' + sx + '" cy="48" r="20" fill="' + c[2] + '"/>';
      s += '<path d="M' + sx + ' 48 m-2 0 a2 2 0 1 1 4 0 a6 6 0 1 1 -12 0 a10 10 0 1 1 20 0 a14 14 0 1 1 -28 0" fill="none" stroke="' + c[3] + '" stroke-width="2.6" stroke-linecap="round"/>';
      s += '<ellipse cx="50" cy="64" rx="24" ry="24" ' + fill + '/>';
    } else {                                       // round (bee, ladybird) with its pattern
      s += '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" ' + fill + '/>';
    }
    return s;
  }

  function pattern(kind, c, R, id) {
    var s = "";
    if (kind === "abeja") {
      s += '<g clip-path="url(#' + id + 'c)" fill="' + c[3] + '" opacity=".9">';
      s += '<rect x="10" y="70" width="80" height="6"/><rect x="10" y="80" width="80" height="6"/></g>';
    } else if (kind === "mariquita") {
      var spots = [[30, 72, 4.5], [70, 72, 4.5], [40, 82, 3.6], [61, 83, 3.6], [24, 58, 3.2], [76, 58, 3.2]];
      var n = 4 + R.i(3);
      for (var i = 0; i < n; i++) s += '<circle cx="' + spots[i][0] + '" cy="' + spots[i][1] + '" r="' + spots[i][2] + '" fill="' + c[3] + '" opacity=".85"/>';
    } else if (kind === "peludo" || kind === "gusano") {
      if (R.f() < .5) s += '<circle cx="34" cy="76" r="3.2" fill="' + c[2] + '" opacity=".7"/><circle cx="66" cy="78" r="2.6" fill="' + c[2] + '" opacity=".7"/>';
    }
    return s;
  }

  function wings(kind, R) {
    if (kind !== "abeja" && !(kind === "redondo" && R.f() < .4)) return "";
    return '<ellipse cx="24" cy="38" rx="11" ry="15" transform="rotate(-28 24 38)" fill="#FFFFFF" opacity=".75"/>' +
           '<ellipse cx="76" cy="38" rx="11" ry="15" transform="rotate(28 76 38)" fill="#FFFFFF" opacity=".75"/>';
  }

  function antennae(type, c, top, R, dark) {
    var s = "", tip = dark ? c[2] : c[3];
    if (type === "bolas") {
      s += '<path d="M43 ' + (top + 3) + ' C40 ' + (top - 6) + ' 36 ' + (top - 10) + ' 33 ' + (top - 13) + '" fill="none" stroke="' + tip + '" stroke-width="2.4" stroke-linecap="round"/>';
      s += '<path d="M57 ' + (top + 3) + ' C60 ' + (top - 6) + ' 64 ' + (top - 10) + ' 67 ' + (top - 13) + '" fill="none" stroke="' + tip + '" stroke-width="2.4" stroke-linecap="round"/>';
      s += '<circle cx="33" cy="' + (top - 13) + '" r="3.6" fill="' + tip + '"/><circle cx="67" cy="' + (top - 13) + '" r="3.6" fill="' + tip + '"/>';
    } else if (type === "rizos") {
      s += '<path d="M44 ' + (top + 3) + ' C42 ' + (top - 8) + ' 30 ' + (top - 8) + ' 33 ' + (top - 15) + ' C35 ' + (top - 19) + ' 40 ' + (top - 15) + ' 37 ' + (top - 12) + '" fill="none" stroke="' + tip + '" stroke-width="2.4" stroke-linecap="round"/>';
      s += '<path d="M56 ' + (top + 3) + ' C58 ' + (top - 8) + ' 70 ' + (top - 8) + ' 67 ' + (top - 15) + ' C65 ' + (top - 19) + ' 60 ' + (top - 15) + ' 63 ' + (top - 12) + '" fill="none" stroke="' + tip + '" stroke-width="2.4" stroke-linecap="round"/>';
    } else if (type === "brote") {                // a sprout: one stem and two leaves
      s += '<path d="M50 ' + (top + 2) + ' L50 ' + (top - 10) + '" stroke="#4E8F2A" stroke-width="2.4" stroke-linecap="round"/>';
      s += '<path d="M50 ' + (top - 8) + ' C44 ' + (top - 16) + ' 38 ' + (top - 12) + ' 38 ' + (top - 10) + ' C42 ' + (top - 7) + ' 47 ' + (top - 7) + ' 50 ' + (top - 8) + 'Z" fill="#7CC24A"/>';
      s += '<path d="M50 ' + (top - 10) + ' C56 ' + (top - 18) + ' 62 ' + (top - 14) + ' 62 ' + (top - 12) + ' C58 ' + (top - 9) + ' 53 ' + (top - 9) + ' 50 ' + (top - 10) + 'Z" fill="#9BD66A"/>';
    } else if (type === "cuernos") {              // snail stalks with little eyes are drawn by the eyes; here, soft horns
      s += '<path d="M40 ' + (top + 4) + ' L37 ' + (top - 6) + '" stroke="' + tip + '" stroke-width="4" stroke-linecap="round"/>';
      s += '<path d="M60 ' + (top + 4) + ' L63 ' + (top - 6) + '" stroke="' + tip + '" stroke-width="4" stroke-linecap="round"/>';
    }
    return s;
  }

  function eye(x, y, r, iris, look, closed) {
    if (closed) return '<path d="M' + n1(x - r * .8) + ' ' + n1(y + 1) + ' Q' + x + ' ' + n1(y - r * .9) + ' ' + n1(x + r * .8) + ' ' + n1(y + 1) + '" fill="none" stroke="#1C1410" stroke-width="2.8" stroke-linecap="round"/>';
    var s = '<circle cx="' + x + '" cy="' + y + '" r="' + n1(r) + '" fill="#FFFFFF"/>';
    s += '<circle cx="' + n1(x + look[0]) + '" cy="' + n1(y + look[1]) + '" r="' + n1(r * .68) + '" fill="' + iris + '"/>';
    s += '<circle cx="' + n1(x + look[0] * 1.15) + '" cy="' + n1(y + look[1] * 1.15) + '" r="' + n1(r * .38) + '" fill="#1C1410"/>';
    s += '<circle cx="' + n1(x + look[0] - r * .28) + '" cy="' + n1(y + look[1] - r * .3) + '" r="' + n1(r * .2) + '" fill="#FFFFFF"/>';
    s += '<circle cx="' + n1(x + look[0] + r * .22) + '" cy="' + n1(y + look[1] + r * .22) + '" r="' + n1(r * .09) + '" fill="#FFFFFF"/>';
    s += '<circle cx="' + x + '" cy="' + y + '" r="' + n1(r) + '" fill="none" stroke="#1C1410" stroke-opacity=".18" stroke-width="1"/>';
    return s;
  }

  function mouth(type, y, c) {
    var ink = "#3A1E14";
    if (type === 0) return '<path d="M42 ' + y + ' Q50 ' + (y + 8) + ' 58 ' + y + '" fill="none" stroke="' + ink + '" stroke-width="2.6" stroke-linecap="round"/>';
    if (type === 1) return '<path d="M41 ' + y + ' Q50 ' + (y + 12) + ' 59 ' + y + ' Z" fill="' + ink + '"/><path d="M45.5 ' + (y + 4.4) + ' Q50 ' + (y + 8) + ' 54.5 ' + (y + 4.4) + ' Q50 ' + (y + 3) + ' 45.5 ' + (y + 4.4) + 'Z" fill="#F07B8A"/>';
    if (type === 2) return '<ellipse cx="50" cy="' + (y + 3) + '" rx="3.6" ry="4.4" fill="' + ink + '"/>';
    if (type === 3) return '<path d="M42 ' + y + ' Q50 ' + (y + 9) + ' 58 ' + y + '" fill="none" stroke="' + ink + '" stroke-width="2.6" stroke-linecap="round"/><rect x="47.6" y="' + (y + 2.6) + '" width="4.8" height="3.6" rx="1" fill="#FFFFFF" stroke="' + ink + '" stroke-width=".8"/>';
    return '<path d="M42 ' + y + ' Q46 ' + (y + 5) + ' 50 ' + y + ' Q54 ' + (y + 5) + ' 58 ' + y + '" fill="none" stroke="' + ink + '" stroke-width="2.6" stroke-linecap="round"/>';
  }

  function socks(R, kind) {
    if (!(kind === "peludo" || kind === "mariquita" || kind === "redondo" || kind === "abeja")) return "";
    if (R.f() > .55) return "";
    var col = R.pick(SOCKS), s = "", xs = R.f() < .5 ? [36, 64] : [28, 44, 56, 72];
    for (var i = 0; i < xs.length; i++) {
      var x = xs[i];
      s += '<rect x="' + (x - 4) + '" y="84" width="8" height="16" rx="3.5" fill="' + col[0] + '"/>';
      s += '<rect x="' + (x - 4) + '" y="88" width="8" height="3" fill="' + col[1] + '"/><rect x="' + (x - 4) + '" y="94" width="8" height="3" fill="' + col[1] + '"/>';
    }
    return s;
  }

  function svg(seed, opts) {
    opts = opts || {};
    var R = makeR(seed), id = "hk" + (xmur3(norm(seed))() % 1e9) + "_";
    var KINDS = ["peludo", "peludo", "abeja", "mariquita", "globo", "gusano", "pulpo", "caracol", "redondo"];
    var kind = R.pick(KINDS);
    var c = kind === "abeja" ? BODIES[1] : kind === "mariquita" ? BODIES[R.pick([5, 5, 3, 7, 6])] : BODIES[R.i(BODIES.length)];
    if (kind === "abeja" && R.f() < .35) c = BODIES[7];
    var iris = R.pick(IRIS);
    var top = kind === "gusano" ? 25 : kind === "pulpo" ? 24 : kind === "caracol" ? 41 : 28;
    var ant = kind === "caracol" ? "cuernos" : kind === "pulpo" || kind === "globo" ? R.pick(["none", "none", "brote"]) : R.pick(["bolas", "bolas", "rizos", "brote", "none"]);
    var eyeY = kind === "gusano" ? 48 : kind === "pulpo" ? 47 : kind === "caracol" ? 60 : 53;
    var eyeR = R.rng(9.5, 11.5), gap = R.rng(12, 14.5);
    var look = [R.rng(-1.6, 1.6), R.rng(-.6, 1.4)];
    var closed = R.f() < .07;
    var m = R.i(5), mY = eyeY + eyeR + 6;
    var blush = R.f() < .55, stache = R.f() < .12;
    var disc = (opts.dark ? DISC_DARK : DISC_LIGHT)[R.i(DISC_LIGHT.length)];

    var s = '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" role="img"' + (opts.title ? ' aria-label="' + String(opts.title).replace(/"/g, "&quot;") + '"' : ' aria-hidden="true"') + '>';
    s += '<defs><radialGradient id="' + id + 'b" cx="38%" cy="32%" r="75%"><stop offset="0" stop-color="' + c[2] + '"/><stop offset=".45" stop-color="' + c[0] + '"/><stop offset="1" stop-color="' + c[1] + '"/></radialGradient>';
    s += '<clipPath id="' + id + 'c"><circle cx="50" cy="57" r="30"/></clipPath>';
    s += '<clipPath id="' + id + 'd"><circle cx="50" cy="50" r="50"/></clipPath></defs>';
    s += '<g clip-path="url(#' + id + 'd)">';
    if (opts.bg !== false) s += '<circle cx="50" cy="50" r="50" fill="' + disc + '"/>';
    s += '<g transform="translate(50 55) scale(1.12) translate(-50 -55)">';
    s += wings(kind, R);
    s += antennae(ant, c, top, R, opts.dark);
    s += socks(R, kind);
    s += body(kind, c, R, id);
    s += pattern(kind, c, R, id);
    s += '<ellipse cx="50" cy="' + (eyeY + eyeR + 16) + '" rx="22" ry="6" fill="#000000" opacity=".06"/>';
    s += eye(n1(50 - gap), n1(eyeY), eyeR, iris, look, closed) + eye(n1(50 + gap), n1(eyeY), eyeR, iris, look, closed);
    if (blush) s += '<ellipse cx="' + n1(50 - gap - 6) + '" cy="' + n1(mY) + '" rx="5" ry="3" fill="#FF7A8A" opacity=".45"/><ellipse cx="' + n1(50 + gap + 6) + '" cy="' + n1(mY) + '" rx="5" ry="3" fill="#FF7A8A" opacity=".45"/>';
    s += mouth(m, n1(mY), c);
    if (stache) s += '<path d="M50 ' + n1(mY - 2) + ' C46 ' + n1(mY - 6) + ' 38 ' + n1(mY - 5) + ' 35 ' + n1(mY - 1) + ' C40 ' + n1(mY - 2) + ' 45 ' + n1(mY + 1) + ' 50 ' + n1(mY - 1) + ' C55 ' + n1(mY + 1) + ' 60 ' + n1(mY - 2) + ' 65 ' + n1(mY - 1) + ' C62 ' + n1(mY - 5) + ' 54 ' + n1(mY - 6) + ' 50 ' + n1(mY - 2) + 'Z" fill="' + c[3] + '"/>';
    s += '</g></g></svg>';
    return s;
  }

  function paint(el, seed, opts) { if (el) el.innerHTML = svg(seed, opts); }
  function hash(seed) {
    var h = (xmur3(norm(seed))() >>> 0).toString(16);
    while (h.length < 8) h = "0" + h;
    return h;
  }

  var api = { svg: svg, paint: paint, hash: hash };
  if (typeof module === "object" && module.exports) module.exports = api;
  root.BichitoHk = api;
})(typeof self !== "undefined" ? self : this);
