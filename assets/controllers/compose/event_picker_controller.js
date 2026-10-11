import { Controller } from "@hotwired/stimulus";
import { showToast } from "../../toast.js";

/**
 * BearerMail: put one of your calendar events into the message.
 *
 * Lists what's coming up; the one picked is written into the body (title,
 * when, where) and attached as an .ics file, so the recipient's mail app shows
 * its own "Add to calendar" button.
 *
 * The attachment goes through the composer's own file input, so it is
 * uploaded, size-checked and listed exactly like a file the user picked.
 *
 * Built with DOM calls and textContent only — event titles are user data.
 */
export default class extends Controller {
    static values = {
        listUrl: String,
        icsUrl: String, // contains the placeholder 0 for the event id
        locale: String,
        i18n: Object,
    };

    async open(event) {
        event.preventDefault();
        this._range = this._editorRange();

        let data;
        try {
            const response = await fetch(this.listUrlValue, { headers: { Accept: "application/json" } });
            if (!response.ok) throw new Error(String(response.status));
            data = await response.json();
        } catch (e) {
            showToast(this.i18nValue.failed);
            return;
        }

        this._show(data.events || []);
    }

    _show(events) {
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
        const icon = document.createElement("i");
        icon.className = "fa-solid fa-calendar-plus text-ink-faint text-sm";
        icon.setAttribute("aria-hidden", "true");
        const title = document.createElement("h2");
        title.className = "text-sm font-semibold text-ink flex-1";
        title.textContent = t.title;
        head.append(icon, title);

        const search = document.createElement("input");
        search.type = "search";
        search.placeholder = t.search;
        search.className = "mx-4 mt-3 h-8 rounded-lg border border-field bg-field px-3 text-sm text-ink placeholder:text-ink-faint focus:outline-none focus-visible:ring-2 focus-visible:ring-accent";

        const list = document.createElement("ul");
        list.className = "flex-1 overflow-y-auto p-2 mt-1";

        if (events.length === 0) {
            const empty = document.createElement("li");
            empty.className = "px-3 py-8 text-center text-sm text-ink-faint";
            empty.textContent = t.empty;
            list.appendChild(empty);
        }

        events.forEach((ev) => {
            const li = document.createElement("li");
            li.dataset.name = `${ev.title} ${ev.location || ""}`.toLowerCase();
            const button = document.createElement("button");
            button.type = "button";
            button.className = "w-full flex items-center gap-3 px-3 py-2 rounded-lg text-left hover:bg-hover transition-colors cursor-pointer";
            const text = document.createElement("span");
            text.className = "min-w-0 flex-1";
            const name = document.createElement("span");
            name.className = "block text-sm text-ink truncate";
            name.textContent = ev.title;
            const meta = document.createElement("span");
            meta.className = "block text-[11px] text-ink-faint truncate";
            meta.textContent = [this._when(ev), ev.location].filter(Boolean).join(" · ");
            text.append(name, meta);
            button.appendChild(text);
            button.addEventListener("click", () => this._insert(ev, button, close));
            li.appendChild(button);
            list.appendChild(li);
        });

        search.addEventListener("input", () => {
            const q = search.value.trim().toLowerCase();
            list.querySelectorAll("li[data-name]").forEach((li) => {
                li.hidden = q !== "" && !li.dataset.name.includes(q);
            });
        });

        const foot = document.createElement("div");
        foot.className = "flex items-center justify-between gap-2 px-4 py-3 border-t border-line shrink-0";
        const hint = document.createElement("span");
        hint.className = "text-[11px] text-ink-faint";
        hint.textContent = t.hint;
        const cancel = document.createElement("button");
        cancel.type = "button";
        cancel.className = "h-8 px-3.5 rounded-lg text-sm font-medium text-ink-soft bg-field border border-field hover:bg-hover cursor-pointer";
        cancel.textContent = t.cancel;
        foot.append(hint, cancel);

        panel.append(head, search, list, foot);
        backdrop.appendChild(panel);
        document.body.appendChild(backdrop);
        search.focus();

        const onKey = (e) => { if (e.key === "Escape") close(); };
        function close() {
            document.removeEventListener("keydown", onKey);
            backdrop.remove();
        }
        document.addEventListener("keydown", onKey);
        cancel.addEventListener("click", close);
        backdrop.addEventListener("click", (e) => { if (e.target === backdrop) close(); });
    }

