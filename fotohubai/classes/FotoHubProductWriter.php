<?php
/**
 * FOTOhub Product Writer — backward-compatible alias
 *
 * The real implementation now lives in FotoHubWriteback, the single shared
 * write-back service. This subclass exists only so older call sites (and any
 * merchant overrides referencing FotoHubProductWriter) keep working.
 *
 * @deprecated Use FotoHubWriteback directly.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/FotoHubWriteback.php';

class FotoHubProductWriter extends FotoHubWriteback
{
}
