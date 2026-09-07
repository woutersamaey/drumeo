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

  function langToggle() {
    const cur = langMeta();
    return `
      <div id="lang-wrap" class="relative shrink-0">
        <button type="button" data-action="lang-menu" class="tap text-sm font-bold flex items-center gap-1" aria-haspopup="listbox" aria-label="Taal">
          <span data-lang-short>${esc(cur.short)}</span>
          <span class="text-muted text-xs">▾</span>
        </button>
        <div id="lang-menu" class="hidden absolute right-0 mt-1 z-40 min-w-[11rem] rounded-xl bg-panel border border-line p-1 shadow-lg" role="listbox">
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
      if (p.skillPacks?.length) {
        for (const pack of p.skillPacks) {
          for (const l of pack.lessons || []) push(l, extra);
        }
      } else {
        for (const l of p.lessons || []) push(l, extra);
      }
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
    if (seqIdx > 0) nearby.push({ role: "vorige", lesson: seq[seqIdx - 1], chapterNumber: chapterNo(seq[seqIdx - 1]) });
    nearby.push({ role: "nu", lesson: seqIdx >= 0 ? seq[seqIdx] : lesson, chapterNumber: number });
    if (seqIdx >= 0 && seqIdx < seq.length - 1) nearby.push({ role: "volgende", lesson: seq[seqIdx + 1], chapterNumber: chapterNo(seq[seqIdx + 1]) });
    return {
      title,
      pathTitle,
      pathSlug,
      packAnchor: anchor,
      number,
      total,
      pct: Math.round((number / total) * 100),
      nearby,
    };
  }

  function lessonById(id) {
    const b = state.bootstrap;
    if (!b) return null;
    if (b.intro && b.intro.id === id) return b.intro;
    for (const p of b.paths || []) {
      for (const l of p.lessons || []) if (l.id === id) return { ...l, pathSlug: p.slug, pathTitle: p.title };
    }
    for (const l of b.order || []) if (l.id === id) return l;
    return state.lessonCache[id] || null;
  }

  function progressOf(id) {
    return (state.bootstrap?.progress || {})[String(id)] || null;
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

  function layout(main, { nav = true } = {}) {
    const profile = state.bootstrap?.profile;
    const top = nav ? `
      <header class="sticky top-0 z-30 bg-ink/90 backdrop-blur border-b border-line">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center gap-3">
          <a href="/home" data-link class="flex items-center gap-2 tap">
            <span class="w-9 h-9 rounded-xl bg-accent grid place-items-center font-black">d</span>
            <span class="font-semibold tracking-tight hidden sm:block">The Method</span>
          </a>
          <nav class="hidden md:flex items-center gap-1 ml-4 text-sm">
            <a href="/home" data-link class="px-3 py-2 rounded-lg hover:bg-card tap">Home</a>
            <a href="/practice" data-link class="px-3 py-2 rounded-lg hover:bg-card tap">Opnieuw oefenen</a>
          </nav>
          <div class="ml-auto flex items-center gap-2">
            ${profile ? langToggle() : ""}
            ${profile ? `<button data-action="switch-profile" class="tap rounded-full" aria-label="Profiel wisselen">
              <span class="w-9 h-9 rounded-full ${COLORS[profile.slug] || "bg-accent"} grid place-items-center font-bold">${profile.name[0]}</span>
            </button>` : ""}
          </div>
        </div>
      </header>` : "";
    const bottom = nav ? `
      <nav class="md:hidden fixed bottom-0 inset-x-0 bg-panel/95 backdrop-blur border-t border-line z-30 pb-[env(safe-area-inset-bottom)]">
        <div class="grid grid-cols-3 text-center text-xs">
          <a href="/home" data-link class="py-3 tap">Home</a>
          <a href="/practice" data-link class="py-3 tap">Oefenen</a>
          <button data-action="switch-profile" class="py-3 tap">Profiel</button>
        </div>
      </nav>` : "";
    return `${top}<main class="${nav ? "pb-24 md:pb-10" : ""}">${main}</main>${bottom}`;
  }

  function esc(s) {
    return String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  }

  function card(lesson, { wide = false } = {}) {
    const p = progressOf(lesson.id);
    const watched = p?.watched;
    const have = available(lesson.vimeoId);
    const w = wide ? "min-w-[280px] w-[280px] sm:w-[320px]" : "w-full";
    return `
      <a href="/watch/${lesson.id}" data-link class="card-hover block ${w} rounded-2xl overflow-hidden bg-card border ${watched ? "card-watched" : "border-line"} transition-transform">
        <div class="relative aspect-video thumb overflow-hidden${watched ? " thumb-watched" : ""}">
          ${thumbPic(lesson.vimeoId, { sizes: wide ? "(min-width: 640px) 320px, 85vw" : "(min-width: 1024px) 25vw, (min-width: 768px) 33vw, 50vw", alt: lesson.title })}
          ${watched ? `<span class="absolute top-2 right-2 z-20 w-7 h-7 rounded-full bg-good text-ink grid place-items-center font-bold">✓</span>` : ""}
          ${!have ? `<span class="absolute top-2 left-2 text-[11px] bg-black/70 px-2 py-1 rounded-full">Nog geen video</span>` : ""}
          <span class="absolute bottom-2 right-2 text-[11px] bg-black/70 px-2 py-0.5 rounded">${esc(lesson.length || fmt(lesson.seconds))}</span>
          <div class="absolute bottom-0 inset-x-0 progress-bar rounded-none"><span style="width:${pct(lesson.id)}%"></span></div>
        </div>
        <div class="p-3">
          <div class="font-semibold leading-snug line-clamp-2${watched ? " watched-title" : ""}">${esc(lesson.title)}</div>
          <div class="text-muted text-sm mt-1 line-clamp-1">${esc(lesson.skillPackTitle || lesson.pathTitle || "")}</div>
        </div>
      </a>`;
  }

  function renderProfiles() {
    const profiles = state.bootstrap?.profiles || [];
    const root = $("#app");
    root.innerHTML = layout(`
      <div class="min-h-[80dvh] grid place-items-center px-4">
        <div class="w-full max-w-4xl text-center">
          <p class="text-muted uppercase tracking-[0.2em] text-sm">Drumeo</p>
          <h1 class="text-4xl sm:text-6xl font-black mt-2 mb-10">Wie gaat er drummen?</h1>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            ${profiles.map((p) => `
              <button data-action="pick-profile" data-slug="${p.slug}" class="tap rounded-3xl bg-card border border-line p-8 hover:border-accent transition">
                <span class="mx-auto w-28 h-28 rounded-full ${COLORS[p.slug] || "bg-accent"} grid place-items-center text-5xl font-black shadow-lg">${esc(p.name[0])}</span>
                <div class="mt-5 text-2xl font-bold">${esc(p.name)}</div>
                <div class="mt-2 text-muted text-sm">${Number(p.lastAudioIndex) === 0 ? "English" : "Nederlands"}</div>
              </button>`).join("")}
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
        <div class="grid md:grid-cols-2">
          <div class="relative aspect-video md:aspect-auto min-h-[220px] thumb overflow-hidden">
            ${thumbPic(resumeLesson.vimeoId, { sizes: "(min-width: 768px) 50vw, 100vw", alt: resumeLesson.title, eager: true })}
            <div class="absolute inset-0 bg-gradient-to-r from-card via-card/40 to-transparent hidden md:block"></div>
          </div>
          <div class="p-6 sm:p-8 flex flex-col justify-center">
            <p class="text-accent text-sm font-semibold uppercase tracking-wide">${resume.reason === "continue" ? "Verder kijken" : "Volgende les"}</p>
            <h2 class="text-3xl font-black mt-2">${esc(resumeLesson.title)}</h2>
            <p class="text-muted mt-2">${esc(resumeLesson.pathTitle || "")}${resume.reason === "continue" ? " · hervat op " + fmt(resume.position) : ""} · ${esc(langMeta().label)}</p>
            <a href="/watch/${resumeLesson.id}" data-link class="mt-6 inline-flex items-center justify-center rounded-full bg-white text-ink font-bold px-6 py-3 tap w-fit">
              ${resume.reason === "continue" ? "Doorgaan" : "Start"}
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
        <div class="flex gap-4 overflow-x-auto no-scrollbar pb-2">
          ${practice.slice(0, 12).map((l) => card(l, { wide: true })).join("")}
        </div>
      </section>` : "";

    const shows = (b.paths || []).map((p) => {
      const watched = (p.lessons || []).filter((l) => progressOf(l.id)?.watched).length;
      const poster = p.posterVimeoId || p.lessons?.[0]?.vimeoId;
      return `
        <a href="/path/${p.slug}" data-link class="card-hover block rounded-3xl overflow-hidden bg-card border border-line">
          <div class="grid sm:grid-cols-[1.4fr_1fr]">
            <div class="relative aspect-video thumb overflow-hidden">
              ${thumbPic(poster, { sizes: "(min-width: 640px) 58vw, 100vw", alt: p.title })}
              <div class="absolute inset-0 bg-gradient-to-t from-black/70 to-transparent"></div>
              <h3 class="absolute bottom-4 left-4 right-4 text-2xl font-black">${esc(p.title)}</h3>
            </div>
            <div class="p-5 flex flex-col justify-center gap-2 text-sm text-muted">
              <div>${esc(p.difficulty || "")}</div>
              <div>${p.videoCount} lessen · ${watched} klaar</div>
              <div class="progress-bar mt-2"><span style="width:${p.videoCount ? Math.round((watched / p.videoCount) * 100) : 0}%"></span></div>
              <p class="line-clamp-3">${esc(p.description || "")}</p>
            </div>
          </div>
        </a>`;
    }).join("");

    $("#app").innerHTML = layout(`
      <div class="max-w-7xl mx-auto px-4 pt-6">
        ${hero}
        ${practiceRow}
        <h3 class="text-2xl font-bold mb-4">The Method</h3>
        <div class="grid gap-6">${shows}</div>
      </div>`);
  }

  function renderPath() {
    const slug = state.route.params.slug;
    const path = (state.bootstrap?.paths || []).find((p) => p.slug === slug);
    if (!path) {
      $("#app").innerHTML = layout(`<div class="p-8">Pad niet gevonden.</div>`);
      return;
    }
    const packs = path.skillPacks?.length ? path.skillPacks : [{ title: path.title, lessons: path.lessons }];
    $("#app").innerHTML = layout(`
      <div class="max-w-7xl mx-auto px-4 pt-6">
        <a href="/home" data-link class="text-muted text-sm">← Home</a>
        <h1 class="text-4xl font-black mt-2">${esc(path.title)}</h1>
        <p class="text-muted mt-2">${esc(path.difficulty)} · ${path.videoCount} lessen</p>
        <p class="mt-3 max-w-2xl">${esc(path.description || "")}</p>
        ${packs.map((pack) => `
          <section id="${esc(packAnchor(pack))}" class="mt-10" style="scroll-margin-top:6rem">
            <h2 class="text-xl font-bold mb-4">${esc(pack.title || "Lessen")}</h2>
            <div class="flex gap-4 overflow-x-auto no-scrollbar pb-2">
              ${(pack.lessons || []).map((l) => card(l, { wide: true })).join("")}
            </div>
          </section>`).join("")}
      </div>`);
  }

  function renderPractice() {
    const items = state.bootstrap?.practice || [];
    $("#app").innerHTML = layout(`
      <div class="max-w-7xl mx-auto px-4 pt-6">
        <h1 class="text-4xl font-black">Opnieuw oefenen</h1>
        <p class="text-muted mt-2 mb-8">Alles wat nog geen top-score kreeg. Oefenen mag altijd opnieuw — we bewaren elke score.</p>
        ${items.length === 0 ? `<div class="rounded-2xl bg-card border border-line p-8 text-muted">Nog niks hier. Speel een les en geef een score!</div>` : `
          <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            ${items.map((l) => card(l)).join("")}
          </div>`}
      </div>`);
  }

  async function renderWatch() {
    const id = state.route.params.id;
    let lesson = state.lessonCache[id] || lessonById(id);
    if (!lesson || !lesson.vimeoId || !lesson.resources) {
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
        <div class="grid lg:grid-cols-[minmax(0,1fr)_320px] gap-4">
          <section>
            <div id="stage" class="relative bg-black rounded-2xl overflow-hidden aspect-video">
              <div id="player-slot" class="absolute inset-0 z-0"></div>
              <div id="prep" class="absolute inset-0 z-10 grid place-items-center bg-black/80 p-6 text-center">
                <div>
                  <div class="text-lg font-semibold">${have ? "Video wordt klaargezet in " + esc(lang.label) + "…" : "Deze video staat nog niet op de NAS"}</div>
                  <div id="prep-detail" class="text-muted mt-2 text-sm"></div>
                  <div class="progress-bar mt-4 max-w-sm mx-auto"><span id="prep-bar" style="width:0%"></span></div>
                </div>
              </div>
            </div>
            <div class="flex items-center justify-between gap-3 mt-3">
              ${prevLesson
                ? `<a href="/watch/${prevLesson.id}" data-link class="tap rounded-full bg-card px-4 py-2 text-sm border border-line">← Vorige</a>`
                : `<span class="rounded-full bg-card px-4 py-2 text-sm border border-line text-muted">← Vorige</span>`}
              ${nextLesson
                ? `<a href="/watch/${nextLesson.id}" data-link class="tap rounded-full bg-white text-ink font-bold px-4 py-2 text-sm">Volgende →</a>`
                : `<span class="rounded-full bg-card px-4 py-2 text-sm border border-line text-muted">Volgende →</span>`}
            </div>
          </section>
          <aside class="rounded-2xl bg-card border border-line overflow-hidden">
            <div class="p-4 border-b border-line">
              <div class="text-muted text-xs uppercase tracking-wide">${esc(chapter.pathTitle)}</div>
              <div class="font-bold mt-0.5">${esc(chapter.title)}</div>
              <div class="text-sm mt-2">Nu les ${chapter.number} van ${chapter.total}</div>
              <div class="progress-bar mt-2" style="height:6px" role="progressbar" aria-valuenow="${chapter.number}" aria-valuemin="1" aria-valuemax="${chapter.total}" aria-label="Les ${chapter.number} van ${chapter.total}"><span style="width:${chapter.pct}%"></span></div>
            </div>
            ${chapter.nearby.map((item) => {
              const l = item.lesson;
              const on = item.role === "nu";
              const w = progressOf(l.id)?.watched;
              const roleLabel = item.role === "vorige" ? "Vorige" : item.role === "nu" ? "Nu aan het kijken" : "Volgende";
              const num = item.chapterNumber ? `${item.chapterNumber}. ` : "";
              const body = `
                <div class="w-24 shrink-0 aspect-video rounded-lg thumb relative overflow-hidden${w ? " thumb-watched" : ""}">
                  ${thumbPic(l.vimeoId, { sizes: "96px", alt: l.title })}
                  ${w ? `<span class="absolute top-1 right-1 z-20 w-5 h-5 text-[11px] rounded-full bg-good text-ink grid place-items-center">✓</span>` : ""}
                  ${on ? `<span class="absolute inset-0 rounded-lg ring-2 ring-accent"></span>` : ""}
                </div>
                <div class="min-w-0">
                  <div class="text-[11px] uppercase tracking-wide ${on ? "text-accent" : "text-muted"}">${roleLabel}</div>
                  <div class="font-semibold text-sm line-clamp-2 mt-0.5${w ? " watched-title" : ""}">${num}${esc(l.title)}</div>
                  <div class="text-muted text-xs mt-1">${esc(l.length || "")}</div>
                </div>`;
              const cls = `flex gap-3 p-3 border-b border-line ${on ? "bg-ink" : "tap"}`;
              return on
                ? `<div class="${cls}" aria-current="true">${body}</div>`
                : `<a href="/watch/${l.id}" data-link class="${cls}">${body}</a>`;
            }).join("")}
            ${chapter.pathSlug ? `<a href="/path/${chapter.pathSlug}${chapter.packAnchor ? "#" + chapter.packAnchor : ""}" data-link class="block p-4 text-sm text-accent tap">Alle lessen in dit hoofdstuk →</a>` : ""}
          </aside>
          <section class="rounded-2xl bg-card border border-line p-5">
            <h1 class="text-2xl sm:text-3xl font-black">${esc(lesson.title)}</h1>
            <p class="text-muted mt-1">${esc([lesson.difficulty, lesson.skillPackTitle, lesson.instructor].filter(Boolean).join(" · "))}</p>
            ${lesson.description ? `<p class="mt-3">${esc(lesson.description)}</p>` : ""}
            ${(lesson.resources || []).length ? `<div class="mt-4"><p class="text-sm text-muted mb-2">Path resources</p>
              <div class="flex flex-col gap-2">
                ${(lesson.resources || []).map((r) => {
                  const pdf = /notation/i.test(r.resource_name || "");
                  const href = pdf ? "/app/notation.pdf" : (r.resource_url || "");
                  return href ? `<a class="rounded-xl bg-ink px-4 py-3 tap border border-line" href="${esc(href)}" target="_blank" rel="noopener">${esc(r.resource_name)}</a>` : `<div class="rounded-xl bg-ink px-4 py-3 border border-line">${esc(r.resource_name)}</div>`;
                }).join("")}
              </div></div>` : ""}
          </section>
        </div>
        <div id="next-modal" class="hidden fixed inset-0 z-50 bg-black/70 grid place-items-center p-4">
          <div class="w-full max-w-lg rounded-3xl bg-panel border border-line p-6 text-center">
            <p class="text-muted">Hoe ging deze les?</p>
            <div class="grid grid-cols-4 gap-3 my-5">
              ${[["4","🤩","Top"],["3","😊","Goed"],["2","😕","Matig"],["1","😢","Moeilijk"]].map(([s,e,l]) => `
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

    if (have) startPlayback(lesson, audioIndex);
    else {
      const d = $("#prep-detail");
      if (d) d.textContent = "Zodra het MKV-bestand binnen is, kun je hier kijken.";
    }
  }

  async function startPlayback(lesson, audioIndex, startAt) {
    const slot = $("#player-slot");
    const prep = $("#prep");
    const detail = $("#prep-detail");
    const bar = $("#prep-bar");
    if (!slot) return;
    teardownPlayer();
    const gen = ++state.playGen;
    const p = progressOf(lesson.id);
    const resumeAt = startAt ?? (p && !p.watched ? p.position : 0);
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
      const pctN = Math.min(95, Math.round((ready / total) * 100));
      if (bar) bar.style.width = (st.playlistUrl ? 100 : pctN) + "%";
      detail.textContent = st.playlistUrl
        ? "Klaar om te spelen"
        : `${st.state || "starting"} · ${st.segmentCount || 0} segmenten`;
    };

    try {
      const run = async (c) => {
        const q = await api("/api/playback/query", { method: "POST", body: JSON.stringify({ videoId: String(lesson.vimeoId), audioIndex, capabilities: c }) });
        let st = await api("/api/playback/prepare", { method: "POST", body: JSON.stringify({ videoId: String(lesson.vimeoId), recipe: q.recipe, audioIndex, intent: "play" }) });
        tickPrep(st);
        while (!st.playlistUrl) {
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
      slot.innerHTML = result.player.html;
      const script = document.createElement("script");
      script.textContent = result.player.js;
      slot.appendChild(script);
      const video = slot.querySelector("video");
      if (!video) throw new Error("no video tag");
      state.player = { video, lesson, recipe: result.st.recipe, audioIndex, safe: usedSafe };
      if (prep) prep.classList.add("hidden");
      bindVideo(video, lesson, resumeAt, usedSafe);
      prefetchNext(lesson, audioIndex, caps);
    } catch (err) {
      if (detail) detail.textContent = "Kon de video niet klaarzetten: " + (err.body?.error || err.message);
    }
  }

  function bindVideo(video, lesson, resumeAt, alreadySafe) {
    const save = (force) => {
      const now = Date.now();
      if (!force && now - state.lastSave < 4000) return;
      state.lastSave = now;
      const duration = video.duration && isFinite(video.duration) ? video.duration : lesson.seconds;
      api("/app/progress", {
        method: "POST",
        body: JSON.stringify({ lessonId: lesson.id, vimeoId: lesson.vimeoId, position: video.currentTime || 0, duration }),
      }).then(async (r) => {
        if (r.watched && state.bootstrap.progress) {
          state.bootstrap.progress[String(lesson.id)] = { ...(progressOf(lesson.id) || {}), watched: true, position: video.currentTime, duration };
        }
      }).catch(() => {});
    };

    video.addEventListener("loadedmetadata", () => {
      if (resumeAt > 1 && resumeAt < (video.duration || lesson.seconds) - 5) {
        try { video.currentTime = resumeAt; } catch {}
      }
    });
    video.addEventListener("timeupdate", () => save(false));
    video.addEventListener("pause", () => save(true));
    video.addEventListener("ended", () => {
      save(true);
      api("/app/watched", { method: "POST", body: JSON.stringify({ lessonId: lesson.id, duration: video.duration || lesson.seconds }) }).catch(() => {});
      showNext(lesson);
    });
    const fallback = () => {
      if (alreadySafe || state.player?.safe) return;
      rememberSafeProfile();
      startPlayback(lesson, state.player.audioIndex ?? currentAudioIndex(), video.currentTime || 0);
    };
    video.addEventListener("error", fallback);
    window.addEventListener("pagehide", () => save(true));
    const playP = video.play();
    if (playP && playP.catch) playP.catch(fallback);
  }

  function tryEnterFullscreen(video) {
    const stage = $("#stage");
    try {
      if (video.webkitEnterFullscreen && isApple()) {
        // iOS: user gesture already happened on play; enter on video element
        return;
      }
      if (stage && !document.fullscreenElement && stage.requestFullscreen) {
        stage.requestFullscreen().catch(() => {});
      }
    } catch {}
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
    if (title) title.textContent = next ? next.title : "Einde van The Method — goed gedaan!";
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
        go(`/watch/${next.id}`);
      }
    }, 1000);
  }

  function teardownPlayer() {
    state.playGen++;
    if (state.prefetch) { clearInterval(state.prefetch); state.prefetch = null; }
    if (state.countdown) { clearInterval(state.countdown); state.countdown = null; }
    const video = state.player.video;
    if (video) {
      try { if (video._vbHls) video._vbHls.destroy(); } catch {}
      try { video.pause(); video.removeAttribute("src"); video.load(); } catch {}
    }
    state.player = { video: null, lesson: null, recipe: null };
  }

  function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

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
  }

  async function onAction(e) {
    const el = e.currentTarget;
    const action = el.getAttribute("data-action");
    if (action === "pick-profile") {
      await api("/app/profile", { method: "POST", body: JSON.stringify({ slug: el.dataset.slug }) });
      await refresh();
      go("/home", true);
      return;
    }
    if (action === "switch-profile") {
      teardownPlayer();
      go("/profiles");
      return;
    }
    if (action === "lang-menu") {
      e.stopPropagation();
      $("#lang-menu")?.classList.toggle("hidden");
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
      $("#lang-menu")?.classList.add("hidden");
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
    if (action === "rate") {
      const lesson = state.player.lesson || lessonById(state.route.params.id);
      await api("/app/rating", { method: "POST", body: JSON.stringify({ lessonId: lesson.id, score: Number(el.dataset.score) }) });
      el.classList.add("ring-2", "ring-accent");
      return;
    }
    if (action === "replay") {
      if (state.countdown) clearInterval(state.countdown);
      $("#next-modal")?.classList.add("hidden");
      const lesson = state.player.lesson || lessonById(state.route.params.id);
      await api("/app/reset", { method: "POST", body: JSON.stringify({ lessonId: lesson.id }) });
      if (state.player.video) {
        state.player.video.currentTime = 0;
        state.player.video.play().catch(() => {});
      } else startPlayback(lesson, state.bootstrap.lastAudioIndex || 0, 0);
      return;
    }
    if (action === "play-next") {
      if (state.countdown) clearInterval(state.countdown);
      const lesson = state.player.lesson || lessonById(state.route.params.id);
      const nid = nextIdOf(lesson);
      if (nid) go(`/watch/${nid}`);
      else go("/home");
    }
  }

  async function render() {
    teardownPlayer();
    const route = state.route;
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
    if (state.bootstrap.profile && route.name === "profiles" && pathOf() === "/") {
      go("/home", true);
      return;
    }
    if (route.name === "profiles") renderProfiles();
    else if (route.name === "home") renderHome();
    else if (route.name === "path") renderPath();
    else if (route.name === "practice") renderPractice();
    else if (route.name === "watch") await renderWatch();
    else renderHome();
    bind();
    const hash = location.hash.replace(/^#/, "");
    if (hash) {
      requestAnimationFrame(() => {
        const el = document.getElementById(hash);
        if (!el) return;
        const headerH = document.querySelector("header")?.getBoundingClientRect().height || 64;
        const top = el.getBoundingClientRect().top + window.scrollY - headerH - 12;
        window.scrollTo({ top: Math.max(0, top), behavior: "smooth" });
      });
    }
  }

  window.addEventListener("popstate", () => {
    state.route = parseRoute();
    render();
  });

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden" && state.player.video && state.player.lesson) {
      const v = state.player.video;
      api("/app/progress", {
        method: "POST",
        body: JSON.stringify({
          lessonId: state.player.lesson.id,
          vimeoId: state.player.lesson.vimeoId,
          position: v.currentTime || 0,
          duration: v.duration || state.player.lesson.seconds,
        }),
      }).catch(() => {});
    }
  });

  document.addEventListener("click", (e) => {
    const wrap = $("#lang-wrap");
    const menu = $("#lang-menu");
    if (!menu || menu.classList.contains("hidden")) return;
    if (wrap && wrap.contains(e.target)) return;
    menu.classList.add("hidden");
  });

  state.route = parseRoute();
  render();
})();
