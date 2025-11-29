<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.deposit-request-btn').forEach(button => {
            button.addEventListener('click', function () {
                let accountId = this.getAttribute('data-account-id');
                let tooltip = this.closest('.tooltip');
                let originalTooltipText = tooltip ? tooltip.getAttribute("data-tip") : null;

                // Disable button to prevent multiple clicks
                this.disabled = true;
                this.classList.add("opacity-50", "cursor-not-allowed");

                // Add success class to tooltip
                if (tooltip) {
                    tooltip.classList.add("tooltip-success");
                    tooltip.setAttribute("data-tip", "Processing deposit...");
                }

                // Step 1: Request CSRF token
                fetch('/sanctum/csrf-cookie', {
                    method: 'GET',
                    credentials: 'include'
                }).then(() => {
                    console.log('CSRF token set!');

                    // Step 2: Make the deposit request after CSRF is set
                    fetch(`/api/v1/accounts/${accountId}/deposit-request`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-XSRF-TOKEN': getCookie('XSRF-TOKEN')
                        },
                        credentials: 'include'
                    })
                        .then(response => {
                            if (!response.ok) {
                                return response.text().then(text => {
                                    throw new Error(text);
                                });
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.deposit_code) {
                                if (tooltip) {
                                    tooltip.setAttribute("data-tip", "Deposit request sent!");
                                }

                                // Show persistent toast with deposit code
                                showToast(`Deposit request created!`, `Your deposit code is: ${data.deposit_code}`, data.deposit_code);

                                // Reset tooltip after 3 seconds
                                setTimeout(() => {
                                    if (tooltip) {
                                        tooltip.classList.remove("tooltip-success");
                                        tooltip.setAttribute("data-tip", originalTooltipText);
                                    }
                                    button.disabled = false;
                                    button.classList.remove("opacity-50", "cursor-not-allowed");
                                }, 3000);
                            } else {
                                alert('Unexpected response: ' + JSON.stringify(data));
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Failed to process deposit request. See console for details.');
                            button.disabled = false;
                            button.classList.remove("opacity-50", "cursor-not-allowed");
                        });
                });
            });
        });
    });

    // Function to get the CSRF token from cookies
    function getCookie(name) {
        let match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }

    // Function to create a persistent toast notification with a Copy button
    function showToast(title, message, depositCode) {
        let toastContainer = document.getElementById('toast-container');

        if (!toastContainer) {
            return;
        }

        toastContainer.classList.remove('hidden');

        let toast = document.createElement('div');
        toast.className = "alert alert-success shadow-lg pointer-events-auto w-full sm:w-[28rem] max-w-full";

        let content = document.createElement('div');
        content.className = "flex flex-col sm:flex-row sm:items-start gap-3 w-full";

        let textContainer = document.createElement('div');
        textContainer.className = "flex-1 text-sm space-y-1 break-words";
        textContainer.innerHTML = `<div class="font-semibold">${title}</div><div>${message}</div>`;

        let actions = document.createElement('div');
        actions.className = "flex flex-col sm:flex-row gap-2 w-full sm:w-auto";

        let copyButton = document.createElement('button');
        copyButton.className = "btn btn-sm btn-outline w-full sm:w-auto";
        copyButton.innerText = "Copy Code";
        copyButton.addEventListener('click', function () {
            copyToClipboard(depositCode);
            copyButton.innerText = "Copied!";
            setTimeout(() => {
                copyButton.innerText = "Copy Code";
            }, 2000);
        });

        let closeButton = document.createElement('button');
        closeButton.className = "btn btn-sm btn-ghost text-base-content border border-base-300 w-full sm:w-auto";
        closeButton.innerText = "Dismiss";
        closeButton.addEventListener('click', function () {
            toast.remove();
            if (toastContainer.childElementCount === 0) {
                toastContainer.classList.add('hidden');
            }
        });

        actions.appendChild(copyButton);
        actions.appendChild(closeButton);

        content.appendChild(textContainer);
        content.appendChild(actions);

        toast.appendChild(content);

        toastContainer.appendChild(toast);
    }

    // Function to copy text to clipboard
    function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => {
                console.log("Copied to clipboard!");
            }).catch(err => {
                console.error("Clipboard API failed", err);
            });
        } else {
            // Fallback for older browsers
            let textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            console.log("Copied using fallback!");
        }
    }
</script>
