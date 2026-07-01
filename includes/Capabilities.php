<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

final class Capabilities
{
    public static function can_manage_agent(): bool
    {
        return current_user_can('manage_options');
    }

    public static function can_manage_credentials(): bool
    {
        return current_user_can('manage_options');
    }
}
