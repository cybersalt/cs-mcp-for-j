<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * RSTicketsPro MCP add-on plugin. Registers a tool set for the RSJoomla!
 * RSTicketsPro helpdesk extension (com_rsticketspro).
 *
 * RSTicketsPro is a classic legacy-MVC Joomla component — controllers /
 * models / tables / views. Unlike 4SEO (whose models live behind a custom
 * "wbApp" framework so we have to talk to the DB directly), RSTicketsPro
 * exposes a proper Joomla AdminModel for tickets with high-level methods
 * (reply, updateInfo, notify, delete, etc.) that fire all the right side
 * effects: email notifications, ticket_history audit, dept-change code
 * regeneration, staff-access validation, time-tracking stop on close.
 *
 * So write tools call $model->updateInfo() / $model->reply() rather than
 * doing direct SQL — every status change, every reply, every dept move
 * generates the exact same downstream events as a human clicking through
 * the admin UI.
 *
 * Read tools are a mix: simple single-row reads use the model's getTicket()
 * etc., while list_tickets does a direct JOIN-heavy SQL because the
 * ListModel's UserState-driven filter API is awkward to drive from outside.
 */
final class Csmcpforjrst extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Read — tickets
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets\ListTicketsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets\GetTicketTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets\GetTicketMessagesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets\GetTicketHistoryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets\GetTicketNotesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets\GetTicketFilesTool::class,

		// Read — lookups
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Lookups\ListDepartmentsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Lookups\ListStatusesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Lookups\ListPrioritiesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Lookups\ListStaffTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Lookups\ListGroupsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Lookups\ListCustomFieldsTool::class,

		// Write — tickets
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets\AddTicketReplyTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets\AddTicketNoteTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets\UpdateTicketTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets\CloseTicketTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets\ReopenTicketTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets\FlagTicketTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets\NotifyTicketTool::class,
		\Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets\DeleteTicketTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		return [RegisterToolsEvent::EVENT_NAME => 'onRegisterTools'];
	}

	public function onRegisterTools(RegisterToolsEvent $event): void
	{
		$registry = $event->getRegistry();
		$db       = $this->getDatabase();

		foreach (self::TOOLS as $toolClass) {
			$registry->register(new $toolClass($db));
		}
	}

	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
