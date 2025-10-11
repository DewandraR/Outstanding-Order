/* public/js/po-report-remark-highlight.js */
(function () {
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    const onlyDigits = (s) => String(s || "").replace(/\D/g, "");
    const pad = (s, n) => onlyDigits(s).padStart(n, "0");

    async function run() {
        const root = document.getElementById("yz-root");
        if (!root) return;

        const mustExpand = parseInt(root.dataset.autoExpand || 0) === 1;
        const kunnr = (root.dataset.hKunnr || "").trim(); // wajib ada
        const vbeln = (root.dataset.hVbeln || "").trim();
        const bstnk = (root.dataset.hBstnk || "").trim();
        const posnr = (root.dataset.hPosnr || "").trim();

        if (!mustExpand || !kunnr) return;

        // 1) Buka kartu customer
        const kunnr10 = pad(kunnr, 10);
        let custCard = null,
            tries = 0;
        while (!custCard && tries++ < 60) {
            custCard =
                document.querySelector(
                    `.yz-customer-card[data-kunnr="${kunnr10}"]`
                ) ||
                document.querySelector(
                    `.yz-customer-card[data-kunnr$="${onlyDigits(kunnr)}"]`
                );
            if (!custCard) await wait(100);
        }
        if (!custCard) return;

        if (!custCard.classList.contains("is-open")) custCard.click();

        // 2) Tunggu Tabel-2 render
        const slot = document.getElementById(custCard.dataset.kid);
        let t2rows = null;
        tries = 0;
        while (tries++ < 80) {
            t2rows = slot.querySelectorAll(".js-t2row");
            if (t2rows.length) break;
            await wait(100);
        }
        if (!t2rows || !t2rows.length) return;

        // 3) Cari baris SO target (by VBELN atau PO/BSTNK)
        let targetRow = null;
        if (vbeln)
            targetRow = [...t2rows].find(
                (r) => (r.dataset.vbeln || "").trim() === vbeln
            );
        if (!targetRow && bstnk) {
            targetRow = [...t2rows].find(
                (r) =>
                    (
                        r.querySelector(".text-start .fw-bold")?.textContent ||
                        ""
                    ).trim() === bstnk
            );
        }
        targetRow = targetRow || t2rows[0];
        if (!targetRow) return;

        // 4) LOGIKA FOKUS MODE BARU: Panggil handler PO yang ada di Blade.
        // Ini memastikan mode fokus/collapse otomatis aktif.

        // Jika tidak ada POSNR, hanya scroll dan highlight T2 (tanpa membuka T3)
        if (!posnr) {
            if (window.scrollAndFlash) {
                window.scrollAndFlash(targetRow);
            }
            return;
        }

        // Jika ADA POSNR, kita HARUS membuka T3 dan mengaktifkan Focus Mode.
        const nest = targetRow.nextElementSibling;
        const soTbody = targetRow.closest("tbody");
        const caret = targetRow.querySelector(".yz-caret");

        if (!nest || nest.style.display === "none") {
            // Kita akan meniru bagian pembuka dari handleSoRowClick:

            // 1. Tutup semua T3 lain di T2 yang sama dan nonaktifkan fokus/is-focused (manual, karena kita tidak memanggil click baris)
            soTbody.querySelectorAll(".yz-nest").forEach((otherNest) => {
                if (otherNest !== nest && otherNest.style.display !== "none") {
                    otherNest.style.display = "none";
                    otherNest.previousElementSibling
                        .querySelector(".yz-caret")
                        ?.classList.remove("rot");
                    otherNest.previousElementSibling.classList.remove(
                        "is-focused"
                    );
                }
            });

            // 2. Terapkan MODE FOKUS ke tbody dan baris target
            soTbody.classList.add("so-focus-mode");
            targetRow.classList.add("is-focused");
            nest.style.display = "";
            caret?.classList.add("rot");

            // 3. Panggil fungsi untuk memuat T3 (karena kita tidak memanggil targetRow.click())
            if (window.openItemsIfNeededForSORow) {
                await window.openItemsIfNeededForSORow(targetRow);
            } else {
                // Fallback jika helper openItemsIfNeededForSORow tidak tersedia
                targetRow.click();
            }
        } else {
            // Jika sudah terbuka (kasus jarang di auto-expand, tapi untuk jaga-jaga)
            soTbody.classList.add("so-focus-mode");
            targetRow.classList.add("is-focused");
            if (window.openItemsIfNeededForSORow) {
                await window.openItemsIfNeededForSORow(targetRow);
            }
        }

        // 5) Tunggu item Tabel-3 loaded
        let loaded = nest?.dataset.loaded === "1";
        tries = 0;
        while (!loaded && tries++ < 80) {
            loaded = nest?.dataset.loaded === "1";
            await wait(100);
        }

        // 6) Temukan baris item by POSNR (db: 6 digit di data-posnr-db)
        const pos6 = pad(posnr, 6);
        let itemRow = null;
        if (pos6) itemRow = nest.querySelector(`tr[data-posnr-db="${pos6}"]`);

        // 7) Scroll & highlight baris item (T3)
        const elementToHighlight = itemRow || targetRow;

        // Final highlight (menggunakan scrollAndFlash)
        if (elementToHighlight && window.scrollAndFlash) {
            window.scrollAndFlash(elementToHighlight);
        }
    }

    if (document.readyState === "complete") {
        run();
    } else {
        window.addEventListener("load", run, { once: true });
    }
})();
