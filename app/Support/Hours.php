<?php

namespace App\Support;

class Hours
{
    /** Minutes as h:mm. */
    public static function format(int $minutes): string
    {
        return intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
