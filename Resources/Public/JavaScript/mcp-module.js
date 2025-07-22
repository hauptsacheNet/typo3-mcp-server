/**
 * MCP Server Module JavaScript
 */

function copyToClipboard(elementId, button) {
    const element = document.getElementById(elementId);
    if (!element) {
        console.error('Element not found:', elementId);
        return;
    }
    
    let textToCopy = element.value;
    let selectionStart = 0;
    let selectionEnd = textToCopy.length;
    
    // Check if this is a server-only copy
    const serverKey = button.getAttribute('data-copy-server-only');
    if (serverKey) {
        const result = extractServerConfigWithPosition(textToCopy, serverKey);
        textToCopy = result.config;
        selectionStart = result.start;
        selectionEnd = result.end;
    }
    
    // Select the relevant text in the textarea for visual feedback
    element.focus();
    element.setSelectionRange(selectionStart, selectionEnd);
    
    // Use modern clipboard API or fallback
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(textToCopy).then(() => {
            showCopyFeedback(button);
        }).catch(err => {
            console.error('Clipboard API failed:', err);
            fallbackCopyWithText(textToCopy, button);
        });
    } else {
        // For fallback, use the already selected text
        try {
            const success = document.execCommand('copy');
            if (success) {
                showCopyFeedback(button);
            } else {
                showManualCopyMessage();
            }
        } catch (err) {
            console.error('execCommand copy failed:', err);
            showManualCopyMessage();
        }
    }
}

function extractServerConfigWithPosition(fullConfig, serverKey) {
    try {
        const config = JSON.parse(fullConfig);
        const serverConfig = config.mcpServers[serverKey];
        const serverConfigJson = JSON.stringify(serverConfig, null, 2);
        
        // Find the position of the server config in the original text
        // Look for the server key followed by the opening brace
        const serverKeyPattern = new RegExp(`"${serverKey}"\\s*:\\s*{`, 'g');
        const match = serverKeyPattern.exec(fullConfig);
        
        if (match) {
            // Find the start of the server config object (after the colon)
            const colonIndex = fullConfig.indexOf(':', match.index);
            let start = fullConfig.indexOf('{', colonIndex);
            
            // Find the matching closing brace
            let braceCount = 1;
            let end = start + 1;
            
            while (end < fullConfig.length && braceCount > 0) {
                if (fullConfig[end] === '{') {
                    braceCount++;
                } else if (fullConfig[end] === '}') {
                    braceCount--;
                }
                end++;
            }
            
            return {
                config: serverConfigJson,
                start: start,
                end: end
            };
        }
        
        // Fallback if position finding fails
        return {
            config: serverConfigJson,
            start: 0,
            end: fullConfig.length
        };
        
    } catch (err) {
        console.error('Failed to extract server config:', err);
        return {
            config: fullConfig,
            start: 0,
            end: fullConfig.length
        };
    }
}

function fallbackCopyWithText(text, button) {
    // Create a temporary textarea with the text to copy
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
            showCopyFeedback(button);
        } else {
            showManualCopyMessage();
        }
    } catch (err) {
        console.error('execCommand copy failed:', err);
        showManualCopyMessage();
    } finally {
        document.body.removeChild(tempTextarea);
    }
}

function fallbackCopy(element) {
    try {
        // Modern clipboard API fallback
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(element.value).then(() => {
                showCopyFeedback(event.target.closest('button'));
            }).catch(err => {
                console.error('Clipboard API failed:', err);
                showManualCopyMessage();
            });
        } else {
            showManualCopyMessage();
        }
    } catch (err) {
        console.error('All copy methods failed:', err);
        showManualCopyMessage();
    }
}

function showCopyFeedback(button) {
    if (!button) return;
    
    const originalWidth = button.offsetWidth;
    
    // Find the icon markup span and last text node (the visible button text)
    const iconMarkup = button.querySelector('.icon-markup');
    const textNodes = Array.from(button.childNodes).filter(node => node.nodeType === Node.TEXT_NODE);
    const lastTextNode = textNodes[textNodes.length - 1]; // Assume last text node is the button text
    
    // Store original values
    const originalIconText = iconMarkup ? iconMarkup.textContent : '';
    const originalButtonText = lastTextNode ? lastTextNode.textContent : '';
    
    // Set fixed width to prevent size changes
    button.style.width = originalWidth + 'px';
    
    // Update icon and text
    if (iconMarkup) {
        iconMarkup.textContent = '✅';
    }
    if (lastTextNode) {
        lastTextNode.textContent = ' Copied!';
    }
    
    button.classList.add('btn-success');
    button.classList.remove('btn-outline-secondary');
    
    setTimeout(() => {
        // Restore original content
        if (iconMarkup) {
            iconMarkup.textContent = originalIconText;
        }
        if (lastTextNode) {
            lastTextNode.textContent = originalButtonText;
        }
        
        button.classList.remove('btn-success');
        button.classList.add('btn-outline-secondary');
        // Remove fixed width to restore normal behavior
        button.style.width = '';
    }, 2000);
}

