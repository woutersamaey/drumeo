(() => {
  const $ = (sel, el = document) => el.querySelector(sel);
  const $$ = (sel, el = document) => [...el.querySelectorAll(sel)];

  const state = {
    bootstrap: null,
    route: { name: "profiles", params: {} },
    lessonCache: {},
    prepareTimers: {},
    prefetch: null,
    player: { video: null, lesson: null, recipe: null },
    saving: false,
    lastSave: 0,
    overlay: null,
    countdown: null,
    playGen: 0,
    weekDay: null,
    pendingFullscreen: false,
    hadFullscreenThisClip: false,
    fsListener: null,
    keepWatchStage: false,
  };

  const COLORS = { vic: "bg-vic", lenn: "bg-lenn", wouter: "bg-wouter" };
  const LANGS = [
    { index: 0, code: "en", short: "EN", label: "English" },
    { index: 1, code: "nl", short: "NL", label: "Nederlands" },
  ];

  function currentAudioIndex() {
    return Number(state.bootstrap?.lastAudioIndex) === 1 ? 1 : 0;
  }

  function langMeta(index = currentAudioIndex()) {
    return LANGS[index === 1 ? 1 : 0];
  }

  function isNl() {
    return currentAudioIndex() === 1;
  }

  function disp(obj, key = "title") {
    if (!obj) return "";
    if (isNl()) {
      const nl = obj[key + "Nl"];
      if (nl) return nl;
    }
    return obj[key] || "";
  }

  function locAttr(en, nl) {
    const e = String(en || "");
    const n = String(nl || e);
    const shown = isNl() ? n : e;
    return `data-en="${esc(e)}" data-nl="${esc(n)}">${esc(shown)}`;
  }

  function closePopMenus() {
    $$("[data-menu-panel]").forEach((menu) => menu.classList.add("hidden"));
    $$("[data-action=lang-menu], [data-action=method-menu]").forEach((btn) => {
      btn.setAttribute("aria-expanded", "false");
    });
  }

  function togglePopMenu(panelId) {
    const menu = $(panelId);
    if (!menu) return;
    const willOpen = menu.classList.contains("hidden");
    closePopMenus();
    if (willOpen) {
      menu.classList.remove("hidden");
      const wrap = menu.closest("[data-menu]");
      wrap?.querySelector("[aria-expanded]")?.setAttribute("aria-expanded", "true");
    }
  }

  function currentPathSlug() {
    if (state.route.name === "path") return state.route.params.slug || "";
    if (state.route.name === "watch") {
      return lessonById(state.route.params.id)?.pathSlug || "";
    }
    return "";
  }

  function methodNav() {
    const paths = state.bootstrap?.paths || [];
    if (!paths.length) return "";
    const current = currentPathSlug();
    const onMethod = !!current;
    const methodLabel = isNl() ? "Hoofdstukken" : "The Method";
    return `
      <div class="relative flex items-center" data-menu="method">
        <button type="button" data-action="method-menu" class="px-3 py-2 rounded-lg tap nav-hover flex items-center gap-1 ${onMethod ? "bg-card font-semibold" : ""}" aria-haspopup="menu" aria-expanded="false" aria-label="${esc(methodLabel)}">
          <span ${locAttr("The Method", "Hoofdstukken")}</span>
          <span class="text-muted text-xs leading-none">▾</span>
        </button>
        <div id="method-menu" data-menu-panel class="nav-drop hidden" role="menu">
          ${paths.map((p, i) => {
            const on = p.slug === current;
            const diffEn = p.difficulty || "";
            const diffNl = p.difficultyNl || diffEn;
            const num = p.n || i + 1;
            return `<a href="/path/${esc(p.slug)}" data-link role="menuitem"
              class="tap flex gap-2.5 w-full rounded-lg px-3 py-2 text-left nav-hover ${on ? "bg-card font-bold" : ""}"
              ${on ? 'aria-current="page"' : ""}>
              <span class="text-sm text-muted tabular-nums w-5 shrink-0 text-right pt-px">${num}</span>
              <span class="min-w-0 flex flex-col">
                <span class="text-sm leading-snug" ${locAttr(p.title, p.titleNl)}</span>
                ${diffEn ? `<span class="text-xs text-muted mt-0.5" ${locAttr(diffEn, diffNl)}</span>` : ""}
              </span>
            </a>`;
          }).join("")}
        </div>
      </div>`;
  }

  function langToggle() {
    const cur = langMeta();
    return `
      <div id="lang-wrap" class="relative shrink-0" data-menu="lang">
        <button type="button" data-action="lang-menu" class="tap text-sm font-bold flex items-center gap-1 px-3 py-2 rounded-lg nav-hover" aria-haspopup="listbox" aria-expanded="false" aria-label="Taal">
          <span data-lang-short>${esc(cur.short)}</span>
          <span class="text-muted text-xs">▾</span>
        </button>
        <div id="lang-menu" data-menu-panel class="hidden absolute right-0 mt-1 z-40 min-w-[11rem] rounded-xl bg-panel border border-line p-1 shadow-lg" role="listbox">
          ${LANGS.map((l) => {
            const on = l.index === cur.index;
            return `<button type="button" data-action="audio" data-index="${l.index}" role="option"
              class="tap flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm text-left ${on ? "bg-card font-bold" : "text-muted"}"
              aria-selected="${on ? "true" : "false"}">
              <span>${esc(l.label)}</span>
              <span class="text-xs font-bold">${on ? "✓" : esc(l.short)}</span>
            </button>`;
          }).join("")}
        </div>
      </div>`;
  }

  function pathOf() {
    return location.pathname.replace(/\/+$/, "") || "/";
  }

  function parseRoute() {
    const p = pathOf();
    if (p === "/" || p === "/profiles") return { name: "profiles", params: {} };
    if (p === "/home") return { name: "home", params: {} };
    if (p === "/history") return { name: "history", params: {} };
    if (p === "/stats") return { name: "stats", params: {} };
    if (p === "/practice") return { name: "practice", params: {} };
    let m = p.match(/^\/path\/([a-zA-Z0-9_-]+)$/);
    if (m) return { name: "path", params: { slug: m[1] } };
    m = p.match(/^\/watch\/(\d+)$/);
    if (m) return { name: "watch", params: { id: Number(m[1]) } };
    return { name: "home", params: {} };
  }

  function go(href, replace = false) {
    if (replace) history.replaceState({}, "", href);
    else history.pushState({}, "", href);
    state.route = parseRoute();
    render();
  }

  function scrollPageToTop() {
    const root = document.scrollingElement || document.documentElement;
    root.scrollTop = 0;
    document.documentElement.scrollTop = 0;
    document.body.scrollTop = 0;
    window.scrollTo(0, 0);
  }

  const IDLE_MS = 60 * 60 * 1000;
  const IDLE_KEY = "drumeo_last_active";

  function lastActiveMs() {
    try {
      const v = Number(localStorage.getItem(IDLE_KEY) || 0);
      return Number.isFinite(v) ? v : 0;
    } catch {
      return 0;
    }
  }

  function touchActive() {
    try { localStorage.setItem(IDLE_KEY, String(Date.now())); } catch {}
  }

  function isSessionIdle() {
    const last = lastActiveMs();
    if (!last) {
      touchActive();
      return false;
    }
    return Date.now() - last >= IDLE_MS;
  }

  function onProfilesScreen() {
    return state.route.name === "profiles";
  }

  function lockIfIdle() {
    if (!isSessionIdle()) return false;
    if (onProfilesScreen()) return true;
    teardownPlayer();
    go("/profiles", true);
    return true;
  }

  async function api(url, opts = {}) {
    const res = await fetch(url, {
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", ...(opts.headers || {}) },
      ...opts,
    });
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch { data = { raw: text }; }
    if (!res.ok) {
      const err = new Error((data && data.error) || res.statusText);
      err.status = res.status;
      err.body = data;
      throw err;
    }
    return data;
  }

  async function refresh() {
    state.bootstrap = await api("/app/bootstrap");
  }

  function sameId(a, b) {
    return String(a) === String(b);
  }

  function packAnchor(pack) {
    if (pack && pack.id != null && String(pack.id) !== "") return "pack-" + pack.id;
    const t = String(pack?.title || "more").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
    return "pack-" + (t || "more");
  }

  function methodSequence() {
    const b = state.bootstrap;
    if (!b) return [];
    const seen = new Set();
    const out = [];
    const push = (l, extra) => {
      if (!l || seen.has(String(l.id))) return;
      seen.add(String(l.id));
      out.push(extra ? { ...l, ...extra } : l);
    };
    if (b.intro) push(b.intro);
    for (const p of b.paths || []) {
      const extra = { pathSlug: p.slug, pathTitle: p.title };
      const list = p.lessons?.length
        ? p.lessons
        : (p.skillPacks || []).flatMap((pack) => pack.lessons || []);
      for (const l of list) push(l, extra);
    }
    return out;
  }

  function chapterContext(lesson, path) {
    const pathTitle = path?.title || lesson.pathTitle || "The Method";
    const pathSlug = path?.slug || lesson.pathSlug || null;
    const pathLessons = path?.skillPacks?.length
      ? path.skillPacks.flatMap((p) => p.lessons || [])
      : (path?.lessons || []);
    let title = pathTitle;
    let chapterLessons = pathLessons;
    let anchor = "";
    if (path?.skillPacks?.length && lesson.skillPackTitle) {
      const pack = path.skillPacks.find((p) => p.title === lesson.skillPackTitle);
      if (pack?.lessons?.length) {
        title = pack.title || title;
        chapterLessons = pack.lessons;
        anchor = packAnchor(pack);
      }
    }
    if (!chapterLessons.length) {
      chapterLessons = methodSequence();
      title = "The Method";
    }
    const seq = methodSequence();
    const seqIdx = seq.findIndex((l) => sameId(l.id, lesson.id));
    const chapIdx = chapterLessons.findIndex((l) => sameId(l.id, lesson.id));
    const total = chapterLessons.length || 1;
    const number = chapIdx >= 0 ? chapIdx + 1 : 1;
    const chapterNo = (item) => {
      const i = chapterLessons.findIndex((l) => sameId(l.id, item.id));
      return i >= 0 ? i + 1 : null;
    };
    const nearby = [];
    if (seqIdx < 0) {
      nearby.push({ role: "nu", lesson, chapterNumber: number });
    } else {
      const roles = { "-2": "eerder", "-1": "vorige", "0": "nu", "1": "volgende", "2": "daarna" };
      for (let off = -2; off <= 2; off++) {
        const i = seqIdx + off;
        if (i < 0 || i >= seq.length) continue;
        nearby.push({ role: roles[String(off)], lesson: seq[i], chapterNumber: chapterNo(seq[i]) });
      }
    }
    const globalN = lessonNo(lesson) || (seqIdx >= 0 ? seqIdx + 1 : number);
    const globalTotal = lessonTotal() || seq.length || total;
    return {
      title,
      titleNl: (path?.skillPacks?.length && lesson.skillPackTitle
        ? path.skillPacks.find((p) => p.title === lesson.skillPackTitle)?.titleNl
        : null) || path?.titleNl || title,
      pathTitle,
      pathTitleNl: path?.titleNl || pathTitle,
      pathSlug,
      packAnchor: anchor,
      number,
      total,
      globalN,
      globalTotal,
      pct: Math.round((globalN / globalTotal) * 100),
      nearby,
    };
  }

  function lessonNo(lesson) {
    if (!lesson) return null;
    const n = Number(lesson.n);
    if (n > 0) return n;
    const i = methodSequence().findIndex((l) => sameId(l.id, lesson.id));
    return i >= 0 ? i + 1 : null;
  }

  function lessonNumHtml(lesson) {
    const n = lessonNo(lesson);
    return n ? `<span class="lesson-num">${n}.</span> ` : "";
  }

  function lessonTotal() {
    const b = state.bootstrap;
    return (b?.order?.length || methodSequence().length || 0);
  }

  function lessonById(id) {
    const b = state.bootstrap;
    if (!b) return null;
    if (b.intro && sameId(b.intro.id, id)) return b.intro;
    for (const p of b.paths || []) {
      for (const l of p.lessons || []) {
        if (sameId(l.id, id)) {
          return { ...l, pathSlug: p.slug, pathTitle: p.title, pathTitleNl: p.titleNl || l.pathTitleNl };
        }
      }
    }
    for (const l of b.order || []) if (sameId(l.id, id)) return l;
    return state.lessonCache[id] || state.lessonCache[String(id)] || null;
  }

  function progressOf(id) {
    return (state.bootstrap?.progress || {})[String(id)] || null;
  }

  function isFollowed(progress, lesson) {
    if (!progress) return false;
    if (progress.watched) return true;
    const dur = progress.duration || lesson?.seconds || 0;
    const pos = progress.position || 0;
    return dur > 0 && pos / dur >= 0.33;
  }

  function noteOf(id) {
    const raw = state.bootstrap?.notes?.[String(id)];
    return typeof raw === "string" && raw.trim() ? raw : "";
  }

  function pencilIcon() {
    return `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>`;
  }

  function skipPrevIcon() {
    return `<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 6h2.2v12H6V6zm3.3 6 9.7 6.2V5.8L9.3 12z"/></svg>`;
  }

  function skipNextIcon() {
    return `<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 5.8v12.4L14.7 12 5 5.8zM16.8 6h2.2v12h-2.2V6z"/></svg>`;
  }

  function setNoteEditorOpen(open) {
    const editor = $("#note-editor");
    const view = $("#note-view");
    const pen = $("[data-action=note-edit]");
    const input = $("#note-input");
    if (!editor) return;
    const body = noteOf(state.route.params.id);
    editor.classList.toggle("hidden", !open);
    if (view) view.classList.toggle("hidden", open || !body);
    if (pen) {
      pen.classList.toggle("is-on", open || !!body);
      pen.setAttribute("aria-expanded", open ? "true" : "false");
    }
    const err = $("#note-error");
    if (err) {
      err.classList.add("hidden");
      err.textContent = "";
    }
    if (open && input) {
      input.value = body;
      input.focus();
      const len = input.value.length;
      try { input.setSelectionRange(len, len); } catch {}
    }
  }

  async function persistNote(body) {
    const lesson = state.player.lesson || lessonById(state.route.params.id);
    if (!lesson) return;
    const err = $("#note-error");
    try {
      const res = await api("/app/note", { method: "POST", body: JSON.stringify({ lessonId: lesson.id, body }) });
      const saved = typeof res.body === "string" ? res.body : String(body || "").trim();
      if (!state.bootstrap.notes) state.bootstrap.notes = {};
      if (saved) state.bootstrap.notes[String(lesson.id)] = saved;
      else delete state.bootstrap.notes[String(lesson.id)];
      const text = $("#note-text");
      if (text) text.textContent = saved;
      const clearBtn = $("[data-action=note-clear]");
      if (clearBtn) clearBtn.classList.toggle("hidden", !saved);
      const input = $("#note-input");
      if (input) input.value = saved;
      const pen = $("[data-action=note-edit]");
      if (pen) {
        const label = saved ? "Notitie bewerken" : "Notitie toevoegen";
        pen.setAttribute("aria-label", label);
        pen.setAttribute("title", label);
      }
      setNoteEditorOpen(false);
    } catch (e) {
      if (err) {
        err.textContent = e.message || "Kon notitie niet bewaren";
        err.classList.remove("hidden");
      }
    }
  }

  const SCORE_EMOJI = { 4: "🤩", 3: "😊", 2: "😕", 1: "😢" };

  function latestScoreOf(id) {
    const raw = state.bootstrap?.latestScore?.[String(id)];
    const n = Number(raw);
    return n >= 1 && n <= 4 ? n : null;
  }

  function thumbBadges(lessonId, { watched = false, compact = false, withEmoji = true } = {}) {
    const emoji = withEmoji ? SCORE_EMOJI[latestScoreOf(lessonId) || 0] : "";
    if (!emoji && !watched) return "";
    return `<div class="thumb-badges${compact ? " is-compact" : ""}">
      ${emoji ? `<span class="thumb-badge thumb-badge-emoji">${emoji}</span>` : ""}
      ${watched ? `<span class="thumb-badge thumb-badge-check">✓</span>` : ""}
    </div>`;
  }

  function available(vimeoId) {
    const id = String(vimeoId);
    return (state.bootstrap?.available || []).some((x) => String(x) === id);
  }

  const THUMB_WIDTHS = [160, 320, 640, 960, 1280, 1920];

  function thumbSrcset(vimeoId, format) {
    const id = encodeURIComponent(vimeoId);
    return THUMB_WIDTHS.map((w) => `/img/${id}/${w}.${format} ${w}w`).join(", ");
  }

  function thumbPic(vimeoId, { sizes = "320px", alt = "", eager = false } = {}) {
    if (!vimeoId) return "";
    const id = encodeURIComponent(vimeoId);
    const lazy = eager ? `fetchpriority="high"` : `loading="lazy"`;
    return `<picture>
      <source type="image/webp" srcset="${thumbSrcset(vimeoId, "webp")}" sizes="${esc(sizes)}">
      <img class="thumb-img" src="/img/${id}/640.jpg" srcset="${thumbSrcset(vimeoId, "jpg")}" sizes="${esc(sizes)}" alt="${esc(alt)}" decoding="async" ${lazy}>
    </picture>`;
  }

  function fmt(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${m}:${String(s).padStart(2, "0")}`;
  }

  function pct(id) {
    const p = progressOf(id);
    if (!p || !p.duration) return p?.watched ? 100 : 0;
    return Math.min(100, Math.round((p.position / p.duration) * 100));
  }

  function isApple() {
    const ua = navigator.userAgent || "";
    return /iPad|iPhone|iPod/.test(ua) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
  }

  function isHandheld() {
    if (isApple()) return true;
    const ua = navigator.userAgent || "";
    if (/Android|webOS|iPhone|iPad|iPod|Mobile|Tablet|Silk/i.test(ua) && !/Windows NT/i.test(ua)) return true;
    try {
      if (navigator.maxTouchPoints > 0 && window.matchMedia("(pointer: coarse)").matches) return true;
    } catch {}
    return false;
  }

  function usesNativeHls() {
    const ua = navigator.userAgent || "";
    const iOS = /iPad|iPhone|iPod/.test(ua);
    const iPadOS = navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1;
    const safari = /^((?!chrome|android|crios|fxios).)*safari/i.test(ua);
    return iOS || iPadOS || (safari && !/Chrome/.test(ua));
  }

  function isAutoplayBlock(err) {
    const name = err && err.name;
    const msg = String((err && err.message) || err || "");
    return name === "NotAllowedError" || /not allowed|user didn't interact|autoplay/i.test(msg);
  }

  function attachPlaylist(video, url) {
    if (!video || !url) return;
    if (video._vbHls) {
      try { video._vbHls.destroy(); } catch {}
      video._vbHls = null;
    }
    if (usesNativeHls()) {
      video.src = url;
      return;
    }
    const HlsClass = window.Hls;
    if (HlsClass && HlsClass.isSupported()) {
      const hls = new HlsClass({
        enableWorker: false,
        lowLatencyMode: false,
        liveDurationInfinity: false,
        startPosition: 0,
      });
      video._vbHls = hls;
      hls.loadSource(url);
      hls.attachMedia(video);
      return;
    }
    video.src = url;
  }

  function hevcOk() {
    const v = document.createElement("video");
    return !!v.canPlayType('video/mp4; codecs="hvc1.1.6.L93.B0"');
  }

  const AVC_ONLY_KEY = "drumeo_avc_only";

  function wantsSafeProfile() {
    if (state.avcOnly) return true;
    try { return sessionStorage.getItem(AVC_ONLY_KEY) === "1"; } catch { return false; }
  }

  function rememberSafeProfile() {
    state.avcOnly = true;
    try { sessionStorage.setItem(AVC_ONLY_KEY, "1"); } catch {}
  }

  function capabilities(safe) {
    const apple = isApple();
    const hevc = !safe && !wantsSafeProfile() && hevcOk();
    if (hevc) {
      return {
        protocols: ["hls"],
        videoCodecs: ["hvc1", "avc1"],
        audioCodecs: ["mp4a.40.2"],
        maxWidth: 3840,
        maxHeight: 2160,
        hdr: false,
        nativeHls: apple,
      };
    }
    return {
      protocols: ["hls"],
      videoCodecs: ["avc1"],
      audioCodecs: ["mp4a.40.2"],
      maxWidth: 1920,
      maxHeight: 1080,
      hdr: false,
      nativeHls: apple,
    };
  }

  function navLink(href, label, { mobile = false, cls = "" } = {}) {
    const on = pathOf() === href;
    const extra = cls ? ` ${cls}` : "";
    if (mobile) {
      return `<a href="${href}" data-link class="py-3 tap ${on ? "font-bold text-white" : "text-muted"}${extra}"${on ? ' aria-current="page"' : ""}>${esc(label)}</a>`;
    }
    return `<a href="${href}" data-link class="px-3 py-2 rounded-lg tap nav-hover ${on ? "bg-card font-semibold" : ""}${extra}"${on ? ' aria-current="page"' : ""}>${esc(label)}</a>`;
  }

  function layout(main, { nav = true } = {}) {
    const profile = state.bootstrap?.profile;
    const top = nav ? `
      <header class="site-header sticky top-0 z-30 bg-ink/90 border-b border-line">
        <div class="site-header-inner max-w-7xl mx-auto py-3 flex items-center gap-2 sm:gap-3 min-w-0">
          <a href="/home" data-link class="flex items-center tap shrink-0" aria-label="Home">
            <img src="/assets/logo-drumeo.svg?v=4f805dc6" alt="Drumeo" class="site-logo" width="96" height="24" decoding="async" draggable="false">
          </a>
          <nav class="flex items-center gap-1 text-sm min-w-0 flex-nowrap">
            ${navLink("/home", "Home", { cls: "hidden lg:flex items-center" })}
            ${methodNav()}
            ${navLink("/practice", "Oefenen", { cls: "hidden lg:flex items-center" })}
            ${navLink("/history", "Geschiedenis", { cls: "hidden lg:flex items-center" })}
            ${navLink("/stats", "Statistieken", { cls: "hidden lg:flex items-center" })}
          </nav>
          <div class="ml-auto flex items-center gap-2">
            ${profile ? langToggle() : ""}
            ${profile ? `<button data-action="switch-profile" class="tap rounded-full nav-hover" aria-label="Profiel wisselen">
              <span class="w-9 h-9 rounded-full ${COLORS[profile.slug] || "bg-accent"} grid place-items-center font-bold">${profile.name[0]}</span>
            </button>` : ""}
          </div>
        </div>
      </header>` : "";
    const bottom = nav ? `
      <nav class="nav-dock lg:hidden fixed bottom-0 inset-x-0 bg-panel/95 border-t border-line z-30">
        <div class="nav-mobile">
          ${navLink("/home", "Home", { mobile: true })}
          ${navLink("/practice", "Oefenen", { mobile: true })}
          ${navLink("/history", "Geschiedenis", { mobile: true })}
          ${navLink("/stats", "Stats", { mobile: true })}
          <button data-action="switch-profile" class="py-3 tap text-muted">Profiel</button>
        </div>
      </nav>` : "";
    return `${top}<main class="${nav ? "has-dock pb-24 lg:pb-10" : ""}">${main}</main>${bottom}`;
  }

  function esc(s) {
    return String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  }

  function hscroll(inner) {
    return `<div class="hscroll">
      <div class="hscroll-track flex gap-4 overflow-x-auto no-scrollbar pb-2">${inner}</div>
      <div class="hscroll-fade" aria-hidden="true"></div>
      <button type="button" class="hscroll-next" aria-label="Meer lessen">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
      </button>
    </div>`;
  }

  function wireHScroll() {
    $$(".hscroll").forEach((wrap) => {
      const track = wrap.querySelector(".hscroll-track");
      const next = wrap.querySelector(".hscroll-next");
      if (!track) return;
      const update = () => {
        const max = track.scrollWidth - track.clientWidth;
        wrap.classList.toggle("can-right", max > 12 && track.scrollLeft < max - 12);
      };
      track.addEventListener("scroll", update, { passive: true });
      next?.addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        const step = Math.min(track.clientWidth * 0.8, 336);
        track.scrollBy({ left: step, behavior: "smooth" });
      });
      requestAnimationFrame(update);
      if (typeof ResizeObserver !== "undefined") new ResizeObserver(update).observe(track);
    });
  }

  function card(lesson, { wide = false } = {}) {
    const p = progressOf(lesson.id);
    const watched = p?.watched;
    const have = available(lesson.vimeoId);
    const w = wide ? "min-w-[280px] w-[280px] sm:w-[320px]" : "w-full";
    return `
      <a href="/watch/${lesson.id}" data-link class="card-hover block ${w} rounded-2xl overflow-hidden bg-card border ${watched ? "card-watched" : "border-line"} transition-transform">
        <div class="relative aspect-video thumb overflow-hidden${watched ? " thumb-watched" : ""}">
          ${thumbPic(lesson.vimeoId, { sizes: wide ? "(min-width: 640px) 320px, 85vw" : "(min-width: 1024px) 25vw, (min-width: 768px) 33vw, 50vw", alt: disp(lesson) })}
          ${thumbBadges(lesson.id, { watched })}
          ${!have ? `<span class="absolute top-2 left-2 text-[11px] bg-black/70 px-2 py-1 rounded-full">Nog geen video</span>` : ""}
          <span class="absolute bottom-2 right-2 text-[11px] bg-black/70 px-2 py-0.5 rounded">${esc(lesson.length || fmt(lesson.seconds))}</span>
          <div class="absolute bottom-0 inset-x-0 progress-bar rounded-none"><span style="width:${pct(lesson.id)}%"></span></div>
        </div>
        <div class="p-3">
          <div class="font-semibold leading-snug line-clamp-2${watched ? " watched-title" : ""}">${lessonNumHtml(lesson)}${esc(disp(lesson))}</div>
          <div class="text-muted text-sm mt-1 line-clamp-1">${esc(disp(lesson, "skillPackTitle") || disp(lesson, "pathTitle"))}</div>
        </div>
      </a>`;
  }

  function fmtPractice(sec) {
    const s = Math.max(0, Math.round(Number(sec) || 0));
    if (s === 0) return "0 min";
    if (s < 60) return `${s} sec`;
    const m = Math.floor(s / 60);
    if (m < 60) return `${m} min`;
    const h = Math.floor(m / 60);
    const rm = m % 60;
    return rm ? `${h} u ${rm} min` : `${h} u`;
  }

  const WATCH_BUCKET_SEC = 3;

  function noteSeenBuckets(video) {
    if (!state.player) return;
    const pos = Number(video?.currentTime) || 0;
    const duration = Number(video?.duration);
    const prev = state.player.lastTickPos;
    state.player.lastTickPos = pos;
    if (prev == null || Number.isNaN(prev)) return;
    const delta = pos - prev;
    if (delta <= 0 || delta > 4) return;
    if (!state.player.seenBuckets) state.player.seenBuckets = new Set();
    const end = (isFinite(duration) && duration > 0) ? duration : pos;
    const from = Math.floor(Math.min(prev, end) / WATCH_BUCKET_SEC);
    const to = Math.floor(Math.min(pos, end) / WATCH_BUCKET_SEC);
    for (let b = from; b <= to; b++) {
      if (b >= 0) state.player.seenBuckets.add(b);
    }
  }

  function takePlayedDelta(video) {
    const pos = Number(video?.currentTime) || 0;
    if (state.player && (state.player.lastSavedPos == null || Number.isNaN(state.player.lastSavedPos))) {
      state.player.lastSavedPos = pos;
      return 0;
    }
    const prev = Number(state.player?.lastSavedPos) || 0;
    let delta = pos - prev;
    if (delta < 0) delta = 0;
    if (delta > 4) delta = 0;
    if (state.player) state.player.lastSavedPos = pos;
    return Math.round(delta * 1000) / 1000;
  }

  function relativePlayed(unix) {
    if (!unix) return "";
    const sec = Math.max(0, Math.floor(Date.now() / 1000 - unix));
    if (sec < 60) return "zojuist";
    if (sec < 3600) return `${Math.floor(sec / 60)} min. geleden`;
    if (sec < 86400) return `${Math.floor(sec / 3600)} u. geleden`;
    const days = Math.floor(sec / 86400);
    if (days === 1) return "gisteren";
    if (days < 7) return `${days} dagen geleden`;
    return new Date(unix * 1000).toLocaleDateString("nl-BE", { day: "numeric", month: "short" });
  }

  function historyItems() {
    const prog = Object.values(state.bootstrap?.progress || {});
    const out = [];
    for (const p of prog) {
      const lesson = lessonById(p.lessonId);
      if (!lesson || !isFollowed(p, lesson)) continue;
      const dur = p.duration || lesson.seconds || 0;
      const pos = p.position || 0;
      const ratio = dur > 0 ? pos / dur : 0;
      out.push({ lesson, progress: p, ratio: p.watched ? 1 : ratio });
    }
    out.sort((a, b) => (b.progress.updated || 0) - (a.progress.updated || 0));
    return out;
  }

  function lastPlayedLabel(unix) {
    if (!unix) return "Nog niet geoefend";
    const t = new Date(unix * 1000);
    const now = new Date();
    const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const startThen = new Date(t.getFullYear(), t.getMonth(), t.getDate());
    const days = Math.round((startToday - startThen) / 86400000);
    if (days <= 0) return "Laatst geoefend vandaag";
    if (days === 1) return "Laatst geoefend gisteren";
    if (days < 7) return `Laatst geoefend ${days} dagen geleden`;
    return `Laatst geoefend ${t.toLocaleDateString("nl-BE", { day: "numeric", month: "short" })}`;
  }

  function renderProfiles() {
    const profiles = state.bootstrap?.profiles || [];
    const root = $("#app");
    root.innerHTML = layout(`
      <div class="min-h-[80vh] grid place-items-center px-4 py-10">
        <div class="w-full max-w-4xl text-center">
          <p class="text-muted uppercase tracking-[0.2em] text-sm">Drumeo</p>
          <h1 class="text-3xl sm:text-5xl lg:text-6xl font-black mt-2 mb-8 sm:mb-10">Wie gaat er drummen?</h1>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            ${profiles.map((p) => {
              const total = Number(p.lessonCount) || 0;
              const done = Number(p.watchedCount) || 0;
              const pctDone = total ? Math.round((done / total) * 100) : 0;
              return `
              <button data-action="pick-profile" data-slug="${p.slug}" class="tap rounded-3xl bg-card border border-line p-8 hover:border-accent transition text-center">
                <span class="mx-auto w-28 h-28 rounded-full ${COLORS[p.slug] || "bg-accent"} grid place-items-center text-5xl font-black shadow-lg">${esc(p.name[0])}</span>
                <div class="mt-5 text-2xl font-bold">${esc(p.name)}</div>
                <div class="mt-2 text-muted text-sm">${lastPlayedLabel(p.lastPlayed)}</div>
                <div class="mt-3 text-sm">${done} / ${total} lessen</div>
                <div class="progress-bar mt-2"><span style="width:${pctDone}%"></span></div>
              </button>`;
            }).join("")}
          </div>
        </div>
      </div>`, { nav: false });
  }

  function renderHome() {
    const b = state.bootstrap;
    const resume = b.resume;
    const resumeLesson = resume ? lessonById(resume.lessonId) : null;
    const practice = b.practice || [];
    const hero = resumeLesson ? `
      <section class="relative overflow-hidden rounded-3xl bg-card border border-line mb-10">
        <div class="grid lg:grid-cols-2">
          <div class="relative aspect-video lg:aspect-auto lg:min-h-[280px] thumb overflow-hidden">
            ${thumbPic(resumeLesson.vimeoId, { sizes: "(min-width: 1024px) 50vw, 100vw", alt: disp(resumeLesson), eager: true })}
            <div class="absolute inset-0 bg-gradient-to-r from-card via-card/40 to-transparent hidden lg:block"></div>
          </div>
          <div class="p-5 sm:p-8 flex flex-col justify-center">
            <p class="text-accent text-sm font-semibold uppercase tracking-wide">Volgende les${lessonNo(resumeLesson) ? ` · les ${lessonNo(resumeLesson)} van ${lessonTotal()}` : ""}</p>
            <h2 class="text-2xl sm:text-3xl font-black mt-2">${lessonNumHtml(resumeLesson)}${esc(disp(resumeLesson))}</h2>
            <p class="text-muted mt-2">${esc(disp(resumeLesson, "pathTitle"))} · ${esc(langMeta().label)}</p>
            <a href="/watch/${resumeLesson.id}" data-link class="mt-6 inline-flex items-center justify-center rounded-full bg-white text-ink font-bold px-5 sm:px-6 py-3 tap w-fit whitespace-nowrap">
              Start volgende les
            </a>
          </div>
        </div>
      </section>` : "";

    const practiceRow = practice.length ? `
      <section class="mb-10">
        <div class="flex items-end justify-between mb-3">
          <h3 class="text-2xl font-bold">Opnieuw oefenen</h3>
          <a href="/practice" data-link class="text-muted text-sm">Alles</a>
        </div>
        ${hscroll(practice.slice(0, 12).map((l) => card(l, { wide: true })).join(""))}
      </section>` : "";

    const week = b.week || { days: [], streak: 0, playedToday: false };
    const inWeek = (date) => (week.days || []).some((d) => d.date === date);
    const selectedDate = (state.weekDay && inWeek(state.weekDay))
      ? state.weekDay
      : (week.days.find((d) => d.isToday)?.date || week.days[week.days.length - 1]?.date || null);
    const selectedDay = week.days.find((d) => d.date === selectedDate) || null;
    const dayFollowed = (selectedDay?.lessonIds || []).filter((id) => {
      const l = lessonById(id);
      return l && isFollowed(progressOf(id), l);
    });
    const nudge = !week.playedToday && week.streak > 0
      ? `Je reeks van ${week.streak} dagen wacht op vandaag.`
      : week.playedToday && week.streak > 1
        ? `${week.streak} dagen op rij. Keep going!`
        : week.playedToday
          ? "Les van vandaag zit erin. Morgen weer!"
          : "Nog geen les vandaag. Eén video telt al!";
    const weekRow = week.days.length ? `
      <section class="mb-10 rounded-3xl bg-card border border-line p-5 sm:p-6">
        <div class="flex items-end justify-between gap-3 mb-4">
          <div>
            <h3 class="text-2xl font-bold">Jouw week</h3>
            <p class="text-muted text-sm mt-1">${esc(nudge)}</p>
          </div>
          <p class="text-muted text-xs hidden sm:block">Elke dag een les houdt je groove scherp</p>
        </div>
        <div class="week-row">
          ${week.days.map((d) => {
            const on = selectedDate === d.date;
            const emojis = [...new Set((d.scores || []).map((s) => SCORE_EMOJI[s]).filter(Boolean))];
            const cls = `week-day${d.played ? " is-played" : ""}${d.isToday ? " is-today" : ""}${on ? " is-on" : ""}`;
            return `<button type="button" data-action="week-day" data-date="${esc(d.date)}" class="${cls}">
              <span class="week-dot"></span>
              <span class="week-emojis">${emojis.length ? emojis.map((e) => `<span>${e}</span>`).join("") : "&nbsp;"}</span>
              <span class="week-label"><span class="week-full">${esc(d.label)}</span><span class="week-short">${esc(d.labelShort || d.label)}</span></span>
            </button>`;
          }).join("")}
        </div>
        ${selectedDay ? `
          <div class="mt-5 pt-5 border-t border-line">
            <p class="font-semibold">${esc(selectedDay.label.charAt(0).toUpperCase() + selectedDay.label.slice(1))} · ${dayFollowed.length ? "dit speelde je" : "nog niks gespeeld"}</p>
            ${dayFollowed.length
              ? `<div class="mt-3 lesson-grid">
                  ${dayFollowed.map((id) => {
                    const l = lessonById(id);
                    return l ? card(l) : "";
                  }).join("")}
                </div>`
              : `<p class="text-muted text-sm mt-2">${selectedDay.isToday ? "Zet ’m op — één les is al een overwinning." : "Deze dag nog geen les."}</p>`}
          </div>` : ""}
      </section>` : "";

    const shows = `
      <section class="mb-10">
        <h3 class="text-2xl font-bold mb-4">The Method</h3>
        <div class="path-grid">
          ${(b.paths || []).map((p, i) => {
            const watched = (p.lessons || []).filter((l) => progressOf(l.id)?.watched).length;
            const poster = p.posterVimeoId || p.lessons?.[0]?.vimeoId;
            const pctDone = p.videoCount ? Math.round((watched / p.videoCount) * 100) : 0;
            const num = p.n || i + 1;
            return `
              <a href="/path/${p.slug}" data-link class="card-hover block rounded-2xl overflow-hidden bg-card border border-line">
                <div class="relative aspect-video thumb overflow-hidden">
                  ${thumbPic(poster, { sizes: "(min-width: 1024px) 30vw, (min-width: 640px) 45vw, 100vw", alt: disp(p) })}
                  <div class="absolute inset-0 bg-gradient-to-t from-black/70 to-transparent"></div>
                  <h3 class="absolute bottom-2 left-4 right-4 text-lg font-black"><span class="tabular-nums">${num}.</span> ${esc(disp(p))}</h3>
                </div>
                <div class="px-3 py-3">
                  <div class="flex justify-between text-xs text-muted mb-2">
                    <span>${esc(disp(p, "difficulty"))}</span>
                    <span>${watched}/${p.videoCount}</span>
                  </div>
                  <div class="progress-bar"><span style="width:${pctDone}%"></span></div>
                </div>
              </a>`;
          }).join("")}
        </div>
      </section>`;

    $("#app").innerHTML = layout(`
      <div class="max-w-7xl mx-auto px-4 pt-6">
        ${hero}
        ${practiceRow}
        ${weekRow}
        ${shows}
      </div>`);
  }

  function pathView() {
    return state.bootstrap?.pathView === "skill" ? "skill" : "order";
  }

  function pathViewToggle() {
    const view = pathView();
    const opts = [
      { id: "order", label: "Lesvolgorde" },
      { id: "skill", label: "Per skill" },
    ];
    return `<div class="seg" role="group" aria-label="Lesweergave">
      ${opts.map((o) => {
        const on = o.id === view;
        return `<button type="button" data-action="path-view" data-view="${o.id}" class="seg-btn${on ? " is-on" : ""}" aria-pressed="${on ? "true" : "false"}">${esc(o.label)}</button>`;
      }).join("")}
    </div>`;
  }

  function renderPath() {
    const slug = state.route.params.slug;
    const path = (state.bootstrap?.paths || []).find((p) => p.slug === slug);
    if (!path) {
      $("#app").innerHTML = layout(`<div class="p-8">Pad niet gevonden.</div>`);
      return;
    }
    const view = pathView();
    const lessons = path.lessons?.length
      ? path.lessons
      : (path.skillPacks || []).flatMap((p) => p.lessons || []);
    const packs = path.skillPacks?.length
      ? path.skillPacks
      : [{ title: path.title, titleNl: path.titleNl, lessons }];
    let body;
    if (view === "skill") {
      body = packs.map((pack) => `
        <section id="${esc(packAnchor(pack))}" class="mt-10" style="scroll-margin-top:6rem">
          <h2 class="text-xl font-bold mb-4">${esc(disp(pack) || "Lessen")}</h2>
          ${hscroll((pack.lessons || []).map((l) => card(l, { wide: true })).join(""))}
        </section>`).join("");
    } else {
      const seen = new Set();
      body = `<section class="mt-8">
        <div class="lesson-grid">
          ${lessons.map((l, i) => {
            const aid = packAnchor({ id: l.skillPackId, title: l.skillPackTitle });
            let idAttr = "";
            if (aid && !seen.has(aid)) {
              seen.add(aid);
              idAttr = ` id="${esc(aid)}" style="scroll-margin-top:6rem"`;
            }
            return `<div${idAttr}>${card(l)}</div>`;
          }).join("")}
        </div>
      </section>`;
    }
    $("#app").innerHTML = layout(`
      <div class="max-w-7xl mx-auto px-4 pt-6">
        <a href="/home" data-link class="text-muted text-sm">← Home</a>
        <div class="path-head">
          <div class="min-w-0">
            <h1 class="text-3xl sm:text-4xl font-black">${path.n ? `${path.n}. ` : ""}${esc(disp(path))}</h1>
            <p class="text-muted mt-2">${esc(disp(path, "difficulty"))} · ${path.videoCount} lessen</p>
          </div>
          ${pathViewToggle()}
        </div>
        <p class="mt-3 max-w-2xl">${esc(disp(path, "description"))}</p>
        ${body}
      </div>`);
  }

  function renderHistory() {
    const items = historyItems();
    $("#app").innerHTML = layout(`
      <div class="max-w-3xl mx-auto px-4 pt-6">
        <h1 class="text-3xl sm:text-4xl font-black">Afspeelgeschiedenis</h1>
        <p class="text-muted mt-2 mb-8">Alles wat je minstens een derde hebt bekeken, meest recent eerst.</p>
        ${items.length === 0
          ? `<div class="rounded-2xl bg-card border border-line p-8 text-muted">Nog geen geschiedenis. Speel een les tot minstens een derde om hem hier te zien.</div>`
          : `<div class="flex flex-col gap-3">
              ${items.map(({ lesson, progress, ratio }) => {
                const watched = progress.watched;
                const series = disp(lesson, "pathTitle") || "The Method";
                const skill = disp(lesson, "skillPackTitle");
                return `
                <a href="/watch/${lesson.id}" data-link class="flex gap-3 rounded-2xl overflow-hidden bg-card border ${watched ? "card-watched" : "border-line"} tap">
                  <div class="relative history-thumb shrink-0 aspect-video thumb overflow-hidden${watched ? " thumb-watched" : ""}">
                    ${thumbPic(lesson.vimeoId, { sizes: "176px", alt: disp(lesson) })}
                    ${thumbBadges(lesson.id, { watched, compact: true })}
                    <span class="absolute bottom-1 right-1 text-[11px] bg-black/70 px-2 py-0.5 rounded">${esc(lesson.length || fmt(lesson.seconds))}</span>
                    <div class="absolute bottom-0 inset-x-0 progress-bar rounded-none"><span style="width:${Math.round(ratio * 100)}%"></span></div>
                  </div>
                  <div class="min-w-0 py-3 pr-3 flex-1">
                    <div class="font-semibold leading-snug line-clamp-2${watched ? " watched-title" : ""}">${lessonNumHtml(lesson)}${esc(disp(lesson))}</div>
                    <div class="mt-1 text-sm leading-snug">
                      <div><span class="text-muted">Reeks</span> · ${esc(series)}</div>
                      ${skill ? `<div><span class="text-muted">Skill</span> · ${esc(skill)}</div>` : ""}
                    </div>
                    ${noteOf(lesson.id) ? `<div class="history-note">${esc(noteOf(lesson.id))}</div>` : ""}
                    <div class="text-muted text-xs mt-2">${Math.round(ratio * 100)}% · ${esc(relativePlayed(progress.updated))}</div>
                  </div>
                </a>`;
              }).join("")}
            </div>`}
      </div>`);
  }

  function emojiCounts(map) {
    const m = map || {};
    return [4, 3, 2, 1].map((s) => ({ score: s, emoji: SCORE_EMOJI[s], n: Number(m[s] || m[String(s)] || 0) }));
  }

  function growthInsight(em) {
    if (!em || !em.total) {
      return "Speel een les en kies een emoji. Dan zie je hier of het oefenen beter voelt.";
    }
    if (em.improved > em.declined) {
      return `Je groeit: ${em.improved} ${em.improved === 1 ? "les voelt" : "lessen voelen"} beter dan de eerste keer.`;
    }
    if (em.declined > em.improved) {
      return "Even tegenslag is oké. Opnieuw oefenen tilt je emoji omhoog.";
    }
    const latest = emojiCounts(em.latest);
    const top = [...latest].sort((a, b) => b.n - a.n)[0];
    if (top && top.n && top.score === 4) return "Alles voelt als 🤩. Jij bent on fire!";
    if (top && top.n) return `Meeste lessen voelen nu als ${top.emoji}.`;
    return "Blijf oefenen — je emoji vertelt hoe het gaat.";
  }

  function battleCopy(profiles, meId) {
    const ranked = [...(profiles || [])].sort((a, b) => (b.practiceSec || 0) - (a.practiceSec || 0));
    const lead = ranked[0];
    const me = ranked.find((p) => p.id === meId) || ranked.find((p) => p.isMe);
    if (!lead || !me) return "Oefen een les en je staat in de drum battle.";
    if ((lead.practiceSec || 0) <= 0) return "Nog niemand heeft geoefend. Wie zet de eerste minuten?";
    if (me.id === lead.id) {
      const second = ranked[1];
      if (second && second.practiceSec > 0) {
        return `Jij leidt. ${esc(second.name)} zit op ${fmtPractice(second.practiceSec)} — blijf voorop.`;
      }
      return "Jij leidt de drum battle. Wie durft je in te halen?";
    }
    const gap = Math.max(0, (lead.practiceSec || 0) - (me.practiceSec || 0));
    return `Nog ${fmtPractice(gap)} tot ${esc(lead.name)}. Dat is één les extra.`;
  }

  async function renderStats() {
    $("#app").innerHTML = layout(`<div class="grid place-items-center min-h-[50vh] text-muted">Laden…</div>`);
    let stats;
    try { stats = await api("/app/stats"); }
    catch (e) {
      $("#app").innerHTML = layout(`<div class="p-8">Kon statistieken niet laden. ${esc(e.message)}</div>`);
      return;
    }
    const me = stats.me || {};
    const em = me.emojis || {};
    const profiles = stats.profiles || [];
    const meId = me.profile?.id;
    const latest = emojiCounts(em.latest);
    const topN = Math.max(0, ...latest.map((x) => x.n));
    const week = me.week || [];
    const weekMax = Math.max(1, ...week.map((d) => d.practiceSec || 0));
    const battleMax = Math.max(1, ...profiles.map((p) => p.practiceSec || 0));
    const ranked = [...profiles].sort((a, b) => (b.practiceSec || 0) - (a.practiceSec || 0));
    const medals = {};
    ranked.forEach((p, i) => { medals[p.id] = ["🥇", "🥈", "🥉"][i] || ""; });
    const growthLessons = (em.lessons || []).filter((l) => l.first !== l.latest || l.count > 1);

    $("#app").innerHTML = layout(`
      <div class="max-w-7xl mx-auto px-4 pt-6">
        <h1 class="text-3xl sm:text-4xl font-black">Statistieken</h1>
        <p class="text-muted mt-2 mb-8">Jouw groei, oefentijd en een drum battle met de rest.</p>

        <section class="mb-10">
          <h2 class="text-2xl font-bold mb-4">Jouw oefentijd</h2>
          <div class="stat-cards">
            <div class="stat-card"><div class="k">Totaal</div><div class="v">${esc(fmtPractice(me.practiceSec))}</div></div>
            <div class="stat-card"><div class="k">Vandaag</div><div class="v">${esc(fmtPractice(me.practiceTodaySec))}</div></div>
            <div class="stat-card"><div class="k">Deze week</div><div class="v">${esc(fmtPractice(me.practiceWeekSec))}</div></div>
            <div class="stat-card"><div class="k">Lessen klaar</div><div class="v">${Number(me.lessonsWatched) || 0}<span class="text-muted text-sm font-semibold"> / ${Number(me.lessonsStarted) || 0} gestart</span></div></div>
          </div>
          <div class="rounded-2xl bg-card border border-line p-4 sm:p-5 mt-4">
            <div class="flex items-end justify-between gap-3 mb-3">
              <p class="font-semibold">Deze week</p>
              <p class="text-muted text-xs">${me.streak ? `${me.streak} dag${me.streak === 1 ? "" : "en"} op rij` : "Nog geen reeks"}</p>
            </div>
            <div class="week-bars">
              ${week.map((d) => {
                const sec = d.practiceSec || 0;
                const h = Math.max(sec > 0 ? 8 : 3, Math.round((sec / weekMax) * 100));
                return `<div class="week-bar${d.isToday ? " is-today" : ""}${d.isFuture ? " is-future" : ""}">
                  <div class="week-bar-fill" style="height:${d.isFuture ? 3 : h}%"></div>
                  <span class="lab"><span class="week-full">${esc(d.label)}</span><span class="week-short">${esc(d.labelShort || d.label)}</span></span>
                </div>`;
              }).join("")}
            </div>
          </div>
        </section>

        <section class="mb-10">
          <h2 class="text-2xl font-bold mb-1">Persoonlijke groei</h2>
          <p class="text-muted text-sm mb-4">${esc(growthInsight(em))}</p>
          <div class="emoji-mix">
            ${latest.map((x) => `
              <div class="emoji-tile${topN && x.n === topN ? " is-top" : ""}">
                <div class="face">${x.emoji}</div>
                <div class="n">${x.n}</div>
                <div class="text-muted text-xs mt-1">nu</div>
              </div>`).join("")}
          </div>
          ${growthLessons.length ? `
            <div class="rounded-2xl bg-card border border-line px-4 mt-4">
              ${growthLessons.map((l) => {
                const lesson = lessonById(l.lessonId);
                const title = lesson ? disp(lesson) : `Les ${l.lessonId}`;
                const same = l.first === l.latest;
                return `<div class="growth-row">
                  <div class="min-w-0">
                    <div class="font-semibold leading-snug line-clamp-2">${esc(title)}</div>
                    <div class="text-muted text-xs mt-1">${l.count} keer beoordeeld</div>
                  </div>
                  <div class="shrink-0 text-xl">${SCORE_EMOJI[l.first] || ""}${same ? "" : " → " + (SCORE_EMOJI[l.latest] || "")}</div>
                </div>`;
              }).join("")}
            </div>` : (em.total ? `<p class="text-muted text-sm mt-4">Nog geen verandering in emoji. Oefen een les opnieuw om groei te zien.</p>` : "")}
        </section>

        <section class="mb-10">
          <h2 class="text-2xl font-bold mb-1">Drum battle</h2>
          <p class="text-muted text-sm mb-5">${battleCopy(profiles, meId)}</p>
          <div class="rounded-2xl bg-card border border-line p-4 sm:p-6">
            <p class="text-xs uppercase tracking-wide text-muted mb-4">Oefentijd + emoji’s per drummer</p>
            <div class="battle-chart">
              ${profiles.map((p) => {
                const h = Math.max(p.practiceSec > 0 ? 6 : 0, Math.round((p.practiceSec / battleMax) * 100));
                const faces = emojiCounts(p.emojis?.all).filter((x) => x.n > 0);
                const emojiLine = faces.length
                  ? faces.map((x) => `${x.emoji}×${x.n}`).join(" ")
                  : "nog geen emoji";
                const rated = Number(p.emojis?.lessonsRated) || 0;
                return `<div class="battle-col${p.isMe ? " is-me" : ""}">
                  <div class="battle-val">${esc(fmtPractice(p.practiceSec))}</div>
                  <div class="battle-track" title="${esc(p.name)}: ${esc(fmtPractice(p.practiceSec))}">
                    <div class="battle-fill is-${esc(p.slug)}" style="height:${h}%"></div>
                  </div>
                  <div class="battle-name">${medals[p.id] || ""} ${esc(p.name)}</div>
                  <div class="battle-emojis">${emojiLine}<div class="mt-1">${rated} ${rated === 1 ? "les" : "lessen"} beoordeeld</div></div>
                </div>`;
              }).join("")}
            </div>
          </div>
        </section>
      </div>`);
  }

  function renderPractice() {
    const items = state.bootstrap?.practice || [];
    $("#app").innerHTML = layout(`
      <div class="max-w-7xl mx-auto px-4 pt-6">
        <h1 class="text-3xl sm:text-4xl font-black">Opnieuw oefenen</h1>
        <p class="text-muted mt-2 mb-8">Alles wat nog geen top-score kreeg. Oefenen mag altijd opnieuw — we bewaren elke score.</p>
        ${items.length === 0 ? `<div class="rounded-2xl bg-card border border-line p-8 text-muted">Nog niks hier. Speel een les en geef een score!</div>` : `
          <div class="lesson-grid">
            ${items.map((l) => card(l)).join("")}
          </div>`}
      </div>`);
  }

  async function renderWatch() {
    const id = state.route.params.id;
    let lesson = state.lessonCache[id] || lessonById(id);
    if (!lesson || !lesson.vimeoId) {
      try { lesson = await api(`/app/lesson/${id}`); state.lessonCache[id] = lesson; }
      catch { lesson = lessonById(id); }
    }
    if (!lesson) {
      $("#app").innerHTML = layout(`<div class="p-8">Les niet gevonden.</div>`);
      return;
    }
    const path = (state.bootstrap?.paths || []).find((p) => p.slug === lesson.pathSlug);
    const chapter = chapterContext(lesson, path);
    const prevLesson = chapter.nearby.find((i) => i.role === "vorige")?.lesson;
    const nextLesson = chapter.nearby.find((i) => i.role === "volgende")?.lesson;
    const have = available(lesson.vimeoId);
    const audioIndex = currentAudioIndex();
    const lang = langMeta(audioIndex);

    $("#app").innerHTML = layout(`
      <div class="max-w-7xl mx-auto px-3 sm:px-4 pt-3">
        <div class="grid lg:grid-cols-[minmax(0,1fr)_320px] xl:grid-cols-[minmax(0,1fr)_360px] gap-4">
          <section>
            <div id="stage" class="stage relative bg-black rounded-2xl overflow-hidden">
              <div id="player-slot" class="z-0 min-h-0 min-w-0"></div>
              <div id="prep" class="z-10 grid place-items-center bg-black/80 p-6 text-center">
                <div>
                  <div class="text-lg font-semibold">${have ? "Video wordt klaargezet in " + esc(lang.label) + "…" : "Deze video staat nog niet op de NAS"}</div>
                  <div id="prep-detail" class="text-muted mt-2 text-sm"></div>
                  <div class="progress-bar mt-4 max-w-sm mx-auto"><span id="prep-bar" style="width:0%"></span></div>
                </div>
              </div>
            </div>
            <div class="flex items-center justify-between gap-3 mt-3">
              ${prevLesson
                ? `<a href="/watch/${prevLesson.id}" data-link class="tap rounded-full bg-card px-4 py-2 text-sm border border-line inline-flex items-center gap-2">${skipPrevIcon()} Vorige</a>`
                : `<span class="rounded-full bg-card px-4 py-2 text-sm border border-line text-muted inline-flex items-center gap-2">${skipPrevIcon()} Vorige</span>`}
              ${nextLesson
                ? `<a href="/watch/${nextLesson.id}" data-link class="tap rounded-full bg-white text-ink font-bold px-4 py-2 text-sm inline-flex items-center gap-2">Volgende ${skipNextIcon()}</a>`
                : `<span class="rounded-full bg-card px-4 py-2 text-sm border border-line text-muted inline-flex items-center gap-2">Volgende ${skipNextIcon()}</span>`}
            </div>
          </section>
          <aside class="rounded-2xl bg-card border border-line overflow-hidden">
            <div class="p-4 border-b border-line">
              <div class="text-muted text-xs uppercase tracking-wide" ${locAttr(chapter.pathTitle, chapter.pathTitleNl)}</div>
              <div class="font-bold mt-0.5" ${locAttr(chapter.title, chapter.titleNl)}</div>
              <div class="text-sm mt-2">Les ${chapter.globalN} van ${chapter.globalTotal}</div>
              <div class="progress-bar mt-2" style="height:6px" role="progressbar" aria-valuenow="${chapter.globalN}" aria-valuemin="1" aria-valuemax="${chapter.globalTotal}" aria-label="Les ${chapter.globalN} van ${chapter.globalTotal}"><span style="width:${chapter.pct}%"></span></div>
            </div>
            ${chapter.nearby.map((item) => {
              const l = item.lesson;
              const on = item.role === "nu";
              const w = progressOf(l.id)?.watched;
              const roleLabel = item.role === "eerder" ? "2 geleden" : item.role === "vorige" ? "Vorige" : item.role === "nu" ? "Nu aan het kijken" : item.role === "volgende" ? "Volgende" : "Over 2";
              const body = `
                <div class="w-24 shrink-0 aspect-video rounded-lg thumb relative overflow-hidden${w ? " thumb-watched" : ""}">
                  ${thumbPic(l.vimeoId, { sizes: "96px", alt: disp(l) })}
                  ${thumbBadges(l.id, { watched: w, compact: true })}
                  ${on ? `<span class="absolute inset-0 rounded-lg ring-2 ring-accent"></span>` : ""}
                </div>
                <div class="min-w-0">
                  <div class="text-[11px] uppercase tracking-wide ${on ? "text-accent" : "text-muted"}">${roleLabel}</div>
                  <div class="font-semibold text-sm line-clamp-2 mt-0.5${w ? " watched-title" : ""}">${lessonNumHtml(l)}<span ${locAttr(l.title, l.titleNl)}</span></div>
                  <div class="text-muted text-xs mt-1">${esc(l.length || "")}</div>
                </div>`;
              const cls = `flex gap-3 p-3 border-b border-line ${on ? "bg-ink" : "tap"}`;
              return on
                ? `<div class="${cls}" aria-current="true">${body}</div>`
                : `<a href="/watch/${l.id}" data-link class="${cls}">${body}</a>`;
            }).join("")}
            ${chapter.pathSlug ? `<a href="/path/${chapter.pathSlug}${chapter.packAnchor ? "#" + chapter.packAnchor : ""}" data-link class="block p-4 text-sm text-accent tap">Alle lessen in dit hoofdstuk →</a>` : ""}
          </aside>
          <section class="pt-1">
            <div class="flex items-start gap-2">
              <h1 class="text-2xl sm:text-3xl font-black min-w-0 flex-1">${lessonNumHtml(lesson)}<span ${locAttr(lesson.title, lesson.titleNl)}</span></h1>
              <button type="button" data-action="note-edit" class="note-pen tap${noteOf(lesson.id) ? " is-on" : ""}" aria-expanded="false" aria-label="${noteOf(lesson.id) ? "Notitie bewerken" : "Notitie toevoegen"}" title="${noteOf(lesson.id) ? "Notitie bewerken" : "Notitie toevoegen"}">${pencilIcon()}</button>
            </div>
            <p class="text-muted mt-1">${esc([disp(lesson, "difficulty"), disp(lesson, "skillPackTitle"), lesson.instructor].filter(Boolean).join(" · "))}</p>
            <div id="note-view" class="${noteOf(lesson.id) ? "" : "hidden"}">
              <p id="note-text" class="note-body mt-3">${esc(noteOf(lesson.id))}</p>
            </div>
            <div id="note-editor" class="hidden mt-3">
              <textarea id="note-input" class="note-input" maxlength="2000" rows="4" placeholder="Jouw opmerking bij deze les…">${esc(noteOf(lesson.id))}</textarea>
              <div class="note-actions">
                <button type="button" data-action="note-save" class="tap rounded-full bg-white text-ink font-bold px-4 py-2 text-sm">Bewaren</button>
                <button type="button" data-action="note-cancel" class="tap rounded-full bg-card px-4 py-2 text-sm border border-line">Annuleren</button>
                <button type="button" data-action="note-clear" class="tap rounded-full px-4 py-2 text-sm text-muted${noteOf(lesson.id) ? "" : " hidden"}">Wissen</button>
              </div>
              <p id="note-error" class="hidden text-sm mt-2" style="color:#fb7185"></p>
            </div>
            ${lesson.description ? `<p class="mt-3">${esc(lesson.description)}</p>` : ""}
            <p class="mt-4"><a href="/app/notation.pdf" class="text-sm text-accent tap inline-flex" target="_blank" rel="noopener">Notatiesleutel (PDF)</a></p>
          </section>
        </div>
        <div id="next-modal" class="hidden fixed inset-0 z-50 bg-black/70 grid place-items-center p-4">
          <div class="w-full max-w-lg rounded-3xl bg-panel border border-line p-6 text-center">
            <p class="text-muted">Hoe ging deze les?</p>
            <div class="grid grid-cols-4 gap-3 my-5">
              ${[["1","😢","Moeilijk"],["2","😕","Matig"],["3","😊","Goed"],["4","🤩","Top"]].map(([s,e,l]) => `
                <button data-action="rate" data-score="${s}" class="tap rounded-2xl bg-card border border-line py-5 text-4xl">
                  <div>${e}</div><div class="text-xs mt-2 text-muted">${l}</div>
                </button>`).join("")}
            </div>
            <p id="next-title" class="font-bold text-lg"></p>
            <p class="text-muted text-sm mt-1">Volgende start over <span id="count">8</span>s</p>
            <div class="flex gap-3 justify-center mt-5">
              <button data-action="replay" class="tap rounded-full bg-card px-5 py-3 border border-line">Opnieuw</button>
              <button data-action="play-next" class="tap rounded-full bg-white text-ink font-bold px-5 py-3">Volgende</button>
            </div>
          </div>
        </div>
      </div>`);

    const savedStage = state.savedStage;
    state.savedStage = null;
    if (savedStage) {
      const fresh = $("#stage");
      if (fresh) fresh.replaceWith(savedStage);
    }
    const reuseVideo = savedStage ? savedStage.querySelector("video") : null;
    const alreadyOn = state.player.video && state.player.lesson && sameId(state.player.lesson.id, lesson.id);
    if (have && !alreadyOn) startPlayback(lesson, audioIndex, undefined, reuseVideo);
    else if (!have) {
      const d = $("#prep-detail");
      if (d) d.textContent = "Zodra het MKV-bestand binnen is, kun je hier kijken.";
    }
  }

  async function startPlayback(lesson, audioIndex, startAt, reuseVideo) {
    const slot = $("#player-slot");
    const prep = $("#prep");
    const detail = $("#prep-detail");
    const bar = $("#prep-bar");
    if (!slot) return;
    if (reuseVideo) {
      if (reuseVideo._vbBind) {
        try { reuseVideo._vbBind.abort(); } catch {}
        reuseVideo._vbBind = null;
      }
      unbindFullscreenWatch();
      if (state.prefetch) { clearInterval(state.prefetch); state.prefetch = null; }
      state.playGen++;
    } else {
      teardownPlayer();
    }
    const gen = ++state.playGen;
    const resumeAt = startAt ?? 0;
    let caps = capabilities(false);
    let usedSafe = wantsSafeProfile();
    if (prep) {
      prep.classList.remove("hidden");
      const title = prep.querySelector(".text-lg");
      if (title) title.textContent = "Video wordt klaargezet in " + langMeta(audioIndex).label + "…";
    }

    const tickPrep = (st) => {
      if (!detail) return;
      const ready = st.durationReadySec || 0;
      const total = st.progress?.durationTotalSec || lesson.seconds || 1;
      const done = st.state === "ready" && st.playlistUrl;
      const pctN = done ? 100 : Math.min(95, Math.round((ready / total) * 100));
      if (bar) bar.style.width = pctN + "%";
      detail.textContent = done
        ? "Klaar om te spelen"
        : (pctN > 0
          ? "Bezig met klaarzetten · " + pctN + "%"
          : "Bezig met klaarzetten…");
    };

    try {
      const run = async (c) => {
        const q = await api("/api/playback/query", { method: "POST", body: JSON.stringify({ videoId: String(lesson.vimeoId), audioIndex, capabilities: c }) });
        let st = await api("/api/playback/prepare", { method: "POST", body: JSON.stringify({ videoId: String(lesson.vimeoId), recipe: q.recipe, audioIndex, intent: "play" }) });
        tickPrep(st);
        while (!(st.state === "ready" && st.playlistUrl)) {
          if (st.state === "failed") throw Object.assign(new Error(st.error || "failed"), { body: st });
          await sleep(1000);
          st = await api(`/api/playback/status?videoId=${encodeURIComponent(lesson.vimeoId)}&recipe=${encodeURIComponent(st.recipe)}&audioIndex=${audioIndex}&intent=play`);
          tickPrep(st);
        }
        const player = await api(`/api/playback/player?videoId=${encodeURIComponent(lesson.vimeoId)}&recipe=${encodeURIComponent(st.recipe)}&audioIndex=${audioIndex}`);
        return { player, st };
      };
      if (gen !== state.playGen) return;

      let result;
      try { result = await run(caps); }
      catch (err) {
        if (!usedSafe) {
          rememberSafeProfile();
          usedSafe = true;
          caps = capabilities(true);
          result = await run(caps);
        } else throw err;
      }

      if (gen !== state.playGen) return;
      let video = reuseVideo;
      if (video) {
        attachPlaylist(video, result.st.playlistUrl);
      } else {
        slot.innerHTML = result.player.html;
        const script = document.createElement("script");
        script.textContent = result.player.js;
        slot.appendChild(script);
        video = slot.querySelector("video");
      }
      if (!video) throw new Error("no video tag");
      const wantFs = !!state.pendingFullscreen;
      state.pendingFullscreen = false;
      state.hadFullscreenThisClip = false;
      state.player = { video, lesson, recipe: result.st.recipe, audioIndex, safe: usedSafe, lastPos: null, lastTickPos: null, lastSavedPos: null, seenBuckets: new Set() };
      if (prep) prep.classList.add("hidden");
      bindVideo(video, lesson, resumeAt, usedSafe, wantFs);
      prefetchNext(lesson, audioIndex, caps);
    } catch (err) {
      if (detail) detail.textContent = "Kon de video niet klaarzetten: " + (err.body?.error || err.message);
    }
  }

  function bindVideo(video, lesson, resumeAt, alreadySafe, wantFs) {
    if (video._vbBind) {
      try { video._vbBind.abort(); } catch {}
    }
    const ac = new AbortController();
    video._vbBind = ac;
    const sig = { signal: ac.signal };
    let armed = false;
    const save = (force) => {
      if (!armed) return;
      const now = Date.now();
      if (!force && now - state.lastSave < 4000) return;
      state.lastSave = now;
      const duration = video.duration && isFinite(video.duration) ? video.duration : lesson.seconds;
      noteSeenBuckets(video);
      const playedDelta = takePlayedDelta(video);
      const playedBuckets = [...(state.player.seenBuckets || [])];
      api("/app/progress", {
        method: "POST",
        body: JSON.stringify({ lessonId: lesson.id, vimeoId: lesson.vimeoId, position: video.currentTime || 0, duration, playedDelta, playedBuckets }),
      }).then(async (r) => {
        const prev = progressOf(lesson.id);
        const wasWatched = !!prev?.watched;
        if (r.watched && state.bootstrap.progress) {
          state.bootstrap.progress[String(lesson.id)] = { ...(prev || {}), watched: true, position: video.currentTime, duration };
        }
        if (r.watched && !wasWatched && !state.player?.didWatchRefresh) {
          if (state.player) state.player.didWatchRefresh = true;
          await refresh();
          const p = state.player;
          if (p?.lesson) {
            prefetchNext(p.lesson, p.audioIndex ?? currentAudioIndex(), capabilities(!!p.safe));
          }
        }
      }).catch(() => {});
    };

    const tryResume = () => {
      const duration = video.duration;
      const seekable = isFinite(duration) && duration > 0;
      if (!seekable) return;
      if (resumeAt > 1 && resumeAt < duration - 5) {
        try { video.currentTime = resumeAt; } catch {}
      }
      if (state.player) {
        state.player.lastTickPos = video.currentTime || resumeAt || 0;
        state.player.lastSavedPos = video.currentTime || resumeAt || 0;
        if (!state.player.seenBuckets) state.player.seenBuckets = new Set();
      }
      armed = true;
    };
    video.addEventListener("loadedmetadata", tryResume, sig);
    video.addEventListener("durationchange", () => { if (!armed) tryResume(); }, sig);
    video.addEventListener("timeupdate", () => {
      noteSeenBuckets(video);
      save(false);
    }, sig);
    video.addEventListener("pause", () => save(true), sig);
    let endHandled = false;
    const onClipEnd = () => {
      if (endHandled) return;
      endHandled = true;
      save(true);
      state.pendingFullscreen = isVideoFullscreen(video) || !!state.hadFullscreenThisClip;
      whenExitedFullscreen(video, () => showNext(lesson));
    };
    video.addEventListener("ended", onClipEnd, sig);
    video.addEventListener("webkitendfullscreen", () => {
      if (video.ended) onClipEnd();
      else noteFullscreen(video, false);
    }, sig);
    video.addEventListener("webkitbeginfullscreen", () => noteFullscreen(video, true), sig);
    unbindFullscreenWatch();
    state.fsListener = () => noteFullscreen(video, isVideoFullscreen(video));
    document.addEventListener("fullscreenchange", state.fsListener);
    document.addEventListener("webkitfullscreenchange", state.fsListener);
    const fallback = () => {
      if (alreadySafe || state.player?.safe) return;
      rememberSafeProfile();
      startPlayback(lesson, state.player.audioIndex ?? currentAudioIndex(), video.currentTime || 0);
    };
    video.addEventListener("error", fallback, sig);
    window.addEventListener("pagehide", () => save(true), sig);
    let programmaticPlay = false;
    let firstPlayFsDone = false;
    video.addEventListener("play", () => {
      if (programmaticPlay) return;
      if (firstPlayFsDone || !isHandheld()) return;
      firstPlayFsDone = true;
      enterFullscreen(video);
    }, sig);
    programmaticPlay = true;
    const playP = video.play();
    const clearProg = () => { programmaticPlay = false; };
    if (playP && playP.finally) playP.finally(clearProg);
    else setTimeout(clearProg, 50);
    if (playP && playP.catch) playP.catch((err) => {
      clearProg();
      if (isAutoplayBlock(err)) return;
      fallback(err);
    });
    if (wantFs) {
      const goFs = () => {
        if (state.player?.video !== video) return;
        enterFullscreen(video);
      };
      video.addEventListener("playing", goFs, { once: true, signal: ac.signal });
      if (!video.paused) goFs();
    }
  }

  function fsElement() {
    return document.fullscreenElement || document.webkitFullscreenElement || null;
  }

  function isVideoFullscreen(video) {
    if (video?.webkitDisplayingFullscreen) return true;
    const el = fsElement();
    if (!el) return false;
    if (el === video) return true;
    const stage = $("#stage");
    return !!(stage && (el === stage || el.contains(video)));
  }

  function noteFullscreen(video, on) {
    if (on) {
      state.hadFullscreenThisClip = true;
      return;
    }
    const dur = Number(video?.duration) || 0;
    const t = Number(video?.currentTime) || 0;
    if (dur > 0 && t >= dur - 1.5) return;
    state.hadFullscreenThisClip = false;
  }

  function exitFullscreen(video) {
    try {
      if (video?.webkitDisplayingFullscreen && typeof video.webkitExitFullscreen === "function") {
        video.webkitExitFullscreen();
      }
    } catch {}
    if (document.fullscreenElement && document.exitFullscreen) {
      document.exitFullscreen().catch(() => {});
    } else if (document.webkitFullscreenElement && document.webkitExitFullscreen) {
      try { document.webkitExitFullscreen(); } catch {}
    }
  }

  function whenExitedFullscreen(video, cb) {
    if (!isVideoFullscreen(video)) {
      cb();
      return;
    }
    let done = false;
    const finish = () => {
      if (done) return;
      done = true;
      video.removeEventListener("webkitendfullscreen", finish);
      document.removeEventListener("fullscreenchange", finish);
      document.removeEventListener("webkitfullscreenchange", finish);
      cb();
    };
    video.addEventListener("webkitendfullscreen", finish);
    document.addEventListener("fullscreenchange", finish);
    document.addEventListener("webkitfullscreenchange", finish);
    exitFullscreen(video);
    setTimeout(finish, 700);
  }

  function requestDocFullscreen(video) {
    const stage = $("#stage");
    const target = stage || video;
    const req = target.requestFullscreen || target.webkitRequestFullscreen;
    if (typeof req === "function") {
      Promise.resolve(req.call(target)).catch(() => {
        const vreq = video.requestFullscreen || video.webkitRequestFullscreen;
        if (typeof vreq === "function") Promise.resolve(vreq.call(video)).catch(() => {});
      });
    }
  }

  function enterFullscreen(video) {
    if (!video || isVideoFullscreen(video)) return;
    let usedWebkit = false;
    try {
      if (typeof video.webkitEnterFullscreen === "function") {
        video.webkitEnterFullscreen();
        usedWebkit = true;
      }
    } catch {}
    if (!usedWebkit) {
      requestDocFullscreen(video);
      return;
    }
    setTimeout(() => {
      if (state.player?.video === video && !isVideoFullscreen(video)) requestDocFullscreen(video);
    }, 300);
  }

  function unbindFullscreenWatch() {
    if (!state.fsListener) return;
    document.removeEventListener("fullscreenchange", state.fsListener);
    document.removeEventListener("webkitfullscreenchange", state.fsListener);
    state.fsListener = null;
  }

  function nextIdOf(lesson) {
    const order = methodSequence();
    const i = order.findIndex((l) => sameId(l.id, lesson?.id));
    return i >= 0 && order[i + 1] ? order[i + 1].id : null;
  }

  function prefetchNext(lesson, audioIndex, caps) {
    const nextId = nextIdOf(lesson);
    if (!nextId) return;
    const next = lessonById(nextId);
    if (!next?.vimeoId || !available(next.vimeoId)) return;
    const body = { videoId: String(next.vimeoId), audioIndex, intent: "prefetch", capabilities: caps };
    api("/api/playback/prepare", { method: "POST", body: JSON.stringify(body) }).catch(() => {});
    if (state.prefetch) clearInterval(state.prefetch);
    state.prefetch = setInterval(() => {
      api("/api/playback/query", { method: "POST", body: JSON.stringify({ videoId: String(next.vimeoId), audioIndex, capabilities: caps }) })
        .then((q) => api(`/api/playback/status?videoId=${encodeURIComponent(next.vimeoId)}&recipe=${encodeURIComponent(q.recipe)}&audioIndex=${audioIndex}`))
        .catch(() => {});
    }, 5000);
  }

  function showNext(lesson) {
    const modal = $("#next-modal");
    if (!modal) return;
    const nid = nextIdOf(lesson);
    const next = nid ? lessonById(nid) : null;
    const title = $("#next-title");
    if (title) title.textContent = next ? disp(next) : "Einde van The Method — goed gedaan!";
    modal.classList.remove("hidden");
    let n = 8;
    const node = $("#count");
    if (state.countdown) clearInterval(state.countdown);
    if (!next) return;
    state.countdown = setInterval(() => {
      n -= 1;
      if (node) node.textContent = String(n);
      if (n <= 0) {
        clearInterval(state.countdown);
        state.countdown = null;
        advanceWatch(next);
      }
    }, 1000);
  }

  function advanceWatch(next) {
    if (!next) {
      go("/home");
      return;
    }
    if (state.countdown) {
      clearInterval(state.countdown);
      state.countdown = null;
    }
    $("#next-modal")?.classList.add("hidden");
    const video = state.player.video;
    if (video) {
      if (video._vbBind) {
        try { video._vbBind.abort(); } catch {}
        video._vbBind = null;
      }
      if (video._vbHls) {
        try { video._vbHls.destroy(); } catch {}
        video._vbHls = null;
      }
    }
    unbindFullscreenWatch();
    if (state.prefetch) { clearInterval(state.prefetch); state.prefetch = null; }
    history.pushState({}, "", `/watch/${next.id}`);
    state.route = parseRoute();
    const audioIndex = currentAudioIndex();
    const run = async () => {
      if (video && available(next.vimeoId)) {
        await startPlayback(next, audioIndex, undefined, video);
      }
      state.keepWatchStage = !!$("#stage") && !!state.player.video;
      render();
    };
    run();
  }

  function teardownPlayer() {
    state.playGen++;
    unbindFullscreenWatch();
    if (state.prefetch) { clearInterval(state.prefetch); state.prefetch = null; }
    if (state.countdown) { clearInterval(state.countdown); state.countdown = null; }
    const video = state.player.video;
    if (video) {
      if (video._vbBind) {
        try { video._vbBind.abort(); } catch {}
        video._vbBind = null;
      }
      try { if (video._vbHls) video._vbHls.destroy(); } catch {}
      try { video.pause(); video.removeAttribute("src"); video.load(); } catch {}
    }
    state.player = { video: null, lesson: null, recipe: null };
  }

  function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

  function paintLocalized() {
    $$("[data-nl]").forEach((el) => {
      if (el.children.length) return;
      const v = isNl() ? el.getAttribute("data-nl") : el.getAttribute("data-en");
      if (v != null) el.textContent = v;
    });
  }

  function paintLangToggles() {
    const cur = currentAudioIndex();
    const meta = langMeta(cur);
    const short = $("[data-lang-short]");
    if (short) short.textContent = meta.short;
    $$("[data-action=audio]").forEach((btn) => {
      const on = Number(btn.dataset.index) === cur;
      btn.setAttribute("aria-selected", on ? "true" : "false");
      btn.classList.toggle("bg-card", on);
      btn.classList.toggle("font-bold", on);
      btn.classList.toggle("text-muted", !on);
      const mark = btn.querySelector("span:last-child");
      if (mark) mark.textContent = on ? "✓" : (Number(btn.dataset.index) === 1 ? "NL" : "EN");
    });
    paintLocalized();
    const methodBtn = $("[data-action=method-menu]");
    if (methodBtn) methodBtn.setAttribute("aria-label", isNl() ? "Hoofdstukken" : "The Method");
  }

  function bind() {
    $$("[data-link]").forEach((a) => {
      a.addEventListener("click", (e) => {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
        e.preventDefault();
        go(a.getAttribute("href"));
      });
    });
    $$("[data-action]").forEach((el) => {
      el.addEventListener("click", onAction);
    });
    const noteInput = $("#note-input");
    if (noteInput) {
      noteInput.addEventListener("keydown", (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key === "Enter") {
          e.preventDefault();
          persistNote(noteInput.value);
        }
        if (e.key === "Escape") {
          e.preventDefault();
          setNoteEditorOpen(false);
        }
      });
    }
  }

  async function onAction(e) {
    const el = e.currentTarget;
    const action = el.getAttribute("data-action");
    if (action === "week-day") {
      const date = el.dataset.date;
      if (!date) return;
      state.weekDay = date;
      render();
      return;
    }
    if (action === "pick-profile") {
      await api("/app/profile", { method: "POST", body: JSON.stringify({ slug: el.dataset.slug }) });
      await refresh();
      touchActive();
      go("/home", true);
      return;
    }
    if (action === "switch-profile") {
      teardownPlayer();
      go("/profiles");
      return;
    }
    if (action === "path-view") {
      const view = el.dataset.view === "skill" ? "skill" : "order";
      if (pathView() === view) return;
      if (state.bootstrap) state.bootstrap.pathView = view;
      api("/app/path-view", { method: "POST", body: JSON.stringify({ view }) })
        .then((res) => {
          if (state.bootstrap && res?.pathView) state.bootstrap.pathView = res.pathView;
        })
        .catch(() => {});
      render();
      return;
    }
    if (action === "lang-menu") {
      e.stopPropagation();
      togglePopMenu("#lang-menu");
      return;
    }
    if (action === "method-menu") {
      e.stopPropagation();
      togglePopMenu("#method-menu");
      return;
    }
    if (action === "fullscreen") {
      const video = state.player.video;
      const stage = $("#stage");
      if (video?.webkitEnterFullscreen) { video.webkitEnterFullscreen(); return; }
      (stage || video)?.requestFullscreen?.().catch(() => {});
      return;
    }
    if (action === "audio") {
      closePopMenus();
      const idx = Number(el.dataset.index) === 1 ? 1 : 0;
      if (idx === currentAudioIndex() && state.route.name === "watch" && state.player.video) return;
      const t = state.player.video?.currentTime || 0;
      const lesson = state.player.lesson || lessonById(state.route.params.id);
      const res = await api("/app/audio", { method: "POST", body: JSON.stringify({ audioIndex: idx }) });
      if (state.bootstrap) {
        state.bootstrap.lastAudioIndex = res.audioIndex ?? idx;
        state.bootstrap.language = res.language || (idx === 1 ? "nl" : "en");
      }
      if (state.route.name === "watch" && lesson && available(lesson.vimeoId)) {
        startPlayback(lesson, idx, t);
        paintLangToggles();
      } else {
        render();
      }
      return;
    }
    if (action === "note-edit") {
      const editor = $("#note-editor");
      setNoteEditorOpen(!!editor && editor.classList.contains("hidden"));
      return;
    }
    if (action === "note-save") {
      persistNote($("#note-input")?.value || "");
      return;
    }
    if (action === "note-cancel") {
      setNoteEditorOpen(false);
      return;
    }
    if (action === "note-clear") {
      persistNote("");
      return;
    }
    if (action === "rate") {
      const lesson = state.player.lesson || lessonById(state.route.params.id);
      const score = Number(el.dataset.score);
      await api("/app/rating", { method: "POST", body: JSON.stringify({ lessonId: lesson.id, score }) });
      if (state.bootstrap) {
        state.bootstrap.latestScore = { ...(state.bootstrap.latestScore || {}), [String(lesson.id)]: score };
      }
      el.classList.add("ring-2", "ring-accent");
      return;
    }
    if (action === "replay") {
      if (state.countdown) clearInterval(state.countdown);
      $("#next-modal")?.classList.add("hidden");
      const lesson = state.player.lesson || lessonById(state.route.params.id);
      await api("/app/reset", { method: "POST", body: JSON.stringify({ lessonId: lesson.id }) });
      const wantFs = !!state.pendingFullscreen;
      state.pendingFullscreen = false;
      if (state.player.video) {
        state.player.video.currentTime = 0;
        state.player.video.play().catch(() => {});
        if (wantFs) enterFullscreen(state.player.video);
      } else {
        state.pendingFullscreen = wantFs;
        startPlayback(lesson, state.bootstrap.lastAudioIndex || 0, 0);
      }
      return;
    }
    if (action === "play-next") {
      const lesson = state.player.lesson || lessonById(state.route.params.id);
      const nid = nextIdOf(lesson);
      const next = nid ? lessonById(nid) : null;
      if (next) advanceWatch(next);
      else go("/home");
    }
  }

  async function render() {
    const keepStage = !!state.keepWatchStage;
    state.keepWatchStage = false;
    if (keepStage && $("#stage")) {
      const stage = $("#stage");
      stage.remove();
      state.savedStage = stage;
      unbindFullscreenWatch();
      if (state.prefetch) { clearInterval(state.prefetch); state.prefetch = null; }
      if (state.countdown) { clearInterval(state.countdown); state.countdown = null; }
      state.playGen++;
    } else {
      teardownPlayer();
      state.savedStage = null;
    }
    const route = state.route;
    if (route.name !== "watch") {
      state.pendingFullscreen = false;
      state.hadFullscreenThisClip = false;
    }
    if (!state.bootstrap) {
      $("#app").innerHTML = `<div class="grid place-items-center min-h-dvh text-muted">Laden…</div>`;
      try { await refresh(); } catch (e) {
        $("#app").innerHTML = `<div class="grid place-items-center min-h-dvh p-6">Kon de app niet laden. ${esc(e.message)}</div>`;
        return;
      }
    }
    if (!state.bootstrap.profile && route.name !== "profiles") {
      go("/profiles", true);
      return;
    }
    if (isSessionIdle()) {
      if (route.name !== "profiles") {
        go("/profiles", true);
        return;
      }
    } else if (state.bootstrap.profile && route.name === "profiles" && pathOf() === "/") {
      go("/home", true);
      return;
    }
    if (route.name === "profiles") renderProfiles();
    else if (route.name === "home") renderHome();
    else if (route.name === "path") renderPath();
    else if (route.name === "history") renderHistory();
    else if (route.name === "stats") await renderStats();
    else if (route.name === "practice") renderPractice();
    else if (route.name === "watch") await renderWatch();
    else renderHome();
    bind();
    wireHScroll();
    const hash = location.hash.replace(/^#/, "");
    if (hash) {
      requestAnimationFrame(() => {
        const el = document.getElementById(hash);
        if (!el) return;
        const headerH = document.querySelector("header")?.getBoundingClientRect().height || 64;
        const top = el.getBoundingClientRect().top + window.scrollY - headerH - 12;
        window.scrollTo({ top: Math.max(0, top), behavior: "smooth" });
      });
    } else {
      scrollPageToTop();
      requestAnimationFrame(() => {
        scrollPageToTop();
        requestAnimationFrame(scrollPageToTop);
      });
    }
  }

  if ("scrollRestoration" in history) history.scrollRestoration = "manual";

  window.addEventListener("popstate", () => {
    state.route = parseRoute();
    render();
  });

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") {
      touchActive();
      if (state.player.video && state.player.lesson) {
        const v = state.player.video;
        api("/app/progress", {
          method: "POST",
          body: JSON.stringify({
            lessonId: state.player.lesson.id,
            vimeoId: state.player.lesson.vimeoId,
            position: v.currentTime || 0,
            duration: v.duration || state.player.lesson.seconds,
            playedDelta: takePlayedDelta(v),
            playedBuckets: [...(state.player.seenBuckets || [])],
          }),
        }).catch(() => {});
      }
      return;
    }
    lockIfIdle();
  });

  window.addEventListener("pageshow", () => { lockIfIdle(); });
  window.addEventListener("focus", () => { lockIfIdle(); });
  setInterval(() => {
    if (document.visibilityState === "visible" && !isSessionIdle()) touchActive();
  }, 60 * 1000);

  document.addEventListener("click", (e) => {
    const inside = e.target.closest?.("[data-menu]");
    if (inside) return;
    closePopMenus();
  });

  state.route = parseRoute();
  render();
})();
