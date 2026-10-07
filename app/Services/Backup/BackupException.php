<?php

namespace App\Services\Backup;

use RuntimeException;

// Messages from this exception are controlled, non-sensitive domain errors.
class BackupException extends RuntimeException {}
