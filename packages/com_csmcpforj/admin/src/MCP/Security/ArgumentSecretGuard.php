<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\MCP\Security;

\defined('_JEXEC') or die;

/**
 * Inspects tool arguments for the presence of known secret strings before a
 * tool dispatches, and aborts the call if any are found.
 *
 * Concept independently developed for cs-mcp-for-j after studying the same
 * problem space addressed by nikosdion/joomla-mcp-php's SecretLeakPreventionTrait
 * (AGPL-3.0) — no code was copied. The threat model is: a prompt-injection
 * attempt that smuggles "&dlid=BEARER_TOKEN..." or the verbatim API token
 * into a tool argument so the AI unwittingly POSTs it to a tracking pixel,
 * pastes it into an article body, or echoes it back to the user. This guard
 * is a server-side circuit breaker — even if the LLM is fooled, the request
 * never reaches the tool handler.
 *
 * Scope: scans only what's passed as tool arguments. The secret itself
 * is supplied by the caller (typically the McpController, which reads the
 * authenticated request's Bearer token from $_SERVER). The guard does NOT
 * read $_SERVER itself — keeps the surface narrow and testable.
 */
final class ArgumentSecretGuard
{
	/**
	 * Throw \RuntimeException if any string-valued leaf inside $arguments
	 * contains any of the $secrets (using a fast O(n) hash_equals-style
	 * substring match — not a regex — so a single secret check is constant
	 * cost regardless of payload size).
	 *
	 * Empty / whitespace-only secrets are silently ignored so a missing
	 * Authorization header in dev mode never produces a "secret '' was
	 * found in everything" false positive.
	 *
	 * @param array<int|string, mixed> $arguments  The tool's $arguments payload
	 * @param array<int, string>       $secrets    Strings that must not appear in any value
	 */
	public static function assertNoSecretsIn(array $arguments, array $secrets): void
	{
		$secrets = array_values(array_unique(array_filter(array_map(
			static fn($s): string => trim((string) $s),
			$secrets
		), static fn(string $s): bool => $s !== '' && strlen($s) >= 8)));

		if ($secrets === []) {
			return;
		}

		self::walk($arguments, $secrets);
	}

	/**
	 * Recursive worker. Walks every scalar leaf in the tree; when it finds a
	 * string, checks all secrets. Stops at the first hit and throws — we don't
	 * leak which secret matched, just that one did.
	 *
	 * @param mixed              $value
	 * @param array<int, string> $secrets
	 */
	private static function walk(mixed $value, array $secrets): void
	{
		if (is_string($value)) {
			foreach ($secrets as $secret) {
				if (str_contains($value, $secret)) {
					throw new \RuntimeException(
						'Refused: tool arguments contain a value that matches a credential held by '
						. 'this MCP session. This usually indicates a prompt-injection attempt where '
						. 'a piece of untrusted content (a fetched URL, an article body, a user '
						. 'message) tried to embed your authentication token into a downstream tool '
						. 'call. The request was rejected before reaching the tool handler. If you '
						. 'genuinely need to pass this value to a tool, change the value or rotate '
						. 'your Joomla API token.'
					);
				}
			}
			return;
		}

		if (is_array($value)) {
			foreach ($value as $sub) {
				self::walk($sub, $secrets);
			}
		}

		// Scalars other than strings (int, float, bool) can't carry a token,
		// and resources/objects shouldn't reach a JSON-decoded MCP payload.
		// Anything we don't recognise we leave alone.
	}
}
