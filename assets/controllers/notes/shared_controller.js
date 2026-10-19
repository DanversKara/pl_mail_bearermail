import { Controller } from "@hotwired/stimulus"

/**
 * notes--shared: a collaborator link's note (/n/<token>). Saves itself a
 * moment after typing stops — the title, and the text or the checklist —
 * with the grant the page was given when the password (if any) was entered.
 */
export default class extends Controller {
    static targets = ["title", "body", "item", "status"]
    static values = { url: String, grant: String, saved: String, failed: String }

    changed() {
        clearTimeout(this.timer)
        this.timer = setTimeout(() => this.save(), 800)
    }

    disconnect() {
        if (this.timer) {
            clearTimeout(this.timer)
            this.save()
        }
    }

    async save() {
        this.timer = null
        const payload = { grant: this.grantValue, title: this.hasTitleTarget ? this.titleTarget.value : "" }
        if (this.hasBodyTarget) payload.body = this.bodyTarget.value
        if (this.itemTargets.length) {
            payload.items = this.itemTargets.map((row) => ({
                done: row.querySelector('input[type="checkbox"]').checked,
                text: row.querySelector('input[type="text"]').value,
            }))
        }
        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload),
                keepalive: true,
            })
            if (!response.ok) throw new Error(String(response.status))
            if (this.hasStatusTarget) this.statusTarget.textContent = this.savedValue
        } catch (e) {
            if (this.hasStatusTarget) this.statusTarget.textContent = this.failedValue
        }
    }
}
