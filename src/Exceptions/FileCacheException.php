<?php

declare(strict_types=1);

namespace Marko\Cache\File\Exceptions;

use Marko\Cache\Exceptions\CacheException;

class FileCacheException extends CacheException
{
    public static function directoryNotCreatable(
        string $path,
        ?string $reason = null,
    ): self {
        return new self(
            message: "Cache directory could not be created: $path",
            context: self::withReason("While creating the file cache directory '$path'", $reason),
            suggestion: "Ensure the parent of '$path' exists and is writable by the web server or CLI user, or change cache.path in config/cache.php",
        );
    }

    public static function writeFailed(
        string $path,
        ?string $reason = null,
    ): self {
        return new self(
            message: "Cache entry could not be written: $path",
            context: self::withReason("While writing the cache entry '$path'", $reason),
            suggestion: "Ensure the cache directory is writable by the web server or CLI user and that '$path' is not a directory",
        );
    }

    private static function withReason(
        string $context,
        ?string $reason,
    ): string {
        return $reason === null || $reason === '' ? $context : "$context: $reason";
    }
}