function showManualCopyMessage() {
    alert('Copy failed. Please select the text manually and copy with Ctrl+C (Cmd+C on Mac).');
}

/**
 * Handle token generation
 */
function generateToken() {
    showLoading(true);
    hideMessages();
    
    const generateBtn = document.getElementById('generate-token-btn');
    if (generateBtn) {
        generateBtn.disabled = true;
    }
    
    fetch(TYPO3.settings.ajaxUrls['mcp_server_generate_token'], {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        showLoading(false);
        
        if (data.success) {
            showSuccessMessage('Token generated successfully! The page will reload to show the updated status.');
            // Reload page after 2 seconds to show updated token status
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            showErrorMessage(data.message || 'Failed to generate token');
            if (generateBtn) {
                generateBtn.disabled = false;
            }
        }
    })
    .catch(error => {
        showLoading(false);
        showErrorMessage('Network error: ' + error.message);
        if (generateBtn) {
            generateBtn.disabled = false;
        }
    });
}

/**
 * Handle token refresh
 */
function refreshToken() {
    showLoading(true);
    hideMessages();
    
    const refreshBtn = document.getElementById('refresh-token-btn');
    if (refreshBtn) {
        refreshBtn.disabled = true;
    }
    
    fetch(TYPO3.settings.ajaxUrls['mcp_server_refresh_token'], {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        showLoading(false);
        
        if (data.success) {
            showSuccessMessage('Token refreshed successfully! The page will reload to show the updated status.');
            // Reload page after 2 seconds to show updated token status
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            showErrorMessage(data.message || 'Failed to refresh token');
            if (refreshBtn) {
                refreshBtn.disabled = false;
            }
        }
    })
    .catch(error => {
        showLoading(false);
        showErrorMessage('Network error: ' + error.message);
        if (refreshBtn) {
            refreshBtn.disabled = false;
        }
    });
}

/**
 * Show loading indicator
 */
function showLoading(show = true) {
    const messagesContainer = document.getElementById('token-messages');
    const loadingDiv = document.getElementById('token-loading');
    const successDiv = document.getElementById('token-success');
    const errorDiv = document.getElementById('token-error');
    
    if (messagesContainer && loadingDiv) {
        if (show) {
            messagesContainer.style.display = 'block';
            loadingDiv.style.display = 'block';
            if (successDiv) successDiv.style.display = 'none';
            if (errorDiv) errorDiv.style.display = 'none';
        } else {
            loadingDiv.style.display = 'none';
        }
    }
}

/**
 * Show success message
 */
function showSuccessMessage(message, autoHide = false) {
    const messagesContainer = document.getElementById('token-messages');
    const successDiv = document.getElementById('token-success');
    const errorDiv = document.getElementById('token-error');
    
    if (messagesContainer && successDiv) {
        messagesContainer.style.display = 'block';
        successDiv.style.display = 'block';
        successDiv.textContent = message;
        if (errorDiv) errorDiv.style.display = 'none';
        
        if (autoHide) {
            setTimeout(() => {
                messagesContainer.style.display = 'none';
            }, 3000);
        }
    }
}

/**
 * Show error message
 */
function showErrorMessage(message) {
    const messagesContainer = document.getElementById('token-messages');
    const errorDiv = document.getElementById('token-error');
    const successDiv = document.getElementById('token-success');
    
    if (messagesContainer && errorDiv) {
        messagesContainer.style.display = 'block';
        errorDiv.style.display = 'block';
        errorDiv.textContent = message;
        if (successDiv) successDiv.style.display = 'none';
    }
}

/**
 * Hide all messages
 */
