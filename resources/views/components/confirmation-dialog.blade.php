<dialog id="nexus-confirmation-dialog" class="modal" aria-labelledby="nexus-confirmation-title" aria-describedby="nexus-confirmation-message">
    <div class="modal-box max-w-lg rounded-lg border border-base-300 bg-base-100 p-0 shadow-lg">
        <div class="border-b border-base-300 px-5 py-4">
            <p class="nexus-kicker">Review action</p>
            <h2 id="nexus-confirmation-title" class="mt-2 font-display text-2xl font-bold text-base-content">Confirm action</h2>
        </div>

        <div class="px-5 py-5">
            <p id="nexus-confirmation-message" class="text-sm leading-6 text-base-content/75"></p>
        </div>

        <div class="flex flex-wrap justify-end gap-2 border-t border-base-300 px-5 py-4">
            <button type="button" class="btn btn-ghost" data-confirm-cancel>Keep current state</button>
            <button type="button" class="btn btn-primary" data-confirm-continue>Continue</button>
        </div>
    </div>

    <form method="dialog" class="modal-backdrop">
        <button aria-label="Close confirmation">close</button>
    </form>
</dialog>
