<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.deposit-request-btn').forEach(button => {
            button.addEventListener('click', function () {
                let accountId = this.getAttribute('data-account-id');
                let tooltip = this.parentElement.closest('.tooltip');
                let originalTooltipText = tooltip.getAttribute("data-tip");

                // Disable button to prevent multiple clicks
                this.disabled = true;
                this.classList.add("opacity-50", "cursor-not-allowed");

                // Add success class to tooltip
                tooltip.classList.add("tooltip-success");
                tooltip.setAttribute("data-tip", "Processing deposit...");

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
                                // Update tooltip to success message
                                tooltip.setAttribute("data-tip", "Deposit request sent!");

                                // Show persistent toast with deposit code
                                showToast(`Deposit request created!`, `Your deposit code is: ${data.deposit_code}`, data.deposit_code);

                                // Reset tooltip after 3 seconds
                                setTimeout(() => {
                                    tooltip.classList.remove("tooltip-success");
                                    tooltip.setAttribute("data-tip", originalTooltipText);
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

        let toast = document.createElement('div');
        toast.className = "alert alert-success flex items-center gap-3 p-4 rounded-lg shadow-lg";

        let textContainer = document.createElement('div');
        textContainer.innerHTML = `<strong>${title}</strong><br>${message}`;

        let copyButton = document.createElement('button');
        copyButton.className = "btn btn-sm btn-outline ml-2";
        copyButton.innerText = "Copy Code";
        copyButton.addEventListener('click', function () {
            copyToClipboard(depositCode);
            copyButton.innerText = "Copied!";
            setTimeout(() => {
                copyButton.innerText = "Copy Code";
            }, 2000);
        });

        let closeButton = document.createElement('button');
        closeButton.className = "btn btn-sm btn-error ml-2";
        closeButton.innerText = "Dismiss";
        closeButton.addEventListener('click', function () {
            toast.remove();
            if (toastContainer.childElementCount === 0) {
                toastContainer.classList.add('hidden');
            }
        });

        toast.appendChild(textContainer);
        toast.appendChild(copyButton);
        toast.appendChild(closeButton);

        toastContainer.appendChild(toast);
        toastContainer.classList.remove('hidden');
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