    async _insert(ev, button, close) {
        button.disabled = true;
        close();
        this._insertDetails(ev);

        // Attach the .ics through the composer's own upload path.
        try {
            const response = await fetch(this.icsUrlValue.replace(/\/0\//, `/${ev.id}/`), { headers: { Accept: "text/calendar" } });
            if (!response.ok) throw new Error(String(response.status));
            const blob = await response.blob();
            const safe = (ev.title || "event").replace(/[\\/:*?"<>|]+/g, "-").slice(0, 80) || "event";
            const file = new File([blob], `${safe}.ics`, { type: "text/calendar" });
            const input = this._fileInput();
            if (!input) throw new Error("no file input");
            const transfer = new DataTransfer();
            transfer.items.add(file);
            input.files = transfer.files;
            input.dispatchEvent(new Event("change", { bubbles: true }));
        } catch (e) {
            showToast(this.i18nValue.attachFailed);
        }
    }

    _compose() {
        return this.element.closest('[data-controller~="compose--compose"]');
    }

    _editor() {
        return this._compose()?.querySelector('[data-compose--compose-target="body"][contenteditable="true"]') || null;
    }

    _fileInput() {
        return this._compose()?.querySelector('[data-compose--compose-target="fileInput"]') || null;
    }

    _editorRange() {
        const editor = this._editor();
        const selection = window.getSelection();
        if (editor && selection && selection.rangeCount > 0 && editor.contains(selection.anchorNode)) {
            return selection.getRangeAt(0).cloneRange();
        }
        return null;
    }

    _insertDetails(ev) {
        const t = this.i18nValue;
        const block = document.createElement("div");
        const strong = document.createElement("strong");
        strong.textContent = `📅 ${ev.title}`;
        block.appendChild(strong);
        const lines = [[t.when, this._when(ev)], [t.where, ev.location]];
        lines.forEach(([label, value]) => {
            if (!value) return;
            block.appendChild(document.createElement("br"));
            block.appendChild(document.createTextNode(`${label}: ${value}`));
        });

        const editor = this._editor();
        if (!editor) return;

        editor.focus();
        const selection = window.getSelection();
        if (this._range) {
            selection.removeAllRanges();
            selection.addRange(this._range);
        } else {
            const end = document.createRange();
            end.selectNodeContents(editor);
            end.collapse(false);
            selection.removeAllRanges();
            selection.addRange(end);
        }

        if (!document.execCommand("insertHTML", false, block.outerHTML + "<br>")) {
            editor.appendChild(block);
        }
        editor.dispatchEvent(new Event("input", { bubbles: true }));
    }

    _when(ev) {
        const locale = this.localeValue || undefined;
        const start = new Date(ev.start);
        if (Number.isNaN(start.getTime())) return "";
        if (ev.allDay) {
            return start.toLocaleDateString(locale, { weekday: "short", month: "short", day: "numeric", timeZone: "UTC" });
        }
        const day = start.toLocaleDateString(locale, { weekday: "short", month: "short", day: "numeric" });
        const from = start.toLocaleTimeString(locale, { hour: "numeric", minute: "2-digit" });
        if (!ev.end) return `${day}, ${from}`;
        const end = new Date(ev.end);
        const to = end.toLocaleTimeString(locale, { hour: "numeric", minute: "2-digit" });
        return `${day}, ${from} – ${to}`;
    }
}
