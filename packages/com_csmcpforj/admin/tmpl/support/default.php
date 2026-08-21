<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/** @var \Cybersalt\Component\Csmcpforj\Administrator\View\Support\HtmlView $this */

$submitUrl = Route::_('index.php?option=com_csmcpforj&task=support.submit');
$formToken = Session::getFormToken();

// Support submission types — keep this list in sync with SupportController::VALID_TYPES
// AND the language keys COM_CSMCPFORJ_SUPPORT_TYPE_<X>. The controller re-validates
// the picker value server-side, so this is display-only ordering; adding a new type
// requires three coordinated edits (controller enum, language keys, this list).
$types = ['bug', 'feature', 'question', 'idea', 'other'];

// Tier tag drives the banner colour + copy. Pro users see a green
// "priority-support" banner; Free users see a friendly blue "we love ideas +
// priority comes with Pro" banner. Neither gates access to the form.
$isPro = !empty($this->proActivated);
?>
<style>
/* Tier-aware banner at the top of the Support form. Same "colored left border
 * + colored bold title on neutral bg" pattern as the Setup Guide advisories,
 * per feedback_advisory_box_design_pattern. Colours pull from the palette:
 * Pro = green (#20a464), Community = blue (#0d6efd). */
.csmcpforj-support-banner {
	background: var(--bs-tertiary-bg);
	border: 1px solid var(--bs-border-color);
	border-radius: .25rem;
	padding: 1rem 1.25rem;
}
.csmcpforj-support-banner-pro       { border-left: 6px solid #20a464; }
.csmcpforj-support-banner-community { border-left: 6px solid #0d6efd; }
.csmcpforj-support-banner h3 { margin: 0 0 .5rem; }
.csmcpforj-support-banner-pro h3       { color: #20a464; }
.csmcpforj-support-banner-community h3 { color: #0d6efd; }
.csmcpforj-support-banner p:last-child { margin-bottom: 0; }
.csmcpforj-support-metadata {
	background: var(--bs-tertiary-bg);
	border: 1px solid var(--bs-border-color);
	border-radius: .25rem;
	padding: .75rem 1rem;
	font-size: .875rem;
}
.csmcpforj-support-metadata dt { color: var(--bs-secondary-color); }
.csmcpforj-support-metadata dd { margin-bottom: .25rem; word-break: break-word; }
</style>

<div class="container-fluid">
	<div class="row">
		<div class="col-lg-9">

			<div class="csmcpforj-support-banner csmcpforj-support-banner-<?php echo $isPro ? 'pro' : 'community'; ?> mb-3">
				<?php if ($isPro) : ?>
					<h3>
						<span class="icon-checkmark-circle" aria-hidden="true"></span>
						<?php echo Text::_('COM_CSMCPFORJ_SUPPORT_BANNER_PRO_HEADING'); ?>
					</h3>
					<p class="mb-0"><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_BANNER_PRO_BODY'); ?></p>
				<?php else : ?>
					<h3>
						<span class="icon-lightbulb" aria-hidden="true"></span>
						<?php echo Text::_('COM_CSMCPFORJ_SUPPORT_BANNER_COMMUNITY_HEADING'); ?>
					</h3>
					<p class="mb-0"><?php echo Text::sprintf(
						'COM_CSMCPFORJ_SUPPORT_BANNER_COMMUNITY_BODY',
						'<a href="' . htmlspecialchars($this->proSignupUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">',
						'</a>'
					); ?></p>
				<?php endif; ?>
			</div>

			<div class="card mb-3">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_FORM_HEADING'); ?></h3>
					<p class="mb-3"><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_FORM_INTRO'); ?></p>

					<form action="<?php echo $submitUrl; ?>" method="post" class="form-validate">
						<input type="hidden" name="<?php echo $formToken; ?>" value="1">

						<div class="mb-3">
							<label for="support_type" class="form-label fw-bold">
								<?php echo Text::_('COM_CSMCPFORJ_SUPPORT_FIELD_TYPE_LABEL'); ?>
								<span class="text-danger" aria-hidden="true">*</span>
							</label>
							<select id="support_type" name="support_type" class="form-select" required>
								<?php foreach ($types as $t) : ?>
									<option value="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>">
										<?php echo Text::_('COM_CSMCPFORJ_SUPPORT_TYPE_' . strtoupper($t)); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<div class="form-text"><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_FIELD_TYPE_HINT'); ?></div>
						</div>

						<div class="mb-3">
							<label for="support_subject" class="form-label fw-bold">
								<?php echo Text::_('COM_CSMCPFORJ_SUPPORT_FIELD_SUBJECT_LABEL'); ?>
								<span class="text-danger" aria-hidden="true">*</span>
							</label>
							<input type="text" id="support_subject" name="support_subject" class="form-control"
								placeholder="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SUPPORT_FIELD_SUBJECT_PLACEHOLDER')); ?>"
								required minlength="3" maxlength="200">
						</div>

						<div class="mb-3">
							<label for="support_description" class="form-label fw-bold">
								<?php echo Text::_('COM_CSMCPFORJ_SUPPORT_FIELD_DESCRIPTION_LABEL'); ?>
								<span class="text-danger" aria-hidden="true">*</span>
							</label>
							<textarea id="support_description" name="support_description" class="form-control"
								rows="8" required minlength="10" maxlength="5000"
								placeholder="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SUPPORT_FIELD_DESCRIPTION_PLACEHOLDER')); ?>"></textarea>
							<div class="form-text"><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_FIELD_DESCRIPTION_HINT'); ?></div>
						</div>

						<div class="mb-3">
							<label for="support_email" class="form-label fw-bold">
								<?php echo Text::_('COM_CSMCPFORJ_SUPPORT_FIELD_EMAIL_LABEL'); ?>
								<span class="text-danger" aria-hidden="true">*</span>
							</label>
							<input type="email" id="support_email" name="support_email" class="form-control"
								value="<?php echo htmlspecialchars($this->userEmail, ENT_QUOTES, 'UTF-8'); ?>"
								required>
							<div class="form-text"><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_FIELD_EMAIL_HINT'); ?></div>
						</div>

						<button type="submit" class="btn btn-primary btn-lg">
							<span class="icon-envelope" aria-hidden="true"></span>
							<?php echo Text::_('COM_CSMCPFORJ_SUPPORT_SUBMIT_BUTTON'); ?>
						</button>
					</form>
				</div>
			</div>

		</div>

		<div class="col-lg-3">
			<div class="card mb-3">
				<div class="card-body">
					<h5 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_METADATA_HEADING'); ?></h5>
					<p class="small text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_METADATA_INTRO'); ?></p>
					<dl class="csmcpforj-support-metadata mb-0">
						<dt><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_METADATA_SITE'); ?></dt>
						<dd><code><?php echo htmlspecialchars($this->siteUrl, ENT_QUOTES, 'UTF-8'); ?></code></dd>
						<dt><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_METADATA_MCP_VERSION'); ?></dt>
						<dd><code>v<?php echo htmlspecialchars($this->extensionVersion, ENT_QUOTES, 'UTF-8'); ?></code></dd>
						<dt><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_METADATA_JOOMLA_VERSION'); ?></dt>
						<dd><code><?php echo htmlspecialchars($this->joomlaVersion, ENT_QUOTES, 'UTF-8'); ?></code></dd>
						<dt><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_METADATA_PHP_VERSION'); ?></dt>
						<dd><code><?php echo htmlspecialchars($this->phpVersion, ENT_QUOTES, 'UTF-8'); ?></code></dd>
						<dt><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_METADATA_PRO_STATUS'); ?></dt>
						<dd>
							<?php if ($isPro) : ?>
								<span class="badge bg-success"><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_METADATA_PRO_ACTIVE'); ?></span>
							<?php else : ?>
								<span class="badge bg-secondary"><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_METADATA_PRO_INACTIVE'); ?></span>
							<?php endif; ?>
						</dd>
						<dt><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_METADATA_ADDONS'); ?></dt>
						<dd>
							<?php if (empty($this->installedAddons)) : ?>
								<em class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SUPPORT_METADATA_ADDONS_NONE'); ?></em>
							<?php else : ?>
								<?php foreach ($this->installedAddons as $addon) : ?>
									<div>
										<code><?php echo htmlspecialchars($addon['element'], ENT_QUOTES, 'UTF-8'); ?></code>
										<?php if (!$addon['enabled']) : ?>
											<span class="text-body-secondary">(disabled)</span>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</dd>
					</dl>
				</div>
			</div>
		</div>

	</div>
</div>
