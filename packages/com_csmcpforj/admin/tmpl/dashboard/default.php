<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Cybersalt\Component\Csmcpforj\Administrator\View\Dashboard\HtmlView $this */

$tokenUrl = Route::_('index.php?option=com_users&task=user.edit');
$permsUrl = Route::_('index.php?option=com_config&view=component&component=com_csmcpforj');
$host     = parse_url($this->endpointUrl, PHP_URL_HOST) ?: 'site';
$endpoint = htmlspecialchars($this->endpointUrl, ENT_QUOTES, 'UTF-8');
?>
<div class="container-fluid">
	<div class="row">
		<div class="col-12 col-lg-8">

			<div class="card mb-3">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_CONNECTION_HEADING'); ?></h3>
					<p><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_CONNECTION_INTRO'); ?></p>
					<dl class="row mb-0">
						<dt class="col-sm-3"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_ENDPOINT'); ?></dt>
						<dd class="col-sm-9"><pre class="mb-2 p-2" style="white-space: pre-wrap; word-break: break-all;"><code><?php echo $endpoint; ?></code></pre></dd>
						<dt class="col-sm-3"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_TOKEN'); ?></dt>
						<dd class="col-sm-9">
							<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_TOKEN_VALUE'); ?>
							<a href="<?php echo $tokenUrl; ?>" class="ms-2"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_TOKEN_LINK'); ?></a>
						</dd>
					</dl>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_HEADING'); ?></h3>
					<p><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_INTRO'); ?></p>

					<div class="alert alert-info mb-3">
						<strong><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_KEY_FACT_LABEL'); ?></strong>
						<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_KEY_FACT_BODY'); ?>
					</div>

					<table class="table table-sm">
						<thead>
							<tr>
								<th><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_COL_GROUP'); ?></th>
								<th><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_COL_OUTOFBOX'); ?></th>
								<th><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_COL_REASON'); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr><td><strong>Super Users</strong></td><td><span class="badge bg-success"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_YES'); ?></span></td><td><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_REASON_SUPER'); ?></td></tr>
							<tr><td><strong>Administrator</strong></td><td><span class="badge bg-success"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_YES'); ?></span></td><td><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_REASON_ADMIN'); ?></td></tr>
							<tr><td><strong>Manager</strong></td><td><span class="badge bg-success"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_YES'); ?></span></td><td><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_REASON_MANAGER'); ?></td></tr>
							<tr><td><strong><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_GROUP_OTHER'); ?></strong></td><td><span class="badge bg-warning text-dark"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_NEEDS_GRANT'); ?></span></td><td><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_REASON_OTHER'); ?></td></tr>
						</tbody>
					</table>

					<p class="mb-0">
						<a href="<?php echo $permsUrl; ?>" class="btn btn-secondary text-white">
							<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_LINK_PERMISSIONS'); ?>
						</a>
					</p>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_CLIENT_CONFIG_HEADING'); ?></h3>
					<p><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_CLIENT_CONFIG_INTRO'); ?></p>

					<h4 class="h6"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_CLIENT_CONFIG_BEARER_HEADING'); ?></h4>
					<pre class="p-2 mb-3" style="white-space: pre-wrap;"><code>{
  "mcpServers": {
    "joomla-<?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?>": {
      "type": "http",
      "url": "<?php echo $endpoint; ?>",
      "headers": {
        "Authorization": "Bearer YOUR_JOOMLA_API_TOKEN"
      }
    }
  }
}</code></pre>

					<h4 class="h6"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_CLIENT_CONFIG_XHEADER_HEADING'); ?></h4>
					<p><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_CLIENT_CONFIG_XHEADER_INTRO'); ?></p>
					<pre class="p-2 mb-0" style="white-space: pre-wrap;"><code>"headers": { "X-Joomla-Token": "YOUR_JOOMLA_API_TOKEN" }</code></pre>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-body">
					<h3 class="card-title">
						<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TOOLS_HEADING'); ?>
						<span class="badge bg-info"><?php echo (int) $this->toolCount; ?></span>
					</h3>

					<?php if (empty($this->toolsByDomain)) : ?>
						<p class="text-muted"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TOOLS_EMPTY'); ?></p>
					<?php else : ?>
						<?php foreach ($this->toolsByDomain as $domain => $tools) : ?>
							<h4 class="h6 mt-3 mb-2">
								<?php echo htmlspecialchars((string) $domain, ENT_QUOTES, 'UTF-8'); ?>
								<span class="badge bg-secondary"><?php echo count($tools); ?></span>
							</h4>
							<table class="table table-sm mb-3">
								<thead>
									<tr>
										<th style="width:30%;"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TOOL_NAME'); ?></th>
										<th><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TOOL_DESCRIPTION'); ?></th>
										<th style="width:90px;"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TOOL_PERMISSION'); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($tools as $tool) : ?>
										<tr>
											<td><code><?php echo htmlspecialchars($tool['name'], ENT_QUOTES, 'UTF-8'); ?></code></td>
											<td><?php echo htmlspecialchars($tool['description'], ENT_QUOTES, 'UTF-8'); ?></td>
											<td>
												<?php if (($tool['permission'] ?? 'use') === 'write') : ?>
													<span class="badge bg-warning text-dark">write</span>
												<?php else : ?>
													<span class="badge bg-success">read</span>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

		</div>

		<div class="col-12 col-lg-4">
			<div class="card mb-3">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_QUICKSTART_HEADING'); ?></h3>
					<ol class="ps-3">
						<li class="mb-2">
							<strong><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_QUICKSTART_STEP1_TITLE'); ?></strong><br>
							<small><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_QUICKSTART_STEP1_BODY'); ?></small>
						</li>
						<li class="mb-2">
							<strong><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_QUICKSTART_STEP2_TITLE'); ?></strong><br>
							<small><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_QUICKSTART_STEP2_BODY'); ?></small>
						</li>
						<li class="mb-2">
							<strong><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_QUICKSTART_STEP3_TITLE'); ?></strong><br>
							<small><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_QUICKSTART_STEP3_BODY'); ?></small>
						</li>
					</ol>
					<a href="<?php echo $tokenUrl; ?>" class="btn btn-primary text-white d-block mb-2">
						<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_LINK_GENERATE_TOKEN'); ?>
					</a>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-body">
					<h4 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TROUBLESHOOT_HEADING'); ?></h4>
					<dl class="mb-0">
						<dt><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TROUBLESHOOT_401_TITLE'); ?></dt>
						<dd><small><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TROUBLESHOOT_401_BODY'); ?></small></dd>
						<dt><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TROUBLESHOOT_404_TITLE'); ?></dt>
						<dd><small><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TROUBLESHOOT_404_BODY'); ?></small></dd>
						<dt><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TROUBLESHOOT_FORBIDDEN_TITLE'); ?></dt>
						<dd class="mb-0"><small><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TROUBLESHOOT_FORBIDDEN_BODY'); ?></small></dd>
					</dl>
				</div>
			</div>
		</div>
	</div>
</div>
