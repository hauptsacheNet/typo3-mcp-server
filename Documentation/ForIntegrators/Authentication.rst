..  include:: /Includes.rst.txt

..  _integrators_authentication:

================
Authentication
================

The MCP server supports two transports with different authentication
characteristics. As an integrator you need to know what each costs, what
each permits, and how to manage credentials.

OAuth (HTTP transport)
======================

For all remote clients (Claude Desktop, ChatGPT Desktop, custom HTTP
clients), the MCP server runs an OAuth code-and-token flow tied to TYPO3
backend users. The flow:

1.  An editor opens :guilabel:`[Username] → MCP Server` in the TYPO3
    backend and copies the **Server URL**.
2.  The MCP client posts to the URL to register an integration.
3.  The MCP server returns a short-lived authorisation code (10 minutes).
4.  The user is redirected into TYPO3 to confirm the integration.
5.  After confirmation, the client exchanges the code for an access
    token (default 30-day lifetime).
6.  Every subsequent MCP call sends the token in the standard ``Authorization:
    Bearer`` header.

Tokens are stored hashed in the ``tx_mcpserver_oauth_codes`` table — the
plaintext is only visible to the client. The implementation lives in
:file:`Classes/Service/OAuthService.php`.

Listing and revoking tokens
---------------------------

The MCP module in the backend (under the user dropdown) lists active
integrations for the current user. Revoking removes the token from the
database; the client gets a 401 on its next call.

For batch operations (cleanup of stale integrations, mass revocation), use
the console command:

..  code-block:: bash

    vendor/bin/typo3 mcp:oauth list
    vendor/bin/typo3 mcp:oauth revoke <token-id>

Permissions
-----------

Tokens carry the permissions of the backend user that authorised them.
There are no MCP-specific scopes; the LLM gets exactly what the user
could do via the backend:

*   Page permissions (read / write / delete) per page tree branch.
*   Table permissions per user group.
*   TCA exclude fields per user group.
*   Workspace assignments and ACLs.
*   File mounts.

This means token security is backend-user security: a compromised token
gives the same access as a compromised backend account.

Stdio (CLI transport)
=====================

The stdio transport bypasses OAuth entirely — there's no token to manage.
Instead, the MCP server runs in the same process as the client (or as a
subprocess launched by the client), inheriting the OS user, the
``$TYPO3_PATH_ROOT``, and whatever backend user impersonation the calling
process establishes.

When started via :bash:`vendor/bin/typo3 mcp:server` on a server where the
process has admin-equivalent file access, the MCP server impersonates the
default admin context. **This is the right transport for local
development and for trusted automation jobs; it is the wrong transport
for granting an external user access.**

A subtle consequence: an editor with low backend permissions cannot use
stdio to gain admin access; the OS user running ``mcp:server`` is what
matters, not who connects to it. But if the OS user has shell access on
the server, all bets are off — stdio is "trust the local environment".

Choosing a transport
====================

..  list-table::
    :header-rows: 1

    *   - Use case
        - Transport
    *   - Editor with their own TYPO3 account, remote machine
        - OAuth
    *   - Multiple editors with different permissions
        - OAuth, one token per editor
    *   - Local development
        - stdio
    *   - CI / scheduled MCP-driven job
        - stdio under a dedicated OS account, OR OAuth with a
          rotated long-lived token
    *   - Public-facing or zero-trust integration
        - OAuth, with monitoring on the token list

Security checklist
==================

*   HTTPS on the TYPO3 backend URL. The OAuth flow exchanges credentials
    in URL fragments and the bearer token is sent on every call.
*   Rotate tokens. The 30-day default is a ceiling, not a target —
    revoke unused integrations periodically.
*   Audit who is connected. The MCP backend module shows active
    integrations; the :doc:`AfterRecordWriteEvent
    <Customization/EventsReference>` lets you log every write the LLM
    makes if you need a paper trail.
*   Don't share tokens across editors. Each editor authenticating
    individually gives you per-user permissions and per-user audit
    trails for free.
*   Treat stdio access like SSH access. Anyone who can run
    ``mcp:server`` on the box can act as the OS user.
