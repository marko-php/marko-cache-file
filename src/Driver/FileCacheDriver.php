<?php

declare(strict_types=1);

namespace Marko\Cache\File\Driver;

use Marko\Cache\CacheItem;
use Marko\Cache\Config\CacheConfig;
use Marko\Cache\Contracts\CacheInterface;
use Marko\Cache\Contracts\CacheItemInterface;
use Marko\Cache\Exceptions\InvalidKeyException;
use Marko\Cache\Exceptions\TamperedCacheValueException;
use Marko\Cache\File\Exceptions\FileCacheException;
use Marko\Cache\Signer\CacheValueSigner;
use Marko\Core\Support\ErrorCapture;
use Psr\Clock\ClockInterface;

readonly class FileCacheDriver implements CacheInterface
{
    public function __construct(
        private CacheConfig $config,
        private ClockInterface $clock,
        private CacheValueSigner $cacheValueSigner,
    ) {}

    /**
     * @throws InvalidKeyException|TamperedCacheValueException
     */
    public function get(
        string $key,
        mixed $default = null,
    ): mixed {
        $this->validateKey($key);

        $data = $this->read($key);

        if ($data === null) {
            return $default;
        }

        if ($this->isExpired($data)) {
            $this->delete($key);

            return $default;
        }

        return $data['value'];
    }

    /**
     * @throws InvalidKeyException|FileCacheException|TamperedCacheValueException
     */
    public function set(
        string $key,
        mixed $value,
        ?int $ttl = null,
    ): bool {
        $this->validateKey($key);
        $this->ensureDirectoryExists();

        $ttl ??= $this->config->defaultTtl();
        $now = $this->clock->now()->getTimestamp();

        $data = [
            'value' => $value,
            'expires_at' => $ttl > 0 ? $now + $ttl : null,
            'created_at' => $now,
        ];

        $this->write($key, $data);

        return true;
    }

    /**
     * @throws InvalidKeyException|TamperedCacheValueException
     */
    public function has(
        string $key,
    ): bool {
        $this->validateKey($key);

        $data = $this->read($key);

        if ($data === null) {
            return false;
        }

        if ($this->isExpired($data)) {
            $this->delete($key);

            return false;
        }

        return true;
    }

    /**
     * @throws InvalidKeyException
     */
    public function delete(
        string $key,
    ): bool {
        $this->validateKey($key);

        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return true;
        }

        return unlink($filePath);
    }

    public function clear(): bool
    {
        if (!is_dir($this->config->path())) {
            return true;
        }

        $cacheFiles = glob($this->config->path() . '/*.cache');

        if ($cacheFiles === false) {
            return false;
        }

        $tmpFiles = glob($this->config->path() . '/*.tmp.*');

        if ($tmpFiles === false) {
            return false;
        }

        $success = true;

        foreach (array_merge($cacheFiles, $tmpFiles) as $file) {
            if (!unlink($file)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * @throws InvalidKeyException|TamperedCacheValueException
     */
    public function getItem(
        string $key,
    ): CacheItemInterface {
        $this->validateKey($key);

        $data = $this->read($key);

        if ($data === null) {
            return CacheItem::miss($key);
        }

        if ($this->isExpired($data)) {
            $this->delete($key);

            return CacheItem::miss($key);
        }

        $expiresAt = $data['expires_at'] !== null
            ? $this->clock->now()->setTimestamp($data['expires_at'])
            : null;

        return CacheItem::hit($key, $data['value'], $expiresAt);
    }

    /**
     * @throws InvalidKeyException|TamperedCacheValueException
     */
    public function getMultiple(
        array $keys,
        mixed $default = null,
    ): iterable {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @throws InvalidKeyException|FileCacheException|TamperedCacheValueException
     */
    public function setMultiple(
        array $values,
        ?int $ttl = null,
    ): bool {
        $success = true;

        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * @throws InvalidKeyException
     */
    public function deleteMultiple(
        array $keys,
    ): bool {
        $success = true;

        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * @throws InvalidKeyException|FileCacheException|TamperedCacheValueException
     */
    public function increment(
        string $key,
        int $ttl,
    ): int {
        $this->validateKey($key);
        $this->ensureDirectoryExists();

        $filePath = $this->getFilePath($key);
        // Never fail open: a counter that silently restarts at 1 on every call
        // would let a rate limiter allow every request.
        $fh = ErrorCapture::run($reason, fn (): mixed => fopen($filePath, 'c+'));

        if (!is_resource($fh)) {
            throw FileCacheException::openFailed($filePath, $reason);
        }

        if (!ErrorCapture::run($reason, fn (): bool => flock($fh, LOCK_EX))) {
            fclose($fh);

            throw FileCacheException::lockFailed($filePath, $reason);
        }

        try {
            $content = stream_get_contents($fh);
            // An unsigned or tampered entry is never unserialized; the counter restarts.
            $serialized = $content !== '' && $content !== false
                ? $this->cacheValueSigner->unwrap($content, $key)
                : null;
            $data = $serialized !== null ? unserialize($serialized) : null;
            $now = $this->clock->now()->getTimestamp();

            if (!is_array($data)
                || !array_key_exists('value', $data)
                || !isset($data['created_at'])
                || ($data['expires_at'] !== null && $now > $data['expires_at'])
            ) {
                $newValue = 1;
                $data = [
                    'value' => $newValue,
                    'expires_at' => $ttl > 0 ? $now + $ttl : null,
                    'created_at' => $now,
                ];
            } else {
                $newValue = (int) $data['value'] + 1;
                $data['value'] = $newValue;
            }

            $envelope = $this->cacheValueSigner->wrap(serialize($data), $key);

            $written = ErrorCapture::run(
                $reason,
                fn (): int|false => ftruncate($fh, 0) && rewind($fh) ? fwrite($fh, $envelope) : false,
            );

            if ($written !== strlen($envelope)) {
                throw FileCacheException::writeFailed($filePath, $reason);
            }
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        return $newValue;
    }

    /**
     * @throws InvalidKeyException
     */
    private function validateKey(
        string $key,
    ): void {
        if ($key === '') {
            throw InvalidKeyException::emptyKey();
        }

        if (!InvalidKeyException::isValidKey($key)) {
            throw InvalidKeyException::forKey($key);
        }
    }

    private function hashKey(
        string $key,
    ): string {
        return hash('xxh128', $key);
    }

    private function getFilePath(
        string $key,
    ): string {
        return $this->config->path() . '/' . $this->hashKey($key) . '.cache';
    }

    /**
     * Read and verify a cache entry. The HMAC is bound to the cache key, so an entry
     * whose HMAC does not verify (tampered, corrupted, copied from another key's file,
     * or signed by an older release) is a miss and is deleted, so a planted file can
     * never reach unserialize().
     *
     * @return array{value: mixed, expires_at: ?int, created_at: int}|null
     *
     * @throws TamperedCacheValueException when no signing key is configured
     */
    private function read(
        string $key,
    ): ?array {
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            return null;
        }

        $serialized = $this->cacheValueSigner->unwrap($content, $key);

        if ($serialized === null) {
            @unlink($filePath);

            return null;
        }

        $data = unserialize($serialized);

        if (!is_array($data) || !array_key_exists('value', $data) || !isset($data['created_at'])) {
            return null;
        }

        return $data;
    }

    /**
     * @param array{value: mixed, expires_at: ?int, created_at: int} $data
     *
     * @throws FileCacheException|TamperedCacheValueException
     */
    private function write(
        string $key,
        array $data,
    ): void {
        $filePath = $this->getFilePath($key);
        $tempPath = $filePath . '.tmp.' . uniqid();

        $serialized = $this->cacheValueSigner->wrap(serialize($data), $key);

        $written = ErrorCapture::run($reason, fn (): int|false => file_put_contents($tempPath, $serialized, LOCK_EX));

        if ($written === false) {
            throw FileCacheException::writeFailed($filePath, $reason);
        }

        if (!ErrorCapture::run($reason, fn (): bool => rename($tempPath, $filePath))) {
            @unlink($tempPath);

            throw FileCacheException::writeFailed($filePath, $reason);
        }
    }

    /**
     * @param array{value: mixed, expires_at: ?int, created_at: int} $data
     */
    private function isExpired(
        array $data,
    ): bool {
        if ($data['expires_at'] === null) {
            return false;
        }

        return $this->clock->now()->getTimestamp() > $data['expires_at'];
    }

    /**
     * Creates the cache directory only when it is missing, so the common case
     * (it already exists) never calls mkdir(). A concurrent creator winning the
     * race is fine: mkdir() fails but the directory then exists.
     *
     * @throws FileCacheException
     */
    private function ensureDirectoryExists(): void
    {
        $path = $this->config->path();

        if (is_dir($path)) {
            return;
        }

        if (!ErrorCapture::run($reason, fn (): bool => mkdir($path, 0755, recursive: true)) && !is_dir($path)) {
            throw FileCacheException::directoryNotCreatable($path, $reason);
        }
    }
}
