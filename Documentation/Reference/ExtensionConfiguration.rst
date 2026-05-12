..  include:: /Includes.rst.txt

..  _reference_extension_configuration:

=========================
Extension settings
=========================

Lookup table for the settings under :guilabel:`Settings → Extension
Configuration → mcp_server`. For the rationale and worked examples see
:doc:`../ForIntegrators/ExtensionConfiguration`.

..  list-table::
    :header-rows: 1
    :widths: 25 15 60

    *   - Key
        - Default
        - Description
    *   - ``additionalReadOnlyTables``
        - ``sys_file``
        - Comma-separated list of non-workspace-capable tables to expose
          read-only. Shown in ``ListTables`` and readable via
          ``ReadTable``; ``WriteTable`` rejects them.
    *   - ``additionalStandaloneTables``
        - ``sys_file_metadata``
        - Comma-separated list of ``hideTable = true`` tables to expose as
          independent records rather than embedded inline children. Useful
          for tables with their own translations, mounts, or visibility
          rules.

Both settings are read at runtime by :php:`TableAccessService`:

*   :php:`TableAccessService::getAdditionalReadOnlyTables()`
    (:file:`Classes/Service/TableAccessService.php`)
*   :php:`TableAccessService::getAdditionalStandaloneTables()`

Format and parsing:

*   Tokens are trimmed (``GeneralUtility::trimExplode``).
*   Empty tokens are skipped.
*   Order is irrelevant.

Both settings can be changed at runtime; values are cached per request
on the singleton ``TableAccessService`` instance.
