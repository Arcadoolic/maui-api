<?php

namespace App\Services\Members;

use RuntimeException;

/** Discord refused the code, or did not answer as expected. */
final class DiscordUnavailable extends RuntimeException {}
