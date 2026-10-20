<?php

/*
 * Emergency compatibility rules of UpdateGuard: a combination found incompatible AFTER a module was published (its
 * release, and its interop.json, can no longer be changed). Shipped with UpdateGuard releases; they are added to the
 * module's own metadata as known conflicts, never replace it. Remove a rule once a fixed version is published.
 *
 *   [
 *       'module'           => 'RefreshGlobal',      module folder name or project id of the module being updated
 *       'versions'         => '1.20.0',             its versions concerned (range; empty = all)
 *       'project'          => 'refresh',            id of the related project
 *       'name'             => 'Refresh',            display name (when the module declares nothing about it)
 *       'project_versions' => '1.9.1',              installed versions of that project concerned (range)
 *       'state'            => 'INCOMPATIBLE',       INCOMPATIBLE (blocks by default) or DEGRADED
 *       'reason'           => 'Tickets list blank with Refresh 1.9.1',
 *       'added'            => '2026-10-20',
 *   ],
 */

return [
];
