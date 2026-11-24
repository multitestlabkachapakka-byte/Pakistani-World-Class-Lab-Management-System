<?php

namespace App\Services;

/** A backup or restore that cannot continue. The message is safe to show to the administrator. */
class BackupException extends \RuntimeException
{
}
