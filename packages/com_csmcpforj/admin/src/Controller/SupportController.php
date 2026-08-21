<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Controller;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Helper\ProActivationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;

/**
 * Support form dispatcher.
 *
 * Two actions:
 *   display() — inherited from BaseController, renders the Support view.
 *   submit()  — receives the form POST, validates, sends email to
 *               support@cybersalt.com, redirects back with a message.
 *
 * Design (Tim 2026-08-19):
 *   - Email is the destination (RSTicketsPro on cybersalt.com not in use).
 *   - Subject-line tag differentiates Pro from Community so Tim can filter
 *     in Gmail without opening every submission.
 *   - Reply-To is the submitting user's Joomla email so Tim can reply
 *     directly and the reply lands in their inbox, not the site's noreply.
 *   - Auto-attached metadata block covers the environment questions any
 *     first support reply would otherwise have to ping-pong for.
 */
final class SupportController extends BaseController
{
	/**
	 * Support inbox destination. Change here (and in language strings' visible
	 * copy) if the routing target changes. Not exposed as a component option
	 * because the Support form ONLY goes to cybersalt.com support — this isn't
	 * a per-site override surface.
	 */
	private const SUPPORT_TO_EMAIL = 'support@cybersalt.com';

	/**
	 * Valid submission types. Wire the picker options in the template AND
	 * the Text keys COM_CSMCPFORJ_SUPPORT_TYPE_<X> — keep them in sync.
	 */
	private const VALID_TYPES = ['bug', 'feature', 'question', 'idea', 'other'];

	/** Length gates. Sane bounds so the form can't be used to smuggle a novel. */
	private const MIN_SUBJECT_LEN     = 3;
	private const MAX_SUBJECT_LEN     = 200;
	private const MIN_DESCRIPTION_LEN = 10;
	private const MAX_DESCRIPTION_LEN = 5000;

	public function submit(): void
	{
		$this->checkToken();

		$app   = Factory::getApplication();
		$input = $app->getInput();
		$user  = $app->getIdentity();
		$redirect = Route::_('index.php?option=com_csmcpforj&view=support', false);

		if (!$user || !$user->id) {
			$this->setMessage(Text::_('COM_CSMCPFORJ_SUPPORT_ERROR_NOT_LOGGED_IN'), 'error');
			$this->setRedirect($redirect);
			return;
		}

		// Field intake. The type-picker is a fixed enum; subject/description
		// are free text with length gates.
		$type        = strtolower(trim((string) $input->post->getString('support_type', '')));
		$subject     = trim((string) $input->post->getString('support_subject', ''));
		$description = trim((string) $input->post->getRaw('support_description', ''));
		$replyEmail  = trim((string) $input->post->getString('support_email', $user->email));

		if (!in_array($type, self::VALID_TYPES, true)) {
			$this->setMessage(Text::_('COM_CSMCPFORJ_SUPPORT_ERROR_INVALID_TYPE'), 'error');
			$this->setRedirect($redirect);
			return;
		}

		if (mb_strlen($subject) < self::MIN_SUBJECT_LEN) {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_SUPPORT_ERROR_SUBJECT_TOO_SHORT', self::MIN_SUBJECT_LEN), 'error');
			$this->setRedirect($redirect);
			return;
		}

		if (mb_strlen($subject) > self::MAX_SUBJECT_LEN) {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_SUPPORT_ERROR_SUBJECT_TOO_LONG', self::MAX_SUBJECT_LEN), 'error');
			$this->setRedirect($redirect);
			return;
		}

		if (mb_strlen($description) < self::MIN_DESCRIPTION_LEN) {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_SUPPORT_ERROR_DESCRIPTION_TOO_SHORT', self::MIN_DESCRIPTION_LEN), 'error');
			$this->setRedirect($redirect);
			return;
		}

		if (mb_strlen($description) > self::MAX_DESCRIPTION_LEN) {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_SUPPORT_ERROR_DESCRIPTION_TOO_LONG', self::MAX_DESCRIPTION_LEN), 'error');
			$this->setRedirect($redirect);
			return;
		}

		if (!filter_var($replyEmail, FILTER_VALIDATE_EMAIL)) {
			$this->setMessage(Text::_('COM_CSMCPFORJ_SUPPORT_ERROR_INVALID_EMAIL'), 'error');
			$this->setRedirect($redirect);
			return;
		}

		// Pro membership state — drives the subject-line tag (Pro | Community)
		// so Tim can filter incoming support at the Gmail inbox level.
		ProActivationHelper::refreshIfStale();
		$isPro = ProActivationHelper::isActivated();
		$tierTag = $isPro ? 'Pro' : 'Community';

		// Compose the outbound email.
		//
		// Type labels in the language file contain HTML entities like `&mdash;`
		// because they're written for HTML rendering in the form UI. Email
		// subjects are plain text — an un-decoded `&mdash;` reads as literal
		// characters in Gmail (Tim caught this on his first test submission
		// 2026-08-20). html_entity_decode with ENT_QUOTES|ENT_HTML5 handles
		// both &mdash; and any other named entities we might add later.
		$typeLabel = html_entity_decode(
			Text::_('COM_CSMCPFORJ_SUPPORT_TYPE_' . strtoupper($type)),
			ENT_QUOTES | ENT_HTML5,
			'UTF-8'
		);
		$emailSubject = sprintf('[MCP for J %s Support] %s: %s', $tierTag, $typeLabel, $subject);

		$metadata = $this->buildMetadataBlock($user, $isPro, $type, $replyEmail);
		$emailBody = $description . "\n\n" . str_repeat('-', 60) . "\n\n" . $metadata;

		try {
			$mailer = Factory::getContainer()->get(\Joomla\CMS\Mail\MailerFactoryInterface::class)->createMailer();
			$mailer->addRecipient(self::SUPPORT_TO_EMAIL);
			$mailer->addReplyTo($replyEmail, $user->name);
			$mailer->setSubject($emailSubject);
			$mailer->setBody($emailBody);
			$mailer->isHtml(false);
			$mailer->Send();
		} catch (\Throwable $e) {
			// Mailer failures on client sites (misconfigured SMTP, blocked port,
			// etc.) shouldn't crash the form — surface the error to the user
			// so they can copy the content elsewhere while their site's mail is
			// broken. Also log so ops has breadcrumb.
			$app->enqueueMessage(
				Text::sprintf('COM_CSMCPFORJ_SUPPORT_ERROR_MAILER_FAILED', $e->getMessage()),
				'error'
			);
			$this->setRedirect($redirect);
			return;
		}

		$this->setMessage(Text::sprintf('COM_CSMCPFORJ_SUPPORT_SENT_SUCCESS', $tierTag));
		$this->setRedirect($redirect);
	}

