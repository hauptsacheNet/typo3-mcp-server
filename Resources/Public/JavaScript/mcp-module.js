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
});