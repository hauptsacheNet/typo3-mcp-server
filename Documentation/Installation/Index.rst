..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

Requirements
============

*   TYPO3 13.4 LTS or 14.3+
*   PHP 8.2 or newer
*   The TYPO3 ``workspaces`` system extension (declared as a dependency, so
   Composer pulls it automatically)

The extension targets composer installations. There is no classic-mode
install path.

Install via Composer
====================

..  code-block:: bash

    composer require hn/typo3-mcp-server

After Composer finishes, install the extension in the **Admin Tools → Extensions**
module (or via :bash:`vendor/bin/typo3 extension:setup`).

The workspaces system extension is required and will be activated automatically
if it is not already.

..  _installation_settings:

Extension settings
==================

Two settings under :guilabel:`Settings → Extension Configuration → mcp_server`
control which tables the MCP exposes. Both default to a reasonable file-handling
setup; you only need to touch them if you want to expose additional tables —
typically when integrating extensions like ``georgringer/news`` or a blog
extension.

``additionalReadOnlyTables``
    Comma-separated list of tables that do **not** support workspaces but should
    still be readable through MCP. Default: ``sys_file``.

``additionalStandaloneTables``
    Comma-separated list of tables marked :php:`hideTable = true` in TCA that
    should be exposed as independent tables instead of being embedded into
    their parent's inline relation. Default: ``sys_file_metadata``.

Full details, defaults, and worked examples for typical extensions are in
:doc:`../ForIntegrators/ExtensionConfiguration`.

..  _installation_verify:

Verifying the installation
==========================

The fastest way to confirm the extension is wired up correctly is the
:bash:`mcp:test` console command:

..  code-block:: bash

    vendor/bin/typo3 mcp:test ListTables

It prints the list of tables the MCP currently exposes. If that command works,
the rest of the tools work too — they all share the same access logic.

For a guided post-install walkthrough (OAuth, first prompt, reviewing the
result in the Workspaces module), continue with :doc:`../QuickStart/Index`. For
a more thorough acceptance test of a specific TYPO3 installation, follow
:doc:`../Testing/PostInstallSmokeTest`.
