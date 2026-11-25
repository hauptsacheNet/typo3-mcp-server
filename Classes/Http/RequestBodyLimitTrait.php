<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads a request body without ever buffering more than a fixed number of bytes.
 *
 * A JSON document grows many times its transfer size once decoded into PHP
 * arrays, so an endpoint that buffers and decodes whatever it is sent can be
 * driven to its memory_limit with a single request. PHP's post_max_size does
 * not help: it only limits the parsing of form data into $_POST, php://input
 * still delivers the whole body.
 */
trait RequestBodyLimitTrait
{
    /**
     * @return string|null the body, or null when it is larger than $maxBytes
     */
    protected function readBodyWithLimit(ServerRequestInterface $request, int $maxBytes): ?string
    {
        // A declared length above the limit is rejected without reading anything.
        $contentLength = trim($request->getHeaderLine('Content-Length'));
        if ($contentLength !== '' && ctype_digit($contentLength)) {
            // Longer than any length within the limit can be: do not rely on
            // an integer cast of an arbitrarily long digit string.
            if (strlen(ltrim($contentLength, '0')) > 12 || (int)$contentLength > $maxBytes) {
                return null;
            }
        }

        // The header may be absent (chunked transfer encoding) or wrong, so the
        // limit is enforced while reading as well: one byte more than allowed
        // is enough to know the body is too large.
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';
        $remaining = $maxBytes + 1;
        while ($remaining > 0 && !$stream->eof()) {
            $chunk = $stream->read(min(65536, $remaining));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
            $remaining -= strlen($chunk);
        }

        return strlen($body) > $maxBytes ? null : $body;
    }
}