	/**
	 * Builds the human-readable metadata block appended to every support email.
	 * Answers the environment questions any first-reply would need — site URL,
	 * cs-mcp-for-j version, Joomla version, PHP version, Pro state, add-on
	 * inventory, submitting user identity. Plain text so it renders sanely in
	 * every email client.
	 */
	private function buildMetadataBlock(
		object $user,
		bool $isPro,
		string $type,
		string $replyEmail
	): string {
		$db = Factory::getContainer()->get(DatabaseInterface::class);

		// cs-mcp-for-j installed version from the package manifest_cache.
		$mcpVersion = 'unknown';
		try {
			$q = $db->getQuery(true)
				->select($db->quoteName('manifest_cache'))
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('element') . ' = ' . $db->quote('pkg_csmcpforj'))
				->where($db->quoteName('type') . ' = ' . $db->quote('package'));
			$db->setQuery($q);
			$raw = (string) $db->loadResult();
			if ($raw !== '') {
				$parsed = json_decode($raw, true);
				$mcpVersion = (string) ($parsed['version'] ?? 'unknown');
			}
		} catch (\Throwable $e) {
			// non-fatal — metadata is a nice-to-have, not a blocker
		}

		// Installed csmcpforj-family add-ons.
		$addonList = [];
		try {
			$q = $db->getQuery(true)
				->select([$db->quoteName('element'), $db->quoteName('enabled')])
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
				->where($db->quoteName('element') . ' LIKE ' . $db->quote('csmcpforj%'))
				->order($db->quoteName('element') . ' ASC');
			$db->setQuery($q);
			foreach ($db->loadObjectList() as $row) {
				$addonList[] = (string) $row->element . ((int) $row->enabled === 1 ? ' (enabled)' : ' (disabled)');
			}
		} catch (\Throwable $e) {
			// non-fatal
		}

		$addons = empty($addonList) ? '(none)' : implode(', ', $addonList);
		$siteUrl = rtrim(Uri::root(), '/');

		return "Submission metadata\n"
			 . "-------------------\n"
			 . "Type:              {$type}\n"
			 . "Site:              {$siteUrl}\n"
			 . "cs-mcp-for-j:      v{$mcpVersion}\n"
			 . "Joomla version:    " . (new Version())->getShortVersion() . "\n"
			 . "PHP version:       " . PHP_VERSION . "\n"
			 . "Pro membership:    " . ($isPro ? 'active' : 'not active') . "\n"
			 . "Add-ons installed: {$addons}\n"
			 . "Submitted by:      {$user->name} <{$user->email}>\n"
			 . "Reply to:          {$replyEmail}\n";
	}
}
