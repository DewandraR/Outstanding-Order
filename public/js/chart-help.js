/* ==========================================================================
   chart-help.js
   --------------------------------------------------------------------------
   - Inject ikon (i) informasi ke judul chart/kartu
   - Konten deskripsi diambil dari chart-help.json (dipassing via data-json)
   - Cara pakai di Blade/HTML:
       1) Tambahkan attribute data-help-key pada judul chart:
          <h5 class="card-title" data-help-key="po.outstanding_by_location">
            Outstanding Value by Location
          </h5>

          ATAU, jika ingin langsung tulis HTML tanpa JSON:
          <h5 class="card-title" data-help="<b>Judul</b><br>Deskripsi singkat...">...</h5>

       2) Script ini akan otomatis menambahkan tombol (i) + popover.
   ========================================================================== */
(function () {
    // ---------- Util: cari <script> ini & ambil atribut data ----------
    const thisScript =
        document.currentScript ||
        (function () {
            const scripts = document.getElementsByTagName("script");
            for (let i = scripts.length - 1; i >= 0; i--) {
                if ((scripts[i].src || "").indexOf("chart-help.js") !== -1)
                    return scripts[i];
            }
            return null;
        })();

    // URL JSON di-pass dari Blade -> aman utk subfolder
    const JSON_URL =
        (thisScript && thisScript.getAttribute("data-json")) ||
        "chart-help.json";

    // Class untuk tombol info
    const BTN_CLASS = "yz-info-btn";

    // ---------- State ----------
    let helpDict = {}; // { "po.outstanding_by_location": "<p>...</p>" , ... }
    let bootstrapReady = false;

    // ---------- Helpers ----------
    function log(...args) {
        if (window && window.console) console.log("[chart-help]", ...args);
    }
    function warn(...args) {
        if (window && window.console) console.warn("[chart-help]", ...args);
    }

    function createInfoButton(html) {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "yz-info-icon ms-1"; // Gunakan 'ms-1' atau 'ms-2' untuk margin kiri kecil
        btn.setAttribute("data-bs-toggle", "popover");
        btn.setAttribute("data-bs-trigger", "click");
        btn.setAttribute("data-bs-html", "true");
        btn.setAttribute("data-bs-container", "body");
        btn.setAttribute("title", "Tentang KPI Ini"); // Ubah judul popover jika perlu
        btn.setAttribute("data-bs-content", html);

        // KEMBALIKAN KE FAS FA-INFO-CIRCLE AGAR KOMPATIBEL DENGAN VERSI GRATIS
        btn.innerHTML = '<i class="fas fa-info-circle"></i>'; // Menggunakan ikon solid

        return btn;
    }

    function initPopovers(scope = document) {
        if (!window.bootstrap || !bootstrap.Popover) {
            bootstrapReady = false;
            return;
        }
        bootstrapReady = true;

        scope.querySelectorAll('[data-bs-toggle="popover"]').forEach((el) => {
            const inst = bootstrap.Popover.getInstance(el);
            if (inst) inst.dispose();
            new bootstrap.Popover(el);
        });
    }

    // Tutup popover saat klik di luar tombol (UX nice)
    function bindGlobalDismiss() {
        document.addEventListener("click", (e) => {
            document.querySelectorAll("." + BTN_CLASS).forEach((btn) => {
                if (!btn.contains(e.target)) {
                    const inst = bootstrap.Popover.getInstance(btn);
                    if (inst) inst.hide();
                }
            });
        });
    }

    // Ambil konten html dari:
    // - data-help (prioritas)
    // - data-help-key -> cari di helpDict
    function resolveHelpHtmlFromEl(el) {
        const inline = el.getAttribute("data-help");
        if (inline && String(inline).trim().length > 0) return inline;

        const key = el.getAttribute("data-help-key");
        if (!key) return null;

        const html = helpDict[key];
        if (typeof html === "string" && html.trim()) return html;

        // Kunci tidak ditemukan
        return `<div class="text-muted small">No help text for <code>${key}</code>.</div>`;
    }

    // Sisipkan tombol setelah element judul
    function attachButtonTo(el, html) {
        // Jangan dobel
        if (el.dataset.helpBound === "1") return;
        el.dataset.helpBound = "1";

        const btn = createInfoButton(html);

        // Kalau judul pakai flex, cukup append; kalau tidak, bungkus biar rapi
        const isInline =
            getComputedStyle(el).display.includes("inline") ||
            el.tagName.toLowerCase() === "span";

        if (isInline) {
            el.appendChild(btn);
        } else {
            // Letakkan di sebelah judul
            const wrap = document.createElement("span");
            wrap.className = "ms-1";
            wrap.appendChild(btn);
            el.appendChild(wrap);
        }
    }

    // Mode deklaratif: cari semua [data-help-key] atau [data-help]
    function attachAllFromAttributes(root = document) {
        const nodes = root.querySelectorAll("[data-help-key],[data-help]");
        nodes.forEach((el) => {
            const html = resolveHelpHtmlFromEl(el);
            if (!html) return;
            attachButtonTo(el, html);
        });
    }

    // (Opsional) Mode auto: kalau kamu belum sempat menambah data-help-key,
    // kita coba deteksi beberapa judul (bisa kamu hapus kalau tidak dipakai).
    // Mapping teks -> key JSON.
    const AUTO_MAP = [
        // PO
        {
            contains: "Outstanding Value by Location",
            key: "po.outstanding_by_location",
        },
        { contains: "PO Status Overview", key: "po.status_overview" },
        {
            contains: "Top 4 Customer with the most Outstanding value",
            key: "po.top_customers_value_usd",
        },
        {
            contains: "Top 4 Customers with Most Overdue PO",
            key: "po.top_customers_overdue",
        },
        {
            contains: "Outstanding PO & Performance Details by Type",
            key: "po.performance_details",
        },
        { contains: "Small Quantity", key: "po.small_qty_by_customer" },

        // SO
        {
            contains: "Value to Pacing vs Overdue by Location",
            key: "so.value_by_location_status",
        },
        { contains: "SO Fulfillment Urgency", key: "so.status_overview" },
        {
            contains:
                "Top 5 Customers by Value of Overdue Orders Awaiting Packing",
            key: "so.top_overdue_customers_value",
        },
        { contains: "SO Due This Week", key: "so.due_this_week_by_so" },
        {
            contains: "Customers Due This Week",
            key: "so.due_this_week_by_customer",
        },
        { contains: "Item with Remark", key: "so.items_with_remark" },
    ];

    function attachAutoByTitle(root = document) {
        // Cari elemen judul umum
        const candidates = root.querySelectorAll(
            "h1,h2,h3,h4,h5,h6,.card-title,.chart-title"
        );
        if (!candidates.length) return;

        AUTO_MAP.forEach(({ contains, key }) => {
            candidates.forEach((el) => {
                if (el.dataset.helpBound === "1") return; // sudah ada
                const text = (el.textContent || "").trim();
                if (!text) return;

                if (text.toLowerCase().includes(contains.toLowerCase())) {
                    const html =
                        helpDict[key] ||
                        `<div class="text-muted small">No help text for <code>${key}</code>.</div>`;
                    attachButtonTo(el, html);
                }
            });
        });
    }

    // ---------- Load JSON ----------
    async function loadHelpJson() {
        try {
            const res = await fetch(JSON_URL, { credentials: "same-origin" });
            if (!res.ok) throw new Error("HTTP " + res.status);
            const data = await res.json();
            // Normalisasi: terima bentuk flat maupun nested
            helpDict = flattenObject(data);
            log("Help JSON loaded:", helpDict);
        } catch (err) {
            warn("Failed to load chart-help.json from", JSON_URL, err);
            helpDict = {}; // tetap kosong, fallback ke data-help inline
        }
    }

    // Utility: flatten nested keys { po: { status_overview: "..." } } -> { "po.status_overview": "..." }
    function flattenObject(obj, prefix = "", out = {}) {
        Object.keys(obj || {}).forEach((k) => {
            const val = obj[k];
            const key = prefix ? prefix + "." + k : k;
            if (val && typeof val === "object" && !Array.isArray(val)) {
                flattenObject(val, key, out);
            } else {
                out[key] = val;
            }
        });
        return out;
    }

    // ---------- Main ----------
    async function run() {
        await loadHelpJson();

        // Tempel tombol untuk mode deklaratif
        attachAllFromAttributes(document);

        // Tempel tombol auto (fallback)
        attachAutoByTitle(document);

        // Inisialisasi popover
        initPopovers(document);

        // Tutup ketika klik di luar
        if (bootstrapReady) bindGlobalDismiss();

        // Jika ada konten dinamis yang dimount belakangan, expose API ringan:
        window.ChartHelp = {
            refresh(scope) {
                try {
                    const root = scope || document;
                    attachAllFromAttributes(root);
                    attachAutoByTitle(root);
                    initPopovers(root);
                } catch (e) {
                    warn("refresh error", e);
                }
            },
            // Bisa dipakai untuk update dict runtime (jarang perlu)
            setDict(dict) {
                helpDict = flattenObject(dict || {});
                this.refresh(document);
            },
        };
    }

    // Jalan saat DOM siap (script di-load dengan defer)
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", run, { once: true });
    } else {
        run();
    }
})();
