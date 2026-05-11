<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Meta;

\defined('_JEXEC') or die;

/**
 * Builds 4SEO's canonical content_id for a Joomla URL. Matches the format
 * 4SEO writes to #__forseo_custom_meta.content_id — alphabetised
 * `key=value` query-string pairs joined with `&`, no leading `?`.
 *
 * Examples (verified live against stage v6.12.0):
 *   ['option'=>'com_content','view'=>'article','id'=>42]
 *     → "id=42&option=com_content&view=article"
 *
 *   ['option'=>'com_jdbuilder','view'=>'page','id'=>10,'Itemid'=>101]
 *     → "Itemid=101&id=10&option=com_jdbuilder&view=page"
 *
 * Source-of-truth: plugins/system/forseo/platform/components/content.php
 * line 225, where 4SEO constructs the article content_id as the literal
 * string above.
 */
trait ContentIdTrait
{
	/**
	 * @param array<string, scalar> $params  URL query params (e.g. option, view, id, Itemid)
	 */
	protected function buildContentId(array $params): string
	{
		// Drop empty values — 4SEO doesn't include them
		$filtered = array_filter(
			$params,
			fn ($v) => $v !== null && $v !== '' && $v !== false
		);

		// Sort by key, case-sensitive ASCII (PHP's default ksort). 4SEO's
		// observed behaviour: "Itemid" sorts before "id" because 'I' < 'i'
		// in ASCII.
		ksort($filtered, SORT_STRING);

		$parts = [];
		foreach ($filtered as $k => $v) {
			$parts[] = $k . '=' . $v;
		}
		return implode('&', $parts);
	}

	/**
	 * Resolve a content_id from either an explicit content_id string or
	 * structured joomla_params. Throws if neither is supplied or if the
	 * resolved string is empty.
	 */
	protected function resolveContentId(array $arguments): string
	{
		if (!empty($arguments['content_id']) && is_string($arguments['content_id'])) {
			return trim($arguments['content_id']);
		}
		if (!empty($arguments['joomla_params']) && is_array($arguments['joomla_params'])) {
			$built = $this->buildContentId($arguments['joomla_params']);
			if ($built !== '') {
				return $built;
			}
		}
		// Convenience: bare article_id implies com_content.article
		if (isset($arguments['article_id']) && (int) $arguments['article_id'] > 0) {
			return $this->buildContentId([
				'id'      => (int) $arguments['article_id'],
				'option'  => 'com_content',
				'view'    => 'article',
			]);
		}
		throw new \InvalidArgumentException(
			'Provide content_id (string), joomla_params (object with option/view/id/Itemid), or article_id (integer shorthand for a com_content article).'
		);
	}
}
