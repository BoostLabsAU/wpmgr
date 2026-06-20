<?php
/**
 * CpUrlProvider interface — anything that can produce the control-plane base URL.
 *
 * Implemented by Settings (the real class) and by test doubles.
 *
 * @package WPMgr\Agent\Security
 */

declare(strict_types=1);

namespace WPMgr\Agent\Security;

/**
 * Provides the control-plane base URL.
 */
interface CpUrlProvider
{
    /**
     * Return the configured control-plane base URL (no trailing slash).
     *
     * @return string
     */
    public function controlPlaneUrl(): string;
}
