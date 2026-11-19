<?php

namespace App\Services\Players;

use RuntimeException;

/**
 * A player could not be added for a reason the person adding them can fix
 * (unknown Riot ID, wrong server...). The message is safe to show as it is.
 */
class PlayerRegistrationException extends RuntimeException {}
