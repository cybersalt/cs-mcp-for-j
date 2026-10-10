<?php

/**
 * @package     Cybersalt.MCPforJ
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Helper;

\defined('_JEXEC') or die;

/**
 * Shared styling for "here is where you type something".
 *
 * WHY THIS EXISTS
 * ---------------
 * Reported 2026-09-17: the Joomla API token field on the dashboard is genuinely
 * hard to find, even when you know it is there and what it looks like. The cause
 * is structural rather than cosmetic — it was a Bootstrap `card border-secondary`,
 * a 1px grey outline on the same background as its surroundings, sitting on a page
 * that also carries a green success alert, a blue info alert, a numbered list, a
 * code block and a large primary button. It was the only element on the page the
 * operator is meant to ACT on, and it was styled quieter than everything bracketing
 * it.
 *
 * THE PATTERN
 * -----------
 * Two treatments, both keyed to Cybersalt brand orange (#fa6400):
 *
 *   .csmcpforj-field-panel  — the house style. Coloured left rail, tinted ground,
 *                             coloured bold label. Deliberately the same visual
 *                             language as the advisory boxes used estate-wide,
 *                             so it reads as part of one system.
 *
 *   .csmcpforj-steps        — a numbered sequence in which exactly one step is the
 *                             thing performed on the page. That step gets
 *                             .csmcpforj-step-do and the orange treatment; the rest
 *                             carry neutral badges. The number is information, not
 *                             decoration: it answers "where does this fit?" rather
 *                             than merely making the box louder. Do not use it where
 *                             the page is not actually a sequence.
 *
 *                             Every step draws its own badge from a CSS counter
 *                             rather than relying on <ol> markers. Styling one step
 *                             and leaving the others as plain "3." reads as a single
 *                             orphaned number, and Atum suppresses the markers here
 *                             anyway — which is exactly what happened on the first
 *                             attempt (Tim, 2026-09-17: "i see the 2 ... but i do not
 *                             see a step 1 or 3 or any others").
 *
 * WHY ORANGE AND NOT THE SEVERITY PALETTE
 * ---------------------------------------
 * The advisory boxes already spend #ffc107 on "warning" and #ff5c66 on "danger".
 * Borrowing either for "type here" would teach two meanings for one colour. Brand
 * orange is unclaimed, so it can mean exactly one thing: this is the part you do.
 *
 * Colours are hard-coded rather than taken from Bootstrap variables, for the same
 * reason the advisory boxes are: Atum overrides the semantic variables per theme
 * and the result drifts between light and dark.
 */
final class FieldStyleHelper
{
    /**
     * Guards against emitting the block twice when a view renders more than one
     * layout, which would be harmless but wasteful.
     */
    private static bool $emitted = false;

