<?php
/**
 * SuppressionCheckerInterface — minimal seam that ProviderRouter uses for
 * the pre-send suppression check.
 *
 * Extracted so ProviderRouter tests can use a simple mock instead of
 * depending on the concrete (final) SuppressionCache class.
 *
 * @package WPMgr\Agent\Email
 */

declare(strict_types=1);

namespace WPMgr\Agent\Email;

/**
 * Contract for the local suppression-list check.
 */
interface SuppressionCheckerInterface
{
	/**
	 * Return true when the given email address is in the local suppression cache
	 * and should not receive mail.
	 *
	 * @param string $email Recipient email address.
	 * @return bool
	 */
	public function is_suppressed( string $email ): bool;
}
