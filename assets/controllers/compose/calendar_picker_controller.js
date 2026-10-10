import { Controller } from "@hotwired/stimulus";
import { showToast } from "../../toast.js";

/**
 * Calendar share links, from the compose toolbar.
 *
 * A share link's token is stored only as a hash (see ShareLinkWriter), so the
 * app genuinely cannot hand the URL back after it was first shown. What this
 * can do honestly is list what exists and take you to Settings → Sharing,
 * where a fresh link can be made and copied. It says so rather than offering
 * a "Copy link" button that cannot copy anything.
 *
 * On its own button (data-controller on the button) — the first version had
 * only a data-action and no controller anywhere above it, so it never ran.
 */
export default class extends Controller {
    static values = { listUrl: String, settingsUrl: String, i18n: Object };

    async open(event) {
        event.preventDefault();

        let data;
        try {
            const response = await fetch(this.listUrlValue, { headers: { Accept: "application/json" } });
            if (!response.ok) throw new Error(String(response.status));
            data = await response.json();
        } catch (e) {
            showToast(this.i18nValue.failed);
            return;
        }

        this._show(data);
    }

    _show(data) {
        const t = this.i18nValue;
        const backdrop = document.createElement("div");
        backdrop.className = "fixed inset-0 z-[60] flex items-center justify-center bg-black/40 backdrop-blur-sm p-4";

        const panel = document.createElement("div");
        panel.className = "popover w-full max-w-md max-h-[70vh] flex flex-col";
        panel.setAttribute("role", "dialog");
        panel.setAttribute("aria-modal", "true");
        panel.setAttribute("aria-label", t.title);

        const head = document.createElement("div");
        head.className = "flex items-center gap-2 px-4 h-12 border-b border-line shrink-0";
        head.innerHTML = '<i class="fa-regular fa-calendar text-ink-faint text-sm" aria-hidden="true"></i>';
        const title = document.createElement("h2");
        title.className = "text-sm font-semibold text-ink";
        title.textContent = t.title;
        head.appendChild(title);

        const body = document.createElement("div");
        body.className = "flex-1 overflow-y-auto px-4 py-3 space-y-4";

        const section = (heading, items, icon) => {
            if (!items.length) return;
            const h = document.createElement("h3");
            h.className = "text-[11px] font-semibold uppercase tracking-wide text-ink-faint mb-1.5";
            h.textContent = heading;
            const ul = document.createElement("ul");
            ul.className = "divide-y divide-line rounded-xl border border-line";
            items.forEach((item) => {
                const li = document.createElement("li");
                li.className = "flex items-center gap-2.5 px-3 py-2 text-sm text-ink";
                li.innerHTML = `<i class="${icon} text-xs text-ink-faint w-4 text-center" aria-hidden="true"></i>`;
                const name = document.createElement("span");
                name.className = "truncate";
                name.textContent = item.name;
                li.appendChild(name);
                ul.appendChild(li);
            });
            const wrap = document.createElement("div");
            wrap.append(h, ul);
            body.appendChild(wrap);
        };

        section(t.calendars, data.links || [], "fa-solid fa-share-nodes");
        section(t.booking, data.pages || [], "fa-solid fa-calendar-check");

        const note = document.createElement("p");
        note.className = "text-xs text-ink-muted";
        note.textContent = (data.links || []).length || (data.pages || []).length ? t.hint : t.empty;
        body.appendChild(note);

        const foot = document.createElement("div");
        foot.className = "flex items-center justify-end gap-2 px-4 py-3 border-t border-line shrink-0";
        const cancel = document.createElement("button");
        cancel.type = "button";
        cancel.className = "h-8 px-3.5 rounded-lg text-sm font-medium text-ink-soft bg-field border border-field hover:bg-hover cursor-pointer";
        cancel.textContent = t.close;
        const go = document.createElement("a");
        go.href = this.settingsUrlValue;
        go.target = "_blank";
        go.rel = "noopener";
        go.className = "inline-flex items-center h-8 px-3.5 rounded-lg text-sm font-medium bg-accent hover:bg-accent-strong text-accent-ink";
        go.textContent = t.open;
        foot.append(cancel, go);

        panel.append(head, body, foot);
        backdrop.appendChild(panel);
        document.body.appendChild(backdrop);
        cancel.focus();

        const onKey = (e) => { if (e.key === "Escape") close(); };
        function close() {
            document.removeEventListener("keydown", onKey);
            backdrop.remove();
        }
        document.addEventListener("keydown", onKey);
        cancel.addEventListener("click", close);
        go.addEventListener("click", close);
        backdrop.addEventListener("click", (e) => { if (e.target === backdrop) close(); });
    }
}