function hideMessages() {
    const successDiv = document.getElementById('token-success');
    const errorDiv = document.getElementById('token-error');
    const loadingDiv = document.getElementById('token-loading');
    
    if (successDiv) {
        successDiv.style.display = 'none';
    }
    if (errorDiv) {
        errorDiv.style.display = 'none';
    }
    if (loadingDiv) {
        loadingDiv.style.display = 'none';
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Add event listeners to all copy buttons using data attributes
    const copyButtons = document.querySelectorAll('.copy-button[data-copy-target]');
    copyButtons.forEach(button => {
        const targetId = button.getAttribute('data-copy-target');
        if (targetId) {
            button.addEventListener('click', () => copyToClipboard(targetId, button));
        }
    });
    
    // Add event listeners for token management buttons
    const generateBtn = document.getElementById('generate-token-btn');
    if (generateBtn) {
        generateBtn.addEventListener('click', generateToken);
    }
    
    const refreshBtn = document.getElementById('refresh-token-btn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', refreshToken);
    }
    
    // Add event listener for token refresh (OAuth tokens table)
    const refreshTokensBtn = document.getElementById('refresh-tokens-btn');
    if (refreshTokensBtn) {
        refreshTokensBtn.addEventListener('click', refreshTokens);
    }
    
    // Add event listener for revoke all tokens
    const revokeAllTokensBtn = document.getElementById('revoke-all-tokens-btn');
    if (revokeAllTokensBtn) {
        revokeAllTokensBtn.addEventListener('click', revokeAllTokens);
    }
    
    // Add event listener for create mcp-remote token
    const createMcpRemoteTokenBtn = document.getElementById('create-mcp-remote-token-btn');
    if (createMcpRemoteTokenBtn) {
        createMcpRemoteTokenBtn.addEventListener('click', createMcpRemoteToken);
    }
    
    // Add event delegation for token revocation buttons (dynamically created)
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('revoke-token-btn') || e.target.closest('.revoke-token-btn')) {
            const button = e.target.classList.contains('revoke-token-btn') ? e.target : e.target.closest('.revoke-token-btn');
            const tokenId = button.getAttribute('data-token-id');
            
            console.log('Revoke button clicked, tokenId:', tokenId, 'button:', button);
            
            if (tokenId && confirm('Are you sure you want to revoke this token? The associated MCP client will lose access immediately.')) {
                revokeToken(tokenId);
            }
        }
    });
    
    // Check OAuth endpoints status
    checkEndpointStatuses();
});

/**
 * Token Management Functions for OAuth Tokens Table
 */

/**
 * Refresh the OAuth tokens table
 */
function refreshTokens() {
    showLoading();
    
    fetch(TYPO3.settings.ajaxUrls.mcp_server_get_tokens, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
    })
    .then(response => response.json())
    .then(data => {
        showLoading(false);
        if (data.success) {
            updateTokensTable(data.tokens);
        } else {
            showErrorMessage('Failed to refresh tokens: ' + data.message);
        }
    })
    .catch(error => {
        showLoading(false);
        showErrorMessage('Error refreshing tokens: ' + error.message);
    });
}

/**
 * Revoke a specific token
 */
function revokeToken(tokenId) {
    showLoading();
    
    // Ensure tokenId is an integer
    const tokenIdInt = parseInt(tokenId, 10);
    
    if (!tokenIdInt || tokenIdInt <= 0) {
        showErrorMessage('Invalid token ID: ' + tokenId);
        showLoading(false);
        return;
    }
    
    fetch(TYPO3.settings.ajaxUrls.mcp_server_revoke_token, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            tokenId: tokenIdInt
        })
    })
    .then(response => response.json())
    .then(data => {
        showLoading(false);
        if (data.success) {
            showSuccessMessage(data.message, true);
            refreshTokens(); // Refresh the token list
        } else {
            showErrorMessage('Failed to revoke token: ' + data.message);
        }
    })
    .catch(error => {
        showLoading(false);
        showErrorMessage('Error revoking token: ' + error.message);
    });
}

/**
 * Revoke all tokens for the current user
 */
function revokeAllTokens() {
    if (!confirm('Are you sure you want to revoke ALL tokens? This will disconnect all MCP clients and require re-authentication.')) {
        return;
    }
    
    showLoading();
    
    fetch(TYPO3.settings.ajaxUrls.mcp_server_revoke_all_tokens, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        showLoading(false);
        if (data.success) {
            showSuccessMessage(data.message, true);
            refreshTokens(); // Refresh the token list to show empty state
        } else {
            showErrorMessage('Failed to revoke all tokens: ' + data.message);
        }
    })
    .catch(error => {
        showLoading(false);
        showErrorMessage('Error revoking all tokens: ' + error.message);
    });
}

/**
 * Update the tokens table with new data
 */
