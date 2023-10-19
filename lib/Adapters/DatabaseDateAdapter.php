<?php

namespace Phoenix\Integrations\WordPress\Adapters;

use DateTime;
use Phoenix\Database\Interfaces\CanConvertDatabaseStringToDateTime;
use Phoenix\Database\Interfaces\CanConvertToDatabaseDateString;

class DatabaseDateAdapter implements CanConvertToDatabaseDateString, CanConvertDatabaseStringToDateTime
{
    /** @inheritDoc */
    public function toDatabaseDateString(DateTime $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }

    /** @inheritDoc */
    public function toDateTime(string $date): DateTime
    {
        return DateTime::createFromFormat('Y-m-d H:i:s', $date);
    }
}