    /**
     * Returns the <style> block, or an empty string if it has already been
     * emitted during this request.
     */
    public static function css(): string
    {
        if (self::$emitted) {
            return '';
        }

        self::$emitted = true;

        return <<<'HTML'
<style>
/* ---- Cybersalt "type here" field treatment -----------------------------
   See FieldStyleHelper for why this exists and why the accent is brand
   orange rather than one of the severity colours. ---------------------- */

/* A — the house style: rail on the left, tinted ground. */
.csmcpforj-field-panel {
	background: rgba(250, 100, 0, .07);
	padding: 1rem 1.15rem;
	margin-bottom: 1rem;
	border: 1px solid rgba(0, 0, 0, .12);
	border-left: 4px solid #fa6400;
	border-radius: 0 .375rem .375rem 0;
}

/* B — a numbered sequence where ONE step is the thing you do.
   ---------------------------------------------------------------------
   Every step carries its own badge rather than relying on the browser's
   <ol> markers. Two reasons, both learned the hard way (Tim, 2026-09-17):
   a styled badge on one step and a plain "3." on the next reads as a
   single orphaned number rather than a sequence, and Atum suppresses the
   markers in this context anyway, so the other steps vanished entirely.
   Neutral badges for the steps you read, brand orange for the step you
   act on — the emphasis still lands in exactly one place. */
.csmcpforj-steps {
	list-style: none;
	margin: 0 0 1rem;
	padding: 0;
	counter-reset: csmcpforj-step;
}

.csmcpforj-steps > li {
	display: flex;
	gap: .9rem;
	align-items: flex-start;
	padding: .35rem 0;
}

.csmcpforj-steps > li::before {
	counter-increment: csmcpforj-step;
	content: counter(csmcpforj-step);
	flex: 0 0 auto;
	width: 1.65rem;
	height: 1.65rem;
	border-radius: 50%;
	background: rgba(0, 0, 0, .08);
	color: inherit;
	font-size: .8125rem;
	font-weight: 700;
	line-height: 1.65rem;
	text-align: center;
	margin-top: .05rem;
}

/* The one step performed on this page. */
.csmcpforj-steps > li.csmcpforj-step-do {
	background: rgba(250, 100, 0, .07);
	border: 1px solid rgba(250, 100, 0, .32);
	border-radius: .375rem;
	padding: 1rem 1.15rem;
	margin: .35rem 0;
}

.csmcpforj-steps > li.csmcpforj-step-do::before {
	background: #fa6400;
	color: #fff;
}

.csmcpforj-step-body {
	flex: 1 1 auto;
	min-width: 0;
}

.csmcpforj-field-label {
	display: block;
	font-weight: 700;
	font-size: .875rem;
	color: #b34700;
	margin-bottom: .45rem;
}

/* Inside a step the label sits against the body text colour — the number
   is already carrying the accent, and two oranges stacked reads as noise. */
.csmcpforj-step-do .csmcpforj-field-label {
	color: inherit;
	font-size: .9375rem;
}

.csmcpforj-field-required {
	display: inline-block;
	font-size: .6875rem;
	font-weight: 700;
	letter-spacing: .08em;
	text-transform: uppercase;
	background: #fa6400;
	color: #fff;
	padding: .1rem .45rem;
	border-radius: 3px;
	margin-left: .4rem;
	vertical-align: 2px;
}

/* The focus ring has to match, or tabbing into the field contradicts the
   colour that led you to it. */
.csmcpforj-field-panel .form-control:focus,
.csmcpforj-step-do .form-control:focus {
	border-color: #fa6400;
	box-shadow: 0 0 0 .2rem rgba(250, 100, 0, .25);
}

.csmcpforj-field-hint {
	display: block;
	margin-top: .5rem;
	font-size: .8125rem;
}

/* ---- inline notice --------------------------------------------------
   The estate advisory language (coloured left rail, bold coloured lead,
   neutral ground) as a plain always-visible block. Distinct from the
   catalog's .csmcpforj-advisory, which is a collapsible <details> scoped
   to that page. Hard-coded hex for the same reason the advisories use it:
   Atum redefines the Bootstrap semantic variables per theme and the
   result drifts between light and dark. */
.csmcpforj-notice {
	border-left: 4px solid #ffc107;
	background: rgba(255, 193, 7, .08);
	border-radius: 0 .375rem .375rem 0;
	padding: .85rem 1.1rem;
	font-size: .9375rem;
}

.csmcpforj-notice strong:first-child {
	color: #9a7400;
}

[data-bs-theme="dark"] .csmcpforj-notice,
.atum-dark .csmcpforj-notice {
	background: rgba(255, 193, 7, .12);
}

[data-bs-theme="dark"] .csmcpforj-notice strong:first-child,
.atum-dark .csmcpforj-notice strong:first-child {
	color: #ffc107;
}

/* ---- dark ----------------------------------------------------------- */

[data-bs-theme="dark"] .csmcpforj-field-panel,
.atum-dark .csmcpforj-field-panel,
[data-bs-theme="dark"] .csmcpforj-steps > li.csmcpforj-step-do,
.atum-dark .csmcpforj-steps > li.csmcpforj-step-do {
	background: rgba(250, 100, 0, .10);
}

/* Neutral badges need to lift off a dark ground, not sink into it. */
[data-bs-theme="dark"] .csmcpforj-steps > li::before,
.atum-dark .csmcpforj-steps > li::before {
	background: rgba(255, 255, 255, .14);
}

[data-bs-theme="dark"] .csmcpforj-field-panel,
.atum-dark .csmcpforj-field-panel {
	border-color: rgba(255, 255, 255, .12);
	border-left-color: #fa6400;
}

[data-bs-theme="dark"] .csmcpforj-steps > li.csmcpforj-step-do,
.atum-dark .csmcpforj-steps > li.csmcpforj-step-do {
	border-color: rgba(250, 100, 0, .38);
}

/* #b34700 is chosen for contrast on white and goes muddy on a dark ground. */
[data-bs-theme="dark"] .csmcpforj-field-label,
.atum-dark .csmcpforj-field-label {
	color: #ff9a4d;
}

[data-bs-theme="dark"] .csmcpforj-step-do .csmcpforj-field-label,
.atum-dark .csmcpforj-step-do .csmcpforj-field-label {
	color: inherit;
}
</style>
HTML;
    }
}
