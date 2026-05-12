..  include:: /Includes.rst.txt

..  _quickstart:

===========
Quick start
===========

This chapter gets an MCP client talking to your TYPO3 instance in about five
minutes. It assumes the extension is already installed
(:doc:`../Installation/Index`) and that you have a backend account that can edit
content.

There are two ways to connect: an **OAuth-based remote connection** for
desktop apps like Claude Desktop, and a **local stdio connection** for command
line clients and admin-level access.

..  toctree::
    :maxdepth: 1

    ConnectingClients

The five-minute path
====================

1.  In the TYPO3 backend, open :guilabel:`[Username] → MCP Server` from the
    user dropdown in the top right. Copy the **Server URL** shown there.

2.  Add the URL as a connection in your MCP client. For Claude Desktop this
    means adding an MCP server in :guilabel:`Settings → Developer`. The client
    opens a browser tab, you authenticate as your backend user, and confirm
    the connection.

3.  Ask the LLM something concrete that grounds it in your site:

    ..  code-block:: text

        Show me the page tree starting from the root, three levels deep.

    The LLM will call the ``GetPageTree`` tool and answer with a tree of pages.

4.  Make a small change. Pick a page from the tree and ask for a tweak you
    can easily reverse:

    ..  code-block:: text

        On page /about-us, change the heading of the first content
        element to "About our team".

    The LLM walks the page with ``GetPage``, finds the element with
    ``ReadTable`` and updates it with ``WriteTable``.

5.  Review the change. Open the :guilabel:`Workspaces` module in the backend.
    You will see the new draft. Publish or discard it from there — nothing has
    touched the live site yet.

That's the loop: prompt, the LLM operates in your workspace, you review and
publish.

What to read next
=================

If you are the editor who will use this day-to-day, continue with
:doc:`../ForEditors/Index` — what to expect, how to prompt, and how to review.

If you are setting this up for an editor team, the **integrator** track
covers how to make the LLM behave well on your specific site:
:doc:`../ForIntegrators/Index`. The first thing to check is
:doc:`../ForIntegrators/ExtensionConfiguration` if you use third-party
extensions like news or blog — they often need a small table-visibility tweak
before the LLM can interact with them.
