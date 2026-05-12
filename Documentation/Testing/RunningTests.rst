..  include:: /Includes.rst.txt

..  _testing_running_tests:

==============
Running tests
==============

The extension ships with two test suites: a fast functional suite that
exercises every tool against TYPO3 directly, and an LLM end-to-end suite
that drives real models against the MCP. Both run from the repository
root.

Functional tests
================

..  code-block:: bash

    composer test

Runs all PHPUnit functional tests in :file:`Tests/Functional/`. Uses
``paratest`` for parallelism. The default backend is SQLite for speed;
override via environment variables for MySQL or PostgreSQL — see
``Build/runTests.sh -h``.

What's covered: tool registration, every read/write/search path, workspace
edge cases (delete placeholders, new records, translation), permission
checks, FlexForm handling, file enrichment, event dispatch.

Adding a new functional test for your customisation
---------------------------------------------------

See :doc:`WritingFunctionalTests` for the patterns. New tests go under
:file:`Tests/Functional/` in your extension and run with the same
:bash:`composer test` invocation, provided your extension declares the
testing framework as a dev dependency.

End-to-end tests with Playwright (browser flow)
===============================================

..  code-block:: bash

    Build/runTests.sh -s e2e

Spins up MySQL, TYPO3, and Playwright in Docker containers and runs the
browser-driven OAuth flow. The Docker compose stack is described in
:file:`Build/docker-compose.yml`.

If Docker is unavailable on the host, the script falls back to a local
mode (host PHP + SQLite + local Playwright). You can force this mode
explicitly:

..  code-block:: bash

    Build/runTests.sh -s e2e --no-docker

To run against an existing TYPO3 instance (a ddev project, a staging
server, etc.):

..  code-block:: bash

    TYPO3_BASE_URL=https://my.ddev.site Build/runTests.sh -s e2e

LLM end-to-end tests
====================

..  code-block:: bash

    composer test:llm

Runs realistic scenarios against real LLMs through the MCP — translation,
content creation, SEO updates, news article generation. Defined under
:file:`Tests/Llm/`. Results land in :file:`.Build/llm-results.xml` and
the helper :file:`Build/check-llm-results.php` asserts a minimum pass
rate (defaults to 3 of N).

These tests are non-deterministic by nature — they're a regression
floor, not a strict assertion. See :file:`Tests/Llm/README.md` for the
infrastructure details (model configuration, retry hooks, retry log).

Code quality
============

..  code-block:: bash

    Build/runTests.sh -s lint

Lints PHP and runs static analysis as configured in the repository.

All options
===========

..  code-block:: bash

    Build/runTests.sh -h

…lists every supported flag: PHP version, database backend, MariaDB vs.
MySQL, suite selection, container vs. local mode.

CI integration
==============

A reasonable CI pipeline for an extension that integrates with the MCP:

1.  :bash:`composer test` for functional tests.
2.  :bash:`Build/runTests.sh -s lint` for code style.
3.  A subset of :bash:`composer test:llm` on selected branches (the
    full suite is slow and costs API calls).

The functional layer is fast and deterministic enough to be a pre-commit
gate. The LLM layer is better run on PRs and nightly.
