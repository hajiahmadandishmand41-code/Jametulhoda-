<?php

declare(strict_types=1);

/**
 * Public listing page (news / articles / reports / events) — Phase 5.
 *
 * @var string                    $heading
 * @var string                    $intro
 * @var string                    $type    internal content type
 * @var list<array<string,mixed>> $items
 * @var int                       $page
 * @var int                       $pages
 * @var int                       $total
 * @var string                    $path    listing base path, e.g. /news
 */

$intro = $intro ?? '';
$total = (int) ($total ?? count($items));
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
require __DIR__ . '/../views/partials/list_body.php';
