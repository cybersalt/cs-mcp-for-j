<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Returns the canonical list of CORE schema types Joomla supports natively,
 * with the headline fields each one expects in its payload. Pass these to
 * set_article_schema as the schema_type, and the payload as the per-type fields.
 *
 * For schema types Joomla doesn't ship a form for (FAQPage, Service,
 * LocalBusiness, Product, Review, BreadcrumbList, etc.), use schema_type=Custom
 * with set_article_custom_jsonld instead.
 */
final class ListSchemaTypesTool extends AbstractTool
{
	public function getName(): string { return 'list_schema_types'; }

	public function getDescription(): string
	{
		return 'List CORE Joomla Schema.org types (Article, BlogPosting, Book, Custom, Event, '
			. 'JobPosting, Organization, Person, Recipe, None) with the typical fields each accepts. '
			. 'Use Custom + set_article_custom_jsonld for types Joomla does not ship a form for.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		return ToolResult::json([
			'types' => [
				[
					'name'        => 'Article',
					'description' => 'Generic article. Most common.',
					'fields'      => ['headline', 'description', 'image', 'author', 'datePublished', 'dateModified', 'publisher'],
				],
				[
					'name'        => 'BlogPosting',
					'description' => 'Blog post — subclass of Article.',
					'fields'      => ['headline', 'description', 'image', 'author', 'datePublished', 'dateModified', 'publisher', 'wordCount'],
				],
				[
					'name'        => 'Book',
					'description' => 'A book.',
					'fields'      => ['name', 'author', 'isbn', 'numberOfPages', 'inLanguage', 'datePublished'],
				],
				[
					'name'        => 'Event',
					'description' => 'A scheduled event.',
					'fields'      => ['name', 'description', 'startDate', 'endDate', 'location', 'eventStatus', 'eventAttendanceMode', 'organizer'],
				],
				[
					'name'        => 'JobPosting',
					'description' => 'A job listing.',
					'fields'      => ['title', 'description', 'datePosted', 'validThrough', 'employmentType', 'hiringOrganization', 'jobLocation', 'baseSalary'],
				],
				[
					'name'        => 'Organization',
					'description' => 'An organization. Usually set site-wide via plg_system_schemaorg params, but supported per-article.',
					'fields'      => ['name', 'url', 'logo', 'description', 'address', 'sameAs', 'contactPoint'],
				],
				[
					'name'        => 'Person',
					'description' => 'A person. Usually set site-wide via plg_system_schemaorg params, but supported per-article.',
					'fields'      => ['name', 'url', 'image', 'jobTitle', 'worksFor', 'sameAs'],
				],
				[
					'name'        => 'Recipe',
					'description' => 'A recipe.',
					'fields'      => ['name', 'image', 'author', 'datePublished', 'description', 'recipeIngredient', 'recipeInstructions', 'recipeYield', 'totalTime', 'nutrition'],
				],
				[
					'name'        => 'Custom',
					'description' => 'Paste arbitrary JSON-LD. Use this for FAQPage, Service, LocalBusiness, Product, Review, BreadcrumbList, etc. Prefer set_article_custom_jsonld so you pass a JSON object, not a stringified string.',
					'fields'      => ['json (stringified JSON-LD)'],
				],
				[
					'name'        => 'None',
					'description' => 'Removes any stored schema row for the article.',
					'fields'      => [],
				],
			],
			'note' => 'Field lists are illustrative; the JSON-LD payload may include any schema.org-valid properties for that @type.',
		]);
	}
}
