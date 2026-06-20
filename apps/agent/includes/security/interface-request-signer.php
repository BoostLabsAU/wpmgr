<?php
/**
 * RequestSigner interface — anything that can sign outbound agent→CP requests.
 *
 * Implemented by Signer (the real class) and by test doubles.
 *
 * @package WPMgr\Agent\Security
 */

declare(strict_types=1);

namespace WPMgr\Agent\Security;

/**
 * Signs outbound agent→CP requests by returning the four auth headers.
 */
interface RequestSigner
{
    /**
     * Produce the four agent-auth headers for an outbound request.
     *
     * @param string $method HTTP method ('GET', 'POST', …).
     * @param string $path   Request path (e.g. '/api/v1/security/hibp/range/…').
     * @param string $body   Raw request body (empty string for GET).
     * @return array<string,string> Header name → header value.
     */
    public function signHeaders(string $method, string $path, string $body): array;
}