function updateTokensTable(tokens) {
    const container = document.getElementById('tokens-container');
    
    if (!container) {
        console.error('Tokens container not found');
        return;
    }
    
    if (tokens.length === 0) {
        container.innerHTML = `
            <div id="no-tokens-message" class="text-center text-muted py-4">
                <div class="mb-3">
                    <span style="font-size: 2rem;">🔑</span>
                </div>
                <p>No active OAuth tokens found.</p>
                <p class="small">Use the Remote MCP Setup above to connect your first MCP client.</p>
            </div>
        `;
    } else {
        const tableHTML = `
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Client Name</th>
                            <th>Created</th>
                            <th>Last Used</th>
                            <th>Expires</th>
                            <th>Token</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tokens-table-body">
                        ${tokens.map(token => `
                            <tr data-token-id="${token.uid}">
                                <td><strong>${token.client_name}</strong></td>
                                <td><small class="text-muted">${token.created}</small></td>
                                <td><small class="text-muted">${token.last_used}</small></td>
                                <td><small class="text-muted">${token.expires}</small></td>
                                <td><code class="small">${token.token_preview}</code></td>
                                <td>
                                    <button class="btn btn-sm btn-danger revoke-token-btn" data-token-id="${token.uid}">
                                        <span class="t3js-icon icon icon-size-small icon-state-default icon-actions-delete" data-identifier="actions-delete">
                                            <span class="icon-markup">🗑️</span>
                                        </span>
                                        Revoke
                                    </button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        container.innerHTML = tableHTML;
    }
}

/**
 * Create mcp-remote token
 */
function createMcpRemoteToken() {
    showLoading();
    
    const button = document.getElementById('create-mcp-remote-token-btn');
    if (button) {
        button.disabled = true;
    }
    
    fetch(TYPO3.settings.ajaxUrls.mcp_server_create_token, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        showLoading(false);
        if (data.success) {
            showSuccessMessage('mcp-remote token created successfully! Refreshing page to show the token URL.');
            // Reload page after 2 seconds to show updated token status
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            showErrorMessage(data.message || 'Failed to create mcp-remote token');
            if (button) {
                button.disabled = false;
            }
        }
    })
    .catch(error => {
        showLoading(false);
        showErrorMessage('Error creating token: ' + error.message);
        if (button) {
            button.disabled = false;
        }
    });
}

/**
 * Check OAuth endpoint statuses
 */
function checkEndpointStatuses() {
    const endpointElements = document.querySelectorAll('.endpoint-status');
    
    endpointElements.forEach(element => {
        const endpoint = element.getAttribute('data-endpoint');
        const checkContent = element.getAttribute('data-check-content') === 'true';
        
        if (endpoint) {
            checkEndpoint(element, endpoint, checkContent);
        }
    });
}

/**
 * Check a single endpoint
 */
function checkEndpoint(element, endpoint, checkContent) {
    // Set checking state
    element.classList.add('checking');
    element.classList.remove('success', 'warning', 'error');
    
    const statusIcon = element.querySelector('.status-icon');
    const statusTooltip = element.querySelector('.status-tooltip');
    
    // Make the request
    fetch(endpoint, {
        method: 'GET',
        headers: {
            'Accept': 'application/json'
        },
        mode: 'cors',
        credentials: 'same-origin'
    })
    .then(response => {
        if (response.ok) {
            // Endpoint is reachable
            if (checkContent) {
                return response.text().then(text => {
                    // Check if our MCP endpoint is mentioned
                    if (text.includes('/mcp')) {
                        setEndpointStatus(element, 'success', 'Endpoint is working correctly');
                    } else {
                        setEndpointStatus(element, 'warning', 'Endpoint is reachable but does not mention MCP endpoint');
                    }
                });
            } else {
                setEndpointStatus(element, 'success', 'Endpoint is reachable');
            }
        } else {
            // Endpoint returned an error
            setEndpointStatus(element, 'error', `Endpoint returned ${response.status} ${response.statusText}`);
        }
    })
    .catch(error => {
        // Network error or CORS issue
        if (error.message.includes('CORS') || error.message.includes('blocked')) {
            setEndpointStatus(element, 'error', 'Endpoint may be blocked by CORS policy or security settings');
        } else {
            setEndpointStatus(element, 'error', `Network error: ${error.message}`);
        }
    });
}

/**
 * Set endpoint status
 */
function setEndpointStatus(element, status, message) {
    element.classList.remove('checking', 'success', 'warning', 'error');
    element.classList.add(status);
    
    const statusTooltip = element.querySelector('.status-tooltip');
    if (statusTooltip) {
        statusTooltip.textContent = message;
    }
}
