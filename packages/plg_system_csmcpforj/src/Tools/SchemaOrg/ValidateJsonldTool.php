<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Lightweight JSON-LD shape validator. Designed to be called BEFORE a bulk
 * write to catch the common mistakes that would otherwise produce a hundred
 * silent-but-broken schema rows. NOT a Schema.org spec validator (that's
 * Google's Rich Results Test or schema.org/validator) — those need network
 * calls and are slower than makes sense inside a tool loop.
 *
 * What it checks:
 *  - Has @context (warns if missing — Joomla's Custom plugin will accept it
 *    but Google won't recognise the type)
 *  - Has @type (errors if missing — without it the block is meaningless)
 *  - For known @type values, checks for the fields Google's Rich Results
 *    documentation marks as required or strongly recommended
 *  - Flags wrapping in @graph (Joomla 5.1+ merges into the page graph
 *    automatically — see set_article_custom_jsonld description)
 */
final class ValidateJsonldTool extends AbstractTool
{
	/**
	 * Required + strongly-recommended fields per @type.
	 * Sourced from Google's Rich Results documentation as of 2026-05.
	 * Not exhaustive — agents adding novel types should treat absence
	 * from this map as "we don't know, no warnings will fire".
	 *
	 * @var array<string, array{required: array<int,string>, recommended: array<int,string>}>
	 */
	private const TYPE_RULES = [
		'Article' => [
			'required'    => ['headline'],
			'recommended' => ['author', 'datePublished', 'image'],
		],
		'BlogPosting' => [
			'required'    => ['headline'],
			'recommended' => ['author', 'datePublished', 'image'],
		],
		'NewsArticle' => [
			'required'    => ['headline'],
			'recommended' => ['author', 'datePublished', 'image'],
		],
		'FAQPage' => [
			'required'    => ['mainEntity'],
			'recommended' => [],
		],
		'Question' => [
			'required'    => ['name', 'acceptedAnswer'],
			'recommended' => [],
		],
		'HowTo' => [
			'required'    => ['name', 'step'],
			'recommended' => ['totalTime', 'image'],
		],
		'Recipe' => [
			'required'    => ['name', 'recipeIngredient', 'recipeInstructions'],
			'recommended' => ['image', 'author', 'recipeYield', 'totalTime', 'nutrition'],
		],
		'Event' => [
			'required'    => ['name', 'startDate', 'location'],
			'recommended' => ['endDate', 'description', 'image', 'organizer', 'eventStatus'],
		],
		'Product' => [
			'required'    => ['name'],
			'recommended' => ['image', 'description', 'offers', 'aggregateRating', 'review'],
		],
		'Offer' => [
			'required'    => ['price', 'priceCurrency'],
			'recommended' => ['availability', 'url'],
		],
		'Review' => [
			'required'    => ['author', 'reviewRating'],
			'recommended' => ['datePublished', 'reviewBody'],
		],
		'JobPosting' => [
			'required'    => ['title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation'],
			'recommended' => ['validThrough', 'employmentType', 'baseSalary'],
		],
		'LocalBusiness' => [
			'required'    => ['name', 'address'],
			'recommended' => ['telephone', 'image', 'url', 'openingHours', 'geo', 'priceRange'],
		],
		'Organization' => [
			'required'    => ['name'],
			'recommended' => ['url', 'logo', 'sameAs', 'contactPoint'],
		],
		'Person' => [
			'required'    => ['name'],
			'recommended' => ['url', 'image', 'jobTitle', 'sameAs'],
		],
		'BreadcrumbList' => [
			'required'    => ['itemListElement'],
			'recommended' => [],
		],
		'VideoObject' => [
			'required'    => ['name', 'description', 'thumbnailUrl', 'uploadDate'],
			'recommended' => ['contentUrl', 'embedUrl', 'duration', 'publisher'],
		],
		'Service' => [
			'required'    => ['name'],
			'recommended' => ['description', 'provider', 'areaServed', 'serviceType'],
		],
		'Book' => [
			'required'    => ['name', 'author'],
			'recommended' => ['isbn', 'numberOfPages', 'inLanguage', 'datePublished'],
		],
	];

	public function getName(): string { return 'validate_jsonld'; }

	public function getDescription(): string
	{
		return 'Validate the shape of a JSON-LD object before writing it. Required: jsonld. '
			. 'Returns errors (must-fix), warnings (should-fix), and info (cosmetic) messages. '
			. 'Pre-flight check before set_article_custom_jsonld_bulk so a typo doesn\'t produce '
			. '500 broken schema rows. Knows the required/recommended fields for: ' . implode(', ', array_keys(self::TYPE_RULES)) . '. '
			. 'Unknown @types pass without field-level warnings (you can still get errors for '
			. 'missing @type, missing @context, or @graph wrapping).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['jsonld'],
			'properties' => [
				'jsonld' => ['type' => 'object', 'description' => 'JSON-LD object to validate.'],
				'expected_type' => ['type' => 'string', 'description' => 'Optional: the @type you expected. If supplied and the actual @type differs, an error is returned.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$jsonld = $arguments['jsonld'] ?? null;
		if (!is_array($jsonld) || $jsonld === []) {
			return ToolResult::error('jsonld must be a non-empty object.');
		}

		$errors      = [];
		$warnings    = [];
		$info        = [];

		// @graph wrapping — Joomla 5.1+ merges custom blocks into the page
		// graph automatically; wrapping in @graph yourself produces a
		// graph-in-graph that's likely wrong.
		if (isset($jsonld['@graph']) && empty($jsonld['@type'])) {
			$warnings[] = '@graph wrapping detected. Joomla 5.1+ merges your block into the page\'s @graph automatically — supply a single object with @type instead.';
		}

		// @context
		if (!isset($jsonld['@context'])) {
			$warnings[] = '@context is missing. Add "@context": "https://schema.org" so consumers (Google, etc.) recognise the vocabulary.';
		}

		// @type — hard error if missing
		if (!isset($jsonld['@type'])) {
			$errors[] = '@type is missing. Without it, the block is not interpretable as Schema.org.';
			return ToolResult::json([
				'ok'       => false,
				'errors'   => $errors,
				'warnings' => $warnings,
				'info'     => $info,
				'detected_type' => null,
				'rules_known' => false,
			]);
		}

		$type     = $jsonld['@type'];
		$typeName = is_array($type) ? (string) ($type[0] ?? '') : (string) $type;

		// expected_type mismatch
		if (!empty($arguments['expected_type'])) {
			$expected = (string) $arguments['expected_type'];
			$haystack = is_array($type) ? $type : [$type];
			if (!in_array($expected, $haystack, true)) {
				$errors[] = 'Expected @type "' . $expected . '" but got "' . $typeName . '".';
			}
		}

		// Per-type required + recommended field checks
		$rulesKnown = false;
		if (isset(self::TYPE_RULES[$typeName])) {
			$rulesKnown = true;
			$rules      = self::TYPE_RULES[$typeName];
			foreach ($rules['required'] as $field) {
				if (!array_key_exists($field, $jsonld) || $this->isEmpty($jsonld[$field])) {
					$errors[] = $typeName . ' requires "' . $field . '" but it is missing or empty.';
				}
			}
			foreach ($rules['recommended'] as $field) {
				if (!array_key_exists($field, $jsonld) || $this->isEmpty($jsonld[$field])) {
					$warnings[] = $typeName . ' recommends "' . $field . '" — Google\'s Rich Results documentation flags it as a strong-recommendation field.';
				}
			}
		} else {
			$info[] = 'No field-level rules registered for @type "' . $typeName . '". Validation skipped — supply the schema.org-correct fields manually.';
		}

		// Spot common typos / mistakes
		if (isset($jsonld['type']) && !isset($jsonld['@type'])) {
			$errors[] = '"type" found instead of "@type". JSON-LD requires the @ prefix.';
		}
		if (isset($jsonld['context']) && !isset($jsonld['@context'])) {
			$errors[] = '"context" found instead of "@context". JSON-LD requires the @ prefix.';
		}

		return ToolResult::json([
			'ok'           => $errors === [],
			'errors'       => $errors,
			'warnings'     => $warnings,
			'info'         => $info,
			'detected_type' => $typeName,
			'rules_known'  => $rulesKnown,
		]);
	}

	private function isEmpty(mixed $value): bool
	{
		if ($value === null) {
			return true;
		}
		if (is_string($value) && trim($value) === '') {
			return true;
		}
		if (is_array($value) && $value === []) {
			return true;
		}
		return false;
	}
}
