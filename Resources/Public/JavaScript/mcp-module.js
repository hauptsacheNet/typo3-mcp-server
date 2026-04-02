/**
 * MCP Server Module - TYPO3 ES6 Module
 */
import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';
import Notification from '@typo3/backend/notification.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

class McpModule {
    constructor() {
        // ES6 modules via includeJavaScriptModules are typically deferred,
        // but guard against edge cases where readyState may still be 'loading'.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.initialize());
        } else {
            this.initialize();
        }
    }

    initialize() {
        // Copy buttons
        document.querySelectorAll('.copy-button[data-copy-target]').forEach(button => {
            const targetId = button.getAttribute('data-copy-target');
            if (targetId) {
                button.addEventListener('click', () => this.copyToClipboard(targetId, button));
            }
        });

        // Token management buttons
        const refreshTokensBtn = document.getElementById('refresh-tokens-btn');
        if (refreshTokensBtn) {
            refreshTokensBtn.addEventListener('click', () => this.refreshTokens());
        }

        const revokeAllTokensBtn = document.getElementById('revoke-all-tokens-btn');
        if (revokeAllTokensBtn) {
            revokeAllTokensBtn.addEventListener('click', () => this.revokeAllTokens());
        }

        const createMcpRemoteTokenBtn = document.getElementById('create-mcp-remote-token-btn');
        if (createMcpRemoteTokenBtn) {
            createMcpRemoteTokenBtn.addEventListener('click', () => this.createToken('mcp-remote token', createMcpRemoteTokenBtn));
        }

        const createN8nTokenBtn = document.getElementById('create-n8n-token-btn');
        if (createN8nTokenBtn) {
            createN8nTokenBtn.addEventListener('click', () => this.createToken('n8n token', createN8nTokenBtn));
        }

        const createManusTokenBtn = document.getElementById('create-manus-token-btn');
        if (createManusTokenBtn) {
            createManusTokenBtn.addEventListener('click', () => this.createToken('manus token', createManusTokenBtn));
        }

        // Delegated revoke button handler
        document.addEventListener('click', (e) => {
            const button = e.target.classList.contains('revoke-token-btn')
                ? e.target
                : e.target.closest('.revoke-token-btn');
            if (!button) return;

            const tokenId = button.getAttribute('data-token-id');
            if (!tokenId) return;

            Modal.advanced({
                title: 'Revoke Token',
                content: 'Are you sure you want to revoke this token? The associated MCP client will lose access immediately.',
                severity: Severity.warning,
                buttons: [
                    {
                        text: 'Cancel',
                        btnClass: 'btn-default',
                        trigger: () => Modal.dismiss()
                    },
                    {
                        text: 'Revoke',
                        btnClass: 'btn-warning',
                        trigger: () => {
                            Modal.dismiss();
                            this.revokeToken(tokenId);
                        }
                    }
                ]
            });
        });

        // Check endpoint statuses
        this.checkEndpointStatuses();
    }

    // =========================================================================
    // Clipboard
    // =========================================================================

    copyToClipboard(elementId, button) {
        const element = document.getElementById(elementId);
        if (!element) {
            console.error('Element not found:', elementId);
            return;
        }

        let textToCopy = element.value;
        let selectionStart = 0;
        let selectionEnd = textToCopy.length;

        const serverKey = button.getAttribute('data-copy-server-only');
        if (serverKey) {
            const result = this.extractServerConfigWithPosition(textToCopy, serverKey);
            textToCopy = result.config;
            selectionStart = result.start;
            selectionEnd = result.end;
        }

        element.focus();
        element.setSelectionRange(selectionStart, selectionEnd);

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(textToCopy).then(() => {
                this.showCopyFeedback(button);
            }).catch(() => {
                this.fallbackCopyWithText(textToCopy, button);
            });
        } else {
            try {
                const success = document.execCommand('copy');
                if (success) {
                    this.showCopyFeedback(button);
                } else {
                    Notification.warning('Copy failed', 'Please select the text manually and copy with Ctrl+C (Cmd+C on Mac).');
                }
            } catch {
                Notification.warning('Copy failed', 'Please select the text manually and copy with Ctrl+C (Cmd+C on Mac).');
            }
        }
    }

    extractServerConfigWithPosition(fullConfig, serverKey) {
        try {
            const config = JSON.parse(fullConfig);
            const serverConfig = config.mcpServers[serverKey];
            const serverConfigJson = JSON.stringify(serverConfig, null, 2);

            const serverKeyPattern = new RegExp(`"${serverKey}"\\s*:\\s*{`, 'g');
            const match = serverKeyPattern.exec(fullConfig);

            if (match) {
                const colonIndex = fullConfig.indexOf(':', match.index);
                let start = fullConfig.indexOf('{', colonIndex);
                let braceCount = 1;
                let end = start + 1;

                while (end < fullConfig.length && braceCount > 0) {
                    if (fullConfig[end] === '{') braceCount++;
                    else if (fullConfig[end] === '}') braceCount--;
                    end++;
                }

                return { config: serverConfigJson, start, end };
            }

            return { config: serverConfigJson, start: 0, end: fullConfig.length };
        } catch {
            return { config: fullConfig, start: 0, end: fullConfig.length };
        }
    }

    fallbackCopyWithText(text, button) {
        const tempTextarea = document.createElement('textarea');
        tempTextarea.value = text;
        tempTextarea.style.position = 'fixed';
        tempTextarea.style.left = '-999999px';
        tempTextarea.style.top = '-999999px';
        document.body.appendChild(tempTextarea);

        tempTextarea.focus();
        tempTextarea.select();

        try {
            const success = document.execCommand('copy');
            if (success) {
                this.showCopyFeedback(button);
            } else {
                Notification.warning('Copy failed', 'Please select the text manually and copy with Ctrl+C (Cmd+C on Mac).');
            }
        } catch {
            Notification.warning('Copy failed', 'Please select the text manually and copy with Ctrl+C (Cmd+C on Mac).');
        } finally {
            document.body.removeChild(tempTextarea);
        }
    }

    showCopyFeedback(button) {
        if (!button) return;

        const originalWidth = button.offsetWidth;
        const iconMarkup = button.querySelector('.icon-markup');
        const textNodes = Array.from(button.childNodes).filter(node => node.nodeType === Node.TEXT_NODE);
        const lastTextNode = textNodes[textNodes.length - 1];

        const originalIconText = iconMarkup ? iconMarkup.textContent : '';
        const originalButtonText = lastTextNode ? lastTextNode.textContent : '';

        button.style.width = originalWidth + 'px';

        if (iconMarkup) iconMarkup.textContent = '✅';
        if (lastTextNode) lastTextNode.textContent = ' Copied!';

        button.classList.add('btn-success');
        button.classList.remove('btn-outline-secondary');

        setTimeout(() => {
            if (iconMarkup) iconMarkup.textContent = originalIconText;
            if (lastTextNode) lastTextNode.textContent = originalButtonText;
            button.classList.remove('btn-success');
            button.classList.add('btn-outline-secondary');
            button.style.width = '';
        }, 2000);
    }

    // =========================================================================
    // Token CRUD
    // =========================================================================

    refreshTokens() {
        new AjaxRequest(TYPO3.settings.ajaxUrls.mcp_server_get_tokens)
            .post({})
            .then(async (response) => {
                const data = await response.resolve();
                if (data.success) {
                    this.updateTokensTable(data.tokens);
                } else {
                    Notification.error('Refresh failed', 'Failed to refresh tokens: ' + data.message);
                }
            })
            .catch((error) => {
                Notification.error('Network error', 'Error refreshing tokens: ' + (error.message || 'Unknown error'));
            });
    }

    revokeToken(tokenId) {
        const tokenIdInt = parseInt(tokenId, 10);

        if (!tokenIdInt || tokenIdInt <= 0) {
            Notification.error('Invalid token', 'Invalid token ID: ' + tokenId);
            return;
        }

        new AjaxRequest(TYPO3.settings.ajaxUrls.mcp_server_revoke_token)
            .post({ tokenId: tokenIdInt })
            .then(async (response) => {
                const data = await response.resolve();
                if (data.success) {
                    Notification.success('Token revoked', data.message);
                    this.refreshTokens();
                } else {
                    Notification.error('Revoke failed', 'Failed to revoke token: ' + data.message);
                }
            })
            .catch((error) => {
                Notification.error('Network error', 'Error revoking token: ' + (error.message || 'Unknown error'));
            });
    }

    revokeAllTokens() {
        Modal.advanced({
            title: 'Revoke All Tokens',
            content: 'Are you sure you want to revoke ALL tokens? This will disconnect all MCP clients and require re-authentication.',
            severity: Severity.warning,
            buttons: [
                {
                    text: 'Cancel',
                    btnClass: 'btn-default',
                    trigger: () => Modal.dismiss()
                },
                {
                    text: 'Revoke All',
                    btnClass: 'btn-warning',
                    trigger: () => {
                        Modal.dismiss();
                        new AjaxRequest(TYPO3.settings.ajaxUrls.mcp_server_revoke_all_tokens)
                            .post({})
                            .then(async (response) => {
                                const data = await response.resolve();
                                if (data.success) {
                                    Notification.success('Tokens revoked', data.message);
                                    this.refreshTokens();
                                } else {
                                    Notification.error('Revoke failed', 'Failed to revoke all tokens: ' + data.message);
                                }
                            })
                            .catch((error) => {
                                Notification.error('Network error', 'Error revoking all tokens: ' + (error.message || 'Unknown error'));
                            });
                    }
                }
            ]
        });
    }

    /**
     * Unified token creation with "show once" modal.
     */
    createToken(clientType, button) {
        if (button) button.disabled = true;

        const requestBody = clientType ? { clientType } : {};

        new AjaxRequest(TYPO3.settings.ajaxUrls.mcp_server_create_token)
            .post(requestBody)
            .then(async (response) => {
                const data = await response.resolve();
                if (data.success && data.token) {
                    this.showTokenModal(data.token, clientType);
                    this.refreshTokens();
                } else if (data.success) {
                    Notification.success('Token created', 'Token created successfully.');
                    setTimeout(() => window.location.reload(), 2000);
                } else {
                    Notification.error('Token creation failed', data.message || 'Unknown error');
                    if (button) button.disabled = false;
                }
            })
            .catch((error) => {
                Notification.error('Network error', 'Error creating token: ' + (error.message || 'Unknown error'));
                if (button) button.disabled = false;
            });
    }

    /**
     * Display a TYPO3 Modal with the plain token (shown only once).
     */
    showTokenModal(plainToken, clientType) {
        const container = document.createElement('div');
        container.style.padding = '10px';

        const warning = document.createElement('div');
        warning.className = 'alert alert-warning';
        const warningStrong = document.createElement('strong');
        warningStrong.textContent = 'This token will only be shown once.';
        warning.appendChild(warningStrong);
        warning.appendChild(document.createTextNode(' Copy it now and store it securely. You will not be able to see it again.'));
        container.appendChild(warning);

        if (clientType) {
            const label = document.createElement('p');
            const strong = document.createElement('strong');
            strong.textContent = 'Client type: ';
            label.appendChild(strong);
            label.appendChild(document.createTextNode(clientType));
            container.appendChild(label);
        }

        const inputGroup = document.createElement('div');
        inputGroup.className = 'input-group mb-3';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control';
        input.value = plainToken;
        input.readOnly = true;
        input.style.fontFamily = 'monospace';
        input.id = 'modal-token-value';
        inputGroup.appendChild(input);

        const copyBtn = document.createElement('button');
        copyBtn.className = 'btn btn-outline-secondary';
        copyBtn.type = 'button';
        copyBtn.textContent = 'Copy';
        copyBtn.addEventListener('click', () => {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(plainToken).then(() => {
                    copyBtn.textContent = 'Copied!';
                    copyBtn.classList.add('btn-success');
                    copyBtn.classList.remove('btn-outline-secondary');
                }).catch(() => {
                    input.select();
                    copyBtn.textContent = 'Select & copy with Ctrl+C';
                });
            } else {
                input.select();
                copyBtn.textContent = 'Select & copy with Ctrl+C';
            }
        });
        inputGroup.appendChild(copyBtn);
        container.appendChild(inputGroup);

        Modal.advanced({
            title: 'Token Created',
            content: container,
            severity: Severity.ok,
            staticBackdrop: true,
            buttons: [
                {
                    text: 'I have copied the token',
                    btnClass: 'btn-primary',
                    trigger: () => {
                        Modal.dismiss();
                        window.location.reload();
                    }
                }
            ]
        });
    }

    // =========================================================================
    // Token Table
    // =========================================================================

    /**
     * Escape HTML special characters to prevent XSS when building innerHTML.
     */
    escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str ?? '')));
        return div.innerHTML;
    }

    updateTokensTable(tokens) {
        const container = document.getElementById('tokens-container');
        if (!container) return;

        if (!tokens || tokens.length === 0) {
            container.innerHTML = `
                <div id="no-tokens-message" class="text-center text-muted py-4">
                    <p>No active OAuth tokens found.</p>
                    <p class="small">Use the Remote MCP Setup above to connect your first MCP client.</p>
                </div>
            `;
        } else {
            const esc = (s) => this.escapeHtml(String(s ?? ''));
            container.innerHTML = `
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Client Name</th>
                                <th>Created</th>
                                <th>Last Used</th>
                                <th>Expires</th>
                                <th>Token Hash</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tokens.map(token => `
                                <tr data-token-id="${esc(token.uid)}">
                                    <td><strong>${esc(token.client_name)}</strong></td>
                                    <td><small class="text-muted">${esc(token.created)}</small></td>
                                    <td><small class="text-muted">${esc(token.last_used)}</small></td>
                                    <td><small class="text-muted">${esc(token.expires)}</small></td>
                                    <td><code class="small">${esc(token.token_hash)}</code></td>
                                    <td>
                                        <button class="btn btn-sm btn-danger revoke-token-btn" data-token-id="${esc(token.uid)}" aria-label="Revoke token for ${esc(token.client_name)}">Revoke</button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }
    }

    // =========================================================================
    // Endpoint Status Checks (use raw fetch — these are cross-origin requests)
    // =========================================================================

    checkEndpointStatuses() {
        document.querySelectorAll('.endpoint-status').forEach(element => {
            const endpoint = element.getAttribute('data-endpoint');
            const checkContent = element.getAttribute('data-check-content') === 'true';
            const checkAuth = element.getAttribute('data-check-auth') === 'true';

            if (endpoint) {
                if (checkAuth) {
                    this.checkMcpEndpointAuth(element, endpoint);
                } else {
                    this.checkEndpoint(element, endpoint, checkContent);
                }
            }
        });
    }

    checkEndpoint(element, endpoint, checkContent) {
        element.classList.add('checking');
        element.classList.remove('success', 'warning', 'error');

        fetch(endpoint, {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            mode: 'cors',
            credentials: 'same-origin'
        })
            .then(response => {
                if (response.ok) {
                    if (checkContent) {
                        return response.text().then(text => {
                            if (text.includes('/mcp')) {
                                this.setEndpointStatus(element, 'success', 'Endpoint is working correctly');
                            } else {
                                this.setEndpointStatus(element, 'warning', 'Endpoint is reachable but does not mention MCP endpoint');
                            }
                        });
                    }
                    return this.setEndpointStatus(element, 'success', 'Endpoint is reachable');
                } else {
                    this.setEndpointStatus(element, 'error', `Endpoint returned ${response.status} ${response.statusText}`);
                }
            })
            .catch(error => {
                if (error.message.includes('CORS') || error.message.includes('blocked')) {
                    this.setEndpointStatus(element, 'error', 'Endpoint may be blocked by CORS policy or security settings');
                } else {
                    this.setEndpointStatus(element, 'error', `Network error: ${error.message}`);
                }
            });
    }

    setEndpointStatus(element, status, message) {
        element.classList.remove('checking', 'success', 'warning', 'error');
        element.classList.add(status);

        const statusTooltip = element.querySelector('.status-tooltip');
        if (statusTooltip) {
            statusTooltip.textContent = message;
        }
    }

    checkMcpEndpointAuth(element, endpoint) {
        element.classList.add('checking');
        element.classList.remove('success', 'warning', 'error');

        fetch(endpoint + '?test=auth', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Authorization': 'Bearer test-header-check-12345'
            },
            mode: 'cors',
            credentials: 'same-origin'
        })
            .then(response => {
                return response.json().then(data => {
                    if (data.headers_received && data.headers_received.authorization) {
                        this.setEndpointStatus(element, 'success', 'MCP endpoint is accessible and can receive Authorization headers');
                        const warningDiv = document.getElementById('auth-header-warning');
                        if (warningDiv) warningDiv.style.display = 'none';
                    } else {
                        this.setEndpointStatus(element, 'error', 'MCP endpoint cannot receive Authorization headers - see warning below');
                        const warningDiv = document.getElementById('auth-header-warning');
                        if (warningDiv) warningDiv.style.display = 'block';
                    }
                }).catch(() => {
                    if (response.status === 401) {
                        this.setEndpointStatus(element, 'warning', 'MCP endpoint is reachable but Authorization header status unknown');
                    } else {
                        this.setEndpointStatus(element, 'error', `MCP endpoint returned ${response.status} ${response.statusText}`);
                    }
                });
            })
            .catch(error => {
                if (error.message.includes('CORS') || error.message.includes('blocked')) {
                    this.setEndpointStatus(element, 'error', 'MCP endpoint may be blocked by CORS policy or security settings');
                } else {
                    this.setEndpointStatus(element, 'error', `Network error: ${error.message}`);
                }
                const warningDiv = document.getElementById('auth-header-warning');
                if (warningDiv) warningDiv.style.display = 'block';
            });
    }
}

export default new McpModule();
