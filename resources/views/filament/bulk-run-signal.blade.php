{{--
    Announces the end of a bulk run to a tab that is not in focus.

    A chunk runs for up to `cars-images.bulk_run_max_seconds_per_chunk`
    seconds, which is long enough that the admin switches tabs and misses the
    Filament toast entirely. Nothing drawn inside the page can reach them
    there, so this listens for the `bulk-run-finished` event dispatched by
    SearchQueryResource and raises two signals that survive a backgrounded
    tab: the tab's own title, and an OS notification.

    Both are best-effort. If notifications are unsupported or refused, the
    title still changes; if scripting fails entirely, the persistent toast is
    still waiting when the admin returns.
--}}
<script>
    document.addEventListener('livewire:init', () => {
        const originalTitle = document.title
        let restoreOnFocus = false

        // Permission can only be requested from a user gesture — asking on page
        // load is silently denied in Chrome. The first click anywhere in the
        // panel is a gesture, and the Run Selected click is itself one, so the
        // prompt lands naturally rather than ambushing the admin on arrival.
        const askOnce = () => {
            document.removeEventListener('click', askOnce)

            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission().catch(() => {})
            }
        }
        document.addEventListener('click', askOnce)

        const restore = () => {
            if (restoreOnFocus) {
                document.title = originalTitle
                restoreOnFocus = false
            }
        }

        // Clear the marker the moment the admin looks at the tab, so a stale
        // "Done" from an earlier run is never left sitting in the title.
        document.addEventListener('visibilitychange', () => {
            if (! document.hidden) {
                restore()
            }
        })
        window.addEventListener('focus', restore)

        Livewire.on('bulk-run-finished', (event) => {
            const payload = Array.isArray(event) ? event[0] : event
            const blocked = payload?.status === 'blocked'
            const processed = payload?.processed ?? 0

            const heading = blocked
                ? 'Bulk run paused — Wikimedia blocked'
                : `Bulk run finished — ${processed} processed`

            document.title = blocked
                ? `⚠️ Paused — ${originalTitle}`
                : `✅ Done (${processed}) — ${originalTitle}`
            restoreOnFocus = true

            if ('Notification' in window && Notification.permission === 'granted') {
                try {
                    new Notification(heading, {
                        body: blocked
                            ? 'The run stopped early and needs a decision.'
                            : "Click 'Run Selected' again to continue.",
                        // Collapses repeated runs onto one notification rather
                        // than stacking a queue the admin has to dismiss.
                        tag: 'cars-images-bulk-run',
                    })
                } catch {
                    // A refused or unsupported notification is not worth
                    // breaking the page over; the title change still stands.
                }
            }
        })
    })
</script>
