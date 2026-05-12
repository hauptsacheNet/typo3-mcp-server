..  include:: /Includes.rst.txt

..  _quickstart_connecting_clients:

==================
Connecting clients
==================

There are two transports. Pick the one that matches your use case.

..  _quickstart_oauth:

OAuth (recommended)
===================

Use this for desktop apps and any client running outside the TYPO3 server
process. The client receives an access token tied to a specific backend user,
and every MCP operation runs with that user's permissions and workspace.

1.  Open :guilabel:`[Username] → MCP Server` in the TYPO3 backend.
2.  Copy the **Server URL** (and optionally the **Integration Name**).
3.  Add the URL in your MCP client's settings. For Claude Desktop, that's
    :guilabel:`Settings → Developer → Edit Config` → add an entry under
    ``mcpServers``.
4.  The client opens a browser tab; sign in with the backend user that should
    perform the edits; approve the connection.
5.  The MCP server hands back a token (30-day lifetime by default). The client
    stores it and sends it on every subsequent call.

Access tokens are listed and can be revoked under
:guilabel:`[Username] → MCP Server`. Old tokens for unused integrations should
be removed there.

..  _quickstart_stdio:

Local stdio
===========

Use this when you are running the client on the same machine as TYPO3 (typical
for development), or when you need admin privileges without managing a token.
This transport runs as the user that started the process, so it bypasses the
OAuth flow.

Add the following to your client's MCP configuration:

..  code-block:: json

    {
      "mcpServers": {
        "your-typo3-name": {
          "command": "php",
          "args": [
            "vendor/bin/typo3",
            "mcp:server"
          ]
        }
      }
    }

Adjust the path to ``vendor/bin/typo3`` if you run the client from a different
working directory.

When to pick which
==================

..  list-table::
    :header-rows: 1

    *   - Situation
        - Use
    *   - Editor on a Mac/Windows laptop, remote TYPO3
        - OAuth
    *   - Developer on the same machine as TYPO3
        - stdio
    *   - Need admin permissions for setup tasks
        - stdio (runs as the CLI user)
    *   - Multiple editors, each with their own permissions
        - OAuth, one connection per editor
    *   - CI / automated agent without an interactive browser
        - stdio with a TYPO3 user impersonation, or OAuth with a long-lived
          token

For the security model behind OAuth (token storage, scopes, revocation), see
:doc:`../ForIntegrators/Authentication`.
