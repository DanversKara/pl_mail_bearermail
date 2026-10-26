import { Controller } from "@hotwired/stimulus"

/**
 * What's new → Send feedback: the screenshot, and the sending (#165).
 *
 * ## The screenshot
 *
 * "Attach a screenshot of the page you were on" draws the page behind the
 * dialog, in the browser, when the box is ticked, and puts it in a hidden field
 * as a data: URL; FeedbackMailer checks the bytes and attaches them. Nothing is
 * taken unless somebody asks, and the picture is shown under the box before it
 * is sent, so what leaves is what they saw. The dialog's own backdrop is
 * filtered out of the clone: a picture of the feedback form is not what anybody
 * means by "the page I was on".
 *
 * A JPEG at most 1600 pixels wide, not a full-size PNG. A real mailbox drawn
 * at a Retina window's size was megabytes as a PNG, and a request that size is
 * what a reverse proxy refuses first — nginx stops at one megabyte unless told
 * otherwise. This is a tenth of it and still readable.
 *
 * html-to-image is imported on the tick, not at page load: almost nobody sends
 * feedback, and nobody else should download a page renderer for it.
 *
 * BearerMail: it failed on real pages ("could not be captured"), so it now
 * tries twice — the second time without web fonts, iframes and video, which
 * are what the renderer most often chokes on — and a picture it cannot load
 * becomes a blank pixel rather than a failure. When both fail, and the browser
 * can share its screen, a button offers to take the picture that way: the
 * browser asks which tab, and one frame of it is kept.
 *
 * ## The sending
 *
 * Posted with fetch() and put into the dialog here, rather than left to Turbo.
 * Turbo visits any answer that is not the dialog's frame as a whole page — the
 * shared handler in request_errors.js does, on purpose, for a redirect — and
 * that closed the dialog with nothing said when an answer came back that was
 * not plMail's: a proxy's refusal of a large body, a login page. Here the
 * dialog stays, and the form says what came back instead, so a failure is
 * visible and names its cause.
 */
export default class extends Controller {
    static targets = ["toggle", "data", "status", "preview", "submit", "failure", "share"]

    static MAX_WIDTH = 1600

    async toggle() {
        if (false === this.toggleTarget.checked) {
            this._clear()
            return
        }

        this.statusTarget.textContent = this.statusTarget.dataset.taking
        this.submitTarget.disabled = true

        try {
            const url = await this._render()

            if (false === this.toggleTarget.checked) {
                return
            }

            this._use(url)
        } catch (error) {
            console.warn("[whats-new] screenshot failed", error)
            this.toggleTarget.checked = false
            this._clear()
            this.statusTarget.textContent = this.statusTarget.dataset.failed
            if (this.hasShareTarget && navigator.mediaDevices?.getDisplayMedia) {
                this.shareTarget.hidden = false
            }
        } finally {
            this.submitTarget.disabled = false
        }
    }

    /** The page drawn in the browser; a second, plainer attempt when the first fails. */
    async _render() {
        const { toJpeg } = await import("html-to-image")
        const backdrop = document.getElementById("modal-backdrop")
        const width = document.documentElement.clientWidth
        const options = {
            quality: 0.8,
            pixelRatio: Math.min(1, this.constructor.MAX_WIDTH / Math.max(1, width)),
            backgroundColor: getComputedStyle(document.body).backgroundColor,
            cacheBust: false,
            // A picture that will not load is a blank pixel, not a failed screenshot.
            imagePlaceholder: "data:image/gif;base64,R0lGODlhAQABAAAAACw=",
        }

        try {
            return await toJpeg(document.body, { ...options, filter: (node) => node !== backdrop })
        } catch (first) {
            console.warn("[whats-new] screenshot, first attempt", first)
            const plain = (node) =>
                node !== backdrop && !["IFRAME", "VIDEO", "OBJECT", "EMBED"].includes(node.tagName)
            return await toJpeg(document.body, { ...options, skipFonts: true, filter: plain })
        }
    }

    /**
     * The fallback: the browser's own screen capture. It asks which tab or window
     * to share, one frame is drawn into a canvas, and the sharing stops at once.
     */
    async shareScreen() {
        this.shareTarget.hidden = true
        this.statusTarget.textContent = this.statusTarget.dataset.taking
        this.submitTarget.disabled = true
        let stream = null

        try {
            stream = await navigator.mediaDevices.getDisplayMedia({
                video: { displaySurface: "browser" },
                audio: false,
                preferCurrentTab: true,
                selfBrowserSurface: "include",
            })
            const video = document.createElement("video")
            video.muted = true
            video.playsInline = true
            video.srcObject = stream
            await video.play()
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))

            const scale = Math.min(1, this.constructor.MAX_WIDTH / Math.max(1, video.videoWidth))
            const canvas = document.createElement("canvas")
            canvas.width = Math.round(video.videoWidth * scale)
            canvas.height = Math.round(video.videoHeight * scale)
            canvas.getContext("2d").drawImage(video, 0, 0, canvas.width, canvas.height)

            this.toggleTarget.checked = true
            this._use(canvas.toDataURL("image/jpeg", 0.8))
        } catch (error) {
            console.warn("[whats-new] screen capture failed", error)
            this.toggleTarget.checked = false
            this._clear()
            this.statusTarget.textContent = this.statusTarget.dataset.failed
        } finally {
            stream?.getTracks().forEach((track) => track.stop())
            this.submitTarget.disabled = false
        }
    }

    _use(url) {
        this.dataTarget.value = url
        this.previewTarget.src = url
        this.previewTarget.hidden = false
        this.statusTarget.textContent = ""
        if (this.hasShareTarget) this.shareTarget.hidden = true
    }

    async send(event) {
        event.preventDefault()

        const form = this.element
        const frame = form.closest("turbo-frame")

        this.submitTarget.disabled = true
        this.failureTarget.hidden = true

        let response = null

        try {
            response = await fetch(form.action, {
                method: "POST",
                body: new FormData(form),
                headers: { Accept: "text/html", "Turbo-Frame": frame?.id ?? "modal" },
                credentials: "same-origin",
            })

            const html = await response.text()
            const answer = new DOMParser().parseFromString(html, "text/html").querySelector(`turbo-frame#${frame?.id ?? "modal"}`)

            if (null !== answer && null !== frame && (response.ok || 422 === response.status)) {
                frame.replaceChildren(...answer.childNodes)
                return
            }
        } catch (error) {
            console.warn("[whats-new] feedback request failed", error)
        }

        this.submitTarget.disabled = false
        this.failureTarget.textContent = this.failureTarget.dataset.message.replace("%status%", null === response ? "—" : String(response.status))
        this.failureTarget.hidden = false
    }

    _clear() {
        this.dataTarget.value = ""
        this.previewTarget.hidden = true
        this.previewTarget.removeAttribute("src")
        this.statusTarget.textContent = ""
    }
}
