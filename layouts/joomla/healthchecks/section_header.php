<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  Layout
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * Header of one Health Check section panel.
 *
 * @var  array  $displayData  ['title' => string, 'summary' => ['worst' => string, 'counts' => array]]
 */

$title   = (string) ($displayData['title'] ?? '');
$worst   = $displayData['summary']['worst'] ?? 'healthy';
$counts  = $displayData['summary']['counts'] ?? [];
$icons   = ['critical' => 'icon-times text-danger', 'warning' => 'icon-warning text-warning', 'healthy' => 'icon-check text-success'];
$badges  = ['critical' => 'bg-danger', 'warning' => 'bg-warning', 'healthy' => 'bg-success'];
$labels  = ['critical' => 'MOD_HEALTHCHECK_FILTER_CRITICAL', 'warning' => 'MOD_HEALTHCHECK_FILTER_WARNING', 'healthy' => 'MOD_HEALTHCHECK_FILTER_HEALTHY'];
$worst   = isset($icons[$worst]) ? $worst : 'healthy';
?>
<span class="d-flex align-items-center flex-grow-1 me-3">
    <span class="<?php echo $icons[$worst]; ?> me-2" aria-hidden="true"></span>
    <span class="visually-hidden"><?php echo Text::_($labels[$worst]); ?>: </span>
    <span class="fw-bold"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></span>
    <span class="ms-auto text-nowrap">
        <?php foreach (['critical', 'warning', 'healthy'] as $status) : ?>
            <?php if (!empty($counts[$status])) : ?>
                <span class="badge <?php echo $badges[$status]; ?> ms-1"><?php echo Text::sprintf('MOD_HEALTHCHECK_SECTION_COUNT_' . strtoupper($status), (int) $counts[$status]); ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </span>
</span>
