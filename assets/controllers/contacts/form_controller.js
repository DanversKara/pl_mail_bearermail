import { Controller } from "@hotwired/stimulus"

/**
 * contacts--form: the dynamic email/phone/address rows on the contact form.
 *
 * Rows are server-rendered with explicit numeric indexes; "add" clones a
 * <template> with the container's next index (a counter that only goes up,
 * so removing a row can never collide two indexes). Removed rows simply
 * never submit — update() treats the submitted set as replace-all.
 *
 * No inline handlers anywhere: the app's CSP refuses them.
 */
export default class extends Controller {
    static targets = [
        "emailRows", "emailTemplate",
        "phoneRows", "phoneTemplate",
        "addressRows", "addressTemplate",
    ]

    addEmail()   { this.#add(this.emailRowsTarget, this.emailTemplateTarget) }
    addPhone()   { this.#add(this.phoneRowsTarget, this.phoneTemplateTarget) }
    addAddress() { this.#add(this.addressRowsTarget, this.addressTemplateTarget) }

    removeRow(event) {
        event.currentTarget.closest("[data-row]")?.remove()
    }

    /** Auto-submit the photo upload form when a file is chosen. */
    submitForm(event) {
        if (event.target.files?.length) {
            event.target.form.requestSubmit()
        }
    }

    #add(container, template) {
        const index = parseInt(container.dataset.nextIndex || "0", 10)
        container.insertAdjacentHTML(
            "beforeend",
            template.innerHTML.replaceAll("__INDEX__", String(index)),
        )
        container.dataset.nextIndex = String(index + 1)
    }
}
