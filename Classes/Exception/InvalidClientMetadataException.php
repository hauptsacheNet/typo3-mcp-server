<?php

declare(strict_types=1);

namespace Hn\McpServer\Exception;

/**
 * Client metadata of a dynamic client registration (RFC 7591) is not acceptable.
 *
 * Reported to the client as "invalid_client_metadata" (RFC 7591 §3.2.2).
 */
class InvalidClientMetadataException extends \InvalidArgumentException
{
}
